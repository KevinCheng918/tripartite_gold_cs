<?php

namespace App\Criteria\User;

use App\Criteria\CriteriaInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * 依關鍵字搜尋使用者（帳號或暱稱模糊比對）
 */
class UserKeywordSearchCriteria implements CriteriaInterface
{
    /** @var string 搜尋關鍵字 */
    private $keyword;

    /**
     * @param string $keyword
     */
    public function __construct($keyword)
    {
        $this->keyword = $keyword;
    }

    /**
     * @param Builder $query
     * @return Builder
     */
    public function apply(Builder $query)
    {
        return $query->where(function (Builder $q) {
            $q->where('account', 'like', "%{$this->keyword}%")
                ->orWhere('nickname', 'like', "%{$this->keyword}%");
        });
    }
}
