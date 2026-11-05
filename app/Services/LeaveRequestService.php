<?php

namespace App\Services;

use App\Models\LeaveRequest;
use App\Repositories\LeaveRequestRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 請假 Service
 */
class LeaveRequestService
{
    private $leaveRepository;
    private $approvalNotice;
    private $userRepository;
    // 特休額度：算一日幾分鐘、還剩多少（需求方 2026-11-05）
    private $leaveQuota;

    public function __construct(
        LeaveRequestRepository $leaveRepository,
        ApprovalNoticeService $approvalNotice,
        UserRepository $userRepository,
        LeaveQuotaService $leaveQuota
    ) {
        $this->leaveRepository = $leaveRepository;
        // 申請與審核都要私訊通知主管（收件人在通知設定頁維護）
        $this->approvalNotice = $approvalNotice;
        $this->userRepository = $userRepository;
        $this->leaveQuota = $leaveQuota;
    }

    /**
     * 查詢全部（審核用）
     *
     * @param array $criteria
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAll($criteria = [])
    {
        return $this->leaveRepository->all($criteria);
    }

    /**
     * 查詢個人請假
     *
     * @param int $userId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getMyList($userId)
    {
        return $this->leaveRepository->getByUser($userId);
    }

    /**
     * 申請請假
     *
     * @param array $params
     * @param int   $userId
     * @return LeaveRequest
     * @throws ValidationException
     */
    public function apply($params, $userId)
    {
        $endDate = filled(Arr::get($params, 'end_date')) ? $params['end_date'] : $params['start_date'];

        // 檢查是否有重疊的請假
        $hasOverlap = $this->leaveRepository->hasOverlapping(
            $userId,
            $params['start_date'],
            $endDate
        );

        if ($hasOverlap) {
            throw ValidationException::withMessages([
                'date' => [trans('leave.msg.overlap')],
            ]);
        }

        $user = $this->userRepository->find((int) $userId);

        if (blank($user)) {
            throw ValidationException::withMessages([
                'date' => [trans('leave.msg.user_not_found')],
            ]);
        }

        $type = $this->resolveType(Arr::get($params, 'type'));
        $minutes = $this->minutesOf($params, $endDate, $user);

        /*
         * ⚠ **額度要在這裡擋，不是等審核**（需求方 2026-11-05：
         * 「特休請完就不能請了」）。只在核准時檢查的話，同一個人可以連送
         * 十張超額的單，全部送得出去，然後在審核階段一張一張爆掉 ——
         * 而主管要一張一張看懂為什麼。
         *
         * ⚠ 第一版**只有特休**會被擋（`constants.LEAVE.QUOTA_TYPES`）。
         */
        if ($this->needsQuota($type) && !$this->leaveQuota->canAfford($user, $minutes)) {
            throw ValidationException::withMessages([
                'date' => [$this->quotaErrorFor($user)],
            ]);
        }

        $leaveRequest = $this->leaveRepository->create([
            'user_id'    => $userId,
            'start_date' => $params['start_date'],
            'end_date'   => $endDate,
            'type'       => $type,
            /*
             * ⚠ **換算後的分鐘數存下來**，不要每次用時再算：
             * 「一日幾小時」是跟著人的出勤模式走的（輪班 12／固定班 8），
             * 那個人日後換了模式，舊假單不該跟著變。
             */
            'minutes'    => $minutes,
            'is_full_day' => (int) $params['is_full_day'],
            'start_time' => Arr::get($params, 'start_time'),
            'end_time'   => Arr::get($params, 'end_time'),
            'reason'     => Arr::get($params, 'reason'),
        ]);

        /*
         * ⚠ **建立成功之後才通知**，而且通知失敗不會往上拋
         * （`ApprovalNoticeService` 自己包 try/catch）——
         * 通知沒送到頂多主管晚點看到，但申請被例外中斷的話，
         * 同仁會以為沒送出而重複送。
         */
        $this->approvalNotice->leaveApplied($leaveRequest);

        return $leaveRequest;
    }

    /**
     * 假別：只收白名單內的，其餘一律當事假
     *
     * ⚠ **不能收 `unclassified`**：那是改版前舊資料的標記，不是給人選的。
     * 讓它從表單進來的話，使用者就有一種「不扣任何額度」的假可以選。
     *
     * @param mixed $type
     * @return string
     */
    private function resolveType($type)
    {
        $allowed = [
            LeaveRequest::TYPE_ANNUAL,
            LeaveRequest::TYPE_SICK,
            LeaveRequest::TYPE_PERSONAL,
        ];

        $value = (string) $type;

        return in_array($value, $allowed, true) ? $value : LeaveRequest::TYPE_PERSONAL;
    }

    /**
     * 這個假別要不要扣額度
     *
     * @param string $type
     * @return bool
     */
    private function needsQuota($type)
    {
        return in_array($type, (array) config('constants.LEAVE.QUOTA_TYPES'), true);
    }

    /**
     * 這張假單總共幾分鐘
     *
     * ⚠ **整天假要換算**（天數 × 該員的日時數），時段假才是真的算時間差 ——
     * 這兩個以前是分開統計的（天歸天、小時歸小時），所以「特休剩幾小時」
     * 根本算不出來。統一成分鐘是這次改動的核心。
     *
     * ⚠ 時段假**支援跨午夜**（19:30~00:00）：終點小於起點就加一天，
     * 跟 `AttendanceService::summariseLeaves()` 原本的算法一致。
     *
     * @param array  $params
     * @param string $endDate
     * @param \App\Models\User $user
     * @return int
     */
    private function minutesOf(array $params, $endDate, $user)
    {
        if ((int) Arr::get($params, 'is_full_day') === 1) {
            $from = Carbon::parse($params['start_date'])->startOfDay();
            $to = Carbon::parse($endDate)->startOfDay();

            return ($from->diffInDays($to) + 1) * $this->leaveQuota->dayMinutes($user);
        }

        $start = $this->minutesOfClock(Arr::get($params, 'start_time'));
        $end = $this->minutesOfClock(Arr::get($params, 'end_time'));
        $minutes = $end - $start;

        // 跨午夜
        if ($minutes <= 0) {
            $minutes += 1440;
        }

        return $minutes;
    }

    /**
     * `HH:MM` → 當天的第幾分鐘
     *
     * @param mixed $clock
     * @return int
     */
    private function minutesOfClock($clock)
    {
        $parts = explode(':', (string) $clock);

        return ((int) Arr::get($parts, 0)) * 60 + ((int) Arr::get($parts, 1));
    }

    /**
     * 分鐘數講成「幾小時」給人看
     *
     * @param int $minutes
     * @return string
     */
    private function hoursText($minutes)
    {
        return (string) round(max(0, (int) $minutes) / 60, 1);
    }

    /**
     * 特休不夠的時候，要告訴他哪一種「不夠」
     *
     * ⚠ **「還沒有特休」與「有過但用完了」是兩件事，訊息不能混用。**
     * 兩種都回「額度不足，目前只剩 0 小時」的話，未滿六個月的新人會以為
     * 自己有特休卻被請光了，跑去追帳 —— 而申請表單底下那一行明明寫著
     * 「尚未取得特休（未滿六個月）」，兩句話互相矛盾，更難懂。
     *
     * 判斷依據是 `period_start` 為 null（`summaryFor()` 在還沒滿六個月或
     * 根本沒填到職日時回 `emptySummary()`）—— 跟畫面那一行用的是同一個訊號，
     * 所以兩邊永遠一致。
     *
     * @param object $user
     * @return string
     */
    private function quotaErrorFor($user)
    {
        $summary = $this->leaveQuota->summaryFor($user);

        if (blank(Arr::get($summary, 'period_start'))) {
            return trans('leave.msg.quota_not_yet');
        }

        return trans('leave.msg.quota_exceeded', [
            'hours' => $this->hoursText(Arr::get($summary, 'remaining_minutes')),
        ]);
    }

    /**
     * 審核請假（通過/拒絕）
     *
     * @param LeaveRequest $leaveRequest
     * @param int          $status
     * @param int          $reviewerId
     * @param string|null  $note
     * @return LeaveRequest
     * @throws ValidationException
     */
    public function respond(LeaveRequest $leaveRequest, $status, $reviewerId, $note = null)
    {
        if ((int) $leaveRequest->status !== LeaveRequest::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'leave' => [trans('leave.msg.already_reviewed')],
            ]);
        }

        $updated = DB::transaction(function () use ($leaveRequest, $status, $reviewerId, $note) {
            return $this->leaveRepository->update($leaveRequest, [
                'status'      => (int) $status,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);
        });

        // ⚠ 擺在 transaction **外面**：通知失敗不該讓已經審好的結果被 rollback
        $this->approvalNotice->leaveReviewed($updated, $status);

        return $updated;
    }

    /**
     * 取得指定日期已通過的請假
     *
     * @param int    $userId
     * @param string $date
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getApprovedOnDate($userId, $date)
    {
        return $this->leaveRepository->getApprovedOnDate($userId, $date);
    }

    /**
     * 檢查指定日期是否被請假阻擋（供排班用）
     *
     * @param int         $userId
     * @param string      $date
     * @param string|null $shiftStart HH:mm
     * @param string|null $shiftEnd   HH:mm
     * @return bool
     */
    public function isBlockedByLeave($userId, $date, $shiftStart = null, $shiftEnd = null)
    {
        $leaves = $this->leaveRepository->getApprovedOnDate($userId, $date);

        if ($leaves->isEmpty()) {
            return false;
        }

        foreach ($leaves as $leave) {
            if ((int) $leave->is_full_day === 1) {
                return true;
            }

            // 時段假：檢查與班別時段是否重疊
            if (filled($shiftStart) && filled($shiftEnd) && filled($leave->start_time) && filled($leave->end_time)) {
                $leaveStart = strtotime($leave->start_time);
                $leaveEnd = strtotime($leave->end_time);
                $sStart = strtotime($shiftStart);
                $sEnd = strtotime($shiftEnd);

                if (max($leaveStart, $sStart) < min($leaveEnd, $sEnd)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 指定日期是否有整天假（供打卡用）
     *
     * @param int    $userId
     * @param string $date
     * @return bool
     */
    public function hasFullDayLeave($userId, $date)
    {
        return $this->leaveRepository->hasApprovedFullDayOnDate($userId, $date);
    }
}
