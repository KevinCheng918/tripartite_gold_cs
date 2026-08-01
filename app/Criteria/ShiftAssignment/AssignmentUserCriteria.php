<?php

namespace App\Criteria\ShiftAssignment;

use App\Criteria\CriteriaInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * 依員工 ID 篩選排班紀錄
 */
class AssignmentUserCriteria implements CriteriaInterface
{
    /** @var int 員工 ID */
    private $userId;

    /**
     * @param int $userId
     */
    public function __construct($userId)
    {
        $this->userId = $userId;
    }

    /**
     * @param Builder $query
     * @return Builder
     */
    public function apply(Builder $query)
    {
        return $query->where('user_id', $this->userId);
    }
}
