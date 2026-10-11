<?php

namespace App\Criteria\Followup;

use App\Criteria\CriteriaInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * 只看某一個對話的待追蹤事項
 */
class FollowupGroupCriteria implements CriteriaInterface
{
    /** @var int telegram_group.id */
    private $groupId;

    /**
     * @param int $groupId
     */
    public function __construct($groupId)
    {
        $this->groupId = (int) $groupId;
    }

    /**
     * @param Builder $query
     * @return Builder
     */
    public function apply(Builder $query)
    {
        return $query->where('telegram_group_id', $this->groupId);
    }
}
