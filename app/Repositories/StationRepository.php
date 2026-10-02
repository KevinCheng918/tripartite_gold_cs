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
    private const LIST_COLUMNS = ['id', 'system_id', 'name', 'domain', 'api_url', 'api_key', 'credits', 'credit_alert_threshold', 'settings', 'telegram_group_id', 'status', 'note', 'synced_at', 'credit_alerted_at', 'created_at'];

    /**
     * 餘點同步要用的欄位
     *
     * ⚠ `credits` 一定要帶 —— `StationService::syncInfo()` 在 API 沒回
     * `admin_credit` 時會退回舊值（`$info['admin_credit'] ?? $station->credits`），
     * 少了這欄會把站台的點數寫成 null。
     *
     * `settings` 不帶：syncInfo 只覆寫不讀它，撈整包 JSON 是白費流量。
     */
    private const CREDIT_SYNC_COLUMNS = ['id', 'system_id', 'name', 'api_url', 'api_key', 'credits', 'credit_alert_threshold', 'telegram_group_id', 'credit_alerted_at'];

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
     * 取得要同步餘點的站台
     *
     * 只撈啟用中、且 api_url / api_key 都有值的 —— 少了任一個
     * `MainSystemApiService::getStationInfo()` 會直接回 null，撈出來只是白跑一趟。
     *
     * 刻意**不**篩 telegram_group_id：沒設群組的站台照樣要同步，
     * 告警會退到內部支援群組讓客服手動通知。
     *
     * @param int|null $stationId 只跑單一站台（Command 的 --station）
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getForCreditSync($stationId = null)
    {
        $query = Station::query()
            ->select(self::CREDIT_SYNC_COLUMNS)
            ->where('status', config('constants.STATION.STATUS.ACTIVE'))
            ->whereNotNull('api_url')
            ->where('api_url', '!=', '')
            ->whereNotNull('api_key')
            ->where('api_key', '!=', '');

        if (filled($stationId)) {
            $query->where('id', (int) $stationId);
        }

        return $query->orderBy('id')->get();
    }

    /**
     * 啟用中、但因為沒設 API 而同步不到餘點的站台
     *
     * `getForCreditSync()` 是在 SQL 的 WHERE 就把這些篩掉的 —— 不是進了迴圈
     * 才跳過，所以它們不會出現在處理結果裡，也不會有任何 log。
     *
     * 問題是「啟用中卻檢查不到」通常不是刻意的（api_key 被清空、貼錯），
     * 而餘點用完照樣會停用客戶後台。靜默漏掉比漏報更糟，所以單獨撈出來記一筆。
     *
     * 只看 status=1：停用／凍結的站台本來就不該檢查，那是刻意的狀態。
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getMissingApiForCreditSync()
    {
        return Station::query()
            ->select(['id', 'name', 'api_url', 'api_key'])
            ->where('status', config('constants.STATION.STATUS.ACTIVE'))
            ->where(function ($query) {
                // 四個條件都是 or，包在 closure 裡才不會把 status 條件也一起 or 掉
                $query->whereNull('api_url')
                    ->orWhere('api_url', '')
                    ->orWhereNull('api_key')
                    ->orWhere('api_key', '');
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * 依 Telegram 群組反查站台
     *
     * 客人在對話裡問匯率時用的：手上只有 `telegram_group_id`，要從它找到
     * 站台所屬的系統，才知道該用哪一筆繳款設定組補點訊息。
     *
     * 只取組訊息需要的欄位。一個群組理論上只對一個站台
     * （`telegram_group_id` 是站台身上的欄位），多對一時取第一筆。
     *
     * @param int $groupId
     * @return Station|null
     */
    public function findByTelegramGroupId($groupId)
    {
        return Station::query()
            ->select(['id', 'system_id', 'name', 'telegram_group_id'])
            ->where('telegram_group_id', (int) $groupId)
            ->first();
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

    /**
     * 取得所有站台名稱（下拉選單用）
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function allForDropdown()
    {
        return Station::query()
            ->select(['id', 'system_id', 'name'])
            ->with(['system:id,name'])
            ->orderBy('name')
            ->get();
    }

    // ---------------------------------------------------------------
    //  系統（System）
    // ---------------------------------------------------------------

    /**
     * 取得所有啟用中的系統
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    /**
     * 統計各系統正常狀態站台數量
     *
     * @return \Illuminate\Support\Collection
     */
    /**
     * 統計各系統站台數量（按狀態分組）
     *
     * @return array ['by_system' => [...], 'by_status' => [...], 'total' => N]
     */
    public function countActiveBySystem()
    {
        $all = Station::query()
            ->selectRaw('system_id, status, COUNT(*) as count')
            ->groupBy('system_id', 'status')
            ->with(['system'])
            ->get();

        $activeStatus = config('constants.STATION.STATUS.ACTIVE');

        $frozenStatus = config('constants.STATION.STATUS.FROZEN');
        $disabledStatus = config('constants.STATION.STATUS.DISABLED');

        // 按系統（含正常/凍結/關閉）
        $bySystem = $all->groupBy('system_id')
            ->map(function ($rows) use ($activeStatus, $frozenStatus, $disabledStatus) {
                $first = $rows->first();
                $active = $rows->where('status', $activeStatus)->sum('count');
                $frozen = $rows->where('status', $frozenStatus)->sum('count');
                $disabled = $rows->where('status', $disabledStatus)->sum('count');
                return [
                    'system_id' => $first->system_id,
                    'name'      => $first->system ? $first->system->name : '未分類',
                    'active'    => $active,
                    'frozen'   => $frozen,
                    'disabled' => $disabled,
                    'total'    => $active + $frozen + $disabled,
                ];
            })->sortBy('system_id')->values();

        // 按狀態
        $activeCount = $all->where('status', config('constants.STATION.STATUS.ACTIVE'))->sum('count');
        $frozenCount = $all->where('status', config('constants.STATION.STATUS.FROZEN'))->sum('count');
        $disabledCount = $all->where('status', config('constants.STATION.STATUS.DISABLED'))->sum('count');
        $total = $all->sum('count');

        return [
            'by_system'  => $bySystem,
            'active'     => $activeCount,
            'frozen'     => $frozenCount,
            'disabled'   => $disabledCount,
            'total'      => $total,
        ];
    }

    public function getActiveSystems()
    {
        return System::query()
            ->select(['id', 'name', 'bot_token'])
            ->where('status', 1)
            ->orderBy('id')
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
