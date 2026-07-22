<?php

namespace App\Criteria\Role;

use App\Criteria\CriteriaInterface;
use Illuminate\Database\Eloquent\Builder;

class RoleActiveCriteria implements CriteriaInterface
{
    public function apply(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
