<?php

namespace App\Services;

use App\Models\ShiftHandover;
use App\Models\ShiftHandoverGap;
use App\Presenters\TelegramUsernamePresenter;
use App\Repositories\AutoReplyTicketRepository;
use App\Repositories\ConversationFollowupRepository;
use App\Repositories\ScheduledReminderRepository;
use App\Repositories\ShiftHandoverGapRepository;
use App\Repositories\ShiftHandoverRepository;
use App\Repositories\UserRepository;
use App\Services\AutoReply\ClaudeCodeMatcher;
use App\Services\Notify\NoticeText;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * 交接漏項盤點：AI 比對交接內容，沒寫到的**一題一題**問要不要補
 *
 * > 需求方 2026-11-19：「整理上一個班有的問題，然後 AI 比對有沒有交接到，
 * > 詢問沒有寫到的要不要補上。」
 *
 * > 需求方 2026-11-23：「我不要用選項，我要用一個問題一個問題問，然後不要
 * > 單一去變，我要留下紀錄：一個問題，問出來，由交接班的人去選擇，選完之後
 * > 就補充到對話，繼續問下一題，這樣無論是下一班的人或是主管、老闆回過頭來看
 * > 才知道斷在哪裡。」
 *
 * ⚠⚠ **所以流程是一問一答，話題本身就是那份紀錄：**
 *
 * ```
 * 開場（這一班有 N 件看起來沒寫進交接，我一題一題問）
 * 第 1 題 ──答──▶ 第 1 題 ＋「✅ 要補上」（按鈕撤掉，題目留著）
 * 第 2 題 ──答──▶ 第 2 題 ＋「⏭ 不用補」
 * 第 3 題  ← 還掛著按鈕 ＝ **斷在這裡**
 * （全部答完才發總結）
 * ```
 *
 * ⚠⚠ **不要改回整批選單**（「全部補上」「都不用」那種）。整批會把整件事
 * 壓成一則「已補上 3 項」—— 哪一題怎麼決定的、答到第幾題就沒下文了，
 * 全都看不出來。
 *
 * ⚠⚠ **跟 `ShiftHandoverService` 是兩件事。** 那一支確認「**有沒有打**」
 * （在交接話題發言就算完成，內容一個字都沒看），這一支看
 * 「**打的內容夠不夠**」。所以：
 *
 * - 那一支**催到完成為止**（交接沒有正當的不做理由）
 * - 這一支**只問一次**（漏項是判斷題，他可能真的覺得不必交接）
 *
 * ⚠ **只比對「這件事有沒有被提到」，不評價寫得好不好** —— 後者沒有客觀
 * 標準，而且會讓這則通知變成找碴。
 *
 * 詳見 .claude/knowledge/features/handover-gap-review.md
 */
class ShiftHandoverReviewService
{
    /** @var string 通知類型：跟交接提醒同一個話題（盤點結果跟交接是同一件事） */
    const NOTICE_TYPE = ShiftHandoverService::NOTICE_TYPE;

    private $handoverRepository;
    private $gapRepository;
    private $ticketRepository;
    private $followupRepository;
    private $reminderRepository;
    private $userRepository;
    private $supportGroup;
    private $matcher;
    private $noticeText;

    /**
     * 這一次請求查過的「交接那個人」
     *
     * ⚠ 存在的理由：按一次按鈕會問兩次 —— `isOwner()`（是不是本人按的）
     * 與 `mentionOf()`（要 tag 誰），兩支要的是同一個帳號。
     * 沒有這個 memo 就是兩趟一模一樣的查詢。
     *
     * @var array<int, \App\Models\User|null>
     */
    private $owners = [];

    /**
     * 這一次請求查過的主管名單
     *
     * ⚠ 催補一輪可能有好幾筆到達 `ESCALATE_AT`，名單對每一筆都一樣 ——
     * 逐筆查就是 N 倍查詢。而**真的到得了那個次數的輪次很少**，
     * 所以也不該在進迴圈前無條件查（見 `managers()`）。
     *
     * @var array<string, \Illuminate\Support\Collection>
     */
    private $managers = [];

    public function __construct(
        ShiftHandoverRepository $handoverRepository,
        ShiftHandoverGapRepository $gapRepository,
        AutoReplyTicketRepository $ticketRepository,
        ConversationFollowupRepository $followupRepository,
        ScheduledReminderRepository $reminderRepository,
        UserRepository $userRepository,
        SupportGroupService $supportGroup,
        ClaudeCodeMatcher $matcher,
        NoticeText $noticeText
    ) {
        $this->handoverRepository = $handoverRepository;
        $this->gapRepository = $gapRepository;
        $this->ticketRepository = $ticketRepository;
        $this->followupRepository = $followupRepository;
        $this->reminderRepository = $reminderRepository;
        // 要 tag 交接的人，所以得查他的 telegram_username
        $this->userRepository = $userRepository;
        $this->supportGroup = $supportGroup;
        // 比對走 Claude Code CLI（訂閱制），掛掉時退成「不比對、全部列出」
        $this->matcher = $matcher;
        // 「已等多久」的排版跟其他幾支通知共用
        $this->noticeText = $noticeText;
    }

    /**
     * 盤一次（`HandoverReviewJob` 的入口）
     *
     * @param int    $handoverId
     * @param string $handoverText 他打的交接原文
     * @return bool 有發出選單才回 true
     */
    public function review($handoverId, $handoverText)
    {
        // 短路：設定層先判，連交接紀錄都不必撈
        if (!$this->isEnabled()) {
            return false;
        }

        /*
         * ⚠ 已經盤過就不要再盤：佇列重送會讓同一個 Job 跑第二次，
         * 那時話題會多出一輪一模一樣的題目，而且題號會對到兩批資料。
         */
        if ($this->gapRepository->existsForHandover($handoverId)) {
            Log::info('這次交接已經盤過漏項了，略過', ['handover_id' => $handoverId]);

            return false;
        }

        $handover = $this->handoverRepository->find($handoverId);

        if (blank($handover)) {
            Log::warning('交接紀錄不見了，漏項沒有盤成', ['handover_id' => $handoverId]);

            return false;
        }

        /*
         * 「另外還有 N 件」那種補充說明要先抽出來：它不是一件具體的事，
         * **不該佔一個題號** —— 佔了就會多出一題**問不出答案的題目**
         * （沒有東西可以補，他只能卡在那裡），也不該被送去比對。
         */
        $split = $this->splitNotes($this->collect($handover));
        $candidates = (array) Arr::get($split, 'items', []);

        /*
         * ⚠ **「沒東西要比」與「比不出來」要分開。**
         *
         * 那一班真的什麼事都沒發生時，候選只剩最後那句固定問話 ——
         * 這時 `$covered` 也是空的，但原因是沒東西可比，不是模型掛掉。
         * 混在一起的話，選單會印「這次沒辦法自動比對交接內容」，
         * 而那是在對一個好好交接完的人說謊。
         */
        $askable = $this->askableIndexes($candidates);
        $covered = blank($askable) ? [] : $this->askCoverage($handoverText, $askable, $candidates);
        $gaps = $this->persist($handover, $candidates, $covered);

        if (blank($gaps)) {
            return false;
        }

        /*
         * 開場一則 ＋ 第一題一則，**之後每答一題才問下一題**
         * （需求方 2026-11-23）。
         *
         * ⚠ 開場不能併進第一題：第一題答完要把答案接在它自己那一則後面，
         * 那是在 `handleCallback()` 裡重組文字的 —— 那裡拿不到
         * 「有沒有比對成」與「另外還有 N 件」這兩個只有這一趟知道的東西。
         * 併進去的話，第一題一答完，開場那段資訊就被重組掉了。
         */
        $sent = $this->sendOpening(
            $handover,
            $gaps,
            filled($askable) && blank($covered),
            (array) Arr::get($split, 'notes', [])
        ) && $this->sendQuestion($handover, $gaps->first(), $gaps->count());

        /*
         * ⚠ **送不出去就把紀錄收回來。**
         *
         * 「有紀錄」＝「問過了」（`existsForHandover()` 靠它擋重跑）——
         * 訊息沒送出去卻留著紀錄的話，這張交接會變成
         * **永遠問不到、也永遠不會再問**的孤兒：題目不存在，所以沒有人
         * 答得到；而下一次重跑又會被擋下。
         *
         * 刪掉之後狀態才一致：有紀錄 ⇔ 題目真的在話題裡。
         *
         * ⚠ 開場送成功、第一題送失敗的話，開場那一則會留在話題裡 ——
         * 刪不掉（Telegram 的刪訊息有時限，而且那段話本身沒有錯）。
         * 下一次重跑會再發一組，話題裡會看到兩個開場。這是刻意選的：
         * **多一則說明，好過整張交接再也問不到。**
         */
        if (!$sent) {
            $this->gapRepository->deleteByHandover((int) $handover->id);
            Log::warning('交接漏項的開場或第一題沒送出去，已把剛寫入的紀錄收回', [
                'handover_id' => $handover->id,
                'gaps'        => $gaps->count(),
            ]);
        }

        return $sent;
    }

    /**
     * 按鈕：這一題「要補上」／「不用補」
     *
     * 答完就把答案接在那一題自己那一則後面（按鈕一起撤掉），
     * 然後問下一題；沒有下一題就發總結。
     *
     * @param array $parts     callback_data 去掉前綴後的片段：[action, handover_id, seq]
     * @param int   $messageId 按鈕所在的訊息
     * @param array $from      Telegram 的 from 物件
     * @return void
     */
    public function handleCallback(array $parts, $messageId, array $from)
    {
        $action = (string) Arr::get($parts, 0, '');
        $handoverId = (int) Arr::get($parts, 1, 0);
        $handover = $this->handoverRepository->find($handoverId);

        if (blank($handover)) {
            $this->supportGroup->editMessage($messageId, $this->text('RESULT_GONE'));

            return;
        }

        $seq = (int) Arr::get($parts, 2, 0);
        $status = $this->statusOfAction($action);

        /*
         * ⚠⚠ **認不出來的按鈕不能當成「不用補」。**
         *
         * 第一版寫成「是 FILL 就補、否則就是 SKIP」，於是任何雜訊都會把
         * 那一題標成**他說不用補** —— 而他其實什麼都沒選。
         *
         * ⚠ 這不是理論問題：**改版的那一刻，話題裡還留著上一版發出去的
         * 選單**，`[全部補上]`（action `a`）跟 `[都不用]` 都按得下去。
         *
         * 兩種都要擋：
         * - action 不是 `FILL`／`SKIP`（舊版的 `a`、被改過的 callback）
         * - **`seq < 1`** —— 新版題號從 1 起算，舊版那兩顆整批按鈕不指向
         *   任何一項、固定帶 0。放它往下走會查不到題目，然後回一句
         *   「這一題已經答過了」，而那是在講一件沒發生過的事。
         */
        if (blank($status) || $seq < 1) {
            Log::warning('交接漏項收到認不出來的按鈕（可能是改版前的舊選單）', [
                'handover_id' => $handoverId,
                'action'      => $action,
                'seq'         => $seq,
            ]);
            $this->supportGroup->editMessage($messageId, $this->text('ACTION_GONE'));

            return;
        }

        $gap = $this->gapRepository->findBySeq($handoverId, $seq);

        if (blank($gap)) {
            $this->supportGroup->editMessage($messageId, $this->text('RESULT_GONE'));

            return;
        }

        /*
         * ⚠ 已經答過的要擋下來：他連按兩下、或 Telegram 重送 callback 時，
         * 不擋就會多問一次下一題（而第一次問的那一則還掛著按鈕）。
         *
         * ⚠⚠ 但**擋的方式不能是把那一則換成一句「已經答過了」** ——
         * 見 `restoreAnswered()`。
         */
        if (!$gap->isPending()) {
            $this->restoreAnswered($handover, $handoverId, $seq, $messageId);

            return;
        }

        /*
         * ⚠ **只有打交接的本人按得動。** 下一班的人按「要補上」等於他在
         * 替上一班承諾 —— 而補上去的那句現況只有上一班答得出來。
         *
         * ⚠⚠ **不可以把題目換掉。** 第一版直接把整則改成
         * 「這是請交接的人自己決定的」—— **題目跟按鈕都不見了，
         * 本人從此答不了這一題，整輪就停在這裡**。
         * 下一班的人好奇按一下就能毀掉一題，而他完全不知道自己做了什麼。
         *
         * 改成把題目原樣重貼、**按鈕留著**，後面接一句說明誰該答。
         * 重貼的文字跟誰按都無關，所以按幾次都一樣。
         */
        if (!$this->isOwner($handover, $from)) {
            $total = $this->gapRepository->countByHandover($handoverId);

            $this->supportGroup->editMessage(
                $messageId,
                $this->buildQuestionText($handover, $gap, $total)
                    . strtr($this->text('NOT_OWNER'), ['{mention}' => $this->mentionOf($handover)]),
                $this->questionKeyboard($handover, $gap)
            );

            return;
        }

        $this->answer($handover, $gap, $messageId, (int) $status);
    }

    /**
     * 把那一則還原成「題目 ＋ 已經記下的答案」
     *
     * ⚠⚠ **重按一顆已經答過的按鈕，不可以把那一則的內容蓋掉。**
     * 第一版回的是一句乾的「這一題已經答過了。」—— 整則被取代，
     * **題目跟答案都不見了**，而那一則就是這個功能唯一的紀錄
     * （需求方 2026-11-23 要的「回過頭來看才知道斷在哪裡」）。
     *
     * ⚠ 這條路一定會被走到：**網路不好時按兩下是很正常的事** ——
     * 第一次的編輯還沒傳回他的畫面，按鈕看起來還在。Telegram 自己也會
     * 重送 callback。
     *
     * 重組出來的文字跟另一次寫進去的**一模一樣**（都是
     * `buildAnsweredText()` 吃同一列資料），所以按幾次結果都相同。
     *
     * @param ShiftHandover $handover
     * @param int $handoverId
     * @param int $seq
     * @param int $messageId
     * @return void
     */
    private function restoreAnswered(ShiftHandover $handover, $handoverId, $seq, $messageId)
    {
        // 重新撈一次：要的是**另一次已經寫進去**的那個處置
        $fresh = $this->gapRepository->findBySeq($handoverId, $seq);

        if (blank($fresh) || $fresh->isPending()) {
            $this->supportGroup->editMessage($messageId, $this->text('RESULT_GONE'));

            return;
        }

        Log::info('交接漏項這一題已經答過了，把那一則還原成紀錄', [
            'handover_id' => $handoverId,
            'seq'         => $seq,
            'status'      => $fresh->status,
        ]);

        $this->supportGroup->editMessage(
            $messageId,
            $this->buildAnsweredText(
                $handover,
                $fresh,
                $this->gapRepository->countByHandover($handoverId),
                (int) $fresh->status
            )
        );
    }

    /**
     * 按鈕動作 → 要寫進去的處置
     *
     * ⚠ 認不出來的回 null，呼叫端負責擋掉 —— **不要給預設值**，
     * 那會把雜訊靜靜地記成一個他沒做過的決定。
     *
     * @param string $action
     * @return int|null 見 constants.HANDOVER_REVIEW.STATUS
     */
    private function statusOfAction($action)
    {
        $actions = (array) config('constants.HANDOVER_REVIEW.ACTION');
        $status = (array) config('constants.HANDOVER_REVIEW.STATUS');

        if ($action === (string) Arr::get($actions, 'FILL')) {
            return (int) Arr::get($status, 'FILLED');
        }

        if ($action === (string) Arr::get($actions, 'SKIP')) {
            return (int) Arr::get($status, 'SKIPPED');
        }

        return null;
    }

    // ---------------------------------------------------------------
    //  他在話題裡補上內容
    // ---------------------------------------------------------------

    /**
     * 同仁引用盤點訊息發言 ＝ 他在補那一題的內容
     *
     * > 需求方 2026-11-23：「這邊有補在最後一句了，但是 AI 沒回了，
     * > 是回覆錯地方嗎，還是什麼問題。」
     * > →「應該是都要回話，有區分話題了，人工沒回錯的話，
     * > 系統不應該回錯才對。」
     *
     * ⚠⚠ **在這之前整條線是斷的**：答完「有，我補上」之後，他補的那句話
     * 沒有被任何地方接住 —— 系統不知道他補了什麼，也不知道他補了沒。
     *
     * ⚠ **只認「引用盤點訊息」**（需求方選的）：話題裡還有交接事項本身、
     * 下一班的交接、同仁之間的討論 —— 不限定引用的話，那些全都會被
     * 誤記成某一題的補充。
     *
     * @param array $payload Telegram Update
     * @return bool 這則訊息被當成補充處理掉了嗎（true ＝ 不要再往下走）
     */
    public function handleTopicReply($payload)
    {
        // 短路：設定層先判，連 payload 都不必拆
        if (!$this->isEnabled()) {
            return false;
        }

        $message = (array) Arr::get($payload, 'message', []);
        $quotedId = (int) Arr::get($message, 'reply_to_message.message_id', 0);
        $note = trim((string) Arr::get($message, 'text', ''));

        // 純字串判斷，最便宜，擺最前面
        if ($quotedId < 1 || blank($note)) {
            return false;
        }

        /*
         * ⚠⚠ **一定要先確認是交接話題，才可以去查 DB。**
         *
         * 話題群組裡**每一則訊息都帶 `reply_to_message`**（指向話題的
         * 建立訊息），所以上面那一道幾乎擋不掉任何東西 ——
         * 少了這一道，支援群組裡**每一句閒聊、每一次求助單作答**
         * 都會先白白跑兩次反查。
         *
         * `AutoReplySupportService::isTicketTopic()` 的註解寫的是同一件事，
         * 而第一版我把這一支擺在它前面，等於把那道防線繞過去了。
         *
         * ⚠ 這一步只讀快取（`AppSettingService::all()` 有快取），
         * 符合「config／cache 判斷先於 DB 查詢」。
         */
        $threadId = (int) Arr::get($message, 'message_thread_id', 0);

        if ($threadId < 1 || !in_array($threadId, $this->supportGroup->topicIdsFor(self::NOTICE_TYPE), true)) {
            return false;
        }

        $gap = $this->gapFor($quotedId);

        if (blank($gap)) {
            return false;
        }

        return $this->recordFill($gap, $note, $message);
    }

    /**
     * 他引用的那一則對應到哪一題
     *
     * 兩種引用都要認：
     *
     * | 他引用的 | 對應到 |
     * |---|---|
     * | 某一題 | 那一題 |
     * | 開場／總結 | 這一輪**第一個「說要補但還沒補」**的題目 |
     *
     * ⚠ 開場與總結不屬於任何一題，所以只能挑一個 —— 挑最早欠著的那個，
     * 因為總結列出來的順序就是那樣，他多半是照著順序補。
     *
     * @param int $quotedId
     * @return ShiftHandoverGap|null
     */
    private function gapFor($quotedId)
    {
        $gap = $this->gapRepository->findByAskMessageId($quotedId);

        if (filled($gap)) {
            return $gap;
        }

        $handover = $this->handoverRepository->findByReviewMessageId($quotedId);

        if (blank($handover)) {
            return null;
        }

        return $this->gapRepository->firstUnfilled((int) $handover->id);
    }

    /**
     * 記下他補的內容，並回他一句
     *
     * @param ShiftHandoverGap $gap
     * @param string           $note
     * @param array            $message Telegram message（要引用他那一則回覆）
     * @return bool
     */
    private function recordFill(ShiftHandoverGap $gap, $note, array $message)
    {
        /*
         * ⚠ **還沒作答的題目不要收。** 那一題的訊息還掛著按鈕，
         * 收下去會變成「資料上補好了，但畫面上還在問」——
         * 兩邊對不起來，下一班看了只會更亂。請他先按按鈕。
         */
        if ($gap->isPending()) {
            $this->replyInTopic($message, $this->text('FILL_NOT_ANSWERED'));

            return true;
        }

        $this->gapRepository->appendFilledNote($gap, $note);

        Log::info('交接漏項收到補充內容', [
            'handover_id' => $gap->shift_handover_id,
            'seq'         => $gap->seq,
            'chars'       => mb_strlen($note),
        ]);

        $this->replyInTopic($message, strtr($this->text('FILL_RECEIVED'), [
            '{seq}'   => (int) $gap->seq,
            '{title}' => $this->gapLabel($gap),
        ]));

        return true;
    }

    /**
     * 這一題在回覆裡怎麼稱呼
     *
     * ⚠ 主管交代那一題的 `title` 是空的（它不是從任何一張表撈出來的），
     * 直接印會變成「已記到『』底下」。
     *
     * @param ShiftHandoverGap $gap
     * @return string
     */
    private function gapLabel(ShiftHandoverGap $gap)
    {
        if ($gap->isManagerAsk()) {
            return $this->text('MANAGER_LABEL');
        }

        return (string) $gap->title;
    }

    /**
     * 在交接話題回一句，並引用他那一則
     *
     * ⚠ **一定要引用**：話題裡同時有好幾題、好幾班的交接，
     * 不引用就看不出這句「收到」是在回應哪一句補充。
     *
     * @param array  $message
     * @param string $text
     * @return void
     */
    private function replyInTopic(array $message, $text)
    {
        $this->supportGroup->send(
            $text,
            null,
            (int) Arr::get($message, 'message_id', 0),
            self::NOTICE_TYPE
        );
    }

    // ---------------------------------------------------------------
    //  催他補
    // ---------------------------------------------------------------

    /**
     * 說了要補卻還沒補的，催一輪
     *
     * > 需求方 2026-11-23 選的是「**催到補完為止**」。
     *
     * ⚠⚠ 這跟「漏項只問一次」不衝突，兩者問的是不同的事：
     *
     * | | 催不催 | 為什麼 |
     * |---|---|---|
     * | 要不要補（`[要補上]`／`[不用補]`） | **只問一次** | 判斷題，他可能真的覺得不必交接 |
     * | 說了要補卻沒補 | **催到補完** | 他已經答應了，這是未完成的事 |
     *
     * ⚠ 第 N 次起連主管一起 tag（沿用交接本身那套 `ESCALATE_AT`）。
     *
     * @return int 這一輪催了幾筆
     */
    public function remindUnfilled()
    {
        // 短路：設定層先判，不必碰 DB
        if (!$this->isEnabled()) {
            return 0;
        }

        $interval = (int) config('constants.HANDOVER_REVIEW.REMIND_INTERVAL');

        if ($interval < 1) {
            return 0;
        }

        $due = $this->gapRepository->getUnfilledDue($interval);

        if (blank($due)) {
            return 0;
        }

        $escalateAt = (int) config('constants.HANDOVER_REVIEW.ESCALATE_AT');
        $sent = 0;

        foreach ($due as $gap) {
            if ($this->remindOne($gap, $escalateAt)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * 主管名單（整支程式只查一次）
     *
     * ⚠⚠ **要延後到真的有人催到第 N 次才查。** 第一版是進迴圈前
     * 無條件查一次 —— 但絕大多數輪次裡沒有任何一筆到得了 `ESCALATE_AT`，
     * 那一次查詢就是白打的。
     *
     * ⚠ 用 `array_key_exists` 判斷查過沒，不是 `filled()` ——
     * 查不到（空集合）也要記住，否則每一筆都會再查一次確認「還是沒有」。
     *
     * @return \Illuminate\Support\Collection
     */
    private function managers()
    {
        if (!array_key_exists('all', $this->managers)) {
            $this->managers['all'] = $this->userRepository->getManagersForMention();
        }

        return $this->managers['all'];
    }

    /**
     * 催一筆
     *
     * ⚠ **不論 tag 不 tag 得到人，`remind_count` 都要往前推進** ——
     * 不推的話這一筆每分鐘都會被重試（求助單、待追蹤、交接本身都踩過）。
     *
     * @param ShiftHandoverGap $gap
     * @param int              $escalateAt
     * @return bool
     */
    private function remindOne(ShiftHandoverGap $gap, $escalateAt)
    {
        $handover = $gap->handover;

        if (blank($handover)) {
            // 交接紀錄被刪了，這一筆催不出意義 —— 直接收掉，不要每分鐘重試
            $this->gapRepository->markReminded($gap, (int) $gap->remind_count + 1);
            Log::warning('交接漏項要催補，但交接紀錄不見了', ['gap_id' => $gap->id]);

            return false;
        }

        $seq = (int) $gap->remind_count + 1;
        $mention = $this->mentionOf($handover);

        // 催到第 N 次還沒補，連主管一起 tag（名單延後到這裡才查）
        if ($seq >= $escalateAt) {
            $others = $this->withoutOwner($this->managers(), $handover);

            if (filled($others)) {
                $mention .= ' ' . $this->buildMentions($others);
            }
        }

        $this->supportGroup->send(
            strtr($this->text('FILL_REMIND'), [
                '{mention}' => $mention,
                '{from}'    => $this->shiftName($handover),
                '{seq}'     => (int) $gap->seq,
                '{title}'   => $this->gapLabel($gap),
                '{times}'   => $seq,
            ]),
            null,
            (int) $gap->ask_message_id > 0
                ? (int) $gap->ask_message_id
                : (int) $handover->handed_message_id,
            self::NOTICE_TYPE
        );

        $this->gapRepository->markReminded($gap, $seq);

        Log::info('交接漏項催補已送出', [
            'handover_id' => $handover->id,
            'seq'         => $gap->seq,
            'times'       => $seq,
        ]);

        return true;
    }

    /**
     * 主管名單裡把交接人自己剔掉
     *
     * ⚠⚠ **交接的人自己就是主管的情況很常見**（小團隊、組長輪班）。
     * 不剔掉的話第 3 次提醒會變成「@他 @他 @別的主管」—— 同一個人
     * 被 tag 兩次，看起來像系統壞掉。實測就是這樣抓到的。
     *
     * ⚠ 比 `id` 不比 Telegram 帳號：帳號可能沒填（那時
     * `mentionOf()` 會退成暱稱），比字串會漏掉。
     *
     * @param \Illuminate\Support\Collection $managers
     * @param ShiftHandover                  $handover
     * @return \Illuminate\Support\Collection
     */
    private function withoutOwner($managers, ShiftHandover $handover)
    {
        $owner = $this->ownerOf($handover);

        if (blank($owner)) {
            return $managers;
        }

        $ownerId = (int) $owner->id;

        return $managers->filter(function ($user) use ($ownerId) {
            return (int) $user->id !== $ownerId;
        })->values();
    }

    /**
     * 把主管名單組成 mention
     *
     * ⚠ 用 `mentionLine()`（吃 username 陣列）—— 它自己會處理
     * `@` 前綴與空值。`getManagersForMention()` 已經濾掉沒填帳號的，
     * 所以這裡不必再濾一次。
     *
     * @param \Illuminate\Support\Collection $users
     * @return string
     */
    private function buildMentions($users)
    {
        return (string) TelegramUsernamePresenter::mentionLine(
            $users->pluck('telegram_username')->all()
        );
    }

    // ---------------------------------------------------------------
    //  蒐集候選
    // ---------------------------------------------------------------

    /**
     * 那一班發生過哪些事
     *
     * 區間 ＝ **上一班的回訊時段**（`[交接日 + 上一班回訊開始, window_end]`）。
     * `window_end` 就是上一班回訊結束，表上已經有。
     *
     * ⚠ 最後一項（主管／老闆交代）**一定會被加進去**，不管前三項有沒有東西 ——
     * 它的價值正是在「系統完全不知道」的那一塊。
     *
     * @param ShiftHandover $handover
     * @return array<int, array{source_type:int, source_id:int|null, title:string, detail:string|null}>
     */
    private function collect(ShiftHandover $handover)
    {
        $start = $this->rangeStart($handover);
        $end = $handover->window_end;
        $limit = (int) config('constants.HANDOVER_REVIEW.MAX_PER_SOURCE');

        $items = array_merge(
            $this->ticketItems($start, $end, $limit),
            $this->followupItems($start, $end, $limit),
            $this->reminderItems($start, $end, $limit)
        );

        $items[] = [
            'source_type' => (int) config('constants.HANDOVER_REVIEW.SOURCE.MANAGER'),
            'source_id'   => null,
            'title'       => '',
            'detail'      => null,
        ];

        return $items;
    }

    /**
     * 把「另外還有 N 件」那種補充說明從候選裡抽出來
     *
     * ⚠ 它們**不進 `shift_handover_gap`**：那張表是「要問他的清單」，
     * 而這種說明沒有東西可以答 —— 讓它佔一個題號只會多出一題
     * 問不出答案的題目（而他只能在那裡卡住）。
     *
     * @param array $candidates
     * @return array{items: array, notes: array<int, string>}
     */
    private function splitNotes(array $candidates)
    {
        $items = [];
        $notes = [];

        foreach ($candidates as $item) {
            if ((bool) Arr::get($item, 'note_only', false)) {
                $notes[] = (string) Arr::get($item, 'title');

                continue;
            }

            $items[] = $item;
        }

        return ['items' => $items, 'notes' => $notes];
    }

    /**
     * 上一班的回訊開始時間
     *
     * ⚠ 用 `handover->date`（上一班回訊開始日）而不是 `window_start` 那天：
     * 大夜班回訊 23:00 開始，它的交接窗口在**隔天** 08:00 —— 用窗口那天
     * 去拼 23:00 會得到隔天晚上，整個區間變成未來，一件事都撈不到。
     *
     * ⚠ 班別沒填回訊時間時退回 `window_start`：那時區間會短一截
     * （只剩重疊的那一小時），但總比拿不到區間整個不盤好。
     *
     * @param ShiftHandover $handover
     * @return Carbon
     */
    private function rangeStart(ShiftHandover $handover)
    {
        $replyStart = filled($handover->fromShift) ? (string) $handover->fromShift->reply_start_time : '';

        if (blank($replyStart)) {
            return $handover->window_start;
        }

        return Carbon::parse("{$handover->date->format('Y-m-d')} {$replyStart}");
    }

    /**
     * 那一班開的、還沒結案的求助單
     *
     * @param Carbon $start
     * @param Carbon $end
     * @param int    $limit
     * @return array
     */
    private function ticketItems(Carbon $start, Carbon $end, $limit)
    {
        $tickets = $this->ticketRepository->getUnclosedBetween($start, $end, $limit);

        if (blank($tickets)) {
            return [];
        }

        $items = [];
        $sourceType = (int) config('constants.HANDOVER_REVIEW.SOURCE.TICKET');

        foreach ($tickets as $ticket) {
            $group = filled($ticket->group) ? (string) $ticket->group->title : '未知群組';
            $waited = $this->noticeText->waited($ticket->created_at, (array) config('constants.AUTO_REPLY.REMIND'));

            $items[] = [
                'source_type' => $sourceType,
                'source_id'   => (int) $ticket->id,
                'title'       => "{$group}：{$this->noticeText->shorten($ticket->question, 60)}",
                'detail'      => "求助單 #{$ticket->id}，已等 {$waited}，還沒結案",
            ];
        }

        /*
         * ⚠ **沒撈滿上限就不必查總數** —— 撈到的就是全部，那一次 COUNT
         * 是白打的（三個來源各一次）。`countOf()` 只在撈滿時才問 DB。
         */
        return $this->withMore($items, $limit, $sourceType, function () use ($start, $end) {
            return (int) $this->ticketRepository->countUnclosedBetween($start, $end);
        });
    }

    /**
     * 那一班開的、還掛著的待追蹤事項
     *
     * @param Carbon $start
     * @param Carbon $end
     * @param int    $limit
     * @return array
     */
    private function followupItems(Carbon $start, Carbon $end, $limit)
    {
        $followups = $this->followupRepository->getOpenBetween($start, $end, $limit);

        if (blank($followups)) {
            return [];
        }

        $items = [];
        $sourceType = (int) config('constants.HANDOVER_REVIEW.SOURCE.FOLLOWUP');

        foreach ($followups as $followup) {
            $group = filled($followup->group) ? (string) $followup->group->title : '未知群組';

            $items[] = [
                'source_type' => $sourceType,
                'source_id'   => (int) $followup->id,
                'title'       => "{$group}：{$this->noticeText->shorten($followup->wait_text, 60)}",
                'detail'      => "待追蹤，{$followup->sender_name} 說的，還沒有結果",
            ];
        }

        return $this->withMore($items, $limit, $sourceType, function () use ($start, $end) {
            return (int) $this->followupRepository->countOpenBetween($start, $end);
        });
    }

    /**
     * 那一班排下去、還沒發出去的提醒
     *
     * @param Carbon $start
     * @param Carbon $end
     * @param int    $limit
     * @return array
     */
    private function reminderItems(Carbon $start, Carbon $end, $limit)
    {
        $reminders = $this->reminderRepository->getPendingCreatedBetween($start, $end, $limit);

        if (blank($reminders)) {
            return [];
        }

        $items = [];
        $sourceType = (int) config('constants.HANDOVER_REVIEW.SOURCE.REMINDER');
        $format = (string) config('constants.SHIFT_HANDOVER_TOPIC.NOTICE.TIME_FORMAT');

        foreach ($reminders as $reminder) {
            $items[] = [
                'source_type' => $sourceType,
                'source_id'   => (int) $reminder->id,
                'title'       => (string) $this->noticeText->shorten($reminder->content, 60),
                'detail'      => "預約提醒，{$reminder->remind_at->format("m/d {$format}")} 會跳出來",
            ];
        }

        return $this->withMore($items, $limit, $sourceType, function () use ($start, $end) {
            return (int) $this->reminderRepository->countPendingCreatedBetween($start, $end);
        });
    }

    /**
     * 超過上限的那些 —— 補一句「另外還有 N 件」
     *
     * ⚠ **不能默默砍掉。** 清單看起來完整但其實被截斷的話，
     * 下一班會以為那就是全部（同其他通知「不要靜默截斷」的原則）。
     *
     * ⚠ `$countOf` 是 **closure 而不是算好的數字**：沒撈滿上限時根本不必
     * 問總數（撈到的就是全部），這樣三個來源各省一次 COUNT。
     *
     * ⚠ PHP 7.4 沒有 arrow function，呼叫端一律用 `function () use (...)`。
     *
     * @param array    $items
     * @param int      $limit
     * @param int      $sourceType
     * @param callable $countOf 回傳同條件下的總數（只在撈滿時才會被呼叫）
     * @return array
     */
    private function withMore(array $items, $limit, $sourceType, callable $countOf)
    {
        if (count($items) < $limit) {
            return $items;
        }

        $total = (int) $countOf();

        if ($total <= $limit) {
            return $items;
        }

        $items[] = [
            'source_type' => $sourceType,
            'source_id'   => null,
            'title'       => strtr($this->text('MORE'), ['{count}' => $total - $limit]),
            'detail'      => null,
            // 「還有 N 件」只是補充說明，不該變成一個可以按「補上」的選項
            'note_only'   => true,
        ];

        return $items;
    }

    // ---------------------------------------------------------------
    //  AI 比對
    // ---------------------------------------------------------------

    /**
     * 問模型：交接原文有沒有提到這幾件事
     *
     * ⚠ **掛掉一律回空陣列**，由呼叫端退成「沒有比對，全部列出來」——
     * 少了過濾只是多問幾項，漏掉才是真的出事。
     *
     * @param string $handoverText
     * @param array  $askable    要比對的候選索引（呼叫端算好，空的不會進來）
     * @param array  $candidates
     * @return array<int, bool> 以候選的索引為 key；空陣列 ＝ 比不出來
     */
    private function askCoverage($handoverText, array $askable, array $candidates)
    {
        // 短路：他一個字都沒打（純貼圖之類）就不必叫模型，那一定全部沒提到
        if (blank(trim((string) $handoverText))) {
            return [];
        }

        $lines = [];

        foreach ($askable as $index) {
            $title = (string) Arr::get($candidates, "{$index}.title");
            $lines[] = "{$index}. {$title}";
        }

        $list = implode("\n", $lines);
        $result = $this->matcher->generateStructured(
            (string) config('constants.HANDOVER_REVIEW.PROMPT'),
            "【交接原文】\n{$handoverText}\n\n【這一班發生過的事】\n{$list}",
            $this->schema()
        );

        if (blank($result)) {
            Log::warning('交接漏項比對沒有結果，改成全部列出', ['items' => count($askable)]);

            return [];
        }

        $covered = [];
        $unknown = [];

        foreach ((array) Arr::get($result, 'items', []) as $row) {
            $index = (int) Arr::get($row, 'index');

            /*
             * ⚠ **模型回的編號要驗過才能用。** 它可能回一個不存在的編號
             * （清單外的、或自己重編過的）—— 照單全收的話，那個 key 會對到
             * 另一個候選，把「其實沒交接到」的事標成已交接而**整項消失**。
             * 這是會讓事情靜靜漏掉的那種錯，所以寧可丟掉可疑的那一筆。
             */
            if (!in_array($index, $askable, true)) {
                $unknown[] = $index;

                continue;
            }

            $covered[$index] = (bool) Arr::get($row, 'covered');
        }

        if (filled($unknown)) {
            Log::warning('交接漏項比對回了清單外的編號，已忽略', [
                'unknown' => $unknown,
                'askable' => $askable,
            ]);
        }

        return $covered;
    }

    /**
     * 哪幾個候選要送去比對
     *
     * 固定問句（主管交代）不送 —— 它**永遠要問**，比對了也不會改變結果，
     * 只是白花一次模型的額度。
     *
     * （「另外還有 N 件」那種說明在 `splitNotes()` 就被抽掉了，走不到這裡。）
     *
     * @param array $candidates
     * @return array<int, int>
     */
    private function askableIndexes(array $candidates)
    {
        $manager = (int) config('constants.HANDOVER_REVIEW.SOURCE.MANAGER');
        $indexes = [];

        foreach ($candidates as $index => $item) {
            if ((int) Arr::get($item, 'source_type') === $manager) {
                continue;
            }

            $indexes[] = (int) $index;
        }

        return $indexes;
    }

    /**
     * 比對結果的 JSON Schema
     *
     * @return array
     */
    private function schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'items' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'index'   => ['type' => 'integer', 'description' => '清單上的編號'],
                            'covered' => ['type' => 'boolean', 'description' => '交接原文有沒有提到這件事'],
                        ],
                        'required'   => ['index', 'covered'],
                    ],
                ],
            ],
            'required'   => ['items'],
        ];
    }

    // ---------------------------------------------------------------
    //  落地與發選單
    // ---------------------------------------------------------------

    /**
     * 把「沒被提到的」寫進 `shift_handover_gap`
     *
     * ⚠ 只存沒被提到的那些 —— 這張表是「要問他的清單」，不是盤點全紀錄。
     *
     * @param ShiftHandover $handover
     * @param array         $candidates
     * @param array         $covered
     * @return \Illuminate\Support\Collection<ShiftHandoverGap>
     */
    private function persist(ShiftHandover $handover, array $candidates, array $covered)
    {
        $rows = [];
        $seq = 0;
        $now = now();

        foreach ($candidates as $index => $item) {
            if ((bool) Arr::get($covered, $index, false)) {
                continue;
            }

            $seq++;
            $rows[] = [
                'shift_handover_id' => (int) $handover->id,
                'source_type'       => (int) Arr::get($item, 'source_type'),
                'source_id'         => Arr::get($item, 'source_id'),
                'title'             => (string) Arr::get($item, 'title'),
                'detail'            => Arr::get($item, 'detail'),
                'covered'           => false,
                'status'            => (int) config('constants.HANDOVER_REVIEW.STATUS.PENDING'),
                'seq'               => $seq,
                // insert() 不會自動填 timestamps（那是 create() 才有的事）
                'created_at'        => $now,
                'updated_at'        => $now,
            ];
        }

        if (blank($rows)) {
            return collect();
        }

        $this->gapRepository->insertMany($rows);

        return $this->gapRepository->getByHandover((int) $handover->id);
    }

    /**
     * 發開場那一則（不帶按鈕）
     *
     * ⚠ **引用他那則交接**（`handed_message_id`）：話題裡同時有好幾班的
     * 交接時，不引用就看不出這一輪盤點是在講哪一則。
     *
     * @param ShiftHandover $handover
     * @param \Illuminate\Support\Collection $gaps
     * @param bool  $noAi  這次沒有比對過（模型掛掉或沒憑證）
     * @param array $notes 「另外還有 N 件」那幾句，接在開場最後
     * @return bool
     */
    private function sendOpening(ShiftHandover $handover, $gaps, $noAi, array $notes)
    {
        $result = $this->supportGroup->send(
            $this->buildOpeningText($handover, $gaps, $noAi, $notes),
            null,
            (int) $handover->handed_message_id,
            self::NOTICE_TYPE
        );

        $messageId = (int) Arr::get((array) $result, 'result.message_id');

        if ($messageId > 0) {
            /*
             * ⚠ **要把 message_id 留下來。** 同仁補交代事項時最自然的動作
             * 是引用開場或總結那一則（需求方 2026-11-23 的截圖就是這樣）——
             * 沒存就認不出那句話是在補什麼，只能當成閒聊丟掉。
             */
            $this->handoverRepository->update($handover, [
                'review_opening_message_id' => $messageId,
            ]);

            return true;
        }

        Log::warning('交接漏項的開場未送達', ['handover_id' => $handover->id, 'response' => $result]);

        return false;
    }

    /**
     * 開場的文字
     *
     * @param ShiftHandover $handover
     * @param \Illuminate\Support\Collection $gaps
     * @param bool  $noAi
     * @param array $notes
     * @return string
     */
    private function buildOpeningText(ShiftHandover $handover, $gaps, $noAi, array $notes)
    {
        $text = strtr($this->openingTemplate($gaps, $noAi), [
            '{from}'    => $this->shiftName($handover),
            '{mention}' => $this->mentionOf($handover),
            '{count}'   => $this->realCount($gaps),
        ]);

        // 「另外還有 N 件」是整批的補充、不屬於任何一題，所以擺在開場最後
        return $text . implode('', $notes);
    }

    /**
     * 開場講哪一句
     *
     * 三種情況要講三種話，講錯任何一種都是在對人說謊：
     *
     * | 情況 | 開場 |
     * |---|---|
     * | 比對過，有漏的 | 「有 N 件事看起來沒寫進交接」 |
     * | 比對過，**全都交接到了** | 「都交接到了 👍，最後確認一件事」 |
     * | 沒比對成（模型掛掉） | 「這一班發生過這些事」＋說明沒比對 |
     *
     * ⚠ 第二種不能沿用第一種：那時只剩最後那句固定問話，
     * 說「有 1 件事沒寫進交接」等於指著一個**問句**說他漏了，
     * 而他其實每一件都寫到了。
     *
     * @param \Illuminate\Support\Collection $gaps
     * @param bool $noAi
     * @return string
     */
    private function openingTemplate($gaps, $noAi)
    {
        if ($noAi) {
            return $this->text('OPENING_NO_AI');
        }

        if ($this->realCount($gaps) < 1) {
            return $this->text('OPENING_ALL_COVERED');
        }

        return $this->text('OPENING');
    }

    /**
     * 真正算「漏掉」的題數
     *
     * ⚠ 最後那句「主管／老闆有沒有交代」**不算漏項** —— 它每一次都會問，
     * 算進去會變成對一個好好交接完的人說「這一班有 1 件事沒寫進交接」。
     *
     * @param \Illuminate\Support\Collection $gaps
     * @return int
     */
    private function realCount($gaps)
    {
        return $gaps->filter(function ($gap) {
            return !$gap->isManagerAsk();
        })->count();
    }

    /**
     * 問一題（一題一則訊息）
     *
     * @param ShiftHandover $handover
     * @param ShiftHandoverGap|null $gap
     * @param int $total
     * @return bool
     */
    private function sendQuestion(ShiftHandover $handover, $gap, $total)
    {
        if (blank($gap)) {
            Log::warning('交接漏項沒有題目可以問', ['handover_id' => $handover->id]);

            return false;
        }

        $result = $this->supportGroup->send(
            $this->buildQuestionText($handover, $gap, $total),
            $this->questionKeyboard($handover, $gap),
            (int) $handover->handed_message_id,
            self::NOTICE_TYPE
        );

        $messageId = (int) Arr::get((array) $result, 'result.message_id');

        if ($messageId > 0) {
            // 引用這一題來補內容時要反查得到（理由同 `sendOpening()`）
            $this->gapRepository->markAskMessageId((int) $gap->id, $messageId);

            return true;
        }

        Log::warning('交接漏項的題目未送達', [
            'handover_id' => $handover->id,
            'seq'         => $gap->seq,
            'response'    => $result,
        ]);

        return false;
    }

    /**
     * 一題的文字
     *
     * ⚠⚠ **這一支必須是「同一列資料 → 同一段文字」。** 答完之後要把答案
     * 接在那一則後面，而那是**重新組一次題目文字**再加答案去編輯的
     * （`editMessageText` 要整則重送，Telegram 沒有「接一段上去」的 API）。
     * 這裡摻進任何「只有發問那一刻才知道」的東西，一答完就會被重組掉。
     *
     * @param ShiftHandover $handover
     * @param ShiftHandoverGap $gap
     * @param int $total
     * @return string
     */
    private function buildQuestionText(ShiftHandover $handover, ShiftHandoverGap $gap, $total)
    {
        $replace = [
            '{seq}'     => (int) $gap->seq,
            '{total}'   => (int) $total,
            '{title}'   => (string) $gap->title,
            '{detail}'  => (string) $gap->detail,
            '{mention}' => $this->mentionOf($handover),
        ];

        if ($gap->isManagerAsk()) {
            return strtr($this->text('QUESTION_MANAGER'), $replace);
        }

        // 來源只給得出標題時，說明那行省掉（不要印一行空白）
        $template = blank($gap->detail) ? 'QUESTION_NO_DETAIL' : 'QUESTION';

        return strtr($this->text($template), $replace);
    }

    /**
     * 一題的兩顆按鈕
     *
     * ⚠ `callback_data` 只有 64 bytes，所以只帶
     * `hr:<action>:<handover_id>:<seq>` —— 內容按下去再去表裡撈。
     *
     * @param ShiftHandover $handover
     * @param ShiftHandoverGap $gap
     * @return array
     */
    private function questionKeyboard(ShiftHandover $handover, ShiftHandoverGap $gap)
    {
        $prefix = (string) config('constants.HANDOVER_REVIEW.CALLBACK');
        $actions = (array) config('constants.HANDOVER_REVIEW.ACTION');
        $id = (int) $handover->id;
        $seq = (int) $gap->seq;

        $fill = (string) Arr::get($actions, 'FILL');
        $skip = (string) Arr::get($actions, 'SKIP');

        // 主管交代那一題問的是「有沒有」，按鈕要跟著換字
        $manager = $gap->isManagerAsk();

        return [[
            [
                'text'          => $this->text($manager ? 'BUTTON_MANAGER_FILL' : 'BUTTON_FILL'),
                'callback_data' => "{$prefix}:{$fill}:{$id}:{$seq}",
            ],
            [
                'text'          => $this->text($manager ? 'BUTTON_MANAGER_SKIP' : 'BUTTON_SKIP'),
                'callback_data' => "{$prefix}:{$skip}:{$id}:{$seq}",
            ],
        ]];
    }

    // ---------------------------------------------------------------
    //  按鈕的後續動作
    // ---------------------------------------------------------------

    /**
     * 記下這一題的答案 → 問下一題（沒有下一題就發總結）→ 把答案接在這一則後面
     *
     * ⚠⚠ **這三步的順序是刻意的。**
     *
     * 下一題（或總結）送不出去時要**把這一題的狀態退回「還沒答」** ——
     * 不退就卡死：這一則的按鈕再按會回「已經答過了」，而下一題永遠不會
     * 出現，整輪盤點停在這裡而且沒有人救得了。退回去之後他再按一次
     * 就是重試。
     *
     * ⚠ 編輯這一則放最後：那一步只是把答案「寫成紀錄」，失敗的代價最小
     * （按鈕還掛著，但再按會得到「已經答過了」）。
     *
     * @param ShiftHandover $handover
     * @param ShiftHandoverGap $gap
     * @param int $messageId 這一題所在的訊息
     * @param int $status    見 constants.HANDOVER_REVIEW.STATUS
     * @return void
     */
    private function answer(ShiftHandover $handover, ShiftHandoverGap $gap, $messageId, $status)
    {
        $handoverId = (int) $handover->id;

        /*
         * ⚠⚠ **一定要看 `markStatus()` 的回傳筆數。**
         *
         * 那一支帶了 `where('status', PENDING)` 的樂觀鎖，就是為了擋
         * 「撈出來到更新之間被另一顆按鈕處理掉」—— 但不看回傳值的話
         * 那道鎖等於沒寫：
         *
         * 兩個 callback 同時進來（他連按兩下、或 Telegram 重送）時，
         * 兩邊都會通過前面的 `isPending()`，接著 A 更新到 1 筆、
         * B 更新到 **0 筆** —— 而 B 若照常往下跑，就會**把同一題再問一次**，
         * 話題裡出現兩則一樣的題目、兩顆都按得下去。
         */
        if ($this->gapRepository->markStatus([(int) $gap->id], $status) < 1) {
            Log::info('交接漏項這一題剛好被另一次按鈕處理掉了，這一次不往下走', [
                'handover_id' => $handoverId,
                'seq'         => $gap->seq,
                'status'      => $status,
            ]);

            /*
             * ⚠ 搶輸的這一邊**也不能把那一則蓋掉**：它要還原成贏的那一邊
             * 寫進去的紀錄，不是一句「已經答過了」。理由同 `restoreAnswered()`。
             */
            $this->restoreAnswered($handover, $handoverId, (int) $gap->seq, $messageId);

            return;
        }

        /*
         * 標記完才撈，而且**只撈一次**：總結要印的是答完之後的狀態，
         * 題數與下一題也都從同一份結果算，不要為了各自再跑一趟查詢。
         */
        $all = $this->gapRepository->getByHandover($handoverId);
        $next = $all->first(function ($row) {
            return $row->isPending();
        });

        $sent = filled($next)
            ? $this->sendQuestion($handover, $next, $all->count())
            : $this->sendSummary($handover, $all);

        if (!$sent) {
            /*
             * ⚠ 一定要用 `revertToPending()`，**不能用
             * `markStatus($ids, PENDING)`** —— 那一支的樂觀鎖條件
             * （`where status = PENDING`）剛好把這個方向的轉移過濾掉，
             * 更新 0 筆而且不報錯。第一版這樣寫，實測整輪就卡在這一題。
             */
            $this->gapRepository->revertToPending([(int) $gap->id]);
            Log::warning('交接漏項的下一題沒送出去，這一題的答案已退回，讓他可以再按一次', [
                'handover_id' => $handoverId,
                'seq'         => $gap->seq,
                'status'      => $status,
            ]);

            return;
        }

        $this->supportGroup->editMessage(
            $messageId,
            $this->buildAnsweredText($handover, $gap, $all->count(), $status)
        );
    }

    /**
     * 答完之後那一則的全文：題目 ＋ 答案
     *
     * ⚠⚠ **題目要留著。** 只印答案的話，下一班捲回話題看到的是一串
     * 「✅ 要補上」「⏭ 不用補」—— 完全看不出那是在答什麼。
     * 需求方 2026-11-23 要的「回過頭來看才知道斷在哪裡」就是指這個。
     *
     * @param ShiftHandover $handover
     * @param ShiftHandoverGap $gap
     * @param int $total
     * @param int $status
     * @return string
     */
    private function buildAnsweredText(ShiftHandover $handover, ShiftHandoverGap $gap, $total, $status)
    {
        $filled = (int) $status === (int) config('constants.HANDOVER_REVIEW.STATUS.FILLED');

        return $this->buildQuestionText($handover, $gap, $total)
            . $this->text('ANSWERED_SEP')
            . strtr($this->text($this->answerKey($gap, $filled)), [
                '{mention}' => $this->mentionOf($handover),
            ]);
    }

    /**
     * 答案那一句用哪個 key
     *
     * @param ShiftHandoverGap $gap
     * @param bool $filled 答的是「要補上」
     * @return string
     */
    private function answerKey(ShiftHandoverGap $gap, $filled)
    {
        if ($gap->isManagerAsk()) {
            return $filled ? 'ANSWER_MANAGER_FILL' : 'ANSWER_MANAGER_SKIP';
        }

        return $filled ? 'ANSWER_FILL' : 'ANSWER_SKIP';
    }

    /**
     * 全部答完 → 發一則總結
     *
     * ⚠ 這一則是給**下一班跟主管**看的：逐題的訊息散在話題裡，
     * 要一眼看出「哪幾件要補、哪幾件不補」還是得有這一則。
     *
     * ⚠ **不再追沒補的那幾件。** 漏項是判斷題，他可能真的覺得那幾件
     * 不必交接 —— 交接本身會催到完成是因為「沒有也會打沒有」，
     * 這裡沒有那個前提。
     *
     * @param ShiftHandover $handover
     * @param \Illuminate\Support\Collection $all
     * @return bool
     */
    private function sendSummary(ShiftHandover $handover, $all)
    {
        $result = $this->supportGroup->send(
            $this->buildSummaryText($handover, $all),
            null,
            (int) $handover->handed_message_id,
            self::NOTICE_TYPE
        );

        $messageId = (int) Arr::get((array) $result, 'result.message_id');

        if ($messageId > 0) {
            // 引用總結來補內容是最常見的動作（理由同 `sendOpening()`）
            $this->handoverRepository->update($handover, [
                'review_summary_message_id' => $messageId,
            ]);

            return true;
        }

        Log::warning('交接漏項的總結未送達', ['handover_id' => $handover->id, 'response' => $result]);

        return false;
    }

    /**
     * 總結的文字
     *
     * @param ShiftHandover $handover
     * @param \Illuminate\Support\Collection $all
     * @return string
     */
    private function buildSummaryText(ShiftHandover $handover, $all)
    {
        $status = (array) config('constants.HANDOVER_REVIEW.STATUS');
        $fills = $this->ofStatus($all, Arr::get($status, 'FILLED'));

        $text = strtr($this->text('SUMMARY_HEADER'), [
            '{from}'  => $this->shiftName($handover),
            '{total}' => $all->count(),
        ])
            . $this->summarySection($fills, 'SUMMARY_FILL')
            . $this->summarySection($this->ofStatus($all, Arr::get($status, 'SKIPPED')), 'SUMMARY_SKIP');

        /*
         * ⚠ 一件都不用補的時候**不要印最後那句** ——
         * 那會變成叫他去補一份空清單。
         */
        if (blank($fills)) {
            return $text;
        }

        return $text . strtr($this->text('SUMMARY_ASK'), [
            '{mention}' => $this->mentionOf($handover),
        ]);
    }

    /**
     * 總結裡的一段（「要補上 N 件」底下那幾行）
     *
     * @param \Illuminate\Support\Collection $gaps
     * @param string $headerKey
     * @return string
     */
    private function summarySection($gaps, $headerKey)
    {
        if (blank($gaps)) {
            return '';
        }

        $text = strtr($this->text($headerKey), ['{count}' => $gaps->count()]);

        foreach ($gaps as $gap) {
            /*
             * ⚠ 主管交代那一題印固定的一句：它的 `title` 是空的
             * （那一題不是從任何一張表撈出來的），直接印會變成一行空白的「•」。
             */
            $text .= $gap->isManagerAsk()
                ? $this->text('SUMMARY_MANAGER_LINE')
                : strtr($this->text('SUMMARY_LINE'), ['{title}' => $gap->title]);
        }

        return $text;
    }

    /**
     * 挑出某一種處置的項目
     *
     * @param \Illuminate\Support\Collection $all
     * @param int $status
     * @return \Illuminate\Support\Collection
     */
    private function ofStatus($all, $status)
    {
        return $all->filter(function ($gap) use ($status) {
            return (int) $gap->status === (int) $status;
        });
    }

    // ---------------------------------------------------------------
    //  小工具
    // ---------------------------------------------------------------

    /**
     * 打這次交接的那個帳號（同一次請求只查一次）
     *
     * ⚠ 用 `array_key_exists` 判斷有沒有查過，不是 `filled()` ——
     * 查不到的結果（null）也要記住，否則每次都會再打一次 DB 去確認
     * 「還是查不到」。
     *
     * @param ShiftHandover $handover
     * @return \App\Models\User|null
     */
    private function ownerOf(ShiftHandover $handover)
    {
        $id = (int) $handover->handed_by_user_id;

        if ($id < 1) {
            return null;
        }

        if (!array_key_exists($id, $this->owners)) {
            $this->owners[$id] = $this->userRepository->find($id);
        }

        return $this->owners[$id];
    }

    /**
     * 按按鈕的是不是打交接的那個人
     *
     * ⚠ 比 `user.telegram_user_id` 而不是顯示名稱 —— 名稱隨時可以改。
     * 查不到交接人時一律放行：那是舊資料或帳號被刪，擋下來只會讓
     * 這組選單永遠按不動。
     *
     * @param ShiftHandover $handover
     * @param array         $from
     * @return bool
     */
    private function isOwner(ShiftHandover $handover, array $from)
    {
        $user = $this->ownerOf($handover);

        if (blank($user) || blank($user->telegram_user_id)) {
            return true;
        }

        return (int) $user->telegram_user_id === (int) Arr::get($from, 'id', 0);
    }

    /**
     * 交接那個人的 `@mention`
     *
     * 退路有兩層：沒填 Telegram 帳號 → 用暱稱（tag 不到人，但看得出在問誰）；
     * 連暱稱都沒有 → 用一個泛稱。
     *
     * ⚠ **不能回空字串**：那句話會變成「　麻煩補一句現在到哪了」，
     * 開頭空一格、而且沒有人知道是在對誰說。
     *
     * @param ShiftHandover $handover
     * @return string
     */
    private function mentionOf(ShiftHandover $handover)
    {
        $user = $this->ownerOf($handover);

        if (filled($user)) {
            $mention = TelegramUsernamePresenter::mentionLine([$user->telegram_username]);

            if (filled($mention)) {
                return $mention;
            }

            if (filled($user->nickname)) {
                return (string) $user->nickname;
            }
        }

        return filled($handover->handed_by_name)
            ? (string) $handover->handed_by_name
            : $this->text('FALLBACK_OWNER');
    }

    /**
     * 班別顯示名稱
     *
     * @param ShiftHandover $handover
     * @return string
     */
    private function shiftName(ShiftHandover $handover)
    {
        $shift = $handover->fromShift;

        if (blank($shift)) {
            return '上一班';
        }

        return filled($shift->display_name) ? (string) $shift->display_name : (string) $shift->name;
    }

    /**
     * 功能開關
     *
     * @return bool
     */
    private function isEnabled()
    {
        return (bool) config('constants.HANDOVER_REVIEW.ENABLED');
    }

    /**
     * 取一句文案
     *
     * @param string $key
     * @return string
     */
    private function text($key)
    {
        return (string) config("constants.HANDOVER_REVIEW.TEXT.{$key}");
    }
}
