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
 * 交接漏項盤點：AI 比對交接內容，沒寫到的用選單問要不要補
 *
 * > 需求方 2026-11-19：「整理上一個班有的問題，然後 AI 比對有沒有交接到，
 * > 詢問沒有寫到的要不要補上，用選單方式。」
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
         * 那時話題會多出一組一模一樣的選單，而且編號會對到兩批資料。
         */
        if ($this->gapRepository->existsForHandover($handoverId)) {
            Log::info('這次交接已經盤過漏項了，略過', ['handover_id' => $handoverId]);

            return false;
        }

        $handover = $this->handoverRepository->find($handoverId);

        if (blank($handover)) {
            return false;
        }

        /*
         * 「另外還有 N 件」那種補充說明要先抽出來：它不是一件具體的事，
         * **不該佔選單編號**（佔了就會出現 1、2、3、5 這種跳號，看起來像壞掉），
         * 也不該被送去比對。
         */
        $split = $this->splitNotes($this->collect($handover));
        $candidates = (array) Arr::get($split, 'items', []);
        $covered = $this->askCoverage($handoverText, $candidates);
        $gaps = $this->persist($handover, $candidates, $covered);

        if (blank($gaps)) {
            return false;
        }

        return $this->sendMenu($handover, $gaps, blank($covered), (array) Arr::get($split, 'notes', []));
    }

    /**
     * 按鈕：補上 / 全部補上 / 都不用
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

        /*
         * ⚠ **只有打交接的本人按得動。** 下一班的人按「補上」等於他在
         * 替上一班承諾 —— 而補上去的那句現況只有上一班答得出來。
         */
        if (!$this->isOwner($handover, $from)) {
            $this->supportGroup->editMessage($messageId, $this->text('NOT_OWNER'));

            return;
        }

        if ($action === (string) config('constants.HANDOVER_REVIEW.ACTION.SKIP')) {
            $this->skipAll($handover, $messageId);

            return;
        }

        if ($action === (string) config('constants.HANDOVER_REVIEW.ACTION.FILL_ALL')) {
            $this->fill($handover, $this->gapRepository->getPendingByHandover($handoverId), $messageId);

            return;
        }

        $gap = $this->gapRepository->findBySeq($handoverId, (int) Arr::get($parts, 2, 0));

        if (blank($gap) || !$gap->isPending()) {
            $this->supportGroup->editMessage($messageId, $this->text('RESULT_GONE'));

            return;
        }

        $this->fill($handover, collect([$gap]), $messageId);
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
     * 而這種說明沒有東西可以補 —— 讓它佔一個編號只會讓選單跳號
     * （1、2、3、5），看起來像系統壞掉。
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

        return $this->withMore(
            $items,
            (int) $this->ticketRepository->countUnclosedBetween($start, $end),
            $limit,
            $sourceType
        );
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

        return $this->withMore(
            $items,
            (int) $this->followupRepository->countOpenBetween($start, $end),
            $limit,
            $sourceType
        );
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

        return $this->withMore(
            $items,
            (int) $this->reminderRepository->countPendingCreatedBetween($start, $end),
            $limit,
            $sourceType
        );
    }

    /**
     * 超過上限的那些 —— 補一句「另外還有 N 件」
     *
     * ⚠ **不能默默砍掉。** 清單看起來完整但其實被截斷的話，
     * 下一班會以為那就是全部（同其他通知「不要靜默截斷」的原則）。
     *
     * @param array $items
     * @param int   $total 同條件下的總數
     * @param int   $limit
     * @param int   $sourceType
     * @return array
     */
    private function withMore(array $items, $total, $limit, $sourceType)
    {
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
     * @param array  $candidates
     * @return array<int, bool> 以候選的索引為 key
     */
    private function askCoverage($handoverText, array $candidates)
    {
        $askable = $this->askableIndexes($candidates);

        // 短路：沒有可比對的項目（只剩固定問句）就不必叫模型
        if (blank($askable) || blank(trim((string) $handoverText))) {
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

        foreach ((array) Arr::get($result, 'items', []) as $row) {
            $covered[(int) Arr::get($row, 'index')] = (bool) Arr::get($row, 'covered');
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
     * 發選單到交接話題
     *
     * ⚠ **引用他那則交接**（`handed_message_id`）：話題裡同時有好幾班的
     * 交接時，不引用就看不出這組選單是在講哪一則。
     *
     * @param ShiftHandover $handover
     * @param \Illuminate\Support\Collection $gaps
     * @param bool  $noAi  這次沒有比對過（模型掛掉或沒憑證）
     * @param array $notes 「另外還有 N 件」那幾句，附在清單最後
     * @return bool
     */
    private function sendMenu(ShiftHandover $handover, $gaps, $noAi, array $notes)
    {
        $text = $this->buildMenuText($handover, $gaps, $noAi, $notes);
        $result = $this->supportGroup->send(
            $text,
            $this->buildKeyboard($handover, $gaps),
            (int) $handover->handed_message_id,
            self::NOTICE_TYPE
        );

        if (filled(Arr::get((array) $result, 'result'))) {
            return true;
        }

        Log::warning('交接漏項選單未送達', ['handover_id' => $handover->id, 'response' => $result]);

        return false;
    }

    /**
     * 選單的文字
     *
     * @param ShiftHandover $handover
     * @param \Illuminate\Support\Collection $gaps
     * @param bool  $noAi
     * @param array $notes
     * @return string
     */
    private function buildMenuText(ShiftHandover $handover, $gaps, $noAi, array $notes)
    {
        $from = $this->shiftName($handover);

        $text = $noAi
            ? strtr($this->text('NO_AI_HEADER'), ['{from}' => $from])
            : strtr($this->text('HEADER'), ['{count}' => $gaps->count(), '{from}' => $from]);

        foreach ($gaps as $gap) {
            $text .= $this->gapLine($gap);
        }

        // 「另外還有 N 件」擺在清單最後：它是整批的補充，不屬於任何一項
        return $text . implode('', $notes);
    }

    /**
     * 選單上的一行
     *
     * @param ShiftHandoverGap $gap
     * @return string
     */
    private function gapLine(ShiftHandoverGap $gap)
    {
        if ($gap->isManagerAsk()) {
            return strtr($this->text('MANAGER_ASK'), ['{seq}' => $gap->seq]);
        }

        // 來源只給得出標題時，編號那行照印、說明那行省掉（不要印一行空白）
        if (blank($gap->detail)) {
            return strtr($this->text('LINE_NO_DETAIL'), [
                '{seq}'   => $gap->seq,
                '{title}' => $gap->title,
            ]);
        }

        return strtr($this->text('LINE'), [
            '{seq}'    => $gap->seq,
            '{title}'  => $gap->title,
            '{detail}' => $gap->detail,
        ]);
    }

    /**
     * 按鈕
     *
     * ⚠ `callback_data` 只有 64 bytes，所以只帶
     * `hr:<action>:<handover_id>:<seq>` —— 內容按下去再去表裡撈。
     *
     * @param ShiftHandover $handover
     * @param \Illuminate\Support\Collection $gaps
     * @return array
     */
    private function buildKeyboard(ShiftHandover $handover, $gaps)
    {
        $prefix = (string) config('constants.HANDOVER_REVIEW.CALLBACK');
        $actions = (array) config('constants.HANDOVER_REVIEW.ACTION');
        $id = (int) $handover->id;

        $buttons = [];

        foreach ($gaps as $gap) {
            $buttons[] = [
                'text'          => strtr($this->text('BUTTON_FILL'), ['{seq}' => $gap->seq]),
                'callback_data' => "{$prefix}:" . Arr::get($actions, 'FILL') . ":{$id}:{$gap->seq}",
            ];
        }

        $rows = array_chunk($buttons, 3);

        $rows[] = [
            [
                'text'          => $this->text('BUTTON_FILL_ALL'),
                'callback_data' => "{$prefix}:" . Arr::get($actions, 'FILL_ALL') . ":{$id}:0",
            ],
            [
                'text'          => $this->text('BUTTON_SKIP'),
                'callback_data' => "{$prefix}:" . Arr::get($actions, 'SKIP') . ":{$id}:0",
            ],
        ];

        return $rows;
    }

    // ---------------------------------------------------------------
    //  按鈕的後續動作
    // ---------------------------------------------------------------

    /**
     * 補上這幾項：系統貼事實，再請他補一句現況
     *
     * ⚠ **系統只貼客觀事實**（需求方 2026-11-19 選的做法）：判斷留給人。
     * 所以每一則都要 tag 他補一句，不能貼完就當作交接完整了。
     *
     * @param ShiftHandover $handover
     * @param \Illuminate\Support\Collection $gaps
     * @param int $messageId
     * @return void
     */
    private function fill(ShiftHandover $handover, $gaps, $messageId)
    {
        // 已經按過的不要再貼一次（「全部補上」跟逐項按可能交錯）
        $gaps = $gaps->filter(function ($gap) {
            return $gap->isPending();
        });

        if (blank($gaps)) {
            $this->supportGroup->editMessage($messageId, $this->text('RESULT_GONE'));

            return;
        }

        $this->gapRepository->markStatus(
            $gaps->pluck('id')->all(),
            config('constants.HANDOVER_REVIEW.STATUS.FILLED')
        );

        $this->supportGroup->editMessage(
            $messageId,
            strtr($this->text('RESULT_FILLED'), ['{count}' => $gaps->count()])
        );

        $this->supportGroup->send(
            $this->buildFilledText($handover, $gaps),
            null,
            (int) $handover->handed_message_id,
            self::NOTICE_TYPE
        );
    }

    /**
     * 補充交接那一則的內容
     *
     * @param ShiftHandover $handover
     * @param \Illuminate\Support\Collection $gaps
     * @return string
     */
    private function buildFilledText(ShiftHandover $handover, $gaps)
    {
        $from = $this->shiftName($handover);
        $mention = $this->mentionOf($handover);

        /*
         * 只有「主管交代」那一項時，系統貼不出任何事實（口頭交代根本看不到），
         * 所以整則換成問他一句。
         */
        if ($gaps->count() === 1 && $gaps->first()->isManagerAsk()) {
            return strtr($this->text('FILLED_MANAGER'), [
                '{from}'    => $from,
                '{mention}' => $mention,
            ]);
        }

        $text = strtr($this->text('FILLED_HEADER'), ['{from}' => $from]);

        foreach ($gaps as $gap) {
            if ($gap->isManagerAsk()) {
                /*
                 * ⚠ 用不帶編號的那一句：選單上的「5.」貼進交接內容裡
                 * 會變成一個沒有上下文的編號，下一班看不懂那是什麼。
                 */
                $text .= $this->text('MANAGER_LINE');

                continue;
            }

            $text .= strtr($this->text('FILLED_LINE'), [
                '{title}'  => $gap->title,
                '{detail}' => (string) $gap->detail,
            ]);
        }

        return $text . strtr($this->text('FILLED_ASK'), ['{mention}' => $mention]);
    }

    /**
     * 都不用：整批記起來，選單換成結果
     *
     * ⚠ **不再追。** 漏項是判斷題，他可能真的覺得那幾件不必交接 ——
     * 交接本身催到完成是因為「沒有也會打沒有」，這裡沒有那個前提。
     *
     * @param ShiftHandover $handover
     * @param int $messageId
     * @return void
     */
    private function skipAll(ShiftHandover $handover, $messageId)
    {
        $pending = $this->gapRepository->getPendingByHandover((int) $handover->id);

        $this->gapRepository->markStatus(
            $pending->pluck('id')->all(),
            config('constants.HANDOVER_REVIEW.STATUS.SKIPPED')
        );

        $this->supportGroup->editMessage($messageId, $this->text('RESULT_SKIPPED'));
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
