<?php

namespace App\Repositories;

use App\Models\Shift;
use Illuminate\Database\Eloquent\Collection;

/**
 * 班別 Repository
 *
 * 負責 shifts 表的所有 DB 操作。
 */
class ShiftRepository
{
    /** @var array 列表查詢欄位 */
    private const LIST_COLUMNS = ['id', 'name', 'display_name', 'start_time', 'end_time', 'is_active', 'sort'];

    /**
     * 取得所有啟用中的班別（依 sort 排序）
     *
     * @return Collection
     */
    public function allActive()
    {
        return Shift::query()
            ->select(self::LIST_COLUMNS)
            ->where('is_active', true)
            ->orderBy('sort')
            ->get();
    }

    /**
     * 取得所有班別（含停用）
     *
     * @return Collection
     */
    public function all()
    {
        return Shift::query()
            ->select(self::LIST_COLUMNS)
            ->orderBy('sort')
            ->get();
    }

    /**
     * 依 ID 查詢班別
     *
     * @param int $id
     * @return Shift|null
     */
    public function find($id)
    {
        return Shift::query()->select(self::LIST_COLUMNS)->find($id);
    }

    /**
     * 新增班別
     *
     * @param array $attributes
     * @return Shift
     */
    public function create($attributes)
    {
        return Shift::query()->create($attributes);
    }

    /**
     * 更新班別
     *
     * @param Shift $shift
     * @param array $attributes
     * @return Shift
     */
    public function update(Shift $shift, $attributes)
    {
        $shift->update($attributes);

        return $shift;
    }
}
