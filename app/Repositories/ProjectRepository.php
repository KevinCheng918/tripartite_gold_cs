<?php

namespace App\Repositories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;

/**
 * 專案 Repository
 */
class ProjectRepository
{
    /**
     * 取得所有啟用專案（下拉選單用）
     *
     * @return Collection
     */
    public function getActive()
    {
        return Project::query()
            ->select(['id', 'name'])
            ->where('status', config('constants.PROJECT.STATUS.ACTIVE'))
            ->orderBy('name')
            ->get();
    }

    /**
     * 新增專案
     *
     * @param array $attributes
     * @return Project
     */
    public function create($attributes)
    {
        return Project::query()->create($attributes);
    }
}
