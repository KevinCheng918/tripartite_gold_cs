<?php

namespace App\Repositories;

use App\Models\StationCreditLog;
use Illuminate\Database\Eloquent\Collection;

/**
 * 站台餘點同步紀錄
 */
class StationCreditLogRepository
{
    /**
     * 報表要用的欄位
     *
     * ⚠ 不取 `updated_at`：這張表只寫不改，那一欄永遠等於 `created_at`。
     *
     * @var array<string>
     */
    private const REPORT_COLUMNS = ['id', 'station_id', 'credits', 'source', 'created_at'];

    /**
     * 記一筆
     *
     * @param array $attributes
     * @return StationCreditLog
     */
    public function create(array $attributes)
    {
        return StationCreditLog::query()->create($attributes);
    }

    /**
     * 某站台某區間的紀錄（舊到新）
     *
     * ⚠ **一定要 `orderBy('created_at')`**：曲線圖是照陣列順序連線的，
     * 沒排序的話線會在圖上來回亂跳。
     *
     * @param int    $stationId
     * @param string $from Y-m-d H:i:s
     * @param string $to   Y-m-d H:i:s
     * @return Collection
     */
    public function getByStationAndRange($stationId, $from, $to)
    {
        return StationCreditLog::query()
            ->select(self::REPORT_COLUMNS)
            ->where('station_id', $stationId)
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->get();
    }

    /**
     * 某站台最後一筆（還沒有任何紀錄時回 null）
     *
     * @param int $stationId
     * @return StationCreditLog|null
     */
    public function latestByStation($stationId)
    {
        return StationCreditLog::query()
            ->select(self::REPORT_COLUMNS)
            ->where('station_id', $stationId)
            ->orderByDesc('created_at')
            ->first();
    }
}
