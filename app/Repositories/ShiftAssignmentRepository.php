<?php

namespace App\Repositories;

use App\Criteria\CriteriaInterface;
use App\Models\ShiftAssignment;
use App\Models\ShiftSwap;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * 排班紀錄 Repository
 *
 * 負責 shift_assignments 與 shift_swaps 表的所有 DB 操作。
 */
class ShiftAssignmentRepository
{
    /** @var array 列表查詢欄位 */
    private const LIST_COLUMNS = ['id', 'user_id', 'shift_id', 'date', 'created_at'];

    /**
     * 依條件分頁查詢排班紀錄
     *
     * @param array $criteria
     * @param int   $perPage
     * @return LengthAwarePaginator
     */
    public function paginate($criteria = [], $perPage = 20)
    {
        $query = ShiftAssignment::query()
            ->select(self::LIST_COLUMNS)
            ->with(['user', 'shift']);

        foreach ($criteria as $criterion) {
            $query = $criterion->apply($query);
        }

        return $query->orderByDesc('date')->orderByDesc('id')->paginate($perPage);
    }

    /**
     * 依條件查詢排班紀錄（不分頁）
     *
     * @param array $criteria
     * @return Collection
     */
    public function getByCriteria($criteria = [])
    {
        $query = ShiftAssignment::query()
            ->select(self::LIST_COLUMNS)
            ->with(['user', 'shift']);

        foreach ($criteria as $criterion) {
            $query = $criterion->apply($query);
        }

        return $query->orderBy('date')->get();
    }

    /**
     * 依 ID 查詢排班紀錄
     *
     * @param int $id
     * @return ShiftAssignment|null
     */
    public function find($id)
    {
        return ShiftAssignment::query()
            ->select(self::LIST_COLUMNS)
            ->with(['user', 'shift'])
            ->find($id);
    }

    /**
     * 檢查員工在該日是否已有排班
     *
     * @param int      $userId
     * @param string   $date      日期（Y-m-d）
     * @param int|null $excludeId 排除的排班 ID（用於換班檢查）
     * @return bool
     */
    public function existsByUserAndDate($userId, $date, $excludeId = null)
    {
        $query = ShiftAssignment::query()
            ->where('user_id', $userId)
            ->where('date', $date);

        if (filled($excludeId)) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /**
     * 檢查員工在該日是否已有同一班別
     *
     * @param int    $userId
     * @param string $date
     * @param int    $shiftId
     * @return bool
     */
    public function existsByUserDateAndShift($userId, $date, $shiftId)
    {
        return ShiftAssignment::query()
            ->where('user_id', $userId)
            ->where('date', $date)
            ->where('shift_id', $shiftId)
            ->exists();
    }

    /**
     * 新增排班紀錄
     *
     * @param array $attributes
     * @return ShiftAssignment
     */
    public function create($attributes)
    {
        return ShiftAssignment::query()->create($attributes);
    }

    /**
     * 更新排班紀錄
     *
     * @param ShiftAssignment $assignment
     * @param array           $attributes
     * @return ShiftAssignment
     */
    public function update(ShiftAssignment $assignment, $attributes)
    {
        $assignment->update($attributes);

        return $assignment;
    }

    /**
     * 依日期範圍查詢排班紀錄
     *
     * @param string $dateFrom
     * @param string $dateTo
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getByDateRange($dateFrom, $dateTo)
    {
        return ShiftAssignment::query()
            ->select(self::LIST_COLUMNS)
            ->with(['user', 'shift'])
            ->whereBetween('date', [$dateFrom, $dateTo])
            ->orderBy('date')
            ->get();
    }

    /**
     * 依員工和日期範圍查詢排班紀錄
     *
     * @param int    $userId
     * @param string $dateFrom
     * @param string $dateTo
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getByUserAndDateRange($userId, $dateFrom, $dateTo)
    {
        return ShiftAssignment::query()
            ->select(self::LIST_COLUMNS)
            ->with(['shift'])
            ->where('user_id', $userId)
            ->whereBetween('date', [$dateFrom, $dateTo])
            ->orderBy('date')
            ->get();
    }

    /**
     * 刪除排班紀錄
     *
     * @param ShiftAssignment $assignment
     * @return bool
     */
    public function delete(ShiftAssignment $assignment)
    {
        return (bool) $assignment->delete();
    }

    /**
     * 新增換班請求
     *
     * @param array $attributes
     * @return ShiftSwap
     */
    public function createSwap($attributes)
    {
        return ShiftSwap::query()->create($attributes);
    }

    /**
     * 依 ID 查詢換班請求（含關聯）
     *
     * @param int $id
     * @return ShiftSwap|null
     */
    public function findSwap($id)
    {
        return ShiftSwap::query()
            ->with(['requester', 'target', 'requesterAssignment.shift', 'targetAssignment.shift'])
            ->find($id);
    }

    /**
     * 查詢所有換班請求（管理者用）
     *
     * @param int $perPage
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginateAllSwaps($perPage = 20)
    {
        return ShiftSwap::query()
            ->with(['requester', 'target', 'requesterAssignment.shift', 'targetAssignment.shift'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * 查詢與指定員工相關的換班請求（發起或被請求）
     *
     * @param int $userId
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function paginateSwapsByUser($userId, $perPage = 20)
    {
        return ShiftSwap::query()
            ->with(['requester', 'target', 'requesterAssignment.shift', 'targetAssignment.shift'])
            ->where(function ($q) use ($userId) {
                $q->where('requester_id', $userId)
                    ->orWhere('target_id', $userId);
            })
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * 更新換班請求狀態
     *
     * @param ShiftSwap $swap
     * @param int       $status
     * @return ShiftSwap
     */
    public function updateSwapStatus(ShiftSwap $swap, $status)
    {
        $swap->update(['status' => $status]);

        return $swap;
    }
}
