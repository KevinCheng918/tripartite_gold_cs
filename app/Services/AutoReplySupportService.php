<?php

namespace App\Services;

use App\Models\AutoReplyTicket;
use App\Models\TelegramGroup;
use App\Repositories\AutoReplyTicketRepository;
use App\Repositories\QuickReplyRepository;
use App\Repositories\StationRepository;
use App\Repositories\TelegramRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 自動回覆 —— 內部支援群組
 *
 * 題庫裡找不到答案時，把問題轉到內部群組請自己人回答，
 * 再用按鈕決定要回覆客人／加入題庫。答不出來的問題就是題庫該長大的訊號。
 *
 * 整條迴路靠 ask_message_id 對應：自己人必須「引用回覆」求助訊息，
 * webhook 收到的 reply_to_message.message_id 才對得回原本那張單。
 */
class AutoReplySupportService
{
    /**
     * 求助訊息裡「前情」每一則的字數上限。
     *
     * 不放 config：這是排版考量（Telegram 訊息別太長），
     * 跟調整脈絡範圍那幾個值不是同一件事，客服也不會需要改它。
     */
    private const TICKET_LINE_CHARS = 200;

    /** @var int config 讀不到時的備用前情則數，理由同 AutoReplyService::contextSetting() */
    private const FALLBACK_TICKET_LINES = 2;

    private $ticketRepository;
    private $telegramRepository;
    private $quickReplyRepository;
    private $userRepository;
    private $stationRepository;
    private $botService;
    private $chatService;
    private $quickReplyService;
    private $appSettingService;

    public function __construct(
        AutoReplyTicketRepository $ticketRepository,
        TelegramRepository $telegramRepository,
        QuickReplyRepository $quickReplyRepository,
        UserRepository $userRepository,
        StationRepository $stationRepository,
        TelegramBotService $botService,
        TelegramChatService $chatService,
        QuickReplyService $quickReplyService,
        AppSettingService $appSettingService
    ) {
        $this->ticketRepository = $ticketRepository;
        $this->telegramRepository = $telegramRepository;
        $this->quickReplyRepository = $quickReplyRepository;
        $this->userRepository = $userRepository;
        $this->stationRepository = $stationRepository;
        $this->botService = $botService;
        $this->chatService = $chatService;
        $this->quickReplyService = $quickReplyService;
        $this->appSettingService = $appSettingService;
    }

    /**
     * 這個 chat_id 是不是內部支援群組
     *
     * webhook 要靠這個在最前面攔截 —— 不攔的話內部群組會被當成客服對話建起來，
     * 自己人的討論會被存成客戶訊息。
     *
     * @param int|string $chatId
     * @return bool
     */
    public function isSupportChat($chatId)
    {
        $supportChatId = $this->appSettingService->get(AppSettingService::KEY_SUPPORT_CHAT_ID);

        if (blank($supportChatId)) {
            return false;
        }

        return (string) $supportChatId === (string) $chatId;
    }

    // ---------------------------------------------------------------
    //  開單
    // ---------------------------------------------------------------

    /**
     * 開一張求助單並發到內部群組
     *
     * @param TelegramGroup $group
     * @param string        $question  客人問題原文
     * @param int|null      $messageId 客人那則訊息的後台 id
     * @param string|null   $hint      AI 判斷摘要
     * @param array         $history   近期對話，一行一則、舊的在前
     * @return AutoReplyTicket|null
     */
    public function openTicket(TelegramGroup $group, $question, $messageId = null, $hint = null, array $history = [])
    {
        if (blank($this->appSettingService->get(AppSettingService::KEY_SUPPORT_CHAT_ID))) {
            Log::warning('未設定內部支援群組，答不出來的問題沒有轉出去', ['group_id' => $group->id]);

            return null;
        }

        $question = trim((string) $question);

        /*
         * 以前是「同群組還有單沒處理完就不重開」，結果客人問的第二個問題會直接消失 ——
         * 他收到「馬上請同仁確認」，同仁那邊卻只看得到第一個問題。
         * 漏掉客戶的問題比內部群組多幾則訊息嚴重得多，所以改成每個問題各開一張。
         *
         * 只擋「一模一樣的問題」：客人手滑重複貼、或等不及又問一次，
         * 這種開第二張單只是洗版，沒有新資訊。
         */
        if (filled($this->ticketRepository->findOpenByQuestion($group->id, $question))) {
            return null;
        }

        $ticket = $this->ticketRepository->create([
            'telegram_group_id' => $group->id,
            'message_id'        => $messageId,
            'question'          => $question,
            'status'            => config('constants.AUTO_REPLY.TICKET_STATUS.PENDING'),
        ]);

        $result = $this->sendToSupport($this->buildAskText($group, $question, $hint, $history));
        $askMessageId = Arr::get($result, 'result.message_id');

        if (blank($askMessageId)) {
            Log::error('求助訊息送出失敗，這張單將無法被回覆對應', ['ticket_id' => $ticket->id]);

            return $ticket;
        }

        return $this->ticketRepository->update($ticket, ['ask_message_id' => $askMessageId]);
    }

    /**
     * 組求助訊息
     *
     * @param TelegramGroup $group
     * @param string        $question
     * @param string|null   $hint
     * @param array         $history 近期對話，一行一則、舊的在前
     * @return string
     */
    private function buildAskText(TelegramGroup $group, $question, $hint = null, array $history = [])
    {
        $lines = [
            '🔔 題庫裡找不到答案',
            '',
            "群組：{$group->title}",
            "問題：{$question}",
        ];

        /*
         * 前情。客人說「這是什麼錯誤呢」時，光看問題那一行完全不知道在問什麼，
         * 同仁還得切回對話視窗往上翻。
         *
         * 只帶最後幾則、每則再截短：這則訊息要送進 Telegram，
         * 客人貼的整包 log 原封不動轉過來會把內部群組洗版。
         */
        $recent = $this->tailHistory($history);

        if (filled($recent)) {
            $lines[] = '';
            $lines[] = '前情：';

            foreach ($recent as $line) {
                $lines[] = "  {$line}";
            }
        }

        // AI 的判斷只給自己人看，客人不會收到。
        // 用處是不必從頭看就知道客人要什麼，也一眼看得出題庫缺了哪一塊
        if (filled($hint)) {
            $lines[] = '';
            $lines[] = $hint;
        }

        $lines[] = '';
        $lines[] = '請「引用回覆」本則訊息提供答案。';
        $lines[] = '⚠️ 回答內容會原文轉給客戶，請用可以直接給客戶看的語氣。';

        return implode("\n", $lines);
    }

    /**
     * 取脈絡的最後幾則，並把每則截短
     *
     * 模型那邊拿的是完整脈絡（越多越好判斷），同仁這邊要的只是
     * 「這句在講什麼」，所以這裡另外砍一次。
     *
     * @param array<int, string> $history
     * @return array<int, string>
     */
    private function tailHistory(array $history)
    {
        $limit = config('auto_reply.context.ticket');
        $limit = blank($limit) ? self::FALLBACK_TICKET_LINES : (int) $limit;

        if ($limit < 1 || blank($history)) {
            return [];
        }

        $lines = array_slice($history, -$limit);

        foreach ($lines as $index => $line) {
            if (mb_strlen($line) > self::TICKET_LINE_CHARS) {
                $lines[$index] = mb_substr($line, 0, self::TICKET_LINE_CHARS) . '…（略）';
            }
        }

        return $lines;
    }

    // ---------------------------------------------------------------
    //  收答案
    // ---------------------------------------------------------------

    /**
     * 處理內部支援群組收到的訊息
     *
     * 只處理「引用回覆求助訊息」，其餘一律忽略 —— 內部群組本來就會有閒聊。
     *
     * @param array $payload Telegram Update
     * @return void
     */
    public function handleSupportMessage($payload)
    {
        $message = Arr::get($payload, 'message');
        $quotedId = Arr::get($payload, 'message.reply_to_message.message_id');

        if (blank($message) || blank($quotedId)) {
            return;
        }

        $answer = trim((string) Arr::get($message, 'text', ''));

        if (blank($answer)) {
            return;
        }

        $ticket = $this->ticketRepository->findByAskMessageId($quotedId);

        if (blank($ticket)) {
            return;
        }

        if ($ticket->isClosed()) {
            $this->sendToSupport('這張單已經處理過了，如果要更新答案請直接到後台題庫修改。', null, $message['message_id']);

            return;
        }

        $this->ticketRepository->update($ticket, [
            'answer'      => $answer,
            'answered_by' => $this->buildSenderName($message),
            'answered_at' => now(),
            'status'      => config('constants.AUTO_REPLY.TICKET_STATUS.ANSWERED'),
        ]);

        $this->sendToSupport(
            "收到 {$this->buildSenderName($message)} 的回覆，要怎麼處理？",
            $this->buildActionKeyboard($ticket->id),
            $message['message_id']
        );
    }

    /**
     * 回答者的顯示名稱
     *
     * @param array $message
     * @return string
     */
    private function buildSenderName($message)
    {
        $from = (array) Arr::get($message, 'from', []);
        $username = Arr::get($from, 'username');

        if (filled($username)) {
            return "@{$username}";
        }

        $firstName = Arr::get($from, 'first_name', '');
        $lastName = Arr::get($from, 'last_name', '');
        $name = trim("{$firstName} {$lastName}");

        return filled($name) ? $name : '同仁';
    }

    // ---------------------------------------------------------------
    //  按鈕
    // ---------------------------------------------------------------

    /**
     * 處理 inline keyboard 的按鈕事件
     *
     * @param array $payload Telegram Update
     * @return void
     */
    public function handleCallback($payload)
    {
        $callback = Arr::get($payload, 'callback_query');
        $data = Arr::get($payload, 'callback_query.data');

        if (blank($callback) || blank($data)) {
            return;
        }

        $parts = explode(':', $data);
        $prefix = array_shift($parts);
        $messageId = Arr::get($callback, 'message.message_id');

        $this->botService->answerCallbackQuery($callback['id']);

        if ($prefix === config('constants.AUTO_REPLY.CALLBACK.ACTION')) {
            $this->handleAction($parts, $messageId);

            return;
        }

        if ($prefix === config('constants.AUTO_REPLY.CALLBACK.CATEGORY')) {
            $this->handleCategoryChosen($parts, $messageId);
        }
    }

    /**
     * 第一層按鈕：決定怎麼處理這個回答
     *
     * @param array    $parts     [action, ticket_id]
     * @param int|null $messageId 按鈕所在的訊息
     * @return void
     */
    private function handleAction(array $parts, $messageId)
    {
        $action = (string) Arr::get($parts, 0, '');
        $ticket = $this->ticketRepository->find((int) Arr::get($parts, 1, 0));

        if (blank($ticket) || $ticket->isClosed()) {
            $this->editSupportMessage($messageId, '這張單已經處理過了。');

            return;
        }

        $actions = config('constants.AUTO_REPLY.ACTION');
        $statuses = config('constants.AUTO_REPLY.TICKET_STATUS');

        if ($action === $actions['IGNORE']) {
            $this->ticketRepository->update($ticket, ['status' => $statuses['IGNORED']]);
            $this->editSupportMessage($messageId, '已忽略這張單，不會回覆客人也不會加入題庫。');

            return;
        }

        if ($action === $actions['REPLY']) {
            $sent = $this->replyToCustomer($ticket);
            $this->ticketRepository->update($ticket, ['status' => $statuses['REPLIED']]);
            $this->editSupportMessage($messageId, $sent ? '✅ 已回覆客人。' : '⚠️ 回覆客人失敗，請到後台手動處理。');

            return;
        }

        // 要加入題庫的兩個動作：先問類別
        if ($action === $actions['REPLY_AND_SAVE']) {
            $sent = $this->replyToCustomer($ticket);
            $this->ticketRepository->update($ticket, ['status' => $statuses['REPLIED']]);

            $prefix = $sent ? '✅ 已回覆客人。' : '⚠️ 回覆客人失敗。';
            $this->editSupportMessage($messageId, "{$prefix}\n\n請選擇要把這題歸到哪個類別：", $this->buildCategoryKeyboard($ticket->id));

            return;
        }

        if ($action === $actions['SAVE_ONLY']) {
            $this->editSupportMessage($messageId, '請選擇要把這題歸到哪個類別：', $this->buildCategoryKeyboard($ticket->id));
        }
    }

    /**
     * 第二層按鈕：選好類別，寫進題庫
     *
     * @param array    $parts     [ticket_id, category_id]
     * @param int|null $messageId
     * @return void
     */
    private function handleCategoryChosen(array $parts, $messageId)
    {
        $ticket = $this->ticketRepository->find((int) Arr::get($parts, 0, 0));
        $categoryId = (int) Arr::get($parts, 1, 0);

        if (blank($ticket)) {
            $this->editSupportMessage($messageId, '找不到這張單。');

            return;
        }

        // 取消：保留前一步的結果，只是不加題庫
        if ($categoryId <= 0) {
            $this->editSupportMessage($messageId, '已取消加入題庫。');

            return;
        }

        $item = $this->saveToQuickReply($ticket, $categoryId);

        if (blank($item)) {
            $this->editSupportMessage($messageId, '⚠️ 加入題庫失敗，請到後台手動新增。');

            return;
        }

        $this->editSupportMessage($messageId, $this->buildSavedText($item));
    }

    /**
     * 寫進題庫，並把求助單標記成已回填
     *
     * label 刻意用客人的原話，不做美化 —— 這一欄的用途是讓模型認得「客人會怎麼問」，
     * 口語的原話比工整的標題更有參考價值。要整理成漂亮的題目，到後台改就好。
     *
     * @param AutoReplyTicket $ticket
     * @param int             $categoryId
     * @return \App\Models\QuickReplyItem|null
     */
    private function saveToQuickReply(AutoReplyTicket $ticket, $categoryId)
    {
        try {
            // 題庫與求助單狀態同生共死 —— 只寫進題庫卻沒標記求助單，
            // 這張單會被當成還沒回填，同一題有機會再被加進去一次
            return DB::transaction(function () use ($ticket, $categoryId) {
                $item = $this->quickReplyService->createItem([
                    'category_id' => $categoryId,
                    'label'       => $ticket->question,
                    'answer'      => $ticket->answer,
                ]);

                $this->ticketRepository->update($ticket, [
                    'status'              => config('constants.AUTO_REPLY.TICKET_STATUS.SAVED'),
                    'quick_reply_item_id' => $item->id,
                ]);

                return $item;
            });
        } catch (\Exception $e) {
            Log::error('求助單回填題庫失敗', ['ticket_id' => $ticket->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * 組寫入成功的結果訊息
     *
     * 把類別／問題／答案攤出來，群組裡所有人當下就看得到加了什麼，
     * 發現歸錯類別或答案要修可以直接去後台改。
     *
     * @param \App\Models\QuickReplyItem $item
     * @return string
     */
    private function buildSavedText($item)
    {
        $category = $this->quickReplyRepository->getActiveCategories()->firstWhere('id', $item->category_id);
        $categoryLabel = filled($category) ? $category->label : '未知類別';

        return implode("\n", [
            '✅ 已加入題庫',
            '',
            "類別：{$categoryLabel}",
            "問題：{$item->label}",
            "答案：{$item->answer}",
            '',
            '如需調整內容，請至後台「快速回覆題庫」頁面編輯。',
        ]);
    }

    /**
     * 把答案轉給客人
     *
     * 送的是自己人打的原文，系統不改寫 —— 只在外層包上禮貌話術。
     *
     * @param AutoReplyTicket $ticket
     * @return bool
     */
    private function replyToCustomer(AutoReplyTicket $ticket)
    {
        $template = $this->appSettingService->get(AppSettingService::KEY_TPL_SUPPORT_FULL);

        if (blank($template) || blank($ticket->answer)) {
            return false;
        }

        try {
            $this->chatService->sendReply(
                $ticket->telegram_group_id,
                strtr($template, ['{答案}' => $ticket->answer]),
                null,
                config('constants.AUTO_REPLY.SENDER_NAME'),
                [
                    'mark_replied' => true,
                    'signature'    => config('constants.AUTO_REPLY.SIGNATURE'),
                    'is_auto'      => true,
                ]
            );

            return true;
        } catch (\Exception $e) {
            Log::error('求助單回覆客人失敗', ['ticket_id' => $ticket->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    // ---------------------------------------------------------------
    //  按鈕組裝
    // ---------------------------------------------------------------

    /**
     * 第一層按鈕
     *
     * callback_data 有 64 bytes 上限，所以只放前綴與 id，不放任何文字。
     *
     * @param int $ticketId
     * @return array
     */
    private function buildActionKeyboard($ticketId)
    {
        $prefix = config('constants.AUTO_REPLY.CALLBACK.ACTION');
        $actions = config('constants.AUTO_REPLY.ACTION');

        return [
            [
                ['text' => '① 只回覆客人', 'callback_data' => "{$prefix}:{$actions['REPLY']}:{$ticketId}"],
                ['text' => '② 回覆客人並加入題庫', 'callback_data' => "{$prefix}:{$actions['REPLY_AND_SAVE']}:{$ticketId}"],
            ],
            [
                ['text' => '③ 只加入題庫', 'callback_data' => "{$prefix}:{$actions['SAVE_ONLY']}:{$ticketId}"],
                ['text' => '④ 忽略', 'callback_data' => "{$prefix}:{$actions['IGNORE']}:{$ticketId}"],
            ],
        ];
    }

    /**
     * 第二層按鈕：類別清單，每列兩顆
     *
     * @param int $ticketId
     * @return array
     */
    private function buildCategoryKeyboard($ticketId)
    {
        $prefix = config('constants.AUTO_REPLY.CALLBACK.CATEGORY');
        $categories = $this->quickReplyRepository->getActiveCategories();

        $buttons = [];
        foreach ($categories as $category) {
            $buttons[] = ['text' => $category->label, 'callback_data' => "{$prefix}:{$ticketId}:{$category->id}"];
        }

        $rows = array_chunk($buttons, 2);
        $rows[] = [['text' => '取消', 'callback_data' => "{$prefix}:{$ticketId}:0"]];

        return $rows;
    }

    // ---------------------------------------------------------------
    //  超時提醒
    // ---------------------------------------------------------------

    /**
     * 掃描超時未回答的求助單並提醒
     *
     * 分兩級：第一次 tag 當下排班的人，再沒回就 tag 主管與老闆。
     * 兩級都跳過工程 —— 客服問題不該去吵工程。
     *
     * @return int 送出的提醒數
     */
    public function remindTimeoutTickets()
    {
        if (blank($this->appSettingService->get(AppSettingService::KEY_SUPPORT_CHAT_ID))) {
            return 0;
        }

        $first = $this->appSettingService->getInt(AppSettingService::KEY_REMIND_FIRST_MINUTES, 10);
        $second = $this->appSettingService->getInt(AppSettingService::KEY_REMIND_SECOND_MINUTES, 10);
        $remind = config('constants.AUTO_REPLY.REMIND');

        $sent = 0;
        $sent += $this->remindStage($first, $remind['NONE'], $remind['ON_DUTY']);
        $sent += $this->remindStage($first + $second, $remind['ON_DUTY'], $remind['MANAGER']);

        return $sent;
    }

    /**
     * 送出某一階段的提醒
     *
     * @param int $minutes   從開單起算的超時分鐘數
     * @param int $fromStage 目前的提醒階段
     * @param int $toStage   要推進到的階段
     * @return int
     */
    private function remindStage($minutes, $fromStage, $toStage)
    {
        $tickets = $this->ticketRepository->getTimeoutTickets($minutes, $fromStage);

        if ($tickets->isEmpty()) {
            return 0;
        }

        $mentions = $this->buildMentions($toStage);
        $sent = 0;

        foreach ($tickets as $ticket) {
            // tag 不到人時仍要推進階段，否則每分鐘都會重試同一張單
            if (filled($mentions)) {
                $this->sendToSupport($this->buildRemindText($mentions, $minutes), null, $ticket->ask_message_id);
                $sent++;
            }

            $this->ticketRepository->update($ticket, [
                'remind_count'     => $toStage,
                'last_reminded_at' => now(),
            ]);
        }

        return $sent;
    }

    /**
     * 組這一階段要 @ 的人
     *
     * @param int $stage
     * @return array username 陣列（已含 @）
     */
    private function buildMentions($stage)
    {
        $users = $stage === config('constants.AUTO_REPLY.REMIND.MANAGER')
            ? $this->userRepository->getManagersForMention()
            : $this->userRepository->getMentionableByIds($this->chatService->getOnDutyUserIds());

        $mentions = [];
        foreach ($users as $user) {
            $mentions[] = '@' . ltrim($user->telegram_username, '@');
        }

        return $mentions;
    }

    /**
     * @param array $mentions
     * @param int   $minutes
     * @return string
     */
    private function buildRemindText(array $mentions, $minutes)
    {
        return implode("\n", [
            implode(' ', $mentions),
            "⏰ 上面這題已經等 {$minutes} 分鐘了，客人還在線上等回覆，麻煩協助看一下 🙏",
        ]);
    }

    // ---------------------------------------------------------------
    //  備援通知
    // ---------------------------------------------------------------

    /**
     * 發一則測試訊息到支援群組
     *
     * 設定完 chat_id 與 bot 之後用這個確認真的送得到 ——
     * 不然要等到第一張求助單才發現 bot 沒被拉進群組、或 Group Privacy 沒關。
     *
     * @return bool
     */
    public function sendTestMessage()
    {
        $result = $this->sendToSupport(implode("\n", [
            '✅ 測試訊息',
            '',
            '內部支援群組設定正確，自動回覆答不出來的問題會送到這裡。',
        ]));

        return filled($result) && filled(Arr::get($result, 'result.message_id'));
    }

    /**
     * 通知大家訂閱撞牆、現在在花 API 的錢
     *
     * @return void
     */
    public function notifyFallback()
    {
        $this->sendToSupport(implode("\n", [
            '⚠️ Claude 訂閱額度已達上限，自動回覆改用備援 API Key。',
            '這段期間的回覆會產生 API 費用，可到後台「全域設定」查看用量。',
        ]));
    }

    // ---------------------------------------------------------------
    //  發訊息
    // ---------------------------------------------------------------

    /**
     * 發訊息到內部支援群組
     *
     * @param string     $text
     * @param array|null $keyboard
     * @param int|null   $replyTo
     * @return array|null
     */
    private function sendToSupport($text, $keyboard = null, $replyTo = null)
    {
        $chatId = $this->appSettingService->get(AppSettingService::KEY_SUPPORT_CHAT_ID);

        if (blank($chatId)) {
            return null;
        }

        $this->switchSupportBot();

        return $this->botService->sendMessage($chatId, $text, $replyTo, $keyboard);
    }

    /**
     * 編輯支援群組裡的訊息（按鈕按完就地改成結果）
     *
     * @param int|null   $messageId
     * @param string     $text
     * @param array|null $keyboard
     * @return void
     */
    private function editSupportMessage($messageId, $text, $keyboard = null)
    {
        $chatId = $this->appSettingService->get(AppSettingService::KEY_SUPPORT_CHAT_ID);

        if (blank($chatId) || blank($messageId)) {
            return;
        }

        $this->switchSupportBot();
        $this->botService->editMessageText($chatId, $messageId, $text, $keyboard);
    }

    /**
     * 切換到支援群組所屬的 Bot
     *
     * 設定頁用下拉選 system，沒選就用 .env 的預設 bot。
     *
     * @return void
     */
    private function switchSupportBot()
    {
        $systemId = $this->appSettingService->getInt(AppSettingService::KEY_SUPPORT_SYSTEM_ID);

        if ($systemId <= 0) {
            return;
        }

        $system = $this->stationRepository->getActiveSystems()->firstWhere('id', $systemId);

        if (filled($system) && filled($system->bot_token)) {
            $this->botService->setToken($system->bot_token);
        }
    }
}
