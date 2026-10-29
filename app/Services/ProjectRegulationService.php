<?php

namespace App\Services;

use App\Models\ProjectRegulation;
use App\Repositories\ProjectRegulationRepository;
use Illuminate\Support\Arr;

/**
 * 系統規章（後台的增修查）
 *
 * 需求方 2026-10-30：做在專案管理裡，一個專案可以有多個規章。
 *
 * ⚠ 小幫手那一端在 `RegulationCommandService`，兩邊**共用同一個
 * Repository** —— 「哪些規章還在用」只有一份定義（`status = 啟用`）。
 */
class ProjectRegulationService
{
    private $regulationRepository;

    public function __construct(ProjectRegulationRepository $regulationRepository)
    {
        $this->regulationRepository = $regulationRepository;
    }

    /**
     * 一批專案各自的規章（專案管理頁用）
     *
     * @param array<int> $projectIds
     * @return array<int, \Illuminate\Database\Eloquent\Collection>
     */
    public function forProjects($projectIds)
    {
        return $this->regulationRepository->getByProjects($projectIds);
    }

    /**
     * 某個專案底下的規章
     *
     * @param int $projectId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function listByProject($projectId)
    {
        return $this->regulationRepository->getByProject($projectId);
    }

    /**
     * 看一份規章的完整內容
     *
     * @param int $id
     * @return ProjectRegulation|null
     */
    public function detail($id)
    {
        return $this->regulationRepository->find($id);
    }

    /**
     * 新增
     *
     * @param array $params
     * @param int   $userId
     * @return ProjectRegulation
     */
    public function store(array $params, $userId)
    {
        return $this->regulationRepository->create([
            'project_id' => (int) Arr::get($params, 'project_id'),
            'title'      => trim((string) Arr::get($params, 'title')),
            'content'    => (string) Arr::get($params, 'content'),
            'status'     => (int) Arr::get($params, 'status', ProjectRegulation::STATUS_ACTIVE),
            'sort'       => (int) Arr::get($params, 'sort', 0),
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
    }

    /**
     * 修改
     *
     * ⚠ **`project_id` 不給改**：規章改掛到別的專案，等於換了一份文件的歸屬，
     * 而小幫手是用「標題＋專案」回答的 —— 改錯會讓人拿到別個專案的規章。
     * 要搬就刪了重建。
     *
     * ⚠ 一定要更新 `updated_by` —— 只記「什麼時候改的」卻不記「誰改的」，
     * 規章出問題時還是查不到人（需求方選的就是這個最小版本）。
     *
     * @param ProjectRegulation $regulation
     * @param array             $params
     * @param int               $userId
     * @return ProjectRegulation
     */
    public function update(ProjectRegulation $regulation, array $params, $userId)
    {
        return $this->regulationRepository->update($regulation, [
            'title'      => trim((string) Arr::get($params, 'title')),
            'content'    => (string) Arr::get($params, 'content'),
            'status'     => (int) Arr::get($params, 'status', ProjectRegulation::STATUS_ACTIVE),
            'sort'       => (int) Arr::get($params, 'sort', 0),
            'updated_by' => $userId,
        ]);
    }

    /**
     * 刪除
     *
     * @param ProjectRegulation $regulation
     * @return bool
     */
    public function destroy(ProjectRegulation $regulation)
    {
        return $this->regulationRepository->delete($regulation);
    }
}
