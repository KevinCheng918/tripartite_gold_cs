<?php

namespace App\Repositories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;

/**
 * 專案 Repository
 */
class ProjectRepository
{
    /** @var array 列表欄位 */
    private const LIST_COLUMNS = ['id', 'name', 'description', 'status', 'created_by', 'created_at', 'updated_at'];

    /**
     * 取得所有專案（含建立者）
     *
     * @return Collection
     */
    public function getAll()
    {
        return Project::query()
            ->select(self::LIST_COLUMNS)
            ->with('creator')
            ->orderByDesc('id')
            ->get();
    }

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
     * 依 ID 查詢
     *
     * @param int $id
     * @return Project|null
     */
    public function find($id)
    {
        return Project::query()
            ->select(self::LIST_COLUMNS)
            ->with('creator')
            ->find($id);
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

    /**
     * 更新專案
     *
     * @param Project $project
     * @param array   $attributes
     * @return Project
     */
    public function update(Project $project, $attributes)
    {
        $project->update($attributes);

        return $project->refresh();
    }
}
