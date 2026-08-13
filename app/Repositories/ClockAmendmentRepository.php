<?php

namespace App\Repositories;

use App\Models\ClockAmendment;
use Illuminate\Database\Eloquent\Collection;

/**
 * 補打卡申請 Repository
 */
class ClockAmendmentRepository
{
    private const COLUMNS = [
        'id', 'user_id', 'date', 'type', 'clock_time',
        'reason', 'status', 'reviewed_by', 'reviewed_at', 'created_at',
    ];

    /**
     * 查詢待審核列表
     *
     * @return Collection
     */
    public function getPending()
    {
        return ClockAmendment::query()
            ->select(self::COLUMNS)
            ->with(['user', 'reviewer'])
            ->where('status', ClockAmendment::STATUS_PENDING)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * 查詢所有申請（含已審核）
     *
     * @return Collection
     */
    public function getAll()
    {
        return ClockAmendment::query()
            ->select(self::COLUMNS)
            ->with(['user', 'reviewer'])
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * 查詢個人申請紀錄
     *
     * @param int $userId
     * @return Collection
     */
    public function getByUser($userId)
    {
        return ClockAmendment::query()
            ->select(self::COLUMNS)
            ->with(['reviewer'])
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * 依 ID 查詢
     *
     * @param int $id
     * @return ClockAmendment|null
     */
    public function find($id)
    {
        return ClockAmendment::query()
            ->select(self::COLUMNS)
            ->with(['user'])
            ->find($id);
    }

    /**
     * 新增申請
     *
     * @param array $attributes
     * @return ClockAmendment
     */
    public function create($attributes)
    {
        return ClockAmendment::query()->create($attributes);
    }

    /**
     * 更新申請
     *
     * @param ClockAmendment $amendment
     * @param array          $attributes
     * @return ClockAmendment
     */
    public function update(ClockAmendment $amendment, $attributes)
    {
        $amendment->update($attributes);

        return $amendment->refresh();
    }

    /**
     * 檢查是否有重複的待審核申請
     *
     * @param int    $userId
     * @param string $date
     * @param int    $type
     * @return bool
     */
    /**
     * 查詢指定月份所有已通過的補打卡（按 user_id 分組計數）
     *
     * @param string $yearMonth Y-m
     * @return \Illuminate\Support\Collection
     */
    public function getApprovedCountByMonth($yearMonth)
    {
        return ClockAmendment::query()
            ->selectRaw('user_id, COUNT(*) as count')
            ->where('status', ClockAmendment::STATUS_APPROVED)
            ->where('date', 'like', "{$yearMonth}%")
            ->groupBy('user_id')
            ->pluck('count', 'user_id');
    }

    /**
     * 查詢指定員工指定月份的已通過補打卡紀錄
     *
     * @param int    $userId
     * @param string $yearMonth
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getApprovedByUserAndMonth($userId, $yearMonth)
    {
        return ClockAmendment::query()
            ->select(['id', 'user_id', 'date', 'type', 'clock_time', 'status'])
            ->where('user_id', $userId)
            ->where('status', ClockAmendment::STATUS_APPROVED)
            ->where('date', 'like', "{$yearMonth}%")
            ->get();
    }

    public function hasPending($userId, $date, $type)
    {
        return ClockAmendment::query()
            ->where('user_id', $userId)
            ->where('date', $date)
            ->where('type', $type)
            ->where('status', ClockAmendment::STATUS_PENDING)
            ->exists();
    }
}
