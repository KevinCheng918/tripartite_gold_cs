<?php

namespace App\Services;

use App\Models\Project;
use App\Repositories\ProjectRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 專案 Service
 *
 * ⚠ **為什麼 2026-10-10 才生出這一支。**
 *
 * `ProjectController` 原本直接呼叫 `ProjectRepository` —— 沒有 Service 那一層。
 * 本來只是分層規範上的瑕疵，但「停用改回啟用時要連動封存卡的清理期限」
 * 是不折不扣的商業邏輯，塞進 Controller 就會變成下一個人找不到的規則。
 */
class ProjectService
{
    private $projectRepository;

    public function __construct(ProjectRepository $projectRepository)
    {
        $this->projectRepository = $projectRepository;
    }

    /**
     * 新增專案
     *
     * @param array $params
     * @param int   $creatorId
     * @return Project
     */
    public function store(array $params, $creatorId)
    {
        return $this->projectRepository->create([
            'name'        => Arr::get($params, 'name'),
            'description' => Arr::get($params, 'description'),
            'status'      => (int) Arr::get($params, 'status', config('constants.PROJECT.STATUS.ACTIVE')),
            'created_by'  => $creatorId,
        ]);
    }

    /**
     * 更新專案
     *
     * @param Project $project
     * @param array   $params
     * @return Project
     */
    public function update(Project $project, array $params)
    {
        if ($this->isReactivating($project, $params)) {
            $params['reactivated_at'] = now();

            Log::info('專案重新啟用，封存卡的 30 天清理期限重新計算', [
                'project_id' => $project->id,
            ]);
        }

        return $this->projectRepository->update($project, $params);
    }

    /**
     * 這次更新是不是「停用 → 啟用」
     *
     * ⚠ **只認這一個方向的轉換。** 編輯一個本來就啟用中的專案（改名、改描述，
     * 表單照樣會把 `status` 一起送上來）不該重新計算期限 —— 否則只要有人去
     * 動一下專案名稱，整個專案的封存卡就又多活 30 天，永遠清不掉。
     *
     * ⚠ 用 `Arr::has()` 而不是只看值：表單沒送 `status` 時代表「不動狀態」，
     * 跟「送了一個等於啟用的值」是兩件事。
     *
     * @param Project $project 更新前的狀態
     * @param array   $params
     * @return bool
     */
    private function isReactivating(Project $project, array $params)
    {
        if (!Arr::has($params, 'status')) {
            return false;
        }

        $active = (int) config('constants.PROJECT.STATUS.ACTIVE');

        return (int) $project->status !== $active && (int) Arr::get($params, 'status') === $active;
    }
}
