<?php

namespace App\Repositories;

use App\Models\StaffDmWipeLog;
use Illuminate\Database\Eloquent\Collection;

/**
 * 私訊清除稽核 Repository
 *
 * ⚠⚠ **刻意只有「寫入」與「讀取」，沒有任何刪除方法。**
 * 可以被清掉的稽核等於沒有稽核，而這個功能本身就是「清掉紀錄」——
 * 留一個 delete 在這裡，遲早會有人（或某個 TTL 排程）順手用掉。
 */
class StaffDmWipeLogRepository
{
    /** @var array */
    private const COLUMNS = [
        'id',
        'operator_user_id',
        'operator_name',
        'target_user_id',
        'target_name',
        'source',
        'cleared_count',
        'expired_count',
        'failed_count',
        'created_at',
    ];

    /**
     * 記一筆
     *
     * @param array $attributes
     * @return StaffDmWipeLog
     */
    public function create(array $attributes)
    {
        return StaffDmWipeLog::query()->create($attributes);
    }

    /**
     * 最近的清除紀錄（設定頁顯示）
     *
     * @param int $limit
     * @return Collection|StaffDmWipeLog[]
     */
    public function getRecent($limit)
    {
        return StaffDmWipeLog::query()
            ->select(self::COLUMNS)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
