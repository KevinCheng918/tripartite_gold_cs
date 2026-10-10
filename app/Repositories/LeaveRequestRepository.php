<?php

namespace App\Repositories;

use App\Models\LeaveRequest;
use Illuminate\Database\Eloquent\Collection;

/**
 * 請假申請 Repository
 */
class LeaveRequestRepository
{
    /** @var array 查詢欄位 */
    private const LIST_COLUMNS = [
        'id', 'user_id', 'start_date', 'end_date',
        'is_full_day', 'start_time', 'end_time',
        'reason', 'status', 'reviewed_by', 'reviewed_at', 'review_note',
        'created_at',
    ];

    /**
     * 查詢全部（審核用）
     *
     * @param array $criteria
     * @return Collection
     */
    public function all($criteria = [])
    {
        $query = LeaveRequest::query()
            ->select(self::LIST_COLUMNS)
            ->with(['user', 'reviewer'])
            ->orderByDesc('created_at');

        if (filled($criteria['user_id'] ?? null)) {
            $query->where('user_id', (int) $criteria['user_id']);
        }

        if (isset($criteria['status']) && $criteria['status'] !== '') {
            $query->where('status', (int) $criteria['status']);
        }

        return $query->get();
    }

    /**
     * 查詢個人請假
     *
     * @param int $userId
     * @return Collection
     */
    public function getByUser($userId)
    {
        return LeaveRequest::query()
            ->select(self::LIST_COLUMNS)
            ->with(['reviewer'])
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * 新增
     *
     * @param array $attributes
     * @return LeaveRequest
     */
    public function create($attributes)
    {
        return LeaveRequest::query()->create($attributes);
    }

    /**
     * 更新
     *
     * @param LeaveRequest $leaveRequest
     * @param array        $attributes
     * @return LeaveRequest
     */
    public function update(LeaveRequest $leaveRequest, $attributes)
    {
        $leaveRequest->update($attributes);

        return $leaveRequest->refresh();
    }

    /**
     * 檢查是否有重疊的請假（待審核或已通過）
     *
     * @param int         $userId
     * @param string      $startDate
     * @param string      $endDate
     * @param int|null    $excludeId
     * @return bool
     */
    public function hasOverlapping($userId, $startDate, $endDate, $excludeId = null)
    {
        $query = LeaveRequest::query()
            ->where('user_id', $userId)
            ->whereIn('status', [LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_APPROVED])
            ->where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate);

        if (filled($excludeId)) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /**
     * 取得指定日期已通過的請假
     *
     * @param int    $userId
     * @param string $date Y-m-d
     * @return Collection
     */
    public function getApprovedOnDate($userId, $date)
    {
        return LeaveRequest::query()
            ->select(self::LIST_COLUMNS)
            ->where('user_id', $userId)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->get();
    }

    /**
     * 指定日期是否有整天的已通過請假
     *
     * @param int    $userId
     * @param string $date
     * @return bool
     */
    public function hasApprovedFullDayOnDate($userId, $date)
    {
        return LeaveRequest::query()
            ->where('user_id', $userId)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->where('is_full_day', 1)
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->exists();
    }

    /**
     * 某段期間內「哪一天有誰請整天假」
     *
     * ⚠ 給**一次撈完、在 PHP 裡比對**的呼叫端用，取代在迴圈裡逐人逐日
     * 呼叫 `hasApprovedFullDayOnDate()` —— 那是標準的 N+1：
     * 曠工標記每天要問一輪排班的人，一次性清理指令更是要問過每一筆曠工紀錄。
     *
     * 回傳的是 `['2026-10-08' => [3, 7], ...]`，用 `in_array()` 查就好。
     *
     * ⚠ 一筆假會跨好幾天，所以要**逐日展開**；但只展開跟查詢區間有交集的那幾天，
     * 不然一筆橫跨半年的假會灌爆這個陣列。
     *
     * @param string $startDate Y-m-d
     * @param string $endDate   Y-m-d（含當天）
     * @return array 日期 => user_id 陣列
     */
    public function fullDayLeaveMapByDateRange($startDate, $endDate)
    {
        $leaves = LeaveRequest::query()
            ->select(['user_id', 'start_date', 'end_date'])
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->where('is_full_day', 1)
            ->where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate)
            ->get();

        $map = [];

        foreach ($leaves as $leave) {
            $from = max(strtotime($startDate), strtotime($leave->start_date->format('Y-m-d')));
            $to = min(strtotime($endDate), strtotime($leave->end_date->format('Y-m-d')));

            for ($day = $from; $day <= $to; $day += 86400) {
                $map[date('Y-m-d', $day)][] = (int) $leave->user_id;
            }
        }

        return $map;
    }

    /**
     * 取得某段期間已通過的請假（全部員工）
     *
     * @param string $startDate Y-m-d
     * @param string $endDate   Y-m-d（含當天）
     * @return Collection
     */
    public function getApprovedByDateRange($startDate, $endDate)
    {
        /*
         * 「有重疊」而不是「落在區間內」：一筆 10/28～11/03 的假，
         * 在十月的報表與十一月的報表都該被算到（各算自己那幾天）。
         * 呼叫端負責取交集。
         */
        return LeaveRequest::query()
            ->select(self::LIST_COLUMNS)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate)
            ->get();
    }

    /**
     * 即將開始或進行中的請假（請假預告用）
     *
     * 「開始日往前推 N 天 ≤ 今天 ≤ 結束日」—— 也就是請假前 N 天開始預告，
     * 一路報到請假結束那天。
     *
     * @param string $date      今天 Y-m-d
     * @param int    $noticeDays 提前幾天開始報
     * @return Collection
     */
    public function getUpcomingApproved($date, $noticeDays)
    {
        return LeaveRequest::query()
            ->select(self::LIST_COLUMNS)
            ->with('user:id,nickname')
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('start_date', '<=', date('Y-m-d', strtotime("{$date} +{$noticeDays} days")))
            ->whereDate('end_date', '>=', $date)
            ->orderBy('start_date')
            ->get();
    }
}
