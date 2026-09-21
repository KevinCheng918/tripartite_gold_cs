<?php

namespace App\Repositories;

use App\Models\AppSetting;
use Illuminate\Database\Eloquent\Collection;

/**
 * 全域設定 Repository
 */
class AppSettingRepository
{
    /** @var array 設定列表欄位 */
    private const COLUMNS = ['id', 'key', 'value', 'is_secret', 'updated_by', 'updated_at'];

    /**
     * 取得全部設定，以 key 為索引
     *
     * @return Collection
     */
    public function getAll()
    {
        return AppSetting::query()
            ->select(self::COLUMNS)
            ->get()
            ->keyBy('key');
    }

    /**
     * 依 key 取單筆
     *
     * @param string $key
     * @return AppSetting|null
     */
    public function findByKey($key)
    {
        return AppSetting::query()
            ->select(self::COLUMNS)
            ->where('key', $key)
            ->first();
    }

    /**
     * 取多筆（設定頁一次讀一整區）
     *
     * @param array $keys
     * @return Collection
     */
    public function getByKeys($keys)
    {
        return AppSetting::query()
            ->select(self::COLUMNS)
            ->whereIn('key', $keys)
            ->get()
            ->keyBy('key');
    }

    /**
     * 寫入設定（存在就更新，不存在就新增）
     *
     * @param string      $key
     * @param string|null $value    已處理好的值（敏感值請先加密）
     * @param bool        $isSecret
     * @param int|null    $userId   操作者
     * @return AppSetting
     */
    public function put($key, $value, $isSecret = false, $userId = null)
    {
        return AppSetting::query()->updateOrCreate(
            ['key' => $key],
            [
                'value'      => $value,
                'is_secret'  => $isSecret,
                'updated_by' => $userId,
            ]
        );
    }

    /**
     * 刪除設定
     *
     * @param string $key
     * @return void
     */
    public function forget($key)
    {
        AppSetting::query()->where('key', $key)->delete();
    }
}
