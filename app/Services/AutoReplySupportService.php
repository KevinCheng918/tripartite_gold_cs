<?php

namespace App\Services;

use App\Jobs\ReplyTicketJob;
use App\Models\AutoReplyTicket;
use App\Models\TelegramGroup;
use App\Repositories\AutoReplyTicketRemindRepository;
use App\Repositories\AutoReplyTicketRepository;
use App\Repositories\QuickReplyRepository;
use App\Repositories\TelegramRepository;
use App\Repositories\UserRepository;
use App\Services\AutoReply\AnswerSplitter;
use App\Services\Notify\NoticeText;
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

    /** @var int 候選按鈕上標題的字數上限，超過 Telegram 會自己截掉 */
    private const BUTTON_LABEL_CHARS = 24;

    /*
     * 這個 class 發到內部群組的訊息屬於哪一類通知。
     *
     * 決定它進哪個 Telegram 話題 —— 對應 `constants.SUPPORT_TOPIC.TYPES` 的 key，
     * 由設定頁的話題清單勾選。沒被任何話題勾到就發到群組主區。
     *
     * ⚠ 這一類被標成 `needs_reply`：求助單要靠 `ask_message_id` 對回客服的
     * 引用回覆，所以**只能勾一個話題**（設定頁會驗證）。發到兩個話題會有兩個
     * message_id、只記得到一個，另一個話題的回覆就沒反應了。
     */
    const NOTICE_TYPE = 'auto_reply_ticket';

    private $ticketRepository;
    private $remindRepository;
    private $telegramRepository;
    private $quickReplyRepository;
    private $userRepository;
    private $botService;
    private $chatService;
    private $quickReplyService;
    private $appSettingService;
    private $supportGroup;
    private $splitter;
    private $noticeText;

    public function __construct(
        AutoReplyTicketRepository $ticketRepository,
        AutoReplyTicketRemindRepository $remindRepository,
        TelegramRepository $telegramRepository,
        QuickReplyRepository $quickReplyRepository,
        UserRepository $userRepository,
        TelegramBotService $botService,
        TelegramChatService $chatService,
        QuickReplyService $quickReplyService,
        AppSettingService $appSettingService,
        SupportGroupService $supportGroup,
        AnswerSplitter $splitter,
        NoticeText $noticeText
    ) {
        $this->ticketRepository = $ticketRepository;
        // 每次提醒 tag 到誰要逐筆落地，才答得出「某個人被催了幾次」
        $this->remindRepository = $remindRepository;
        $this->telegramRepository = $telegramRepository;
        $this->quickReplyRepository = $quickReplyRepository;
        $this->userRepository = $userRepository;
        $this->botService = $botService;
        $this->chatService = $chatService;
        $this->quickReplyService = $quickReplyService;
        $this->appSettingService = $appSettingService;
        $this->supportGroup = $supportGroup;
        $this->splitter = $splitter;
        // 等待時間與題目截短的排版，跟三支通知 service 共用同一份
        $this->noticeText = $noticeText;
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
     * @param string|null   $hint       AI 判斷摘要
     * @param array         $history    近期對話，一行一則、舊的在前
     * @param array         $candidates 沾得上邊的題目 id，最相近的在前
     * @return AutoReplyTicket|null
     */
    public function openTicket(TelegramGroup $group, $question, $messageId = null, $hint = null, array $history = [], array $candidates = [])
    {
        if (!$this->supportGroup->isConfigured()) {
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

        // 候選按鈕要等 ticket 建好才組得出來（callback_data 需要 ticket id）
        $result = $this->sendToSupport(
            $this->buildAskText($group, $question, $hint, $history),
            $this->buildCandidateKeyboard($ticket->id, $candidates)
        );
        $askMessageId = Arr::get($result, 'result.message_id');

        if (blank($askMessageId)) {
            Log::error('求助訊息送出失敗，這張單將無法被回覆對應', ['ticket_id' => $ticket->id]);

            return $ticket;
        }

        return $this->ticketRepository->update($ticket, ['ask_message_id' => $askMessageId]);
    }

    /**
     * 回答「這個話題的 id 是多少」
     *
     * 設定頁要填話題 id，但 Telegram 介面上看不到那個數字。請人去複製訊息連結
     * 數第幾段很容易看錯 —— webhook 的 update 本來就帶 `message_thread_id`，
     * 在話題裡輸入指令直接回答最可靠。
     *
     * @param array  $message
     * @param string $text
     * @return bool 有處理就回 true（呼叫端要跳過後續的作答判斷）
     */
    private function answerTopicId(array $message, $text)
    {
        $command = (string) config('constants.SUPPORT_TOPIC.ID_COMMAND');

        // 短路：絕大多數訊息連斜線開頭都不是，先擋掉再談其他
        if (mb_substr($text, 0, 1) !== '/') {
            return false;
        }

        /*
         * ⚠ **群組裡的指令常常帶著機器人名稱**（`/topicid@my_bot`）——
         * 從指令選單點選、或群組裡不只一個 bot 時，Telegram 就會加上去。
         * 完整字串相等會對不上，使用者只會看到「輸入了沒反應」。
         */
        if (strtolower(strtok($text, '@ ')) !== strtolower($command)) {
            return false;
        }

        $threadId = Arr::get($message, 'message_thread_id');

        /*
         * 主區（General）沒有 thread id。
         *
         * ⚠ 不能靜默 —— 使用者會以為指令壞了，然後去填一個錯的 id。
         */
        $reply = filled($threadId)
            ? strtr((string) config('constants.SUPPORT_TOPIC.ID_REPLY'), ['{id}' => (int) $threadId])
            : (string) config('constants.SUPPORT_TOPIC.ID_REPLY_GENERAL');

        /*
         * ⚠ 直接走 SupportGroupService 而不是 sendToSupport() ——
         * 後者會套 NOTICE_TYPE，把回覆丟到「AI 問題」那個話題去，
         * 而這句一定要回在使用者輸入的那個話題裡。
         */
        $result = $this->supportGroup->sendToThread($reply, $threadId);

        /*
         * 有收到指令就記一筆 —— 「輸入了沒反應」有兩種可能：webhook 沒收到、
         * 或收到了但回覆送不出去。沒有這行分不出是哪一種。
         */
        Log::info('收到話題 id 查詢', [
            'thread_id' => $threadId,
            'replied'   => filled(Arr::get((array) $result, 'result')),
        ]);

        return true;
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
        /*
         * 這一句是從實際出的包學來的：有人回「稍等，請人員為您說明」然後按了
         * 「加入題庫」，那句話就變成「系統費怎麼算」的標準答案 ——
         * 之後每個問系統費的客人都會收到「稍等」，而且因為是命中題庫，
         * **不會開求助單，沒有任何人知道**。
         */
        $lines[] = '⚠️ 按「加入題庫」之後，這個答案會自動回給往後問同樣問題的客戶，'
            . '所以「稍等」「我問一下」這類過渡用語請不要寫進去。';

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

        if (blank($message)) {
            return;
        }

        $text = trim((string) Arr::get($message, 'text', ''));

        // 話題 id 查詢要在「必須引用回覆」之前攔 —— 它是直接輸入的，沒有引用
        if ($this->answerTopicId($message, $text)) {
            return;
        }

        $quotedId = Arr::get($payload, 'message.reply_to_message.message_id');

        /*
         * ⚠ 群組開了話題功能之後，**話題裡的每則訊息都會帶 `reply_to_message`**
         * （指向話題的建立訊息），不只是真的引用別人的那些 ——
         * 所以這道判斷在 forum 群組裡幾乎擋不掉閒聊了。
         * 真正的過濾是下面的 `findByAskMessageId()` 查不到就 return。
         */
        if (blank($quotedId)) {
            return;
        }

        $answer = $text;

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

            return;
        }

        if ($prefix === config('constants.AUTO_REPLY.CALLBACK.PICK')) {
            $this->handleCandidatePicked($parts, $messageId);
        }
    }

    /**
     * 同仁按了「用 #111 回覆」
     *
     * 兩件事一起做：
     *
     * 1. **當下**：用題庫原文回客人，不用同仁打字，也不會新增重複題目。
     * 2. **下一次**：把客人那句原話記成這一題的問法樣本。那些字（「錢沒進來」
     *    之於「回調」）在題目的標題與答案裡都不會出現，只能從這裡補上。
     *    累積起來進 system prompt，同樣的問法下次就直接命中。
     *
     * @param array    $parts     [ticket_id, item_id]
     * @param int|null $messageId 按鈕所在的訊息
     * @return void
     */
    private function handleCandidatePicked(array $parts, $messageId)
    {
        $ticket = $this->ticketRepository->find((int) Arr::get($parts, 0, 0));

        if (blank($ticket) || $ticket->isClosed()) {
            $this->editSupportMessage($messageId, '這張單已經處理過了。');

            return;
        }

        $item = $this->quickReplyRepository->findActiveItem((int) Arr::get($parts, 1, 0));

        if (blank($item)) {
            $this->editSupportMessage($messageId, '⚠️ 這題已經被停用或刪除了，請改用引用回覆作答。');

            return;
        }

        $sent = $this->replyWithItem($ticket, $item);

        if (!$sent) {
            $this->editSupportMessage($messageId, '⚠️ 回覆客人失敗，請到後台手動處理。');

            return;
        }

        $learned = $this->closeTicketWithItem($ticket, $item);

        // 題庫內容變了就要清 prompt 快取，否則模型要等 TTL 過了才看得到新問法。
        // 放在交易外：清快取不是 DB 操作，rollback 也收不回來
        if (filled($learned)) {
            $this->quickReplyService->flushMatchPrompt();
        }

        $lines = ["✅ 已用 #{$item->id} {$item->label} 回覆客人。"];

        if (filled($learned)) {
            $lines[] = '';
            $lines[] = '📝 已記住客人這次的問法，之後同樣問法會自動回答。';
        }

        $this->editSupportMessage($messageId, implode("\n", $lines));
    }

    /**
     * 結掉求助單並記下問法樣本
     *
     * 兩張表要同生共死：單子標成已回覆、樣本卻沒寫進去的話，這次沒命中就
     * 完全白費了（客人下次同樣問法還是會轉人工），而且不會有任何跡象。
     *
     * ⚠️ **送訊息給客人刻意排在交易外面**（呼叫端已經送完才進來）——
     * 把 Telegram API 包進交易裡，網路一慢就是整個交易掛在那裡等。
     *
     * @param AutoReplyTicket $ticket
     * @param object          $item
     * @return \App\Models\QuickReplyPhrasing|null 新記下的樣本；重複或失敗時為 null
     */
    private function closeTicketWithItem(AutoReplyTicket $ticket, $item)
    {
        try {
            return DB::transaction(function () use ($ticket, $item) {
                $this->ticketRepository->update($ticket, [
                    'status'              => config('constants.AUTO_REPLY.TICKET_STATUS.REPLIED'),
                    'answer'              => $item->answer,
                    'quick_reply_item_id' => $item->id,
                    'answered_at'         => now(),
                ]);

                // 同一題已經有一模一樣的句子時回 null，那不是失敗
                return $this->quickReplyRepository->addPhrasing(
                    $item->id,
                    $ticket->question,
                    $ticket->telegram_group_id,
                    config('constants.QUICK_REPLY.PHRASING_SOURCE.SUPPORT')
                );
            });
        } catch (\Exception $e) {
            Log::error('求助單結案與記錄問法失敗', [
                'ticket_id' => $ticket->id,
                'item_id'   => $item->id,
                'error'     => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 先問客人，並把這句追問記進題庫
     *
     * 客人只丟一句「訂單沒收到款」，同仁得先問代理帳號與訂單號才查得下去。
     * 以前這種單子只能按忽略，那次對話就白費了 —— 現在存起來之後，
     * AI 比對到同樣的問法就會自己追問。
     *
     * 跟「② 回覆客人並加入題庫」的差別只有一個：**不必選類別**。
     * 一律歸到「需要補充資訊」，因為那個類別名稱同時是給模型看的線索。
     *
     * @param AutoReplyTicket $ticket
     * @param int|null        $messageId
     * @return void
     */
    private function handleAskInfo(AutoReplyTicket $ticket, $messageId)
    {
        $this->queueCustomerReply($ticket);
        $item = $this->saveToQuickReply($ticket, $this->askInfoCategoryId());

        $lines = ['✅ 已安排把問題送給客人。'];

        if (blank($item)) {
            $lines[] = '';
            $lines[] = '⚠️ 這句沒能存進題庫，下次同樣的問法還是會轉過來。';
            $this->editSupportMessage($messageId, implode("\n", $lines));

            return;
        }

        $lines[] = '';
        $lines[] = "📝 已記住：客人這樣問的時候要先跟他要這些資料（#{$item->id}）。";
        $lines[] = '之後遇到同樣的問法，系統會自己問，不會再轉過來。';

        $this->editSupportMessage($messageId, implode("\n", $lines));
    }

    /**
     * 取「需要補充資訊」類別的 id，沒有就建一個
     *
     * 正常情況下 AskInfoCategorySeeder 已經建好了。這裡補一層是因為
     * 漏跑 seeder 的話，同仁會按下按鈕卻存不進去 —— 那比多建一個類別糟。
     *
     * @return int
     */
    private function askInfoCategoryId()
    {
        $label = config('constants.AUTO_REPLY.ASK_INFO_CATEGORY');
        $category = $this->quickReplyRepository->findCategoryByLabel($label);

        if (filled($category)) {
            return $category->id;
        }

        Log::warning('「需要補充資訊」類別不存在，自動建立（AskInfoCategorySeeder 可能沒跑）');

        return $this->quickReplyService->createCategory(['label' => $label])->id;
    }

    /**
     * 用題庫原文回覆客人
     *
     * 走的是自動回覆命中時的同一套話術模板與分則邏輯 ——
     * 同一份答案不該因為是誰送的而排版不同。
     *
     * @param AutoReplyTicket $ticket
     * @param object          $item
     * @return bool
     */
    private function replyWithItem(AutoReplyTicket $ticket, $item)
    {
        // 直接送題庫原文，不包外殼 —— 跟自動回覆命中題庫時完全一致
        // （對客話術頁 2026-09-30 移除，那層外殼本來就跟 AI 的承接句重複）
        $chunks = $this->splitter->split($item->answer);
        $lastIndex = count($chunks) - 1;

        foreach ($chunks as $index => $chunk) {
            $isLast = $index === $lastIndex;

            try {
                $this->chatService->sendReply(
                    $ticket->telegram_group_id,
                    $chunk,
                    null,
                    config('constants.AUTO_REPLY.SENDER_NAME'),
                    [
                        'mark_replied' => $isLast,
                        'signature'    => $isLast ? config('constants.AUTO_REPLY.SIGNATURE') : null,
                        'is_auto'      => true,
                    ]
                );
            } catch (\Exception $e) {
                Log::error('用題庫原文回覆客人失敗', [
                    'ticket_id' => $ticket->id,
                    'item_id'   => $item->id,
                    'chunk'     => $index + 1,
                    'error'     => $e->getMessage(),
                ]);

                // 第一則就失敗才算完全沒送出去；中間失敗客人已經看到一部分，
                // 這時回報失敗會讓同仁重送一次而變成重複訊息
                return $index > 0;
            }
        }

        return true;
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
            $this->queueCustomerReply($ticket);
            $this->ticketRepository->update($ticket, ['status' => $statuses['REPLIED']]);
            $this->editSupportMessage($messageId, '✅ 已安排回覆客人。');

            return;
        }

        // 先問客人：送出去之後把這句存進「需要補充資訊」類別，
        // 之後 AI 比對到同樣的問法就會自己追問，不必再轉一次人工
        if ($action === $actions['ASK_INFO']) {
            $this->handleAskInfo($ticket, $messageId);

            return;
        }

        // 要加入題庫的兩個動作：先問類別
        if ($action === $actions['REPLY_AND_SAVE']) {
            /*
             * ⚠ 回覆丟背景、類別選單馬上出來。
             *
             * 原本是先同步把答案轉給客人（中間要跑模型寫承接句，好幾秒）
             * 才顯示選單 —— 需求方 2026-10-06 回報「這個按鈕跑很慢」。
             */
            $this->queueCustomerReply($ticket);
            $this->ticketRepository->update($ticket, ['status' => $statuses['REPLIED']]);

            $this->editSupportMessage(
                $messageId,
                "✅ 已安排回覆客人。\n\n請選擇要把這題歸到哪個類別：",
                $this->buildCategoryKeyboard($ticket->id)
            );

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
     * 安排把答案轉給客人（丟背景）
     *
     * ⚠ **按鈕那邊不等結果**：轉答案前要讓模型讀過答案再寫承接句，那要跑
     * 幾秒；同步做的話同仁按完得乾等才看得到下一步（2026-10-06 回報）。
     *
     * 代價是「有沒有送成功」當下不知道，所以訊息只能寫「已安排回覆」。
     * 真的失敗時 `ReplyTicketJob` 會回報到內部群組 —— 不講的話沒有人會發現
     * 客人其實沒收到。
     *
     * @param AutoReplyTicket $ticket
     * @return void
     */
    private function queueCustomerReply(AutoReplyTicket $ticket)
    {
        ReplyTicketJob::dispatch($ticket->id);
    }

    /**
     * 把答案轉給客人（`ReplyTicketJob` 的入口）
     *
     * ⚠ 這件事跑在背景，不在 webhook 裡 —— 轉答案前要讓模型讀過答案再寫
     * 承接句，那要幾秒。同步做的話同仁按完按鈕得乾等才看得到下一步。
     *
     * @param AutoReplyTicket $ticket
     * @return bool
     */
    public function sendTicketAnswer(AutoReplyTicket $ticket)
    {
        return $this->replyToCustomer($ticket);
    }

    /**
     * 把答案轉給客人（實作）
     *
     * 外部請走 `sendTicketAnswer()` —— 它是 `ReplyTicketJob` 的入口。
     *
     * @param AutoReplyTicket $ticket
     * @return bool
     */
    private function replyToCustomer(AutoReplyTicket $ticket)
    {
        if (blank($ticket->answer)) {
            return false;
        }

        /*
         * ⚠ **同仁寫什麼就送什麼，一個字都不加。**
         *
         * 這裡原本會在前面補一句承接（先是 12 句固定話術輪替，後來改成讓模型
         * 讀過答案再寫）。兩種都拿掉了 —— 需求方 2026-10-06：
         *
         * > 只要同仁有回覆，就是使用同仁回覆就好，即使是叫他等待，也是一樣，
         * > 因為同仁回覆就是正確的
         *
         * 實際踩到的毛病：同仁回「稍等，請人員為您說明」，前面卻被加上
         * 「為您問清楚了 😊」—— 上一句說問到了、下一句還在請客人等。
         * 承接句治不了這個，因為它永遠在猜那段答案是什麼性質。
         *
         * 客人問完當下已經收到一則「馬上請同仁為您說明」了（轉人工那則），
         * 所以這裡再客套一次本來就是多的。
         */
        try {
            $this->chatService->sendReply(
                $ticket->telegram_group_id,
                (string) $ticket->answer,
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
            ],
            [
                // 客人給的資訊不夠，要先跟他要資料。跟②的差別是**不必選類別** ——
                // 一律歸到「需要補充資訊」，之後 AI 就靠這一類自己追問
                ['text' => '④ 先問客人，並記住要問什麼', 'callback_data' => "{$prefix}:{$actions['ASK_INFO']}:{$ticketId}"],
            ],
            [
                ['text' => '⑤ 忽略', 'callback_data' => "{$prefix}:{$actions['IGNORE']}:{$ticketId}"],
            ],
        ];
    }

    /**
     * 第二層按鈕：類別清單，每列兩顆
     *
     * @param int $ticketId
     * @return array
     */
    /**
     * 候選題目按鈕（開單當下就出現）
     *
     * 客人問的其實題庫有答案、模型卻沒比對出來 —— 這種情況以前同仁只能
     * 自己回後台翻一百多題，或乾脆重打一次答案然後按「加入題庫」，
     * 結果題庫就多出一題重複的。
     *
     * 按鈕上要帶題號與標題：同仁得先看得出那是哪一題，才敢按下去。
     *
     * @param int   $ticketId
     * @param array $candidates 題目 id，最相近的在前
     * @return array|null 沒有候選時回 null（Telegram 不接受空的 keyboard）
     */
    private function buildCandidateKeyboard($ticketId, array $candidates)
    {
        if (blank($candidates)) {
            return null;
        }

        $prefix = config('constants.AUTO_REPLY.CALLBACK.PICK');
        $limit = (int) config('constants.AUTO_REPLY.CANDIDATE_LIMIT');
        $rows = [];

        foreach (array_slice($candidates, 0, $limit) as $itemId) {
            // 模型可能挑到剛被停用或刪掉的題目，那種不該讓同仁按下去才發現
            $item = $this->quickReplyRepository->findActiveItem($itemId);

            if (blank($item)) {
                continue;
            }

            // Telegram 按鈕文字過長會被截掉，先自己截並補省略號
            $label = mb_strlen($item->label) > self::BUTTON_LABEL_CHARS
                ? mb_substr($item->label, 0, self::BUTTON_LABEL_CHARS) . '…'
                : $item->label;

            $rows[] = [[
                'text'          => "📋 用 #{$item->id} {$label}",
                'callback_data' => "{$prefix}:{$ticketId}:{$item->id}",
            ]];
        }

        return blank($rows) ? null : $rows;
    }

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
     * 掃描該提醒的求助單並提醒
     *
     * 2026-10-06 從「固定催兩次」改成**一直催到單被處理**：
     *
     *   第 1、2 次   → 只 tag 當班人員
     *   第 3 次以後   → tag 當班人員 ＋ 主管與老闆（都跳過工程，客服問題不吵工程）
     *   超過上限      → 發一則收尾就停
     *
     * ⚠ **「問題解決」＝ status 離開 PENDING**，四種出口都算（已回答、已回客人、
     * 已入題庫、已忽略）。客服自己在後台回了客人會把單標成 IGNORED，所以
     * 提醒也會自己停，不必再去內部群組處理一次。
     *
     * @return int 送出的提醒數
     */
    public function remindTimeoutTickets()
    {
        if (!$this->supportGroup->isConfigured()) {
            return 0;
        }

        $first = $this->appSettingService->getInt(AppSettingService::KEY_REMIND_FIRST_MINUTES, 10);
        $interval = $this->appSettingService->getInt(AppSettingService::KEY_REMIND_INTERVAL_MINUTES, 10);
        $max = $this->appSettingService->getInt(
            AppSettingService::KEY_REMIND_MAX_COUNT,
            (int) config('constants.AUTO_REPLY.REMIND.MAX_COUNT')
        );

        $tickets = $this->ticketRepository->getTicketsDueForRemind($first, $interval, $max);

        if ($tickets->isEmpty()) {
            return 0;
        }

        /*
         * ⚠ **要 tag 的人整輪只查一次**。
         *
         * 這支每分鐘跑，而且同時卡住十張單是常態 —— 逐張單去查排班與主管名單
         * 就是十倍查詢。一分鐘內當班的人與主管名單都不會變。
         */
        $targets = $this->remindTargets($this->chatService->getOnDutyUserIds());
        $sent = 0;

        foreach ($tickets as $ticket) {
            if ($this->remindOne($ticket, $targets, $max)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * 提醒一張單
     *
     * ⚠ **不論送不送得出去，`remind_count` 都要往前推進**：tag 不到人就不更新的話，
     * 這張單每分鐘都會被重試一次，整晚下來是幾百次查詢與送信。
     *
     * @param \App\Models\AutoReplyTicket $ticket
     * @param array                       $targets 見 `remindTargets()`：各階段要 tag 的人
     * @param int                         $max     次數上限
     * @return bool 有送出訊息才算 true
     */
    private function remindOne($ticket, array $targets, $max)
    {
        $stageConfig = config('constants.AUTO_REPLY.REMIND.STAGE');
        $seq = (int) $ticket->remind_count + 1;
        $isFinal = $seq > $max;

        // 收尾那則一定要 tag 到主管老闆 —— 系統要放手了，總得有人知道
        $stage = $isFinal
            ? (int) Arr::get($stageConfig, 'FINAL')
            : ($seq >= (int) config('constants.AUTO_REPLY.REMIND.ESCALATE_AT')
                ? (int) Arr::get($stageConfig, 'MANAGER')
                : (int) Arr::get($stageConfig, 'ON_DUTY'));

        $users = $this->pickTargets($targets, $stage);
        $text = $isFinal
            ? $this->buildFinalText($users, $ticket, $seq - 1)
            : $this->buildRemindText($users, $ticket, $seq);

        $ok = $this->sendRemind($ticket, $text);

        /*
         * 提醒紀錄與單上的次數要一起成立。
         *
         * 少了交易：紀錄寫進去但次數沒推進，這張單下一分鐘會再催一次，
         * 統計上就多出一筆不存在的提醒。
         */
        DB::transaction(function () use ($ticket, $seq, $users, $stage, $isFinal, $max) {
            $this->remindRepository->record($ticket->id, $seq, $users->pluck('id')->all(), $stage);

            $this->ticketRepository->update($ticket, [
                /*
                 * 收尾那則把次數推到 max + 1 —— 查詢用 `remind_count <= $max`
                 * 收單，推過去之後這張單就不會再被撈出來。
                 */
                'remind_count'     => $isFinal ? $max + 1 : $seq,
                'last_reminded_at' => now(),
            ]);
        });

        Log::info('求助單超時提醒', [
            'ticket_id' => $ticket->id,
            'seq'       => $seq,
            'stage'     => $stage,
            'final'     => $isFinal,
            'mentioned' => $users->pluck('nickname')->all(),
            'sent'      => $ok,
        ]);

        return $ok;
    }

    /**
     * 把提醒送出去
     *
     * ⚠ 失敗不丟例外：一張單送不出去不能讓整輪停掉（同時卡住十張單時，
     * 第一張失敗就全部不催了）。回傳只用來統計「真的送出幾則」，
     * 次數推進與紀錄不受影響 —— 理由見 `remindOne()`。
     *
     * @param \App\Models\AutoReplyTicket $ticket
     * @param string                      $text
     * @return bool
     */
    private function sendRemind($ticket, $text)
    {
        try {
            $result = $this->sendToSupport($text, null, $ticket->ask_message_id);

            if (blank(Arr::get((array) $result, 'result'))) {
                Log::warning('求助單提醒未送達', ['ticket_id' => $ticket->id, 'response' => $result]);

                return false;
            }

            $this->reissueIfQuoteBroken($ticket, $result);

            return true;
        } catch (\Exception $e) {
            Log::error('求助單提醒送出失敗', ['ticket_id' => $ticket->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * 引用掛不上時，重發一張求助訊息並把 `ask_message_id` 換成新的
     *
     * ⚠ **沒有這條迴路就接不回來。** 客服作答靠「引用回覆求助訊息」，原訊息不在
     * 了（被刪、或群組換過 id）就永遠回不了 —— 那張單會一路催到上限然後消失，
     * 而客人的問題從頭到尾沒人處理。
     *
     * ⚠ 怎麼知道引用沒掛上：送出時帶了 `allow_sending_without_reply`，所以引用
     * 失敗時 Telegram **不會報錯**，而是靜默退化成一般訊息 —— 回應裡就少了
     * `result.reply_to_message`。那個欄位在不在，是唯一分得出來的訊號。
     *
     * 2026-10-06 踩到：群組開話題被升級成 supergroup，舊的 message_id 在新群組
     * 裡不存在，群組裡只剩一排孤兒提醒。
     *
     * @param \App\Models\AutoReplyTicket $ticket
     * @param array|null                  $result 剛才那則提醒的 API 回應
     * @return void
     */
    private function reissueIfQuoteBroken($ticket, $result)
    {
        // 本來就沒有引用對象（理論上不會發生），不是這裡要處理的狀況
        if (blank($ticket->ask_message_id)) {
            return;
        }

        if (filled(Arr::get((array) $result, 'result.reply_to_message'))) {
            return;
        }

        $remind = (array) config('constants.AUTO_REPLY.REMIND');
        $reissued = $this->sendToSupport(strtr((string) Arr::get($remind, 'REISSUE'), [
            '{group}'    => $this->ticketGroupTitle($ticket),
            '{question}' => (string) $ticket->question,
        ]));
        $newAskId = Arr::get((array) $reissued, 'result.message_id');

        if (blank($newAskId)) {
            Log::error('求助單重發失敗，這張單仍然無法被回覆對應', ['ticket_id' => $ticket->id]);

            return;
        }

        $this->ticketRepository->update($ticket, ['ask_message_id' => $newAskId]);

        Log::warning('求助單的原始訊息已失效，已重發並更新對應', [
            'ticket_id'      => $ticket->id,
            'old_message_id' => $ticket->ask_message_id,
            'new_message_id' => $newAskId,
        ]);
    }

    /**
     * 整輪要用到的兩組人，一次查完
     *
     * ⚠ 第 3 次之後是「當班人員 **＋** 主管與老闆」，不是「換成主管」——
     * 2026-10-06 以前的版本是後者，當班的人會以為事情已經不關他了。
     *
     * @param array $onDutyIds 當班人員的 user.id
     * @return array{on_duty: \Illuminate\Support\Collection, escalated: \Illuminate\Support\Collection}
     */
    private function remindTargets(array $onDutyIds)
    {
        $onDuty = collect($this->userRepository->getMentionableByIds($onDutyIds));

        return [
            'on_duty' => $onDuty,
            // id 去重：主管自己也在值班時，不要被 tag 兩次
            'escalated' => $onDuty
                ->concat($this->userRepository->getManagersForMention())
                ->unique('id')
                ->values(),
        ];
    }

    /**
     * 這一階段要用哪一組
     *
     * @param array $targets
     * @param int   $stage
     * @return \Illuminate\Support\Collection
     */
    private function pickTargets(array $targets, $stage)
    {
        $onDutyStage = (int) config('constants.AUTO_REPLY.REMIND.STAGE.ON_DUTY');
        $key = (int) $stage === $onDutyStage ? 'on_duty' : 'escalated';

        return collect(Arr::get($targets, $key, []));
    }

    /**
     * 一般提醒的文案
     *
     * @param \Illuminate\Support\Collection $users
     * @param \App\Models\AutoReplyTicket    $ticket
     * @param int                            $seq
     * @return string
     */
    private function buildRemindText($users, $ticket, $seq)
    {
        $remind = (array) config('constants.AUTO_REPLY.REMIND');

        return strtr((string) Arr::get($remind, 'TEXT'), [
            '{mentions}' => $this->buildMentions($users),
            // 從開單起算，不是從上次提醒起算 —— 客人等的是前者
            '{waited}'   => $this->noticeText->waited($ticket->created_at, $remind),
            '{count}'    => $seq,
            '{group}'    => $this->ticketGroupTitle($ticket),
            '{question}' => $this->noticeText->shorten($ticket->question, (int) Arr::get($remind, 'QUESTION_CHARS')),
        ]);
    }

    /**
     * 這張單的客人群組名稱
     *
     * @param \App\Models\AutoReplyTicket $ticket
     * @return string
     */
    private function ticketGroupTitle($ticket)
    {
        return filled($ticket->group) ? (string) $ticket->group->title : '-';
    }

    /**
     * 撞到上限、不再提醒的收尾文案
     *
     * @param \Illuminate\Support\Collection $users
     * @param \App\Models\AutoReplyTicket    $ticket
     * @param int                            $count 總共催了幾次
     * @return string
     */
    private function buildFinalText($users, $ticket, $count)
    {
        $remind = (array) config('constants.AUTO_REPLY.REMIND');

        return strtr((string) Arr::get($remind, 'FINAL_TEXT'), [
            '{mentions}' => $this->buildMentions($users),
            '{count}'    => $count,
            '{group}'    => $this->ticketGroupTitle($ticket),
            '{question}' => $this->noticeText->shorten($ticket->question, (int) Arr::get($remind, 'QUESTION_CHARS')),
        ]);
    }

    /**
     * 組 @ 字串
     *
     * tag 不到任何人時給一句說明 —— 直接空字串的話訊息會以空行開頭，
     * 看起來像系統壞了。
     *
     * @param \Illuminate\Support\Collection $users
     * @return string
     */
    private function buildMentions($users)
    {
        if (blank($users)) {
            return (string) config('constants.AUTO_REPLY.REMIND.NO_MENTION');
        }

        $mentions = [];

        foreach ($users as $user) {
            $mentions[] = '@' . ltrim($user->telegram_username, '@');
        }

        return implode(' ', $mentions);
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
            '這段期間的回覆會產生 API 費用，可到後台「AI 引擎」查看用量。',
        ]));
    }

    // ---------------------------------------------------------------
    //  發訊息
    //
    //  實作搬到 SupportGroupService（站台餘點告警也要走同一條路）。
    //  這兩支留著當這個 class 的語意入口 —— 裡面沒有邏輯，只是 21 個呼叫點
    //  讀起來是「送到支援群組」而不是「送到某個 chat_id」。
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
        return $this->supportGroup->send($text, $keyboard, $replyTo, self::NOTICE_TYPE);
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
        $this->supportGroup->editMessage($messageId, $text, $keyboard);
    }
}
