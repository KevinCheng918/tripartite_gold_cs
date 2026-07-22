<?php

namespace App\Criteria\Role;

use App\Criteria\CriteriaInterface;
use Illuminate\Database\Eloquent\Builder;

class RoleKeywordSearchCriteria implements CriteriaInterface
{
    private string $keyword;

    public function __construct(string $keyword)
    {
        $this->keyword = $keyword;
    }

    public function apply(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('name', 'like', "%{$this->keyword}%")
                ->orWhere('display_name', 'like', "%{$this->keyword}%");
        });
    }
}
