<?php

namespace App\Repositories;

use App\Models\AutoReplyTicketEscalation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * 求助單升級紀錄 Repository
 *
 * 寫入是「一次升級動作 → 每個對象一列」；讀取只有一種：
 * 「這幾張單各自升級到哪了」—— 排程靠它決定下一步是撥號、私訊、還是停。
 */
class AutoReplyTicketEscalationRepository
{
    /** @var array 欄位 */
    private const COLUMNS = [
        'id', 'auto_reply_ticket_id', 'stage', 'seq', 'attempt',
        'user_id', 'user_name', 'result', 'detail', 'created_at',
    ];

    /**
     * 記一次升級動作
     *
     * ⚠ 一次動作會寫多列（撥給／私訊給幾個人就幾列），所以用 `insert` 批次寫
     * —— 逐列 `create()` 在一次通知五個人時就是五趟來回。比照
     * `AutoReplyTicketRemindRepository::record()`。
     *
     * ⚠ `insert` 不經過 Model，timestamps 要自己帶。
     *
     * @param array<int, array> $rows
     * @return void
     */
    public function record(array $rows)
    {
        if (blank($rows)) {
            return;
        }

        $now = now();

        foreach ($rows as $index => $row) {
            $rows[$index] = array_merge($row, ['created_at' => $now, 'updated_at' => $now]);
        }

        AutoReplyTicketEscalation::query()->insert($rows);
    }

    /**
     * 這幾張單的升級紀錄（整輪只查一次）
     *
     * ⚠ **存在的理由是消 N+1。** 排程每分鐘跑，同時有好幾張單在升級中是常態
     * —— 逐張去查紀錄就是那個張數的查詢。比照
     * `CustomerFollowupService::askOnDuty()` 的「整輪只查一次」。
     *
     * @param array<int> $ticketIds
     * @return array<int, Collection> key = auto_reply_ticket_id
     */
    public function getByTicketIds(array $ticketIds)
    {
        if (blank($ticketIds)) {
            return [];
        }

        return AutoReplyTicketEscalation::query()
            ->select(self::COLUMNS)
            ->whereIn('auto_reply_ticket_id', $ticketIds)
            ->orderBy('id')
            ->get()
            ->groupBy('auto_reply_ticket_id')
            ->all();
    }

    /**
     * 某段期間的升級紀錄（統計用）
     *
     * ⚠ 用區間而不是 `whereDate()`：後者等於 `DATE(created_at) = ?`，
     * 欄位被函式包住就吃不到索引。比照
     * `AutoReplyTicketRemindRepository::rangeOf()`。
     *
     * @param string $startDate Y-m-d
     * @param string $endDate   Y-m-d（含當天）
     * @return Collection
     */
    public function getForRange($startDate, $endDate)
    {
        return AutoReplyTicketEscalation::query()
            ->select(self::COLUMNS)
            ->with(['ticket', 'user'])
            ->whereBetween('created_at', [
                Carbon::parse($startDate)->startOfDay(),
                Carbon::parse($endDate)->endOfDay(),
            ])
            ->orderBy('id')
            ->get();
    }
}
