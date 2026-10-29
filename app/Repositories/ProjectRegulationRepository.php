<?php

namespace App\Repositories;

use App\Models\ProjectRegulation;
use Illuminate\Database\Eloquent\Collection;

/**
 * 系統規章
 */
class ProjectRegulationRepository
{
    /**
     * 列表要用的欄位
     *
     * ⚠ **不取 `content`**：規章內容可能上萬字，列表一次撈十幾筆就是幾十萬
     * 字元進記憶體，而畫面上只顯示標題。要內容時走 `find()`。
     *
     * @var array<string>
     */
    private const LIST_COLUMNS = [
        'id', 'project_id', 'title', 'status', 'sort', 'updated_by', 'updated_at',
    ];

    /**
     * 某個專案底下的規章
     *
     * @param int  $projectId
     * @param bool $activeOnly 只要啟用中的
     * @return Collection
     */
    public function getByProject($projectId, $activeOnly = false)
    {
        $query = ProjectRegulation::query()
            ->select(self::LIST_COLUMNS)
            ->with(['updater'])
            ->where('project_id', $projectId);

        if ($activeOnly) {
            $query->where('status', ProjectRegulation::STATUS_ACTIVE);
        }

        return $query->orderBy('sort')->orderBy('id')->get();
    }

    /**
     * 一次撈好幾個專案底下的規章（專案管理頁一次顯示所有專案）
     *
     * ⚠ **一次撈完**：專案管理頁是一個專案一個分頁、全部一起輸出 ——
     * 逐專案查就是 N+1。
     *
     * @param array<int> $projectIds
     * @return array<int, Collection> project_id => 規章
     */
    public function getByProjects($projectIds)
    {
        $projectIds = array_values(array_filter((array) $projectIds));

        if (blank($projectIds)) {
            return [];
        }

        return ProjectRegulation::query()
            ->select(self::LIST_COLUMNS)
            ->with(['updater'])
            ->whereIn('project_id', $projectIds)
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->groupBy('project_id')
            ->all();
    }

    /**
     * 照標題找啟用中的規章（小幫手用）
     *
     * ⚠ 用 `LIKE` 而不是完全相等：使用者講「三方規章」時，後台可能存成
     * 「三方規章（2026 版）」。完全相等會變成「後台有、小幫手找不到」。
     *
     * ⚠ **可能回好幾筆** —— 不同專案底下可以有同名的規章。
     * 呼叫端要問是哪一個專案的，不可以自己挑第一筆
     * （需求方 2026-10-30：撞名才問專案）。
     *
     * ⚠ 這裡**要帶 `content`**：找到就要整段發出去，再查一次是白費。
     *
     * @param string $title
     * @return Collection
     */
    public function searchActiveByTitle($title)
    {
        $title = trim((string) $title);

        if (blank($title)) {
            return ProjectRegulation::query()->whereRaw('1 = 0')->get();
        }

        return ProjectRegulation::query()
            ->with(['project'])
            ->where('status', ProjectRegulation::STATUS_ACTIVE)
            ->where('title', 'like', '%' . $title . '%')
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }

    /**
     * 所有啟用中的規章標題（找不到時列出來給人參考）
     *
     * @return Collection
     */
    public function getActiveTitles()
    {
        return ProjectRegulation::query()
            ->select(['id', 'project_id', 'title'])
            ->with(['project'])
            ->where('status', ProjectRegulation::STATUS_ACTIVE)
            ->orderBy('project_id')
            ->orderBy('sort')
            ->get();
    }

    /**
     * @param int $id
     * @return ProjectRegulation|null
     */
    public function find($id)
    {
        return ProjectRegulation::query()->with(['project'])->find($id);
    }

    /**
     * @param array $attributes
     * @return ProjectRegulation
     */
    public function create(array $attributes)
    {
        return ProjectRegulation::query()->create($attributes);
    }

    /**
     * @param ProjectRegulation $regulation
     * @param array             $attributes
     * @return ProjectRegulation
     */
    public function update(ProjectRegulation $regulation, array $attributes)
    {
        $regulation->update($attributes);

        return $regulation->refresh();
    }

    /**
     * @param ProjectRegulation $regulation
     * @return bool
     */
    public function delete(ProjectRegulation $regulation)
    {
        return (bool) $regulation->delete();
    }
}
