<?php

namespace App\Repositories;

use App\Models\CreditTopup;
use Illuminate\Database\Eloquent\Collection;

/**
 * 站台補點/扣點 Repository
 */
class CreditTopupRepository
{
    /** @var array 查詢欄位 */
    private const COLUMNS = [
        'id', 'station_id', 'action_type', 'credit_type',
        'usdt_amount', 'exchange_rate', 'credit_amount',
        'status', 'api_response', 'requested_by', 'reviewed_by',
        'reviewed_at', 'note', 'created_at',
    ];

    /**
     * 查詢所有紀錄
     *
     * @param array $filters 篩選條件
     * @return Collection
     */
    public function all($filters = [])
    {
        $query = CreditTopup::query()
            ->select(self::COLUMNS)
            ->with(['station.system', 'requester', 'reviewer'])
            ->orderByDesc('created_at');

        if (filled($filters['station_id'] ?? null)) {
            $query->where('station_id', (int) $filters['station_id']);
        }

        // status 為 0 時 filled() 會回 false，需用 isset + !== ''
        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('status', (int) $filters['status']);
        }

        return $query->get();
    }

    /**
     * 依 ID 查詢
     *
     * @param int $id
     * @return CreditTopup|null
     */
    public function find($id)
    {
        return CreditTopup::query()
            ->select(self::COLUMNS)
            ->with(['station', 'requester'])
            ->find($id);
    }

    /**
     * 新增
     *
     * @param array $attributes
     * @return CreditTopup
     */
    public function create($attributes)
    {
        return CreditTopup::query()->create($attributes);
    }

    /**
     * 更新
     *
     * @param CreditTopup $topup
     * @param array       $attributes
     * @return CreditTopup
     */
    public function update(CreditTopup $topup, $attributes)
    {
        $topup->update($attributes);

        return $topup->refresh();
    }
}
