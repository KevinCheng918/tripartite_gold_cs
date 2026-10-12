<?php

namespace App\Services;

use App\Criteria\Followup\FollowupDateRangeCriteria;
use App\Criteria\Followup\FollowupGroupCriteria;
use App\Criteria\Followup\FollowupSourceCriteria;
use App\Criteria\Followup\FollowupStatusCriteria;
use App\Models\ConversationFollowup;
use App\Repositories\ConversationFollowupRemindRepository;
use App\Repositories\ConversationFollowupRepository;
use App\Repositories\UserRepository;
use App\Services\AutoReply\FollowupAskWriter;
use App\Services\AutoReply\WaitDetector;
use App\Services\Notify\EscalatingReminder;
use App\Services\Notify\NoticeText;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 待追蹤事項：有人說「稍等」就記一筆，隔一段時間問當班人員要不要追
 *
 * 跟求助單反方向 —— 求助單是「我們欠客人答案」，這裡是「對方欠我們東西」。
 * 完整設計與需求方的七個決策見
 * `.claude/knowledge/features/customer-followup.md`。
 *
 * 四個入口：
 *
 * | 入口 | 誰呼叫 |
 * |---|---|
 * | `handleMessage()` | `FollowupDetectJob`（每一則收到／送出的訊息） |
 * | `askOnDuty()`     | `followup:ask`（每分鐘） |
 * | `handleCallback()`| webhook 的按鈕事件 |
 * | `decide()`        | 後台列表頁的手動追蹤／結案 |
 *
 * ⚠ **提醒的升級規則不在這裡**，在 `EscalatingReminder` ——
 * 跟求助單共用同一份（第幾次叫主管、上限、收尾那則）。
 */
class CustomerFollowupService
{
    /*
     * 發到內部群組的訊息屬於哪一類通知（決定進哪個話題）。
     *
     * ⚠ 這一類**不是** `needs_reply`：決定靠 inline keyboard 的按鈕，
     * callback_data 自己帶 id，所以發到兩個話題也對得回來。
     */
    const NOTICE_TYPE = 'conversation_followup';

    /** @var string 列表的「全部狀態」。數字會跟真正的 status 混淆，所以用字串 */
    const STATUS_ALL = 'all';

    /**
     * @var string 列表的「只看未結案」（等待中＋已追問）。
     *
     * 2026-10-12 之前這是「留白」的意思，現在留白是看全部，
     * 要只看未結案得明講。
     */
    const STATUS_OPEN = 'open';

    private $repository;
    private $remindRepository;
    private $userRepository;
    private $detector;
    private $askWriter;
    private $supportGroup;
    private $chatService;
    private $appSettingService;
    private $escalating;
    private $noticeText;

    public function __construct(
        ConversationFollowupRepository $repository,
        ConversationFollowupRemindRepository $remindRepository,
        UserRepository $userRepository,
        WaitDetector $detector,
        FollowupAskWriter $askWriter,
        SupportGroupService $supportGroup,
        TelegramChatService $chatService,
        AppSettingService $appSettingService,
        EscalatingReminder $escalating,
        NoticeText $noticeText
    ) {
        $this->repository = $repository;
        // 每次問當班人員 tag 到誰要逐筆落地，才答得出「某個人被問了幾次」
        $this->remindRepository = $remindRepository;
        $this->userRepository = $userRepository;
        $this->detector = $detector;
        $this->askWriter = $askWriter;
        $this->supportGroup = $supportGroup;
        // 發訊息問客人、取當班人員都走它
        $this->chatService = $chatService;
        $this->appSettingService = $appSettingService;
        $this->escalating = $escalating;
        $this->noticeText = $noticeText;
    }

    // ---------------------------------------------------------------
    //  一、訊息進來
    // ---------------------------------------------------------------

    /**
     * 一則訊息進來（或送出）時要做的事
     *
     * 三件事依序判斷：這句算誰承諾的 → 是不是等待詞 → 既有那筆怎麼辦。
     *
     * @param int      $groupId
     * @param int|null $messageId 後台的 telegram_message.id
     * @param string   $text
     * @param array    $context   direction / is_auto / from / group_id
     * @return void
     */
    public function handleMessage($groupId, $messageId, $text, array $context)
    {
        // 短路：開關關掉就什麼都不做，連名單與詞表都不讀
        if (!$this->isEnabled()) {
            return;
        }

        $source = $this->detector->source($context);

        // AI 自動回覆那句（必然含「稍等」，而求助單已經在催了）
        if (blank($source)) {
            return;
        }

        $open = $this->repository->findOpenByGroupAndSource($groupId, $source);
        $keyword = $this->detector->match($text);

        if (filled($open)) {
            $this->advance($open, $keyword, $messageId, $text);

            return;
        }

        if (blank($keyword)) {
            return;
        }

        $this->openFollowup($groupId, $messageId, $text, $keyword, $source, $context);
    }

    /**
     * 既有那筆怎麼辦
     *
     * ⚠ **這裡是整個功能最容易寫錯的地方**（需求方 2026-10-11 問出來的）：
     * 已經追問過、客人回一句「稍等」時**絕對不能結案**。那正是最該追的情況
     * —— 我們問了、客人又拖一次，結果系統判定他回來了把單收掉，
     * 這件事從此沒有人再看。
     *
     * 所以順序是「先看是不是等待詞，再決定要不要結案」。
     *
     * @param ConversationFollowup $followup
     * @param string|null          $keyword   命中的等待詞，不是等待詞時為 null
     * @param int|null             $messageId
     * @param string               $text
     * @return void
     */
    private function advance(ConversationFollowup $followup, $keyword, $messageId, $text)
    {
        // 又拖一次：退回等待中、重新計時、拖延次數 +1
        if (filled($keyword)) {
            $this->repository->update($followup, array_merge(
                $this->resetWait($messageId, $text, $keyword),
                ['wait_count' => (int) $followup->wait_count + 1]
            ));

            Log::info('待追蹤事項又被拖一次', [
                'followup_id' => $followup->id,
                'waits'       => (int) $followup->wait_count + 1,
                'was_asked'   => $followup->isAsked(),
            ]);

            return;
        }

        /*
         * 不是等待詞，而且我們追問過了 → 對方真的回應了，結案。
         *
         * ⚠ 只有「客人承諾」才有這個出口。我方承諾是我們自己欠的事，
         * 客人回什麼都不代表做完了 —— 那種只能靠人按「不追了」。
         */
        if ($followup->isAsked() && $followup->isFromCustomer()) {
            $this->repository->update($followup, [
                'status' => (int) config('constants.FOLLOWUP.STATUS.ANSWERED'),
            ]);

            Log::info('待追蹤事項：對方回應了，結案', ['followup_id' => $followup->id]);

            return;
        }

        /*
         * 還在等待中、對方說的是別的話 → 只把計時往後推（需求方決策 3）。
         *
         * ⚠ 固定從開單起算的話，對話正熱的時候系統會插進來問當班人員
         * 「要不要追蹤」，而客人其實就在線上。
         *
         * ⚠ **我方承諾不推後**：我們自己在對話裡講別的話不代表事情有進展，
         * 跟著推後會讓那筆永遠等不到被問的那一刻。
         */
        if ($followup->isWaiting() && $followup->isFromCustomer()) {
            $this->repository->update($followup, $this->pushBackWait());
        }
    }

    /**
     * 只把計時往後推，**不動原句與命中的詞**
     *
     * ⚠ 這一支的存在就是為了修一個顯示上的 bug（2026-10-12）：原本這條路也走
     * `resetWait()`，它會把 `wait_text` 換成客人最新說的那一句、卻刻意保留舊的
     * `matched_keyword` —— 於是列表上出現
     *
     * ```
     * 原句：能回調了嗎
     * 命中：稍等
     * ```
     *
     * 看起來像系統把「能回調了嗎」判成了等待詞。其實沒有：「稍等」是更早那句
     * 真正命中的詞，「能回調了嗎」只是客人後來說的別的話。
     *
     * 「原句」要回答的是**哪一句話讓我們開始等**，推後計時並沒有改變那個答案 ——
     * 改變的只是「從什麼時候開始算安靜」。
     *
     * ⚠ `telegram_message_id` 也不動：它是用來引用那則訊息的，
     * 要跟 `wait_text` 指同一句，不然群組訊息會引用到不相干的一則。
     *
     * @return array
     */
    private function pushBackWait()
    {
        return [
            'wait_at'          => now(),
            // 對方剛說話，這一輪重新算（理由同 resetWait）
            'remind_count'     => 0,
            'last_reminded_at' => null,
        ];
    }

    /**
     * 「重新開始等」要寫的那幾個欄位
     *
     * ⚠ **只給「這一句本身就是等待詞」用**（目前唯一的呼叫點是「又拖一次」）：
     * 它會把原句與命中的詞一起換掉，所以兩者必須是同一句話算出來的。
     * 客人說了別的話而只是推後計時，請走 `pushBackWait()` ——
     * 混用會讓列表顯示「原句 A、命中 B」那種對不起來的組合。
     *
     * ⚠ **提醒次數要歸零**：對方剛說話，這一輪重新算 —— 不歸零的話
     * 一筆活著的事項會因為累積到上限而被系統放棄。
     *
     * ⚠ **`wait_count` 與 `ask_round` 不歸零**（它們在呼叫端維護）：
     * 那是「拖了幾次、我們追了幾次」，跟著歸零的話拖第五次的事項
     * 看起來會跟剛開的一樣新。
     *
     * @param int|null    $messageId
     * @param string      $text
     * @param string|null $keyword
     * @return array
     */
    private function resetWait($messageId, $text, $keyword)
    {
        return [
            'status'              => (int) config('constants.FOLLOWUP.STATUS.WAITING'),
            'telegram_message_id' => $messageId,
            'wait_text'           => $this->shortText($text),
            'matched_keyword'     => $keyword,
            'wait_at'             => now(),
            'remind_count'        => 0,
            'last_reminded_at'    => null,
        ];
    }

    /**
     * 開一筆新的
     *
     * ⚠ **開單時不立刻問當班人員**：要等對話安靜滿設定的分鐘數
     * （`askOnDuty()` 負責）。對方話才剛說完就去問「要不要追蹤」，
     * 答案永遠是「再等一下」。
     *
     * @param int      $groupId
     * @param int|null $messageId
     * @param string   $text
     * @param string   $keyword
     * @param int      $source
     * @param array    $context
     * @return void
     */
    private function openFollowup($groupId, $messageId, $text, $keyword, $source, array $context)
    {
        $followup = $this->repository->create([
            'telegram_group_id'   => $groupId,
            'telegram_message_id' => $messageId,
            'source'              => $source,
            'sender_name'         => $this->senderName($context),
            'sender_user_id'      => $this->senderUserId($context, $source),
            'wait_text'           => $this->shortText($text),
            'matched_keyword'     => $keyword,
            'wait_at'             => now(),
            'status'              => (int) config('constants.FOLLOWUP.STATUS.WAITING'),
            'wait_count'          => 1,
        ]);

        Log::info('開了一筆待追蹤事項', [
            'followup_id' => $followup->id,
            'group_id'    => $groupId,
            'source'      => $source,
            'keyword'     => $keyword,
        ]);
    }

    // ---------------------------------------------------------------
    //  二、問當班人員（排程）
    // ---------------------------------------------------------------

    /**
     * 跑一輪：對話安靜夠久的事項，到內部群組問當班人員要不要追
     *
     * @return int 送出幾則
     */
    public function askOnDuty()
    {
        // 短路：開關與群組設定都是 config/設定層的判斷，先於任何查詢
        if (!$this->isEnabled() || !$this->supportGroup->isConfigured()) {
            return 0;
        }

        $max = $this->maxCount();
        $items = $this->repository->getDueForAsk(
            $this->appSettingService->getInt(
                AppSettingService::KEY_FOLLOWUP_QUIET_MINUTES,
                (int) config('constants.FOLLOWUP.DEFAULT.QUIET_MINUTES')
            ),
            $this->appSettingService->getInt(
                AppSettingService::KEY_FOLLOWUP_INTERVAL_MINUTES,
                (int) config('constants.FOLLOWUP.DEFAULT.INTERVAL_MINUTES')
            ),
            $max
        );

        if ($items->isEmpty()) {
            return 0;
        }

        /*
         * ⚠ **當班人員整輪只查一次**。這支每分鐘跑，同時卡住十筆是常態 ——
         * 逐筆去查排班與主管名單就是十倍查詢，而一分鐘內名單都不會變。
         */
        $targets = $this->escalating->targets($this->chatService->getOnDutyUserIds());
        $sent = 0;

        foreach ($items as $item) {
            if ($this->askOne($item, $targets, $max)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * 問一筆
     *
     * ⚠ **不論送不送得出去，`remind_count` 都要往前推進**：tag 不到人就不更新
     * 的話，這筆每分鐘都會被重試，整晚下來是幾百次查詢與送信（求助單踩過）。
     *
     * @param ConversationFollowup $followup
     * @param array                $targets 見 `EscalatingReminder::targets()`
     * @param int                  $max
     * @return bool 有送出才算 true
     */
    private function askOne(ConversationFollowup $followup, array $targets, $max)
    {
        $plan = $this->escalating->plan($followup->remind_count, $targets, [
            'max'         => $max,
            'escalate_at' => (int) config('constants.FOLLOWUP.ESCALATE_AT'),
        ]);

        $users = Arr::get($plan, 'users');
        $seq = (int) Arr::get($plan, 'seq');
        $isFinal = (bool) Arr::get($plan, 'is_final');

        $result = $this->sendAsk(
            $followup,
            $this->buildAskText($followup, $users, $seq, $isFinal)
        );
        $askMessageId = Arr::get((array) $result, 'result.message_id');

        DB::transaction(function () use ($followup, $plan, $seq, $users, $askMessageId) {
            $this->remindRepository->record(
                $followup->id,
                $seq,
                $users->pluck('id')->all(),
                (int) Arr::get($plan, 'stage')
            );

            $this->repository->update($followup, [
                'remind_count'     => (int) Arr::get($plan, 'next_count'),
                'last_reminded_at' => now(),
                // 按鈕要改寫的是「最新那則」詢問訊息，送失敗時保留舊的
                'ask_message_id'   => filled($askMessageId) ? $askMessageId : $followup->ask_message_id,
            ]);
        });

        Log::info('問當班人員要不要追蹤', [
            'followup_id' => $followup->id,
            'seq'         => $seq,
            'final'       => $isFinal,
            'mentioned'   => $users->pluck('nickname')->all(),
            'sent'        => filled($askMessageId),
        ]);

        return filled($askMessageId);
    }

    /**
     * 詢問訊息的文案
     *
     * ⚠ **一定要自己站得住**：帶對話名稱、誰說的、原句、拖幾次、追問幾次。
     * 求助單踩過一次 —— 舊文案只寫「上面這題」，群組升級成 supergroup 之後
     * 引用掛不上，群組裡就只剩一排看不懂的孤兒訊息。
     *
     * @param ConversationFollowup           $followup
     * @param \Illuminate\Support\Collection $users
     * @param int                            $seq
     * @param bool                           $isFinal
     * @return string
     */
    private function buildAskText(ConversationFollowup $followup, $users, $seq, $isFinal)
    {
        $ask = (array) config('constants.FOLLOWUP.ASK');
        $template = $isFinal
            ? Arr::get($ask, 'FINAL_TEXT')
            : ($seq > 1 ? Arr::get($ask, 'REMIND_TEXT') : Arr::get($ask, 'TEXT'));

        return strtr((string) $template, [
            '{mentions}' => $this->escalating->mentions($users, (string) Arr::get($ask, 'NO_MENTION')),
            '{source}'   => (string) config("constants.FOLLOWUP.SOURCE_LABEL.{$followup->source}"),
            '{group}'    => $this->groupTitle($followup),
            '{sender}'   => (string) $followup->sender_name,
            '{text}'     => (string) $followup->wait_text,
            '{waits}'    => (int) $followup->wait_count,
            '{rounds}'   => (int) $followup->ask_round,
            '{count}'    => $isFinal ? (int) $followup->remind_count : $seq,
            '{waited}'   => $this->noticeText->waited($followup->wait_at, (array) config('constants.FOLLOWUP')),
        ]);
    }

    /**
     * 送出詢問訊息（附兩顆按鈕）
     *
     * @param ConversationFollowup $followup
     * @param string               $text
     * @return array|null
     */
    private function sendAsk(ConversationFollowup $followup, $text)
    {
        try {
            return $this->supportGroup->send(
                $text,
                $this->buildKeyboard($followup),
                null,
                self::NOTICE_TYPE
            );
        } catch (\Exception $e) {
            // 一筆送失敗不能中斷整輪
            Log::error('待追蹤事項的詢問訊息送出失敗', [
                'followup_id' => $followup->id,
                'error'       => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 兩顆按鈕
     *
     * 我方承諾沒有「問客人」這個動作，所以第一顆的字不一樣 ——
     * 按下去會發生什麼事，按之前就要看得出來。
     *
     * @param ConversationFollowup $followup
     * @return array
     */
    private function buildKeyboard(ConversationFollowup $followup)
    {
        $ask = (array) config('constants.FOLLOWUP.ASK');
        $prefix = (string) config('constants.FOLLOWUP.CALLBACK.DECIDE');
        $decisions = (array) config('constants.FOLLOWUP.DECISION');

        $follow = $followup->isFromCustomer()
            ? Arr::get($ask, 'BUTTON_FOLLOW')
            : Arr::get($ask, 'BUTTON_FOLLOW_STAFF');

        return [
            [
                ['text' => (string) $follow, 'callback_data' => "{$prefix}:{$decisions['FOLLOW']}:{$followup->id}"],
            ],
            [
                ['text' => (string) Arr::get($ask, 'BUTTON_CLOSE'), 'callback_data' => "{$prefix}:{$decisions['CLOSE']}:{$followup->id}"],
            ],
        ];
    }

    // ---------------------------------------------------------------
    //  三、按鈕
    // ---------------------------------------------------------------

    /**
     * 當班人員按了按鈕
     *
     * @param array    $parts     [decision, followup_id]
     * @param int|null $messageId 按鈕所在的訊息
     * @param array    $from      Telegram 的 from（換成後台帳號記 decided_by）
     * @return void
     */
    public function handleCallback(array $parts, $messageId, array $from)
    {
        $followup = $this->repository->find((int) Arr::get($parts, 1, 0));
        $decided = (array) config('constants.FOLLOWUP.DECIDED');

        if (blank($followup) || $followup->isClosed()) {
            $this->editAsk($messageId, (string) Arr::get($decided, 'GONE'));

            return;
        }

        $user = $this->userRepository->findByTelegramUserId((int) Arr::get($from, 'id'));
        $who = filled($user) ? (string) $user->nickname : (string) Arr::get($decided, 'UNKNOWN_USER');

        if ((string) Arr::get($parts, 0) === (string) config('constants.FOLLOWUP.DECISION.CLOSE')) {
            $this->closeByDecision($followup, $user, $messageId, $who);

            return;
        }

        $this->followByDecision($followup, $user, $messageId, $who);
    }

    /**
     * 後台列表
     *
     * ⚠ **預設只看還活著的**（等待中＋已追問）：這一頁是待辦清單，
     * 混進已結案的就看不出「現在要處理幾件」。要看歷史時前端送
     * `status=all`。
     *
     * @param array $params source / status / group_id / per_page
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function list(array $params)
    {
        $status = (array) config('constants.FOLLOWUP.STATUS');
        $criteria = [];

        /*
         * ⚠ **留白＝看全部**（需求方 2026-10-12 改的，原本留白是「只看未結案」）。
         *
         * 「只看未結案」改成明確的 `open`。這樣「不帶參數」與畫面上的預設
         * 是同一件事 —— 原本 API 直接打不帶 status 會得到跟畫面預設不同的結果，
         * 那種不一致最難查。
         */
        $wanted = Arr::get($params, 'status');

        if ($wanted === self::STATUS_OPEN) {
            $criteria[] = new FollowupStatusCriteria([
                Arr::get($status, 'WAITING'),
                Arr::get($status, 'ASKED'),
            ]);
        } elseif (filled($wanted) && $wanted !== self::STATUS_ALL) {
            $criteria[] = new FollowupStatusCriteria((int) $wanted);
        }

        // 開單日期區間。兩個都有才篩 —— 只填一邊是使用者還沒填完
        if (filled(Arr::get($params, 'date_from')) && filled(Arr::get($params, 'date_to'))) {
            $criteria[] = new FollowupDateRangeCriteria(
                Arr::get($params, 'date_from'),
                Arr::get($params, 'date_to')
            );
        }

        if (filled(Arr::get($params, 'source'))) {
            $criteria[] = new FollowupSourceCriteria(Arr::get($params, 'source'));
        }

        if (filled(Arr::get($params, 'group_id'))) {
            $criteria[] = new FollowupGroupCriteria(Arr::get($params, 'group_id'));
        }

        return $this->repository->paginate(
            $criteria,
            (int) Arr::get($params, 'per_page', config('constants.PAGINATION.DEFAULT'))
        );
    }

    /**
     * 後台列表頁的手動追蹤／結案
     *
     * 跟按鈕走同一條路（`decided_by` 一樣要記）—— 統計要分得出
     * 「有人決定」與「沒人理」。
     *
     * @param int  $id
     * @param int  $userId 操作者
     * @param bool $follow true=追蹤, false=結案
     * @return ConversationFollowup
     * @throws \RuntimeException
     */
    public function decide($id, $userId, $follow)
    {
        $followup = $this->repository->find($id);

        if (blank($followup) || $followup->isClosed()) {
            throw new \RuntimeException(trans('followup.msg.not_found'));
        }

        $user = $this->userRepository->find($userId);
        $who = filled($user) ? (string) $user->nickname : (string) config('constants.FOLLOWUP.DECIDED.UNKNOWN_USER');

        if ($follow) {
            $this->followByDecision($followup, $user, $followup->ask_message_id, $who);

            return $this->repository->find($id);
        }

        $this->closeByDecision($followup, $user, $followup->ask_message_id, $who);

        return $this->repository->find($id);
    }

    /**
     * 決定不追了
     *
     * @param ConversationFollowup  $followup
     * @param \App\Models\User|null $user
     * @param int|null              $messageId
     * @param string                $who
     * @return void
     */
    private function closeByDecision(ConversationFollowup $followup, $user, $messageId, $who)
    {
        $this->repository->update($followup, [
            'status'     => (int) config('constants.FOLLOWUP.STATUS.CLOSED'),
            'decided_by' => filled($user) ? $user->id : null,
            'decided_at' => now(),
        ]);

        $this->editAsk($messageId, $this->buildDecidedText('CLOSED', $followup, $who));

        Log::info('待追蹤事項結案', ['followup_id' => $followup->id, 'by' => $who]);
    }

    /**
     * 決定要追蹤
     *
     * 兩種來源做的事完全不同：
     *
     * - 客人承諾 → 發訊息問客人進度（AI 寫，引用他當初那則）
     * - 我方承諾 → **只** 在群組 tag 當班人員，絕不回頭問客人
     *
     * @param ConversationFollowup  $followup
     * @param \App\Models\User|null $user
     * @param int|null              $messageId
     * @param string                $who
     * @return void
     */
    private function followByDecision(ConversationFollowup $followup, $user, $messageId, $who)
    {
        $followup->isFromCustomer()
            ? $this->askCustomer($followup)
            : $this->handOverToOnDuty($followup);

        $this->repository->update($followup, [
            'status'     => (int) config('constants.FOLLOWUP.STATUS.ASKED'),
            'ask_round'  => (int) $followup->ask_round + 1,
            // 追問完重新開始等 —— 對方沒回的話，安靜期過後會再問一次當班人員
            'wait_at'          => now(),
            'remind_count'     => 0,
            'last_reminded_at' => null,
            'decided_by'       => filled($user) ? $user->id : null,
            'decided_at'       => now(),
        ]);

        $key = $followup->isFromCustomer() ? 'FOLLOW_CUSTOMER' : 'FOLLOW_STAFF';
        $this->editAsk($messageId, $this->buildDecidedText($key, $followup, $who));

        Log::info('待追蹤事項決定追蹤', [
            'followup_id' => $followup->id,
            'source'      => $followup->source,
            'round'       => (int) $followup->ask_round + 1,
            'by'          => $who,
        ]);
    }

    /**
     * 發訊息問客人進度
     *
     * ⚠ **引用客人當初那則**：隔了半天之後只發一句「想確認一下進度」，
     * 客人不知道在講哪件事。引用掛不上時（訊息被 purge）退成在文案裡
     * 帶上原句摘要。
     *
     * ⚠ `is_auto` 要給 true：一來那確實是系統發的，二來
     * `WaitDetector` 靠它排除自己發出去的訊息 ——
     * 不給的話這則「想跟您確認」會被當成我方承諾再開一筆。
     *
     * @param ConversationFollowup $followup
     * @return void
     */
    private function askCustomer(ConversationFollowup $followup)
    {
        $text = $this->askWriter->write($followup->wait_text, (int) $followup->ask_round + 1);
        $quoteId = $followup->telegram_message_id;

        if (blank($quoteId)) {
            $text = strtr((string) config('constants.FOLLOWUP.ASK_CUSTOMER.WITH_QUOTE'), [
                '{text}'  => $text,
                '{quote}' => (string) $followup->wait_text,
            ]);
        }

        try {
            $this->chatService->sendReply(
                $followup->telegram_group_id,
                $text,
                null,
                config('constants.AUTO_REPLY.SENDER_NAME'),
                [
                    'mark_replied' => true,
                    'signature'    => config('constants.AUTO_REPLY.SIGNATURE'),
                    'is_auto'      => true,
                    'reply_to_id'  => $quoteId,
                ]
            );
        } catch (\Exception $e) {
            /*
             * 問不出去也要讓狀態前進成「已追問」：卡在等待中的話，
             * 下一分鐘排程又會問當班人員一樣的問題，而他已經按過了。
             */
            Log::error('追問客人失敗', [
                'followup_id' => $followup->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * 我方承諾：在群組 tag 當班人員接手
     *
     * ⚠ **一定要另發新訊息**，不能只靠改寫原訊息 ——
     * `editMessageText` 不發通知，被 tag 的人不會知道這件事落到他身上了。
     *
     * ⚠ **不指名當初說那句話的同事**（需求方決策 5）：他今天不一定上班，
     * 交辦給他只會沒人做。`sender_name` 留在訊息裡讓當班的人知道是誰講的。
     *
     * @param ConversationFollowup $followup
     * @return void
     */
    private function handOverToOnDuty(ConversationFollowup $followup)
    {
        $ask = (array) config('constants.FOLLOWUP.ASK');
        $targets = $this->escalating->targets($this->chatService->getOnDutyUserIds());

        $text = strtr((string) Arr::get($ask, 'STAFF_FOLLOW_TEXT'), [
            '{mentions}' => $this->escalating->mentions(
                Arr::get($targets, 'on_duty'),
                (string) Arr::get($ask, 'NO_MENTION')
            ),
            '{group}'  => $this->groupTitle($followup),
            '{sender}' => (string) $followup->sender_name,
            '{text}'   => (string) $followup->wait_text,
        ]);

        try {
            $this->supportGroup->send($text, null, null, self::NOTICE_TYPE);
        } catch (\Exception $e) {
            Log::error('交接給當班人員的訊息送出失敗', [
                'followup_id' => $followup->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    // ---------------------------------------------------------------
    //  四、收舊單
    // ---------------------------------------------------------------

    /**
     * 把放太久還沒結果的事項收掉
     *
     * ⚠ **排程不掛這支**（`FOLLOWUP.STALE_DAYS` 預設 0 = 停用）：需求方要
     * 「不自動結案」，就不該有一條悄悄替他收單的路。要用時手動給天數。
     *
     * ⚠ **不通知客人**：那些客人早就不在線上等了（所以才會放到過期），
     * 現在補一句只會把已經冷掉的事情重新點燃。
     *
     * @param int  $days
     * @param bool $dryRun 只列出不實際關閉
     * @return array count / items
     */
    public function closeStale($days, $dryRun = false)
    {
        $days = (int) $days;

        // 沒給天數時什麼都不做，不自己挑一個預設值 —— 猜錯的代價是
        // 把還該追的事項一起收掉
        if ($days < 1) {
            return ['count' => 0, 'items' => []];
        }

        $items = $this->repository->getStale($days);

        if ($dryRun || $items->isEmpty()) {
            return ['count' => $items->count(), 'items' => $items];
        }

        $expired = (int) config('constants.FOLLOWUP.STATUS.EXPIRED');

        foreach ($items as $item) {
            $this->repository->update($item, ['status' => $expired]);
        }

        Log::info('待追蹤事項逾期收掉', ['count' => $items->count(), 'days' => $days]);

        return ['count' => $items->count(), 'items' => $items];
    }

    // ---------------------------------------------------------------
    //  共用
    // ---------------------------------------------------------------

    /**
     * 功能開著嗎
     *
     * @return bool
     */
    public function isEnabled()
    {
        return $this->appSettingService->getBool(AppSettingService::KEY_FOLLOWUP_ENABLED);
    }

    /**
     * 最多問幾次
     *
     * @return int
     */
    private function maxCount()
    {
        return $this->appSettingService->getInt(
            AppSettingService::KEY_FOLLOWUP_MAX_COUNT,
            (int) config('constants.FOLLOWUP.DEFAULT.MAX_COUNT')
        );
    }

    /**
     * 按鈕按下後改寫詢問訊息
     *
     * @param int|null $messageId
     * @param string   $text
     * @return void
     */
    private function editAsk($messageId, $text)
    {
        if (blank($messageId)) {
            return;
        }

        try {
            $this->supportGroup->editMessage($messageId, $text);
        } catch (\Exception $e) {
            // 改寫失敗不影響狀態已經更新的事實
            Log::warning('待追蹤事項的詢問訊息改寫失敗', [
                'message_id' => $messageId,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * 決定之後那則訊息的文案
     *
     * @param string               $key
     * @param ConversationFollowup $followup
     * @param string               $who
     * @return string
     */
    private function buildDecidedText($key, ConversationFollowup $followup, $who)
    {
        return strtr((string) config("constants.FOLLOWUP.DECIDED.{$key}"), [
            '{who}'   => $who,
            '{group}' => $this->groupTitle($followup),
            '{text}'  => (string) $followup->wait_text,
        ]);
    }

    /**
     * 對話名稱
     *
     * 關聯查不到（對話被刪）時給 id —— 空字串會讓訊息看起來少一行。
     *
     * @param ConversationFollowup $followup
     * @return string
     */
    private function groupTitle(ConversationFollowup $followup)
    {
        $group = $followup->group;

        return filled($group) ? (string) $group->title : "#{$followup->telegram_group_id}";
    }

    /**
     * 原句存成快照（截短）
     *
     * @param string $text
     * @return string
     */
    private function shortText($text)
    {
        return $this->noticeText->shorten($text, (int) config('constants.FOLLOWUP.WAIT_CHARS'));
    }

    /**
     * 誰說的
     *
     * @param array $context
     * @return string|null
     */
    private function senderName(array $context)
    {
        $name = Arr::get($context, 'sender_name');

        return filled($name) ? mb_substr((string) $name, 0, 100) : null;
    }

    /**
     * 我方承諾時對應的後台帳號
     *
     * ⚠ **客人承諾一律 null，而且不去查**：客人的 Telegram id 不可能在
     * `user` 表裡，每開一筆都查一次只是白費一趟 DB。
     *
     * 我方承諾也可能查不到（同事的個人帳號還沒綁定），那時只剩
     * `sender_name` 看得出是誰 —— 這個欄位只拿來顯示，查不到不影響功能。
     *
     * @param array $context
     * @param int   $source
     * @return int|null
     */
    private function senderUserId(array $context, $source)
    {
        if ((int) $source !== (int) config('constants.FOLLOWUP.SOURCE.STAFF')) {
            return null;
        }

        // 後台送出的訊息本來就帶著是誰送的，不必再查一次
        $userId = Arr::get($context, 'sender_user_id');

        if (filled($userId)) {
            return (int) $userId;
        }

        $telegramUserId = Arr::get($context, 'from.id');

        if (blank($telegramUserId)) {
            return null;
        }

        $user = $this->userRepository->findByTelegramUserId((int) $telegramUserId);

        return filled($user) ? (int) $user->id : null;
    }
}
