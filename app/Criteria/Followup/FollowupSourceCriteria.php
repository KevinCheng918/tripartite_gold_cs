<?php

namespace App\Criteria\Followup;

use App\Criteria\CriteriaInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * 依來源篩選待追蹤事項（客人承諾／我方承諾）
 */
class FollowupSourceCriteria implements CriteriaInterface
{
    /** @var int 見 constants.FOLLOWUP.SOURCE */
    private $source;

    /**
     * @param int $source
     */
    public function __construct($source)
    {
        $this->source = (int) $source;
    }

    /**
     * @param Builder $query
     * @return Builder
     */
    public function apply(Builder $query)
    {
        return $query->where('source', $this->source);
    }
}
