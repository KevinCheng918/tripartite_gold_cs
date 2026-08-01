<?php

namespace App\Criteria;

use Illuminate\Database\Eloquent\Builder;

/**
 * Criteria 介面
 *
 * 封裝可複用的查詢條件，由 Repository 的 paginate / get 方法套用。
 */
interface CriteriaInterface
{
    /**
     * 將條件套用至 query builder
     *
     * @param Builder $query
     * @return Builder
     */
    public function apply(Builder $query);
}
