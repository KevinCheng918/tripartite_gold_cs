<?php

namespace App\Criteria\User;

use App\Criteria\CriteriaInterface;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class UserActiveCriteria implements CriteriaInterface
{
    public function apply(Builder $query): Builder
    {
        return $query->where('status', User::STATUS_ACTIVE);
    }
}
