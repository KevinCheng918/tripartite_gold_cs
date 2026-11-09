<?php

namespace App\Services;

use App\Models\ShiftHandover;
use App\Repositories\ShiftAssignmentRepository;
use App\Repositories\ShiftHandoverRepository;
use App\Repositories\ShiftRepository;
use App\Repositories\UserRepository;
use App\Services\Notify\EscalatingReminder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * 班別交接（交接話題）
 *
 * 每班都要在交接話題打交接事項。沒打就在話題 tag 兩邊 ——
 * 上一班「請打交接事項」、下一班「你要上線了」—— **催到完成為止**。
 *
 * ⚠ **交接窗口 ＝ 兩班回訊時間重疊的那一段**（需求方刻意設計的一小時），
 * 不是「上一班結束的那一刻」。上下班時間大量重疊（都 12 小時），
 * 所以一律看回訊時間。
 *
 * 詳見 .claude/knowledge/features/shift-handover-topic.md
 */
class ShiftHandoverService
{
    private $repository;
    private $shiftRepository;
    private $assignmentRepository;
    private $userRepository;
    private $appSettingService;
    private $supportGroup;
    private $staffIgnoreService;
    private $escalating;

    public function __construct(
        ShiftHandoverRepository $repository,
        ShiftRepository $shiftRepository,
        ShiftAssignmentRepository $assignmentRepository,
        UserRepository $userRepository,
        AppSettingService $appSettingService,
        SupportGroupService $supportGroup,
        StaffIgnoreService $staffIgnoreService,
        EscalatingReminder $escalating
    ) {
        $this->repository = $repository;
        $this->shiftRepository = $shiftRepository;
        $this->assignmentRepository = $assignmentRepository;
        $this->userRepository = $userRepository;
        $this->appSettingService = $appSettingService;
        $this->supportGroup = $supportGroup;
        // 認出「在交接話題發言的是哪一位同仁」，走它那套 ID 優先的對法
        $this->staffIgnoreService = $staffIgnoreService;
        // 主管名單沿用求助單那一份（需求方 2026-11-08 指定）
        $this->escalating = $escalating;
    }

    /**
     * 通知類型（`constants.SUPPORT_TOPIC.TYPES` 的 key）
     *
     * @var string
     */
    const NOTICE_TYPE = 'shift_handover';

    /**
     * 交接提醒是否啟用
     *
     * ⚠ **預設開**（需求方指定）——`getBool()` 帶 true 當預設。
     *
     * @return bool
     */
    public function isEnabled()
    {
        return $this->appSettingService->getBool(AppSettingService::KEY_SHIFT_HANDOVER_ENABLED, true);
    }

    /**
     * 提前幾分鐘開始提醒
     *
     * @return int
     */
    public function leadMinutes()
    {
        return $this->appSettingService->getInt(
            AppSettingService::KEY_SHIFT_HANDOVER_LEAD_MINUTES,
            (int) config('constants.SHIFT_HANDOVER_TOPIC.DEFAULT.LEAD_MINUTES')
        );
    }

    /**
     * 沒交接時每隔幾分鐘再催
     *
     * @return int
     */
    public function intervalMinutes()
    {
        return $this->appSettingService->getInt(
            AppSettingService::KEY_SHIFT_HANDOVER_INTERVAL_MINUTES,
            (int) config('constants.SHIFT_HANDOVER_TOPIC.DEFAULT.INTERVAL_MINUTES')
        );
    }

    /**
     * 交接話題的 thread id（可能多個）
     *
     * ⚠ **用 `topicIdsFor()` 而不是 `threadIdsFor()`**：後者沒勾時會回
     * `[null]`（＝主區），而這個功能要雙向運作 —— 沒勾話題時發送端退到主區、
     * 偵測端卻認不出任何訊息是交接，結果是**在主區無上限地催而且永遠無法
     * 被滿足**。`topicIdsFor()` 沒勾時回空陣列，正好讓整個功能短路。
     *
     * @return array<int>
     */
    public function topicIds()
    {
        return $this->supportGroup->topicIdsFor(self::NOTICE_TYPE);
    }

    // ---------------------------------------------------------------
    //  一、排程：建窗口並催
    // ---------------------------------------------------------------

    /**
     * 跑一輪
     *
     * @return int 送出幾則
     */
    public function run()
    {
        // 短路：設定層的判斷先於任何查詢（這支每分鐘跑）
        if (!$this->isEnabled() || !$this->supportGroup->isConfigured()) {
            return 0;
        }

        /*
         * ⚠ **沒設定交接話題就整個不動。** 理由見 `topicIds()` ——
         * 這個功能沒有次數上限，在主區催一件永遠無法被滿足的事
         * 不是「少一個通知」，是整天轟炸。
         */
        if (blank($this->topicIds())) {
            Log::warning('交接提醒已啟用，但「通知設定 → 話題分流」還沒勾交接話題，整個功能先不動作');

            return 0;
        }

        /*
         * ⚠ **排班與可 tag 名單整輪只查一次。**
         *
         * 一輪要問好幾次「某天某班有誰」（建窗口時每個窗口一次、催的時候
         * 上下兩班各一次）—— 逐次去查就是那個次數的查詢，而一分鐘內
         * 名單不會變。比照 `CustomerFollowupService::askOnDuty()`。
         */
        $roster = $this->buildRoster();

        $this->syncWindows($roster);

        $due = $this->repository->getDueForRemind($this->leadMinutes(), $this->intervalMinutes());
        $noStaff = $this->repository->getNoStaffPendingNotice($this->leadMinutes());

        return $this->remindPending($due, $roster) + $this->noticeNoStaff($noStaff, $roster);
    }

    /**
     * 這幾天的排班與可以被 tag 的人（整輪只查兩次）
     *
     * ⚠ **範圍是昨天到明天**：大夜班那一窗的上一班排在昨天、下一班落在
     * 今天；而今天的窗口可能把下一班算到明天。抓寬一天比少抓一天安全 ——
     * 少抓會讓某一班變成「查不到人」而被誤判成沒排班。
     *
     * @return array{assignments: array, users: \Illuminate\Support\Collection}
     */
    private function buildRoster()
    {
        $assignments = $this->assignmentRepository->getShiftUserMapByDateRange(
            now()->copy()->subDay()->format('Y-m-d'),
            now()->copy()->addDay()->format('Y-m-d')
        );

        $userIds = [];

        foreach ($assignments as $byShift) {
            foreach ($byShift as $ids) {
                $userIds = array_merge($userIds, $ids);
            }
        }

        return [
            'assignments' => $assignments,
            // 以 id 為 key，後面逐班取名單時就只是陣列查找，不再碰 DB
            'users'       => collect($this->userRepository->getMentionableByIds(array_unique($userIds)))
                ->keyBy('id'),
        ];
    }

    /**
     * 把「現在該存在」的交接窗口建出來
     *
     * ⚠ **每分鐘自己算、自己 `firstOrCreate`**，不另做一支「每天先建四筆」的
     * job：班別的回訊時間後台可改、班別也可以新增停用，預先建立的話改了
     * 時間就要回頭修當天已建的列 —— 那種「兩邊各算一次」是這份程式碼
     * 踩過好幾次的坑。
     *
     * @return void
     */
    private function syncWindows(array $roster)
    {
        $statuses = (array) config('constants.SHIFT_HANDOVER_TOPIC.STATUS');

        foreach ($this->buildWindows() as $window) {
            $date = (string) Arr::get($window, 'date');
            $start = Arr::get($window, 'window_start');
            $end = Arr::get($window, 'window_end');

            // 走整輪撈好的排班表，不要在迴圈裡再查一次 DB
            $hasFrom = $this->hasStaff($roster, Arr::get($window, 'from_shift_id'), $date);

            /*
             * 下一班的排班掛在**窗口那一天**，不是 `date`（上一班回訊起始日）
             * —— 大夜班那一窗兩者差一天。
             */
            $hasTo = $this->hasStaff(
                $roster,
                Arr::get($window, 'to_shift_id'),
                $start->format('Y-m-d')
            );

            /*
             * ⚠ **下一班沒人 → 把交接往後挪到上一班回訊結束前**
             * （需求方 2026-11-09）。
             *
             * 重疊那一小時的用意是「兩班都在線上時互相交接」。下一班根本
             * 沒有人的話，那個重疊沒有意義 —— 不該讓上一班提早一小時停下來
             * 寫交接，他後面還要繼續回訊。讓他做到回訊結束再寫，
             * 內容也比較完整。
             *
             * 做法是把 `window_start` 移到 `window_end`，提醒邏輯
             * （`window_start - lead`）完全不用動：
             *   晚班→大夜（大夜沒人）：22:55 提醒 → 改成 **23:55**
             *
             * ⚠ 「下班」取的是**回訊結束**不是上下班的下班（需求方 2026-11-09
             * 指定）—— 這個功能每一處判斷都看回訊時間，上下班時間四個班
             * 大量重疊、拿來判斷誰該交接會一次撈到三個人。
             */
            $start = $hasTo ? $start : $end;

            /*
             * 上一班沒有人排班 → 建成「不催」，只跟下一班講一聲。
             *
             * 這是需求方說的大夜班情況（「因為大夜班沒人，如果早班要上班
             * 就請他去看早班待接手和昨天晚班的交接事項」）。
             *
             * ⚠ 跟上面那段是**兩件不同的事**：這裡是「**上一班**沒人所以
             * 沒東西可交接」，上面是「**下一班**沒人所以不急著交接」。
             */
            $status = $hasFrom
                ? (int) Arr::get($statuses, 'PENDING')
                : (int) Arr::get($statuses, 'NO_STAFF');

            $this->repository->firstOrCreateWindow(
                [
                    'date'          => $date,
                    'from_shift_id' => Arr::get($window, 'from_shift_id'),
                    'to_shift_id'   => Arr::get($window, 'to_shift_id'),
                ],
                [
                    'window_start' => $start,
                    'window_end'   => $end,
                    'status'       => $status,
                ]
            );
        }
    }

    /**
     * 算出現在前後該有哪些交接窗口
     *
     * 做法是**以上一班回訊開始的那一天 D 為基準**，把時間換成「D 午夜起算的
     * 分鐘數」，跨午夜就自然超過 1440：
     *
     * | 配對 | 上一班回訊 | 下一班起點 | 窗口 |
     * |---|---|---|---|
     * | 早→午 | 480–840 | 780 | D 13:00–14:00 |
     * | 午→晚 | 780–1140 | 1080 | D 18:00–19:00 |
     * | 晚→大夜 | 1080–**1440** | 1380 | D 23:00–00:00 |
     * | 大夜→早 | 1380–**1980** | 480**+1440** | **D+1** 08:00–09:00 |
     *
     * ⚠ **最後一列是這支存在的理由。** 大夜班回訊 23:00–09:00，它跟早班的
     * 交接窗口落在隔天早上，而排班紀錄掛在 23:00 那天 —— 所以 `date` 存 D，
     * 查「大夜班有誰」才查得到人。既有的
     * `TelegramChatService::getOnDutyAssignments()` 只查今天，在大夜班有人時
     * 會撈錯人（需求方 2026-11-08 同意那支不動）。
     *
     * @return array<int, array> date / from_shift_id / to_shift_id / window_start / window_end
     */
    private function buildWindows()
    {
        $shifts = $this->shiftRepository->allActive()
            ->filter(function ($shift) {
                // 沒設回訊時間的班別排不進交接 —— 整個判斷的依據就是回訊時間
                return filled($shift->reply_start_time) && filled($shift->reply_end_time);
            })
            ->sortBy(function ($shift) {
                return $this->toMinutes($shift->reply_start_time);
            })
            ->values();

        if ($shifts->count() < 2) {
            return [];
        }

        $windows = [];
        $withinHours = (int) config('constants.SHIFT_HANDOVER_TOPIC.CREATE_WITHIN_HOURS');
        $lead = $this->leadMinutes();

        // 昨天與今天都算：大夜班那一窗的 D 是昨天
        foreach ([now()->copy()->subDay(), now()->copy()] as $base) {
            $date = $base->format('Y-m-d');
            $midnight = Carbon::parse("{$date} 00:00:00");

            foreach ($shifts as $index => $from) {
                $to = $shifts->get(($index + 1) % $shifts->count());

                $window = $this->windowFor($from, $to, $midnight);

                if (blank($window)) {
                    continue;
                }

                /** @var Carbon $start */
                $start = Arr::get($window, 'window_start');

                // 還沒到提醒時間就先不建 —— 建了也只是一筆還不該催的列
                if (now()->lessThan($start->copy()->subMinutes($lead))) {
                    continue;
                }

                /*
                 * ⚠ **太舊的不建。** 這不是「催到什麼時候」的上限（那個沒有），
                 * 而是「要不要為一個過去的窗口建紀錄」—— 沒有這道，
                 * 第一次上線會把歷史上每一天的窗口全部補建出來然後同時開催。
                 */
                if ($start->copy()->addHours($withinHours)->lessThan(now())) {
                    continue;
                }

                $windows[] = [
                    'date'          => $date,
                    'from_shift_id' => (int) $from->id,
                    'to_shift_id'   => (int) $to->id,
                    'window_start'  => $start,
                    'window_end'    => Arr::get($window, 'window_end'),
                ];
            }
        }

        return $windows;
    }

    /**
     * 一組班別的交接窗口（上一班回訊起始日的午夜為基準）
     *
     * @param \App\Models\Shift $from
     * @param \App\Models\Shift $to
     * @param Carbon            $midnight 上一班回訊起始日的 00:00
     * @return array|null 兩班回訊沒有重疊時回 null
     */
    private function windowFor($from, $to, Carbon $midnight)
    {
        $fromStart = $this->toMinutes($from->reply_start_time);
        $fromEnd = $this->toMinutes($from->reply_end_time);

        // 跨午夜（例如大夜班 23:00–09:00，或晚班 18:00–00:00）
        if ($fromEnd <= $fromStart) {
            $fromEnd += 1440;
        }

        $toStart = $this->toMinutes($to->reply_start_time);

        // 下一班的起點若在上一班起點之前，那是隔天的那一次
        if ($toStart <= $fromStart) {
            $toStart += 1440;
        }

        /*
         * 下一班的起點要落在上一班的回訊區間內 —— 那段重疊就是交接窗口。
         * 落在區間外代表兩班的回訊時間沒有重疊（中間有空窗），那種情況
         * 沒有「重疊的交接時間」可用，不建窗口。
         */
        if ($toStart <= $fromStart || $toStart > $fromEnd) {
            return null;
        }

        return [
            'window_start' => $midnight->copy()->addMinutes($toStart),
            'window_end'   => $midnight->copy()->addMinutes($fromEnd),
        ];
    }

    /**
     * `HH:mm` 或 `HH:mm:ss` → 午夜起算的分鐘數
     *
     * @param string $time
     * @return int
     */
    private function toMinutes($time)
    {
        $parts = explode(':', (string) $time);

        return ((int) Arr::get($parts, 0)) * 60 + (int) Arr::get($parts, 1);
    }

    // ---------------------------------------------------------------
    //  二、催
    // ---------------------------------------------------------------

    /**
     * 催還沒交接的那幾筆
     *
     * @param \Illuminate\Database\Eloquent\Collection $due    `getDueForRemind()` 的結果
     * @param array                                    $roster `buildRoster()` 的回傳
     * @return int 送出幾則
     */
    private function remindPending($due, array $roster)
    {
        if ($due->isEmpty()) {
            return 0;
        }

        // ⚠ 主管名單同理，整輪只查一次 —— 逐筆查就是 N 倍查詢
        $managers = $this->userRepository->getManagersForMention();
        $escalateAt = (int) config('constants.SHIFT_HANDOVER_TOPIC.ESCALATE_AT');
        $sent = 0;

        foreach ($due as $handover) {
            if ($this->remindOne($handover, $roster, $managers, $escalateAt)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * 催一筆
     *
     * ⚠ **不論送不送得出去，`remind_count` 都要往前推進**：tag 不到人就不更新
     * 的話，這筆每分鐘都會被重試（求助單與待追蹤事項都踩過）。
     *
     * @param ShiftHandover                            $handover
     * @param array                                    $roster `buildRoster()` 的回傳
     * @param \Illuminate\Database\Eloquent\Collection $managers
     * @param int                                      $escalateAt
     * @return bool
     */
    private function remindOne(ShiftHandover $handover, array $roster, $managers, $escalateAt)
    {
        $seq = (int) $handover->remind_count + 1;

        /*
         * ⚠ 兩邊的日期不一樣，**不要都用同一個**：
         * 上一班用 `date`（它回訊開始的那一天），下一班用 `window_start`
         * 那一天 —— 大夜班那一窗兩者相差一天。
         */
        $fromUsers = $this->mentionableFor($roster, $handover->from_shift_id, $handover->date->format('Y-m-d'));
        $toUsers = $this->mentionableFor($roster, $handover->to_shift_id, $handover->window_start->format('Y-m-d'));

        // 催到第 N 次還沒人動，連主管一起 tag（沿用求助單那份名單）
        if ($seq >= $escalateAt) {
            $fromUsers = $fromUsers->concat($managers)->unique('id')->values();
        }

        /*
         * 下一班有沒有人**排班**（不是「有沒有人可以 tag」）——
         * 決定要不要改用「沒有人接手」那套文案。理由見 `hasStaff()`。
         */
        $hasTo = $this->hasStaff($roster, $handover->to_shift_id, $handover->window_start->format('Y-m-d'));

        $this->supportGroup->send(
            $this->buildRemindText($handover, $fromUsers, $toUsers, $seq, $hasTo),
            null,
            null,
            self::NOTICE_TYPE
        );

        $this->repository->update($handover, [
            'remind_count'     => $seq,
            'last_reminded_at' => now(),
        ]);

        Log::info('交接提醒已送出', [
            'handover_id' => $handover->id,
            'seq'         => $seq,
            'from_shift'  => $handover->from_shift_id,
            'to_shift'    => $handover->to_shift_id,
        ]);

        return true;
    }

    /**
     * 那一班那天**有沒有人排班**（純查找，不碰 DB）
     *
     * ⚠ 跟「有沒有人可以被 tag」**是兩件事**，不要互相取代：
     *
     * - 這一支問的是「**有沒有排班**」—— 決定要不要催、要不要把交接往後挪
     * - `mentionableFor()` 回空還可能是「排了班但那個人沒填 Telegram 帳號」
     *
     * 混用的話，一個人只是漏填 Telegram 帳號，整個交接就會被當成「這一班
     * 沒人」而靜悄悄跳過 —— 那是最難發現的那種壞法。
     *
     * @param array  $roster  `buildRoster()` 的回傳
     * @param int    $shiftId
     * @param string $date    Y-m-d
     * @return bool
     */
    private function hasStaff(array $roster, $shiftId, $date)
    {
        return filled(Arr::get($roster, "assignments.{$date}." . (int) $shiftId, []));
    }

    /**
     * 那一班可以被 tag 的人（純查找，不碰 DB）
     *
     * ⚠ **這支以前會自己查兩次 DB，而它是在迴圈裡被呼叫的** ——
     * 2026-11-08 稽核時改成吃 `buildRoster()` 的結果。那正是
     * `CustomerFollowupService::askOnDuty()` 的註解警告過的事。
     *
     * @param array  $roster  `buildRoster()` 的回傳
     * @param int    $shiftId
     * @param string $date    Y-m-d（那一班回訊開始的那一天）
     * @return \Illuminate\Support\Collection
     */
    private function mentionableFor(array $roster, $shiftId, $date)
    {
        $ids = (array) Arr::get($roster, "assignments.{$date}." . (int) $shiftId, []);

        if (blank($ids)) {
            return collect();
        }

        /*
         * 名單已經在 `buildRoster()` 撈好並以 id 為 key ——
         * 這裡只是挑出這一班的那幾個。撈不到的是被
         * `getMentionableByIds()` 濾掉的人（工程、停用、沒填 Telegram 帳號）。
         */
        return collect($ids)
            ->map(function ($id) use ($roster) {
                return Arr::get($roster, 'users')->get((int) $id);
            })
            ->filter()
            ->values();
    }

    /**
     * 組提醒訊息
     *
     * @param ShiftHandover                  $handover
     * @param \Illuminate\Support\Collection $fromUsers
     * @param \Illuminate\Support\Collection $toUsers
     * @param int                            $seq
     * @param bool                           $hasTo 下一班那天**有沒有人排班**
     * @return string
     */
    private function buildRemindText(ShiftHandover $handover, $fromUsers, $toUsers, $seq, $hasTo)
    {
        $notice = (array) config('constants.SHIFT_HANDOVER_TOPIC.NOTICE');
        $format = (string) Arr::get($notice, 'TIME_FORMAT');
        $fromName = $this->shiftName($handover->fromShift);
        $toName = $this->shiftName($handover->toShift);

        $replace = [
            '{from}'          => $fromName,
            '{to}'            => $toName,
            '{from_mentions}' => $this->escalating->mentions($fromUsers, Arr::get($notice, 'NO_MENTION')),
            '{to_mentions}'   => $this->escalating->mentions($toUsers, Arr::get($notice, 'NO_MENTION')),
            '{window_start}'  => $handover->window_start->format($format),
            '{window_end}'    => $handover->window_end->format($format),
            /*
             * ⚠ 下一班**自己的**回訊開始時間，不是窗口起點。
             *
             * 「下一班沒人就把窗口延到上一班回訊結束」之後兩者會分岔 ——
             * 窗口建立時沒人、之後臨時補了人，照窗口起點印就會對一個
             * 23:00 就該上線的人說「你 00:00 要開始回訊了」。
             */
            '{to_start}'      => $this->shiftReplyStart($handover->toShift),
            '{seq}'           => $seq,
        ];

        // 第一次不加「第 N 次提醒」—— 一開始就那樣寫像在罵人
        $text = $seq > 1 ? strtr((string) Arr::get($notice, 'AGAIN_PREFIX'), $replace) : '';

        $text .= strtr((string) Arr::get($notice, 'HEADER'), $replace);
        $text .= strtr((string) Arr::get($notice, 'FROM_LINE'), $replace);

        /*
         * 下一班沒人排班時換一套說法。
         *
         * 原本那句是「你 {window_start} 要開始回訊了」—— 這種情況下沒有
         * 那個人，而且 `window_start` 已經被挪成上一班的回訊結束時間，
         * 照原句送出去會變成對著不存在的一班說「你 00:00 要開始回訊」。
         */
        $text .= $hasTo
            ? strtr((string) Arr::get($notice, 'TO_LINE'), $replace)
            : strtr((string) Arr::get($notice, 'NO_NEXT_LINE'), $replace);

        $footer = $hasTo
            ? Arr::get($notice, 'FOOTER')
            : Arr::get($notice, 'FOOTER_NO_NEXT');

        return $text . strtr((string) $footer, $replace);
    }

    // ---------------------------------------------------------------
    //  三、上一班沒人 → 跟下一班講一聲（只發一次）
    // ---------------------------------------------------------------

    /**
     * 上一班沒人的那幾筆 → 跟下一班講一聲（只發一次）
     *
     * @param \Illuminate\Database\Eloquent\Collection $pending `getNoStaffPendingNotice()` 的結果
     * @param array                                    $roster  `buildRoster()` 的回傳
     * @return int 送出幾則
     */
    private function noticeNoStaff($pending, array $roster)
    {
        if ($pending->isEmpty()) {
            return 0;
        }

        $notice = (array) config('constants.SHIFT_HANDOVER_TOPIC.NOTICE');
        // 最近一筆已交接的，整輪只查一次
        $previous = $this->repository->findLatestHanded();
        $sent = 0;

        foreach ($pending as $handover) {
            /*
             * ⚠ **兩班都沒人 → 一則都不發。**
             *
             * 這則的用意是「跟下一班說上一班沒交接」，而下一班也沒人的話
             * 就沒有收件人 —— 照發只會在話題裡留下一則 tag 不到任何人的
             * 訊息（`mentions()` 會印「這一班沒有可以 tag 的人員」）。
             *
             * 仍然要標 `notified_next_at`，否則每分鐘都會重試一次。
             */
            if (!$this->hasStaff($roster, $handover->to_shift_id, $handover->window_start->format('Y-m-d'))) {
                $this->repository->update($handover, ['notified_next_at' => now()]);

                Log::info('交接窗口兩班都沒人排班，不發通知', [
                    'handover_id' => $handover->id,
                    'from_shift'  => $handover->from_shift_id,
                    'to_shift'    => $handover->to_shift_id,
                ]);

                continue;
            }

            $toUsers = $this->mentionableFor(
                $roster,
                $handover->to_shift_id,
                $handover->window_start->format('Y-m-d')
            );

            $replace = [
                '{from}'         => $this->shiftName($handover->fromShift),
                '{to}'           => $this->shiftName($handover->toShift),
                '{to_mentions}'  => $this->escalating->mentions($toUsers, Arr::get($notice, 'NO_MENTION')),
                '{window_start}' => $handover->window_start->format((string) Arr::get($notice, 'TIME_FORMAT')),
                /*
                 * 「早班待接手清單」的發送時間。
                 *
                 * ⚠ 讀的是**既有那個功能**的常數（`SHIFT_HANDOVER.SEND_AT`），
                 * 不是本功能的 `SHIFT_HANDOVER_TOPIC` —— 兩個名字很像，
                 * 而這裡要的是那支 07:30 排程的時間。指錯會在訊息裡印出空字串，
                 * 而且不會報錯。
                 *
                 * ⚠ 那個常數只是「給人看的字」，真正的排程時間在
                 * `Console\Kernel`，改要兩邊一起改。
                 */
                '{handover_at}'  => (string) config('constants.SHIFT_HANDOVER.SEND_AT'),
            ];

            /*
             * 找得到上一份交接就引用它 —— 需求方要早班「去看昨天晚班的交接
             * 事項」，而引用比寫「請往上找」有用得多（點一下就跳到原文）。
             */
            $hasPrevious = filled($previous) && filled($previous->handed_message_id);
            $template = $hasPrevious
                ? Arr::get($notice, 'NO_STAFF')
                : Arr::get($notice, 'NO_STAFF_NO_PREVIOUS');

            $this->supportGroup->send(
                strtr((string) $template, $replace),
                null,
                $hasPrevious ? (int) $previous->handed_message_id : null,
                self::NOTICE_TYPE
            );

            $this->repository->update($handover, ['notified_next_at' => now()]);
            $sent++;

            Log::info('上一班沒人，已通知下一班去看待接手清單與上一份交接', [
                'handover_id'   => $handover->id,
                'from_shift'    => $handover->from_shift_id,
                'to_shift'      => $handover->to_shift_id,
                'quoted_handed' => $hasPrevious ? $previous->id : null,
            ]);
        }

        return $sent;
    }

    // ---------------------------------------------------------------
    //  四、偵測：有人在交接話題發言
    // ---------------------------------------------------------------

    /**
     * 交接話題有人發言 → 記成完成交接
     *
     * ⚠ **不消耗這則訊息。** 呼叫端（webhook）在這之後還要讓匯率、指令、
     * 求助單那幾支判斷照跑 —— 這支只是一個旁觀的記錄者。
     *
     * ⚠ 判定是「**那一班的人**發言才算」（需求方選）：用
     * `StaffIgnoreService::matchUserId()` 認出是哪位同仁，再比對他排的班。
     * 他沒填 Telegram 帳號就對不到人 —— 跟 tag 不到他是同一個根源。
     *
     * @param array $payload Telegram Update
     * @return bool 有記到才回 true（呼叫端不需要據此 return）
     */
    public function detect($payload)
    {
        // 短路：設定層先判，連 payload 都不必拆
        if (!$this->isEnabled()) {
            return false;
        }

        $topicIds = $this->topicIds();

        if (blank($topicIds)) {
            return false;
        }

        $message = Arr::get($payload, 'message');

        if (blank($message)) {
            return false;
        }

        $threadId = (int) Arr::get($message, 'message_thread_id', 0);

        if ($threadId < 1 || !in_array($threadId, $topicIds, true)) {
            return false;
        }

        // 交接要有內容。純貼圖、純表情不算 —— 但「沒有」兩個字算
        if (blank(trim((string) Arr::get($message, 'text', '')))) {
            return false;
        }

        $userId = $this->staffIgnoreService->matchUserId((array) Arr::get($message, 'from', []));

        if (blank($userId)) {
            Log::info('交接話題有人發言，但認不出是哪一位同仁（沒填 Telegram 帳號？）', [
                'thread_id' => $threadId,
                'from'      => Arr::get($message, 'from.username'),
            ]);

            return false;
        }

        return $this->markHanded($userId, $message);
    }

    /**
     * 把這句話算到他那一班的待交接紀錄上
     *
     * @param int   $userId
     * @param array $message
     * @return bool
     */
    private function markHanded($userId, array $message)
    {
        /*
         * 他排的班要從**昨天到今天**撈：大夜班的交接窗口在隔天早上，
         * 那時他的排班紀錄還掛在前一天。
         */
        $shiftIds = $this->assignmentRepository->getShiftIdsByUserAndDateRange(
            $userId,
            now()->copy()->subDay()->format('Y-m-d'),
            now()->format('Y-m-d')
        );

        $handover = $this->repository->findPendingForShifts($shiftIds, $this->leadMinutes());

        if (blank($handover)) {
            return false;
        }

        $user = $this->userRepository->find($userId);
        $name = filled($user) ? (string) $user->nickname : '';

        $this->repository->update($handover, [
            'status'            => config('constants.SHIFT_HANDOVER_TOPIC.STATUS.HANDED'),
            'handed_at'         => now(),
            'handed_by_user_id' => $userId,
            'handed_by_name'    => $name,
            // 下一班沒人可交接時要引用這則，所以 message_id 一定要留
            'handed_message_id' => (int) Arr::get($message, 'message_id'),
        ]);

        Log::info('交接完成', [
            'handover_id' => $handover->id,
            'user_id'     => $userId,
            'from_shift'  => $handover->from_shift_id,
        ]);

        /*
         * 回報一聲。不發的話下一班不知道上一班打完了沒 ——
         * 而他們正是被 tag 說「請先看上面的交接」的那一群。
         */
        $this->supportGroup->send(
            strtr((string) config('constants.SHIFT_HANDOVER_TOPIC.NOTICE.DONE'), [
                '{from}' => $this->shiftName($handover->fromShift),
                '{name}' => filled($name) ? $name : '不明人員',
            ]),
            null,
            (int) Arr::get($message, 'message_id'),
            self::NOTICE_TYPE
        );

        return true;
    }

    /**
     * 班別顯示名稱（關聯撈不到時的退路）
     *
     * @param \App\Models\Shift|null $shift
     * @return string
     */
    private function shiftName($shift)
    {
        return filled($shift) ? (string) $shift->display_name : '該班別';
    }

    /**
     * 班別自己的回訊開始時間（`HH:mm`）
     *
     * ⚠ 欄位本來就是 `HH:mm` 字串（`shifts.reply_start_time` 是 varchar(5)），
     * 不必也不該再格式化一次。
     *
     * @param \App\Models\Shift|null $shift
     * @return string 關聯或欄位缺的時候回空字串 —— 寧可少一段時間，
     *                也不要印出 `00:00` 這種看起來像真的卻是錯的時間
     */
    private function shiftReplyStart($shift)
    {
        if (blank($shift) || blank($shift->reply_start_time)) {
            return '';
        }

        return (string) $shift->reply_start_time;
    }
}
