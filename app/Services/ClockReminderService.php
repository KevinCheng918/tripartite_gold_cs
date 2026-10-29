<?php

namespace App\Services;

use App\Models\AttendanceReminder;
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

    public function __construct(
        AttendanceService $attendanceService,
        UserRepository $userRepository,
        AttendanceRepository $attendanceRepository,
        LeaveRequestRepository $leaveRepository,
        AttendanceReminderRepository $reminderRepository,
        StaffDmService $staffDm
    ) {
        // 兩種出勤模式的分岔只在這一支裡，不要自己再寫一次
        $this->attendanceService = $attendanceService;
        $this->userRepository = $userRepository;
        $this->attendanceRepository = $attendanceRepository;
        $this->leaveRepository = $leaveRepository;
        $this->reminderRepository = $reminderRepository;
        $this->staffDm = $staffDm;
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
     * ⚠ **只發一次**，而且只在「上班前那段」發。過了上班時間再講
     * 「快上班了」已經不成立 —— 那是遲到，是另一回事。
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
        // 已經打了就不必提醒
        if (filled($record) && filled($record->clock_in)) {
            return null;
        }

        $fireAt = $start->copy()->subMinutes((int) config('constants.CLOCK_REMINDER.BEFORE_IN_MINUTES'));

        if ($now->lt($fireAt) || $now->gte($start)) {
            return null;
        }

        if (filled($this->stateOf($context, $user->id, $date, AttendanceReminder::TYPE_CLOCK_IN))) {
            return null;
        }

        return $this->buildPending($user, $date, AttendanceReminder::TYPE_CLOCK_IN, [
            '{name}'    => (string) $user->nickname,
            '{start}'   => $start->format('H:i'),
            '{minutes}' => (string) $now->diffInMinutes($start),
        ]);
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
    private function buildPending($user, $date, $type, array $replace)
    {
        $key = $type === AttendanceReminder::TYPE_CLOCK_IN ? 'BEFORE_IN' : 'AFTER_OUT';

        return [
            'user_id' => (int) $user->id,
            'user'    => (string) $user->nickname,
            'type'    => $type,
            'date'    => $date,
            'text'    => strtr((string) config("constants.CLOCK_REMINDER.{$key}"), $replace),
        ];
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
