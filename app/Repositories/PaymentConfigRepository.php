<?php

namespace App\Repositories;

use App\Models\PaymentConfig;
use Illuminate\Database\Eloquent\Collection;

/**
 * 繳款設定 Repository
 */
class PaymentConfigRepository
{
    private const COLUMNS = [
        'id', 'system_id', 'title', 'content', 'template',
        'image', 'status', 'sort_order', 'created_at',
    ];

    /**
     * 查詢所有繳款設定（含系統名稱）
     *
     * @param int|null $systemId
     * @return Collection
     */
    public function all($systemId = null)
    {
        $query = PaymentConfig::query()
            ->select(self::COLUMNS)
            ->with(['system'])
            ->orderBy('system_id')
            ->orderBy('sort_order');

        if (filled($systemId)) {
            $query->where('system_id', $systemId);
        }

        return $query->get();
    }

    /**
     * 依系統 ID 查詢啟用中的繳款設定
     *
     * @param int $systemId
     * @return Collection
     */
    public function getActiveBySystem($systemId)
    {
        return PaymentConfig::query()
            ->select(self::COLUMNS)
            ->where('system_id', $systemId)
            ->where('status', 1)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * 依 ID 查詢
     *
     * @param int $id
     * @return PaymentConfig|null
     */
    public function find($id)
    {
        return PaymentConfig::query()
            ->select(self::COLUMNS)
            ->with(['system'])
            ->find($id);
    }

    /**
     * 新增
     *
     * @param array $attributes
     * @return PaymentConfig
     */
    public function create($attributes)
    {
        return PaymentConfig::query()->create($attributes);
    }

    /**
     * 更新
     *
     * @param PaymentConfig $config
     * @param array         $attributes
     * @return PaymentConfig
     */
    public function update(PaymentConfig $config, $attributes)
    {
        $config->update($attributes);

        return $config->refresh();
    }

    /**
     * 刪除
     *
     * @param PaymentConfig $config
     * @return bool
     */
    public function delete(PaymentConfig $config)
    {
        return $config->delete();
    }
}
