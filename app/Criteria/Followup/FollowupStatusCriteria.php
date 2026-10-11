<?php

namespace App\Criteria\Followup;

use App\Criteria\CriteriaInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * 依狀態篩選待追蹤事項
 *
 * 吃一個或多個狀態 —— 列表預設只看「還活著的」（等待中＋已追問），
 * 那是兩個狀態，所以不能只收單一個值。
 */
class FollowupStatusCriteria implements CriteriaInterface
{
    /** @var array<int> 見 constants.FOLLOWUP.STATUS */
    private $statuses;

    /**
     * @param array<int>|int $statuses
     */
    public function __construct($statuses)
    {
        $this->statuses = array_map('intval', (array) $statuses);
    }

    /**
     * @param Builder $query
     * @return Builder
     */
    public function apply(Builder $query)
    {
        return $query->whereIn('status', $this->statuses);
    }
}
