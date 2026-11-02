<?php

namespace App\Services;

use App\Models\AttendanceReminder;
use App\Services\Notify\ClockReminderWriter;
use App\Repositories\AttendanceReminderRepository;
use App\Repositories\AttendanceRepository;
use App\Repositories\LeaveRequestRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * 打卡提醒
 *
 * 需求方 2026-10-29：「補強打卡提醒：上班前 15 分鐘提醒、下班 30 分鐘後提醒，
 * 未打下班卡則每 30 分鐘持續提醒，平假日皆適用。」
 *
 * ⚠ **「平假日皆適用」＝ 判準是「有沒有班」，不是星期幾。**
 * 不看週末、也不看國定假日，只看 `AttendanceService::resolveWorkTimes()`
 * 回不回得到時間（輪班看當天有沒有排到、固定班看在不在 `work_days` 裡）。
 * 輪班的人本來就沒有休假、也不放國定假日 —— 排到就是要上班。
 *
 * ⚠ **這支每分鐘跑一次**，所以所有查詢都是批次的：人、排班、出勤紀錄、
 * 請假、提醒狀態各一次，跟同仁人數無關。逐人查會變成一天幾萬次查詢。
 *
 * ⚠ **不進個人靜音清單**（需求方 2026-10-29 指定不能關）：會把它關掉的人，
 * 正好就是最需要它的人，而忘記打卡的後果（曠工）是公司要承擔的。
 */
class ClockReminderService
{
    /** @var string 這天不用上班（沒排到班、或固定班的休假日） */
    const SKIP_NO_DUTY = 'no_duty';

    /** @var string 請整天假 */
    const SKIP_ON_LEAVE = 'on_leave';

    /** @var string 已經打完卡了 */
    const SKIP_DONE = 'done';

    /** @var string 還沒到該提醒的時間 */
    const SKIP_NOT_YET = 'not_yet';

    /** @var string 這一輪已經提醒過了 */
    const SKIP_SENT = 'sent';

    /** @var string 超過持續提醒的上限 */
    const SKIP_EXPIRED = 'expired';

    private $attendanceService;
    private $userRepository;
    private $attendanceRepository;
    private $leaveRepository;
    private $reminderRepository;
    private $staffDm;
    // 上班前那四則的語氣（每天一組，見 ClockReminderWriter）
    private $reminderWriter;

    public function __construct(
        AttendanceService $attendanceService,
        UserRepository $userRepository,
        AttendanceRepository $attendanceRepository,
        LeaveRequestRepository $leaveRepository,
        AttendanceReminderRepository $reminderRepository,
        StaffDmService $staffDm,
        ClockReminderWriter $reminderWriter
    ) {
        // 兩種出勤模式的分岔只在這一支裡，不要自己再寫一次
        $this->attendanceService = $attendanceService;
        $this->userRepository = $userRepository;
        $this->attendanceRepository = $attendanceRepository;
        $this->leaveRepository = $leaveRepository;
        $this->reminderRepository = $reminderRepository;
        $this->staffDm = $staffDm;
        $this->reminderWriter = $reminderWriter;
    }

    /**
     * 跑一輪
     *
     * @param array $params dry_run（只回要發什麼，不送出）／now（指定「現在」，測試用）
     * @return array<int, array{user: string, type: string, date: string, text: string}>
     *     這一輪該發的那幾則
     */
    public function run(array $params = [])
    {
        $now = Arr::get($params, 'now');
        $now = filled($now) ? Carbon::parse($now) : now();

        $users = $this->userRepository->getForClockReminder();

        // 短路：沒有人就什麼都不必查
        if (blank($users)) {
            return [];
        }

        /*
         * ⚠ **要看兩天**：跨日班（22:00–06:00）的下班時間在隔天清晨，
         * 那時 `now()` 已經換日，但出勤日期仍然是前一天。
         * 只看今天的話，夜班的人永遠收不到下班提醒。
         */
        $today = $now->copy()->format('Y-m-d');
        $yesterday = $now->copy()->subDay()->format('Y-m-d');
        $dates = [$yesterday, $today];

        $context = $this->loadContext($users, $dates);
        $pending = [];

        foreach ($users as $user) {
            foreach ($dates as $date) {
                $pending = array_merge(
                    $pending,
                    $this->checkUserDate($user, $date, $now, $context)
                );
            }
        }

        if (Arr::get($params, 'dry_run')) {
            return $pending;
        }

        /*
         * ⚠ 把人直接帶進去，**不要在送出時逐人 `find()`** ——
         * 名單上面已經撈過一次了，再查一次就是 N+1。
         * 同一分鐘內整組人都要提醒（大家同一個班別）時特別明顯。
         */
        return $this->send($pending, $users->keyBy('id'));
    }

    /**
     * 這一輪要用到的資料，全部一次撈完
     *
     * @param \Illuminate\Support\Collection $users
     * @param array<string>                  $dates
     * @return array{work: array, records: array, leaves: array, states: array}
     */
    private function loadContext($users, array $dates)
    {
        $userIds = $users->pluck('id')->map(function ($id) {
            return (int) $id;
        })->all();

        $work = [];

        foreach ($dates as $date) {
            $work[$date] = $this->attendanceService->resolveWorkTimes($users, $date);
        }

        $records = [];

        foreach ($this->attendanceRepository->getAllByDateRange(Arr::first($dates), Arr::last($dates)) as $record) {
            $records[(int) $record->user_id][$record->date->format('Y-m-d')] = $record;
        }

        return [
            'work'    => $work,
            'records' => $records,
            // [date => [userId, …]]
            'leaves'  => $this->leaveRepository->fullDayLeaveMapByDateRange(Arr::first($dates), Arr::last($dates)),
            'states'  => $this->reminderRepository->getStates($userIds, $dates),
        ];
    }

    /**
     * 某個人在某一天該不該提醒
     *
     * @param \App\Models\User $user
     * @param string           $date
     * @param Carbon           $now
     * @param array            $context
     * @return array<int, array> 這個人這一天要發的那幾則（最多一則）
     */
    private function checkUserDate($user, $date, Carbon $now, array $context)
    {
        $userId = (int) $user->id;
        $workTime = Arr::get($context, "work.{$date}.{$userId}");

        // 這天不用上班 —— 沒排到班、或固定班的休假日
        if (blank($workTime)) {
            return [];
        }

        // 請整天假的人今天本來就不用打卡，提醒了只是打擾（需求方 2026-10-29）
        if (in_array($userId, (array) Arr::get($context, "leaves.{$date}", []), true)) {
            return [];
        }

        $record = Arr::get($context, "records.{$userId}.{$date}");
        [$start, $end] = $this->workBoundaries($date, $workTime);

        $clockIn = $this->clockInReminder($user, $date, $now, $start, $record, $context);

        if (filled($clockIn)) {
            return [$clockIn];
        }

        $clockOut = $this->clockOutReminder($user, $date, $now, $end, $record, $context);

        return filled($clockOut) ? [$clockOut] : [];
    }

    /**
     * 這一天的上下班絕對時間
     *
     * ⚠ 跨日班的下班在**隔天** —— 只比 `H:i` 的話，22:00 上班的人會在
     * 當天 06:30 就被提醒下班（那時他根本還沒上班）。
     *
     * @param string $date
     * @param array  $workTime
     * @return array{0: Carbon, 1: Carbon}
     */
    private function workBoundaries($date, array $workTime)
    {
        $base = Carbon::parse($date)->startOfDay();

        return [
            $base->copy()->addMinutes((int) Arr::get($workTime, 'start_minutes')),
            // end_minutes 跨日時已經是 +1440，加上去就自然落在隔天
            $base->copy()->addMinutes((int) Arr::get($workTime, 'end_minutes')),
        ];
    }

    /**
     * 上班前提醒
     *
     * 需求方 2026-11-01 改成**分四階段**：上班前 15／10／5／1 分鐘各一次，
     * 中間只要打了卡，後面幾次就不再發。
     *
     * ⚠ **過了上班時間就不發**。再講「快上班了」已經不成立 ——
     * 那是遲到，是另一回事。
     *
     * ⚠ **「已經打卡就不再提醒」靠的是每一輪重新讀打卡紀錄**（`$record`
     * 來自 `loadContext()`），不是記一個旗標 —— 所以他在第二次與第三次
     * 提醒之間打卡，第三次自然就不會發。
     *
     * @param \App\Models\User $user
     * @param string           $date
     * @param Carbon           $now
     * @param Carbon           $start
     * @param mixed            $record
     * @param array            $context
     * @return array|null
     */
    private function clockInReminder($user, $date, Carbon $now, Carbon $start, $record, array $context)
    {
        // 已經打了就不必提醒（四個階段共用這一道）
        if (filled($record) && filled($record->clock_in)) {
            return null;
        }

        if ($now->gte($start)) {
            return null;
        }

        $stage = $this->clockInStage($now, $start);

        // 還沒進到第一個階段
        if (blank($stage)) {
            return null;
        }

        /*
         * ⚠ **用 `sent_count` 判斷發到第幾階段，不另外開欄位。**
         *
         * `attendance_reminder.type` 是 `varchar(10)`，塞不下
         * `clock_in_15` 這種值（11 字元），而為了四個階段去加欄位或改欄寬
         * 要多一次 migration —— 用既有的計數器就夠了。
         *
         * 判準是「這個階段的訊息發過了沒」：`sent_count` 已經 ≥ 階段序號+1
         * 就代表發過。
         *
         * ⚠ 這樣**漏跑也不會補發一串**：假設排程停到只剩 5 分鐘才恢復，
         * 階段序號是 2，`sent_count` 0 < 3 成立 → 只發這一則（而且用的是
         * 第三階段的語氣），不會把前兩則一起補出來。
         */
        $state = $this->stateOf($context, $user->id, $date, AttendanceReminder::TYPE_CLOCK_IN);
        $sentCount = filled($state) ? (int) $state->sent_count : 0;

        if ($sentCount >= $stage + 1) {
            return null;
        }

        return $this->buildPending($user, $date, AttendanceReminder::TYPE_CLOCK_IN, [
            '{name}'    => (string) $user->nickname,
            '{start}'   => $start->format('H:i'),
            '{minutes}' => (string) $now->diffInMinutes($start),
        ], $stage);
    }

    /**
     * 現在落在第幾個上班前階段（0 起算）
     *
     * 以 `BEFORE_IN_STAGES`（由大到小，例如 15／10／5／1）切出四段窗口：
     *
     * ```
     * [上班前15, 上班前10)  → 0
     * [上班前10, 上班前5)   → 1
     * [上班前5,  上班前1)   → 2
     * [上班前1,  上班)      → 3
     * ```
     *
     * ⚠ 窗口是**左閉右開**且彼此不重疊 —— 重疊的話同一分鐘會算成兩個階段，
     * 而兩個階段都會想發訊息。
     *
     * @param Carbon $now
     * @param Carbon $start
     * @return int|null null ＝ 還沒到第一個階段
     */
    private function clockInStage(Carbon $now, Carbon $start)
    {
        $stages = array_values((array) config('constants.CLOCK_REMINDER.BEFORE_IN_STAGES'));
        $remaining = $now->diffInSeconds($start) / 60;
        $stage = null;

        /*
         * ⚠ 要的是**最後一個**符合的，不是第一個。
         *
         * 門檻由大到小（15／10／5／1），剩 10 分鐘時 `10 <= 15` 與
         * `10 <= 10` 都成立 —— 取第一個會永遠停在第 0 階段，
         * 於是四則只會發出第一則。（2026-11-01 實測抓到：每一個時間點
         * 都回「第 0 階段」。）
         */
        foreach ($stages as $index => $minutes) {
            if ($remaining > (float) $minutes) {
                continue;
            }

            $stage = (int) $index;
        }

        return $stage;
    }

    /**
     * 下班後提醒
     *
     * ⚠ **只有打了上班卡、還沒打下班卡才提醒**。整天都沒打卡的人，
     * 上班提醒已經講過了，再叫他「記得打下班卡」只是噪音 ——
     * 而且他多半根本沒來上班（那是曠工排程的事）。
     *
     * ⚠ **會重複發**：下班後 30 分鐘開始，每 30 分鐘一次，
     * 直到他打卡或超過上限。
     *
     * @param \App\Models\User $user
     * @param string           $date
     * @param Carbon           $now
     * @param Carbon           $end
     * @param mixed            $record
     * @param array            $context
     * @return array|null
     */
    private function clockOutReminder($user, $date, Carbon $now, Carbon $end, $record, array $context)
    {
        if (blank($record) || blank($record->clock_in) || filled($record->clock_out)) {
            return null;
        }

        $firstAt = $end->copy()->addMinutes((int) config('constants.CLOCK_REMINDER.AFTER_OUT_MINUTES'));

        if ($now->lt($firstAt)) {
            return null;
        }

        /*
         * ⚠ **上限一律用「下班後 N 小時」，不分日班夜班**（需求方 2026-10-29）。
         *
         * 原本想用「隔天凌晨 1 點曠工排程跑完」當終點，但夜班 06:00 下班的人
         * 那時早就過了 —— 照字面做的話夜班永遠收不到下班提醒。
         * 改成相對下班時間算，兩種班別才是同一條規則。
         */
        $deadline = $end->copy()->addHours((int) config('constants.CLOCK_REMINDER.MAX_HOURS'));

        if ($now->gte($deadline)) {
            return null;
        }

        $state = $this->stateOf($context, $user->id, $date, AttendanceReminder::TYPE_CLOCK_OUT);

        // 已經發過就要等滿間隔才發下一則
        if (filled($state)) {
            $nextAt = filled($state->last_sent_at)
                ? $state->last_sent_at->copy()->addMinutes((int) config('constants.CLOCK_REMINDER.REPEAT_MINUTES'))
                : $firstAt;

            if ($now->lt($nextAt)) {
                return null;
            }
        }

        return $this->buildPending($user, $date, AttendanceReminder::TYPE_CLOCK_OUT, [
            '{name}'    => (string) $user->nickname,
            '{end}'     => $end->format('H:i'),
            '{minutes}' => (string) $end->diffInMinutes($now),
        ]);
    }

    /**
     * 組一則待發的提醒
     *
     * @param \App\Models\User      $user
     * @param string                $date
     * @param string                $type
     * @param array<string, string> $replace
     * @return array{user_id: int, user: string, type: string, date: string, text: string}
     */
    private function buildPending($user, $date, $type, array $replace, $stage = null)
    {
        return [
            'user_id' => (int) $user->id,
            'user'    => (string) $user->nickname,
            'type'    => $type,
            'date'    => $date,
            'text'    => $type === AttendanceReminder::TYPE_CLOCK_IN
                ? $this->clockInText($date, $stage, $replace)
                : strtr((string) config('constants.CLOCK_REMINDER.AFTER_OUT'), $replace),
        ];
    }

    /**
     * 上班前提醒的內容：AI 寫的那一句 ＋ 固定的事實行
     *
     * 需求方 2026-11-01：「這 4 次的說話跟語氣和態度每天都要不一樣，不然會
     * 產生惰性，話語與語氣這個是自己人看的，所以讓 AI 自由發揮就好。」
     *
     * ⚠ **事實（幾點上班、還有幾分鐘）不交給模型寫**：它某天多寫一個數字
     * 就會變成錯誤資訊，而這是會讓人遲到的那種錯。模型只負責那一句話。
     *
     * ⚠ 名字留在開頭：四句都是模型寫的，沒有名字的話會像群發公告。
     *
     * @param string   $date
     * @param int|null $stage
     * @param array    $replace
     * @return string
     */
    private function clockInText($date, $stage, array $replace)
    {
        $lines = $this->reminderWriter->linesFor($date);
        $line = (string) Arr::get($lines, (int) $stage, '');

        // 階段數比文案多（有人改了 BEFORE_IN_STAGES 卻沒改公版）—— 退回舊公版
        if (blank($line)) {
            return strtr((string) config('constants.CLOCK_REMINDER.BEFORE_IN'), $replace);
        }

        $name = (string) Arr::get($replace, '{name}');
        $fact = strtr((string) config('constants.CLOCK_REMINDER.BEFORE_IN_FACT'), $replace);

        return "{$name}，{$line}\n{$fact}";
    }

    /**
     * 這個人這一天這一種提醒的狀態
     *
     * @param array  $context
     * @param int    $userId
     * @param string $date
     * @param string $type
     * @return AttendanceReminder|null
     */
    private function stateOf(array $context, $userId, $date, $type)
    {
        return Arr::get(
            $context,
            'states.' . AttendanceReminderRepository::key($userId, $date, $type)
        );
    }

    /**
     * 把這幾則發出去
     *
     * ⚠ **送出成功才記狀態**：先記後送的話，送失敗那一則會被當成已發，
     * 下一輪不會補 —— 而那正是最需要補的情況。
     *
     * ⚠ 一個人發不出去不能讓整輪停掉（`StaffDmService::send()` 自己
     * 不丟例外，這裡再包一層是為了狀態寫入）。
     *
     * @param array                          $pending
     * @param \Illuminate\Support\Collection $users 以 id 為 key，省掉逐人再查一次
     * @return array 真的送出去的那幾則
     */
    private function send(array $pending, $users)
    {
        $sent = [];

        foreach ($pending as $item) {
            try {
                $user = $users->get((int) Arr::get($item, 'user_id'));

                if (blank($user) || !$this->staffDm->send($user, Arr::get($item, 'text'))) {
                    continue;
                }

                $this->reminderRepository->markSent(
                    (int) Arr::get($item, 'user_id'),
                    (string) Arr::get($item, 'date'),
                    (string) Arr::get($item, 'type')
                );

                $sent[] = $item;
            } catch (\Exception $e) {
                Log::error('打卡提醒發送失敗', [
                    'user_id' => Arr::get($item, 'user_id'),
                    'type'    => Arr::get($item, 'type'),
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        if (filled($sent)) {
            Log::info('打卡提醒已送出', ['count' => count($sent)]);
        }

        return $sent;
    }
}
