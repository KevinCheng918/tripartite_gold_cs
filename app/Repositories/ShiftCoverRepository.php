<?php

namespace App\Repositories;

use App\Models\ShiftCover;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * 代班紀錄 Repository
 *
 * 負責 shift_covers 表的所有 DB 操作。
 */
class ShiftCoverRepository
{
    /** @var array 列表查詢欄位 */
    private const LIST_COLUMNS = [
        'id', 'assignment_id', 'requester_id', 'cover_user_id',
        'cover_start', 'cover_end', 'reason',
        'cover_user_status', 'admin_status', 'admin_id',
        'cover_user_responded_at', 'admin_responded_at', 'created_at',
    ];

    /** @var array 預載關聯 */
    private const WITH_RELATIONS = [
        'requester', 'coverUser', 'admin',
        'assignment.shift', 'assignment.user',
    ];

    /**
     * 依 ID 查詢代班紀錄
     *
     * @param int $id
     * @return ShiftCover|null
     */
    public function find($id)
    {
        return ShiftCover::query()
            ->select(self::LIST_COLUMNS)
            ->with(self::WITH_RELATIONS)
            ->find($id);
    }

    /**
     * 新增代班紀錄
     *
     * @param array $attributes
     * @return ShiftCover
     */
    public function create($attributes)
    {
        return ShiftCover::query()->create($attributes);
    }

    /**
     * 更新代班紀錄
     *
     * @param ShiftCover $cover
     * @param array      $attributes
     * @return ShiftCover
     */
    public function update(ShiftCover $cover, $attributes)
    {
        $cover->update($attributes);

        return $cover;
    }

    /**
     * 查詢與指定員工相關的代班紀錄（發起或被請求）
     *
     * @param int $userId
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function paginateByUser($userId, $perPage = 20)
    {
        return ShiftCover::query()
            ->select(self::LIST_COLUMNS)
            ->with(self::WITH_RELATIONS)
            ->where(function ($q) use ($userId) {
                $q->where('requester_id', $userId)
                    ->orWhere('cover_user_id', $userId);
            })
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * 查詢所有待管理者審核的代班紀錄
     *
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function paginatePendingAdmin($perPage = 20)
    {
        return ShiftCover::query()
            ->select(self::LIST_COLUMNS)
            ->with(self::WITH_RELATIONS)
            ->where('cover_user_status', ShiftCover::STATUS_APPROVED)
            ->where('admin_status', ShiftCover::STATUS_PENDING)
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * 查詢指定日期範圍內已核准的代班紀錄（不分頁，課表用）
     *
     * @param string $dateFrom
     * @param string $dateTo
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getApprovedByDateRange($dateFrom, $dateTo)
    {
        return ShiftCover::query()
            ->select(self::LIST_COLUMNS)
            ->with(['requester', 'coverUser', 'assignment'])
            ->where('cover_user_status', ShiftCover::STATUS_APPROVED)
            ->where('admin_status', ShiftCover::STATUS_APPROVED)
            ->whereHas('assignment', function ($q) use ($dateFrom, $dateTo) {
                $q->whereBetween('date', [$dateFrom, $dateTo]);
            })
            ->get();
    }

    /**
     * 查詢所有代班紀錄（分頁）
     *
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function paginateAll($perPage = 20)
    {
        return ShiftCover::query()
            ->select(self::LIST_COLUMNS)
            ->with(self::WITH_RELATIONS)
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
