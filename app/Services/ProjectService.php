<?php

namespace App\Services;

use App\Models\Project;
use App\Repositories\ProjectRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
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
    private $ownerService;

    public function __construct(
        ProjectRepository $projectRepository,
        ProjectOwnerService $ownerService
    ) {
        $this->projectRepository = $projectRepository;
        // 負責人的指派規則全在它身上（只會有一位、不可以是管理者、要參與本專案）
        $this->ownerService = $ownerService;
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
            /*
             * 新增時可以一併指定負責人（需求方 2026-10-30），留空就是「未指派」。
             *
             * ⚠ **這裡不驗「有沒有參與這個專案」**：專案是這一刻才建立的，
             * 沒有人勾得了參與它。存檔後專案卡片會標紅字提醒去帳號管理把他
             * 加入參與專案 —— 之後走「變更負責人」才會被擋。
             * 詳見 features/project-owner.md 的「兩個裁示撞在一起」。
             */
            'owner_id'    => Arr::get($params, 'owner_id'),
            'status'      => (int) Arr::get($params, 'status', config('constants.PROJECT.STATUS.ACTIVE')),
            'created_by'  => $creatorId,
        ]);
    }

    /**
     * 更新專案（含換負責人）
     *
     * ⚠ **負責人那一段不要自己塞 `owner_id` 進 `$params`**，一定要走
     * `ProjectOwnerService::assign()` —— 自己塞會繞過「不可以是管理者」與
     * 「必須參與本專案」那兩關，讓「編輯」變成後門。同一條規則只能有一份實作。
     *
     * ⚠ 用 `Arr::has()` 而不是看值：沒送 `owner_id` 代表「不動負責人」，
     * 送了 null 代表「清空負責人」—— 兩者都要支援，而且不能搞混。
     *
     * @param Project $project
     * @param array   $params
     * @return Project
     */
    public function update(Project $project, array $params)
    {
        // 短路：沒有要動負責人就只有一次寫入，不必開 transaction
        if (!Arr::has($params, 'owner_id')) {
            return $this->updateBasics($project, $params);
        }

        /*
         * ⚠ **一定要包 transaction**：負責人被 `assign()` 擋下來的時候
         * （指派了管理者、或指派了沒參與本專案的人），名稱／說明／狀態
         * 也不可以留下來 —— 不然使用者看到「指派失敗」，
         * 卻不知道自己改的名字其實已經存進去了。
         */
        return DB::transaction(function () use ($project, $params) {
            $project = $this->updateBasics($project, Arr::except($params, ['owner_id']));

            return $this->ownerService->assign($project, Arr::get($params, 'owner_id'));
        });
    }

    /**
     * 更新專案的基本欄位（名稱／說明／狀態），不含負責人
     *
     * @param Project $project
     * @param array   $params
     * @return Project
     */
    private function updateBasics(Project $project, array $params)
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
