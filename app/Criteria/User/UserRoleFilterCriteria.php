<?php

namespace App\Criteria\User;

use App\Criteria\CriteriaInterface;
use Illuminate\Database\Eloquent\Builder;

class UserRoleFilterCriteria implements CriteriaInterface
{
    private int $roleId;

    public function __construct(int $roleId)
    {
        $this->roleId = $roleId;
    }

    public function apply(Builder $query): Builder
    {
        return $query->whereHas('roles', function (Builder $q) {
            $q->where('roles.id', $this->roleId);
        });
    }
}
