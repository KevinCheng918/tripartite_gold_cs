<?php

namespace App\Criteria\Followup;

use App\Criteria\CriteriaInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * 依開單日期篩選待追蹤事項
 *
 * ⚠ 比的是 `created_at`（這筆是什麼時候開的），不是 `wait_at`
 * （最後一次重新計時）。「上週有幾筆沒人處理」問的是前者 ——
 * 後者會因為對方今天又說了一句話而跳到今天，那筆就從上週的清單裡消失了。
 *
 * ⚠ 用區間而不是 `whereDate()`：後者等於 `DATE(created_at) = ?`，
 * 欄位被函式包住就吃不到索引。比照 `AutoReplyTicketRemindRepository::rangeOf()`。
 */
class FollowupDateRangeCriteria implements CriteriaInterface
{
    /** @var string Y-m-d */
    private $startDate;

    /** @var string Y-m-d */
    private $endDate;

    /**
     * @param string $startDate Y-m-d
     * @param string $endDate   Y-m-d（含當天）
     */
    public function __construct($startDate, $endDate)
    {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
    }

    /**
     * @param Builder $query
     * @return Builder
     */
    public function apply(Builder $query)
    {
        return $query->whereBetween('created_at', [
            "{$this->startDate} 00:00:00",
            "{$this->endDate} 23:59:59",
        ]);
    }
}
