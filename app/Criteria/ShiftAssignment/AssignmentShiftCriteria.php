<?php

namespace App\Criteria\ShiftAssignment;

use App\Criteria\CriteriaInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * 依班別 ID 篩選排班紀錄
 */
class AssignmentShiftCriteria implements CriteriaInterface
{
    /** @var int 班別 ID */
    private $shiftId;

    /**
     * @param int $shiftId
     */
    public function __construct($shiftId)
    {
        $this->shiftId = $shiftId;
    }

    /**
     * @param Builder $query
     * @return Builder
     */
    public function apply(Builder $query)
    {
        return $query->where('shift_id', $this->shiftId);
    }
}
