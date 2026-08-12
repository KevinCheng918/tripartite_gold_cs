<?php

namespace App\Repositories;

use App\Models\Station;
use App\Models\System;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * 站台 Repository
 */
class StationRepository
{
    /** @var array 列表查詢欄位 */
    private const LIST_COLUMNS = ['id', 'system_id', 'name', 'domain', 'api_url', 'api_key', 'credits', 'settings', 'telegram_group_id', 'status', 'note', 'synced_at', 'created_at'];

    /**
     * 分頁查詢
     *
     * @param array $criteria 篩選條件
     * @param int   $perPage
     * @return LengthAwarePaginator
     */
    public function paginate($criteria = [], $perPage = 20)
    {
        $query = Station::query()
            ->select(self::LIST_COLUMNS)
            ->with(['system', 'telegramGroup']);

        // 關鍵字（名稱或域名）
        if (filled($criteria['keyword'] ?? null)) {
            $keyword = $criteria['keyword'];
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                    ->orWhere('domain', 'like', "%{$keyword}%");
            });
        }

        // 域名
        if (filled($criteria['domain'] ?? null)) {
            $query->where('domain', 'like', "%{$criteria['domain']}%");
        }

        // 系統
        if (filled($criteria['system_id'] ?? null)) {
            $query->where('system_id', (int) $criteria['system_id']);
        }

        // 狀態
        if (filled($criteria['status'] ?? null) || ($criteria['status'] ?? null) === '0' || ($criteria['status'] ?? null) === 0) {
            $query->where('status', (int) $criteria['status']);
        }

        // 點數範圍
        if (filled($criteria['credits_min'] ?? null)) {
            $query->where('credits', '>=', (float) $criteria['credits_min']);
        }
        if (filled($criteria['credits_max'] ?? null)) {
            $query->where('credits', '<=', (float) $criteria['credits_max']);
        }

        // 商城狀態（JSON 欄位）
        if (filled($criteria['support_shop'] ?? null)) {
            $query->whereRaw("JSON_EXTRACT(settings, '$.support_shop') = ?", [$criteria['support_shop'] === 'true' ? 'true' : 'false']);
        }

        // 跑分員狀態（JSON 欄位）
        if (filled($criteria['score_runner'] ?? null)) {
            $query->whereRaw("JSON_EXTRACT(settings, '$.score_runner') = ?", [$criteria['score_runner'] === 'true' ? 'true' : 'false']);
        }

        return $query->orderByDesc('id')->paginate($perPage);
    }

    /**
     * 取得所有啟用中的站台（有 Telegram 群組的）
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getActiveWithTelegram()
    {
        return Station::query()
            ->select(['id', 'system_id', 'name', 'domain', 'telegram_group_id'])
            ->with(['system', 'telegramGroup'])
            ->where('status', config('constants.STATION.STATUS.ACTIVE'))
            ->whereNotNull('telegram_group_id')
            ->orderBy('name')
            ->get();
    }

    /**
     * 依 ID 查詢
     *
     * @param int $id
     * @return Station|null
     */
    public function find($id)
    {
        return Station::query()
            ->select(self::LIST_COLUMNS)
            ->with(['system', 'telegramGroup'])
            ->find($id);
    }

    /**
     * 新增
     *
     * @param array $attributes
     * @return Station
     */
    public function create($attributes)
    {
        return Station::query()->create($attributes);
    }

    /**
     * 更新
     *
     * @param Station $station
     * @param array   $attributes
     * @return Station
     */
    public function update(Station $station, $attributes)
    {
        $station->update($attributes);

        return $station;
    }

    // ---------------------------------------------------------------
    //  系統（System）
    // ---------------------------------------------------------------

    /**
     * 取得所有啟用中的系統
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getActiveSystems()
    {
        return System::query()
            ->select(['id', 'name'])
            ->where('status', 1)
            ->orderBy('name')
            ->get();
    }

    /**
     * 新增系統
     *
     * @param array $attributes
     * @return System
     */
    public function createSystem($attributes)
    {
        return System::query()->create($attributes);
    }

    /**
     * 更新系統
     *
     * @param System $system
     * @param array  $attributes
     * @return System
     */
    public function updateSystem(System $system, $attributes)
    {
        $system->update($attributes);

        return $system->refresh();
    }
}
