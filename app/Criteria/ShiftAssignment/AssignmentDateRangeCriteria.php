<?php

namespace App\Criteria\ShiftAssignment;

use App\Criteria\CriteriaInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * 依日期範圍篩選排班紀錄
 */
class AssignmentDateRangeCriteria implements CriteriaInterface
{
    /** @var string 起始日期（Y-m-d） */
    private $from;

    /** @var string 結束日期（Y-m-d） */
    private $to;

    /**
     * @param string $from 起始日期
     * @param string $to   結束日期
     */
    public function __construct($from, $to)
    {
        $this->from = $from;
        $this->to = $to;
    }

    /**
     * @param Builder $query
     * @return Builder
     */
    public function apply(Builder $query)
    {
        return $query->whereBetween('date', [$this->from, $this->to]);
    }
}
