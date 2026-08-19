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
     * 取得指定月份已通過的請假（全部員工）
     *
     * @param string $yearMonth Y-m
     * @return Collection
     */
    public function getApprovedByMonth($yearMonth)
    {
        $monthStart = "{$yearMonth}-01";
        $monthEnd = date('Y-m-t', strtotime($monthStart));

        return LeaveRequest::query()
            ->select(self::LIST_COLUMNS)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->where('start_date', '<=', $monthEnd)
            ->where('end_date', '>=', $monthStart)
            ->get();
    }
}
