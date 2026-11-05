<?php

namespace App\Services;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Repositories\LeaveRequestRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * 特休額度：年資 → 日數 → 小時 → 還剩多少
 *
 * 需求方 2026-11-05：「按照他的年資去計算他特休的天數⋯⋯用小時計算，
 * 特休請完就不能請了。」
 *
 * ⚠ **年度用週年制**（需求方裁示，也是勞基法預設）：從到職日起算，
 * 每滿一年是一期。
 *
 * ⚠ **6 個月以上未滿 1 年那 3 日是特例**：條文寫「為取得特別休假權利後
 * **六個月**之期間」—— 那一期是從滿 6 個月那天到滿 1 年，只有半年，
 * 不是從到職日起算的一整年。
 *
 * ⚠ **未休完遞延一年**（需求方裁示 ＋ 勞基法）。但這裡沒有逐年度的帳本，
 * 採用的簡化見 `carryOverMinutes()` —— 連續兩年沒休完時會略為高估。
 *
 * ⚠ **留職停薪沒有被扣掉**：系統目前沒有留停的資料，年資一律從 `hired_at`
 * 算到今天。有人留停過的話這裡算出來的年資與特休都會偏多 —— 已知限制。
 */
class LeaveQuotaService
{
    private $leaveRepository;

    public function __construct(LeaveRequestRepository $leaveRepository)
    {
        $this->leaveRepository = $leaveRepository;
    }

    /**
     * 這個人現在的特休狀況
     *
     * @param User        $user
     * @param string|null $onDate 以哪一天為準（預設今天），測試與回溯用
     * @return array{
     *     entitled_days: float, day_minutes: int, period_start: string|null,
     *     period_end: string|null, entitled_minutes: int, carry_over_minutes: int,
     *     used_minutes: int, pending_minutes: int, remaining_minutes: int,
     *     available_minutes: int
     * }
     */
    public function summaryFor(User $user, $onDate = null)
    {
        $on = filled($onDate) ? Carbon::parse($onDate)->startOfDay() : now()->startOfDay();
        $period = $this->periodOf($user, $on);

        // 還沒滿 6 個月（或根本沒填到職日）—— 一天特休都還沒有
        if (blank($period)) {
            return $this->emptySummary($user);
        }

        $dayMinutes = $this->dayMinutes($user);
        $days = $this->entitledDays((float) Arr::get($period, 'service_years'));
        $entitled = (int) round($days * $dayMinutes);
        $carryOver = $this->carryOverMinutes($user, $period, $dayMinutes);

        $used = $this->minutesIn(
            $user,
            Arr::get($period, 'start'),
            Arr::get($period, 'end'),
            LeaveRequest::STATUS_APPROVED
        );

        $pending = $this->minutesIn(
            $user,
            Arr::get($period, 'start'),
            Arr::get($period, 'end'),
            LeaveRequest::STATUS_PENDING
        );

        $available = $entitled + $carryOver;

        return [
            'entitled_days'      => $days,
            'day_minutes'        => $dayMinutes,
            'period_start'       => Arr::get($period, 'start')->toDateString(),
            'period_end'         => Arr::get($period, 'end')->toDateString(),
            'entitled_minutes'   => $entitled,
            'carry_over_minutes' => $carryOver,
            'used_minutes'       => $used,
            'pending_minutes'    => $pending,
            'available_minutes'  => $available,
            // ⚠ 可能是負數：舊資料或人工調整過都可能超支，夾成 0 會看不出問題
            'remaining_minutes'  => $available - $used,
        ];
    }

    /**
     * 還能不能再請這麼久的特休
     *
     * ⚠ **要扣掉待審核中的**（需求方裁示：核准時扣，但申請時就要檢查）。
     * 只看已核准的話，同一個人可以連送十張超額的單，全部通過檢查，
     * 然後在審核階段才一張一張爆掉。
     *
     * @param User $user
     * @param int  $minutes 這次要請幾分鐘
     * @param int  $exceptId 改單時要排除自己那一張
     * @return bool
     */
    public function canAfford(User $user, $minutes, $exceptId = null)
    {
        $summary = $this->summaryFor($user);

        $pending = (int) Arr::get($summary, 'pending_minutes');

        if (filled($exceptId)) {
            $pending -= $this->minutesOfRequest($exceptId);
        }

        $left = (int) Arr::get($summary, 'remaining_minutes') - max(0, $pending);

        return $left >= (int) $minutes;
    }

    /**
     * 一日特休等於幾分鐘
     *
     * ⚠ 輪班 12 小時、固定班 8 小時（需求方 2026-11-05 裁示）。
     * **額度與消耗用同一把尺** —— 不然「總是請長班」的人會比別人少休到
     * 好幾天，而額度上寫著一樣的日數。
     *
     * @param User $user
     * @return int
     */
    public function dayMinutes(User $user)
    {
        $fixed = (int) config('constants.USER.WORK_MODE.FIXED');
        $key = (int) $user->work_mode === $fixed ? 'FIXED' : 'SHIFT';

        return (int) config("constants.LEAVE.DAY_HOURS.{$key}") * 60;
    }

    /**
     * 年資對應的特休日數
     *
     * ⚠ 10 年以上走公式不查表（`BASE_DAYS + (年資 - 9)`，上限 30）——
     * 展開成二十幾格的話，改上限要改一堆列，而且看不出「每年加一日」。
     *
     * @param float $serviceYears 年資（年，可含小數）
     * @return float
     */
    public function entitledDays($serviceYears)
    {
        $long = (array) config('constants.LEAVE.ANNUAL_LONG_SERVICE');
        $from = (float) Arr::get($long, 'FROM_YEARS');

        if ($serviceYears >= $from) {
            $days = (float) Arr::get($long, 'BASE_DAYS') + (floor($serviceYears) - $from + 1);

            return min($days, (float) Arr::get($long, 'MAX_DAYS'));
        }

        $days = 0.0;

        // 取「不大於年資」的最大那一格
        foreach ((array) config('constants.LEAVE.ANNUAL_TIERS') as $years => $tierDays) {
            if ($serviceYears >= (float) $years) {
                $days = (float) $tierDays;
            }
        }

        return $days;
    }

    /**
     * 現在落在哪一期（週年制）
     *
     * ⚠ **6 個月以上未滿 1 年是特例**：那一期是「滿 6 個月那天 ~ 滿 1 年
     * 前一天」，只有半年（條文：「為取得特別休假權利後六個月之期間」）。
     * 照一般週年期算的話，他會在還沒滿 6 個月時就拿到那 3 日。
     *
     * @param User   $user
     * @param Carbon $on
     * @return array{start: Carbon, end: Carbon, service_years: float}|null
     *     null ＝ 還沒滿 6 個月，或沒填到職日
     */
    public function periodOf(User $user, Carbon $on)
    {
        if (blank($user->hired_at)) {
            return null;
        }

        $hired = Carbon::parse($user->hired_at)->startOfDay();

        // 還沒到職（預填未來日期）—— 不給額度，也不要算出負的年資
        if ($on->lt($hired)) {
            return null;
        }

        $halfYear = $hired->copy()->addMonthsNoOverflow(6);

        if ($on->lt($halfYear)) {
            return null;
        }

        $firstAnniversary = $hired->copy()->addYearNoOverflow(1);

        // 第一期：滿 6 個月 ~ 滿 1 年前一天
        if ($on->lt($firstAnniversary)) {
            return [
                'start'         => $halfYear,
                'end'           => $firstAnniversary->copy()->subDay(),
                'service_years' => 0.5,
            ];
        }

        /*
         * 之後每一期都是一整年。`$years` 是這一期開始時的年資 ——
         * 用它查級距，所以「滿 2 年那天」起就是 10 日，不必等到期末。
         */
        $years = (int) $hired->diffInYears($on);
        $start = $hired->copy()->addYearsNoOverflow($years);

        return [
            'start'         => $start,
            'end'           => $start->copy()->addYearNoOverflow(1)->subDay(),
            'service_years' => (float) $years,
        ];
    }

    /**
     * 上一期沒休完、可以遞延過來的分鐘數
     *
     * ⚠ **這是簡化版**：正確的遞延要逐年度記帳（這一期用掉的到底是「上期
     * 遞延來的」還是「本期的」）。不開新資料表做不到完整帳本，所以用
     *
     *     遞延 = max(0, 上期額度 − 上期已用)
     *
     * 連續兩年都沒休完時會**略為高估**（上上期的遞延已經失效，但上期已用
     * 沒有被歸到那個池子）。勞基法只准遞延一年，所以誤差有上限。
     * 要完全正確得開一張逐期記帳的表。
     *
     * ⚠ 第一期（滿 6 個月那半年）沒有上一期，直接回 0。
     *
     * @param User  $user
     * @param array $period     本期
     * @param int   $dayMinutes
     * @return int
     */
    private function carryOverMinutes(User $user, array $period, $dayMinutes)
    {
        $years = (float) Arr::get($period, 'service_years');

        // 第一期之前沒有東西可以遞延
        if ($years < 1) {
            return 0;
        }

        $start = Arr::get($period, 'start');
        $previousStart = $years < 2
            // 第二期的上一期是那半年，不是一整年
            ? Carbon::parse($user->hired_at)->startOfDay()->addMonthsNoOverflow(6)
            : $start->copy()->subYearNoOverflow(1);

        $previousDays = $this->entitledDays($years < 2 ? 0.5 : $years - 1);
        $previousEntitled = (int) round($previousDays * $dayMinutes);

        $previousUsed = $this->minutesIn(
            $user,
            $previousStart,
            $start->copy()->subDay(),
            LeaveRequest::STATUS_APPROVED
        );

        return max(0, $previousEntitled - $previousUsed);
    }

    /**
     * 某段期間內某狀態的特休分鐘數
     *
     * ⚠ **跨年度的假單依「每一天各自歸屬的年度」拆開算**（例如 12/28~1/3）。
     * 整張算進開始那一期的話，年度交界那幾天會算錯 —— 而那正是最容易
     * 超支的時候。拆分在 `Repository` 那一層做不到（它只認日期範圍），
     * 所以這裡拿回假單自己按天分攤。
     *
     * @param User   $user
     * @param Carbon $start
     * @param Carbon $end
     * @param int    $status
     * @return int
     */
    private function minutesIn(User $user, Carbon $start, Carbon $end, $status)
    {
        $leaves = $this->leaveRepository->getAnnualByUserAndRange(
            (int) $user->id,
            $start->toDateString(),
            $end->toDateString(),
            (int) $status
        );

        $total = 0;

        foreach ($leaves as $leave) {
            $total += $this->minutesWithin($leave, $start, $end);
        }

        return $total;
    }

    /**
     * 一張假單落在這段期間裡的分鐘數
     *
     * ⚠ 整天假按天平均分攤；時段假不會跨日（`start_time`~`end_time` 同一天
     * 或跨午夜，仍算一天），所以只要起始日在範圍內就整張算進來。
     *
     * @param LeaveRequest $leave
     * @param Carbon       $start
     * @param Carbon       $end
     * @return int
     */
    private function minutesWithin(LeaveRequest $leave, Carbon $start, Carbon $end)
    {
        $minutes = (int) $leave->minutes;

        if ($minutes < 1) {
            return 0;
        }

        $from = Carbon::parse($leave->start_date)->startOfDay();
        $to = Carbon::parse($leave->end_date)->startOfDay();
        $totalDays = $from->diffInDays($to) + 1;

        // 單日的假單不必分攤
        if ($totalDays < 2) {
            return $from->betweenIncluded($start, $end) ? $minutes : 0;
        }

        $overlapFrom = $from->greaterThan($start) ? $from : $start;
        $overlapTo = $to->lessThan($end) ? $to : $end;

        if ($overlapFrom->greaterThan($overlapTo)) {
            return 0;
        }

        $overlapDays = $overlapFrom->diffInDays($overlapTo) + 1;

        return (int) round($minutes * $overlapDays / $totalDays);
    }

    /**
     * 某一張假單的分鐘數（改單時要把自己扣回來）
     *
     * @param int $id
     * @return int
     */
    private function minutesOfRequest($id)
    {
        $leave = $this->leaveRepository->find((int) $id);

        return filled($leave) ? (int) $leave->minutes : 0;
    }

    /**
     * 還沒有特休時的回傳（形狀要跟正常情況一致）
     *
     * ⚠ 回同一個形狀而不是 null：呼叫端如果要先判斷 null 才能取值，
     * 每一個顯示的地方都要寫一次那個判斷，遲早漏掉一處。
     *
     * @param User $user
     * @return array
     */
    private function emptySummary(User $user)
    {
        return [
            'entitled_days'      => 0.0,
            'day_minutes'        => $this->dayMinutes($user),
            'period_start'       => null,
            'period_end'         => null,
            'entitled_minutes'   => 0,
            'carry_over_minutes' => 0,
            'used_minutes'       => 0,
            'pending_minutes'    => 0,
            'available_minutes'  => 0,
            'remaining_minutes'  => 0,
        ];
    }
}
