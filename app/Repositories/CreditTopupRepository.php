<?php

namespace App\Repositories;

use App\Models\CreditTopup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;

/**
 * 站台補點/扣點 Repository
 */
class CreditTopupRepository
{
    /** @var array 查詢欄位 */
    private const COLUMNS = [
        'id', 'station_id', 'action_type', 'credit_type', 'input_type',
        'usdt_amount', 'exchange_rate', 'credit_amount',
        'status', 'api_response', 'requested_by', 'reviewed_by',
        'reviewed_at', 'note', 'images', 'created_at',
    ];

    /**
     * 這個站台有幾筆還沒審核的補點單
     *
     * 餘點告警用：客戶已經申請補點了就不該再催他，
     * 該催的是我們自己去審核（見 StationCreditAlertService）。
     *
     * @param int $stationId
     * @return int
     */
    public function countPendingByStation($stationId)
    {
        return CreditTopup::query()
            ->where('station_id', (int) $stationId)
            ->where('status', CreditTopup::STATUS_PENDING)
            ->count();
    }

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

        if (filled(Arr::get($filters, 'system_id'))) {
            $systemId = (int) Arr::get($filters, 'system_id');
            $query->whereHas('station', function ($q) use ($systemId) {
                $q->where('system_id', $systemId);
            });
        }

        if (filled(Arr::get($filters, 'station_id'))) {
            $query->where('station_id', (int) Arr::get($filters, 'station_id'));
        }

        /*
         * 這裡原本的註解寫「status 為 0 時 filled() 會回 false」——
         * 那是反過來的：`blank()` 對數字與布林一律回 false，所以
         * **filled(0) 與 filled('0') 都是 true**，待審核（status=0）本來就篩得到。
         *
         * 真正要擋的是「沒帶」與「空字串」，filled() 兩個都擋得住。
         */
        if (filled(Arr::get($filters, 'status'))) {
            $query->where('status', (int) Arr::get($filters, 'status'));
        }

        if (filled(Arr::get($filters, 'requested_by'))) {
            $query->where('requested_by', (int) Arr::get($filters, 'requested_by'));
        }

        if (filled(Arr::get($filters, 'date_from'))) {
            $query->where('created_at', '>=', Arr::get($filters, 'date_from') . ' 00:00:00');
        }

        if (filled(Arr::get($filters, 'date_to'))) {
            $query->where('created_at', '<=', Arr::get($filters, 'date_to') . ' 23:59:59');
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
