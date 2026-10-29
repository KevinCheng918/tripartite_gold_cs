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
    /**
     * 列表欄位
     *
     * ⚠ **`owner_id` 漏掉的話 `owner` 關聯會撈不到**，負責人永遠顯示
     * 「未指派」而且不會有任何錯誤。這個專案為「select 漏欄位」修過好幾次
     * （見 `UserRepository::LIST_COLUMNS` 的註解）。
     *
     * @var array<string>
     */
    private const LIST_COLUMNS = ['id', 'name', 'description', 'status', 'reactivated_at', 'owner_id', 'created_by', 'created_at', 'updated_at'];

    /**
     * 一起撈的關聯
     *
     * ⚠ 三支查詢共用同一份：少撈 `owner` 的那一支會在 Blade 逐筆查（N+1），
     * 而且畫面看起來一模一樣 —— 不會有人發現。
     *
     * @var array<string>
     */
    private const LIST_RELATIONS = ['creator', 'owner'];

    /**
     * 取得所有專案（含建立者）
     *
     * @return Collection
     */
    public function getAll()
    {
        return Project::query()
            ->select(self::LIST_COLUMNS)
            ->with(self::LIST_RELATIONS)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * 這個人看得到的專案
     *
     * ⚠ **「檢視專案」權限不等於看得到全部專案**（需求方 2026-10-30 回報）。
     * 原本 `getAll()` 直接撈全部 —— 只要勾了 `project.view`，就算一個專案
     * 都沒參與也看得到所有專案。
     *
     * 過濾規則照**任務看板既有的那一套**（`TaskBoardController::index()`）：
     *
     * | 身份 | 看得到 |
     * |---|---|
     * | 管理者 | 全部（系統維護用的帳號）|
     * | 有勾參與專案 | 只有勾到的那幾個 |
     * | 沒勾任何參與專案 | **一個都沒有** |
     *
     * ⚠ 最後那一列是重點：沒勾等於「空集合」，不是「不限制」。
     * 寫成「沒勾就看全部」的話，新帳號一建立就看得到所有專案的規章。
     *
     * @param \App\Models\User $user
     * @return Collection
     */
    public function getVisibleTo($user)
    {
        if ($user->isAdmin()) {
            return $this->getAll();
        }

        // ⚠ 正規化與「碰不碰得到」的判準都在 User 上，這裡不另外寫一份
        $projectIds = $user->projectIdList();

        /*
         * 短路：一個都沒勾就不必下查詢。
         *
         * ⚠ 回空集合，不要 `whereRaw('1 = 0')->get()` —— 那是一趟必然沒結果
         * 的查詢，而且是 `select *`。
         */
        if (blank($projectIds)) {
            return new Collection();
        }

        return Project::query()
            ->select(self::LIST_COLUMNS)
            ->with(self::LIST_RELATIONS)
            ->whereIn('id', $projectIds)
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
            ->with(self::LIST_RELATIONS)
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
