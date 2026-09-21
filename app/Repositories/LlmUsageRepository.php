<?php

namespace App\Repositories;

use App\Models\LlmUsageLog;
use Illuminate\Database\Eloquent\Collection;

/**
 * LLM 呼叫紀錄 Repository
 */
class LlmUsageRepository
{
    /** @var array 明細欄位 */
    private const COLUMNS = [
        'id', 'used_on', 'telegram_group_id', 'source', 'model',
        'duration_ms', 'is_error', 'is_rate_limited',
        'input_tokens', 'output_tokens', 'created_at',
    ];

    /**
     * 新增一筆呼叫紀錄
     *
     * @param array $attributes
     * @return LlmUsageLog
     */
    public function create($attributes)
    {
        return LlmUsageLog::query()->create($attributes);
    }

    /**
     * 依來源統計區間內的用量
     *
     * 用 selectRaw 做聚合，不撈明細 —— 統計頁只需要加總結果。
     *
     * @param string $from Y-m-d
     * @param string $to   Y-m-d
     * @return Collection 每筆含 source / calls / errors / rate_limited / input_tokens / output_tokens
     */
    public function summarizeBySource($from, $to)
    {
        return LlmUsageLog::query()
            ->selectRaw('source')
            ->selectRaw('COUNT(*) as calls')
            ->selectRaw('SUM(is_error) as errors')
            ->selectRaw('SUM(is_rate_limited) as rate_limited')
            ->selectRaw('SUM(input_tokens) as input_tokens')
            ->selectRaw('SUM(output_tokens) as output_tokens')
            ->selectRaw('AVG(duration_ms) as avg_duration_ms')
            ->whereBetween('used_on', [$from, $to])
            ->groupBy('source')
            ->get();
    }

    /**
     * 依日期統計（近 N 天走勢用）
     *
     * @param string $from Y-m-d
     * @param string $to   Y-m-d
     * @return Collection 每筆含 used_on / source / calls / rate_limited
     */
    public function summarizeByDate($from, $to)
    {
        return LlmUsageLog::query()
            ->selectRaw('used_on')
            ->selectRaw('source')
            ->selectRaw('COUNT(*) as calls')
            ->selectRaw('SUM(is_rate_limited) as rate_limited')
            ->selectRaw('SUM(input_tokens) as input_tokens')
            ->selectRaw('SUM(output_tokens) as output_tokens')
            ->whereBetween('used_on', [$from, $to])
            ->groupBy('used_on', 'source')
            ->orderByDesc('used_on')
            ->get();
    }

    /**
     * 某日某來源的呼叫次數（備援每日上限檢查用）
     *
     * @param string $date   Y-m-d
     * @param int    $source 見 constants.AUTO_REPLY.SOURCE
     * @return int
     */
    public function countByDateAndSource($date, $source)
    {
        return LlmUsageLog::query()
            ->where('used_on', $date)
            ->where('source', $source)
            ->count();
    }

    /**
     * 刪除指定天數之前的紀錄（保留期清理用）
     *
     * @param int $days
     * @return int 刪除筆數
     */
    public function deleteOlderThan($days)
    {
        return LlmUsageLog::query()
            ->where('used_on', '<', now()->subDays($days)->format('Y-m-d'))
            ->delete();
    }

    /**
     * 最近的明細（設定頁查問題用）
     *
     * @param int $limit
     * @return Collection
     */
    public function getRecent($limit = 50)
    {
        return LlmUsageLog::query()
            ->select(self::COLUMNS)
            ->with('group')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
