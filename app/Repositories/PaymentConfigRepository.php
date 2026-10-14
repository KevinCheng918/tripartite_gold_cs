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
        'id', 'system_id', 'title', 'content', 'template', 'topup_template',
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
     * 全部啟用中的繳款設定（不分系統）
     *
     * 「今天匯率多少」那支指令要一次把每個系統的補點訊息都列出來 ——
     * 不同系統的收款方式不一樣，併成一則會讓人貼錯給客戶。
     *
     * ⚠ 帶 `system` 關聯：回報時每一則前面要標是哪個系統的，
     * 不帶的話就是一則一查（N+1）。
     *
     * @return Collection
     */
    public function getAllActive()
    {
        return PaymentConfig::query()
            ->select(self::COLUMNS)
            ->with(['system'])
            ->where('status', 1)
            ->orderBy('system_id')
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
