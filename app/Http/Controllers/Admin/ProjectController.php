<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\StoreRegulationRequest;
use App\Http\Requests\Project\UpdateRegulationRequest;
use App\Http\Requests\StaffManage\AssignOwnerRequest;
use App\Http\Requests\StaffManage\StoreProjectRequest;
use App\Http\Requests\StaffManage\UpdateProjectRequest;
use App\Http\Resources\ProjectRegulationResource;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\ProjectRegulation;
use App\Repositories\ProjectRepository;
use App\Services\ProjectOwnerService;
use App\Services\ProjectRegulationService;
use App\Services\ProjectService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * 專案管理控制器
 *
 * ⚠ 讀走 Repository、寫走 Service。寫入有連動邏輯
 * （停用改回啟用時要重算封存卡的清理期限），那不能放在 Controller。
 */
class ProjectController extends Controller
{
    private $projectRepository;
    private $projectService;
    private $regulationService;
    private $ownerService;

    public function __construct(
        ProjectRepository $projectRepository,
        ProjectService $projectService,
        ProjectRegulationService $regulationService,
        ProjectOwnerService $ownerService
    ) {
        $this->projectRepository = $projectRepository;
        $this->projectService = $projectService;
        // 系統規章掛在每個專案底下（需求方 2026-10-30）
        $this->regulationService = $regulationService;
        // 負責人（只會有一位，需求方 2026-10-30）
        $this->ownerService = $ownerService;
    }

    /**
     * 專案管理頁面
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        /*
         * ⚠ **只看得到自己參與的專案**（需求方 2026-10-30）。
         * 「檢視專案」權限管的是「能不能進這一頁」，不是「看得到哪幾個」——
         * 過濾規則見 `ProjectRepository::getVisibleTo()`。
         */
        $projects = $this->projectRepository->getVisibleTo(Auth::user());

        $projectIds = $projects->pluck('id')->all();

        return view('admin.project.index', [
            'projects'    => $projects,
            // 規章跟著專案一起撈，不要在 Blade 裡逐專案查（N+1）
            'regulations' => $this->regulationService->forProjects($projectIds),
            /*
             * 「變更負責人」下拉的候選名單（逐專案篩過：只有參與該專案的人）。
             *
             * ⚠ **一次撈完所有專案的**，不要在 Blade 裡逐專案查 ——
             * 那是 N+1，而且每個專案的名單都要重新掃一次全部帳號。
             */
            'ownerCandidates' => $this->ownerService->candidatesFor($projectIds),
            /*
             * 「新增專案」下拉的候選名單（全部啟用帳號，不分專案）。
             *
             * ⚠ **跟上面那一份不能共用**：新增的那一刻專案還不存在，
             * 沒有人勾得了參與它 —— 用上面那一份的話下拉只剩管理者。
             * 兩支底層共用同一趟查詢（見 `UserRepository` 的 memo）。
             */
            'ownerAssignable' => $this->ownerService->assignableUsers(),
        ]);
    }

    /**
     * Ajax 取得專案列表
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function ajaxList()
    {
        // ⚠ 跟頁面同一套過濾 —— 只擋畫面、不擋 API 等於沒擋
        $projects = $this->projectRepository->getVisibleTo(Auth::user());

        return ProjectResource::collection($projects);
    }

    /**
     * Ajax 新增專案
     *
     * @param StoreProjectRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxStore(StoreProjectRequest $request)
    {
        $params = $request->validated();

        /*
         * ⚠ **沒有 `project.assign_owner` 就不收 `owner_id`**：這條路徑的
         * route 擋的是 `project.edit`（開專案的權限），而指派負責人刻意是
         * 另一個 keyword。不拔掉的話，只有 `project.edit` 的人雖然看不到
         * 那個下拉，直接打 API 照樣指派得了負責人 —— 等於這個權限沒有用。
         *
         * ⚠ 用 `Arr::except()` 整個拔掉，不要改寫成 null —— 那會變成
         * 「明確指定未指派」，跟「這個人無權決定」是兩回事
         * （雖然結果一樣，但下次加預設負責人的邏輯時就會分岔）。
         */
        if (!Auth::user()->hasPermission('project.assign_owner')) {
            $params = Arr::except($params, ['owner_id']);
        }

        try {
            $project = $this->projectService->store($params, Auth::id());

            return response()->json([
                'message' => '專案已新增',
                'project' => new ProjectResource($project->load(['creator', 'owner'])),
            ]);
        } catch (\Exception $e) {
            Log::error('專案新增失敗', ['error' => $e->getMessage()]);

            return response()->json(['message' => '新增失敗'], 500);
        }
    }

    /**
     * Ajax 更新專案
     *
     * @param UpdateProjectRequest $request
     * @param Project              $project
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxUpdate(UpdateProjectRequest $request, Project $project)
    {
        $params = $request->validated();

        // 沒有 `project.assign_owner` 就不收 `owner_id`（理由同 `ajaxStore()`）
        if (!Auth::user()->hasPermission('project.assign_owner')) {
            $params = Arr::except($params, ['owner_id']);
        }

        try {
            /*
             * 負責人也在 `$params` 裡（有 `project.assign_owner` 才收得到）——
             * ⚠ 換負責人與基本欄位的 transaction、以及「不可以是管理者／
             * 必須參與本專案」那兩關，都在 `ProjectService::update()` 裡面，
             * **不要搬到這裡** —— Controller 不碰 DB、也不放商業邏輯。
             */
            $updated = $this->projectService->update($project, $params);

            return response()->json([
                'message' => '專案已更新',
                'project' => new ProjectResource($updated->load(['creator', 'owner'])),
            ]);
        } catch (ValidationException $e) {
            // ⚠ 單獨接，不要掉進下面變成 500「更新失敗」—— 理由見 `ajaxAssignOwner()`
            return response()->json([
                'message' => Arr::first(Arr::flatten($e->errors())),
            ], 422);
        } catch (\Exception $e) {
            Log::error('專案更新失敗', ['error' => $e->getMessage(), 'project_id' => $project->id]);

            return response()->json(['message' => '更新失敗'], 500);
        }
    }

    /**
     * Ajax 指派／更換／清空專案負責人
     *
     * ⚠ **權限之外還要過 `canTouchProject()`**：`can:project.assign_owner`
     * 只回答「這個人能不能指派負責人」，不回答「能不能碰這一個專案」——
     * 網址只有一個 id，沒參與那個專案的人照樣打得到。
     *
     * ⚠ `ValidationException` 要**單獨接**，不要被下面那個 `\Exception`
     * 吃掉變成 500：「這位同仁尚未參與本專案」是要給操作者看的話，
     * 回 500「指派失敗」的話他不知道該去做什麼。
     *
     * @param AssignOwnerRequest $request
     * @param Project            $project
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxAssignOwner(AssignOwnerRequest $request, Project $project)
    {
        if (!$this->canTouchProject((int) $project->id)) {
            return response()->json(['message' => trans('project.regulation_no_access')], 403);
        }

        $params = $request->validated();

        try {
            $updated = $this->ownerService->assign($project, Arr::get($params, 'owner_id'));

            return response()->json([
                'message' => trans('project.owner_assigned'),
                'project' => new ProjectResource($updated->load(['creator', 'owner'])),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => Arr::first(Arr::flatten($e->errors())),
            ], 422);
        } catch (\Exception $e) {
            Log::error('專案負責人指派失敗', [
                'error'      => $e->getMessage(),
                'project_id' => $project->id,
            ]);

            return response()->json(['message' => trans('project.owner_assign_failed')], 500);
        }
    }

    // ---------------------------------------------------------------
    //  系統規章（掛在每個專案底下）
    // ---------------------------------------------------------------

    /**
     * Ajax 看一份規章的完整內容
     *
     * 列表只帶標題（內容可能上萬字），點開才撈全文。
     *
     * @param ProjectRegulation $regulation
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxRegulationDetail(ProjectRegulation $regulation)
    {
        if (!$this->canTouchProject((int) $regulation->project_id)) {
            return response()->json(['message' => trans('project.regulation_no_access')], 403);
        }

        return response()->json([
            'regulation' => new ProjectRegulationResource($regulation->load(['project', 'updater'])),
        ]);
    }

    /**
     * Ajax 新增規章
     *
     * @param StoreRegulationRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxRegulationStore(StoreRegulationRequest $request)
    {
        $params = $request->validated();

        if (!$this->canTouchProject((int) Arr::get($params, 'project_id'))) {
            return response()->json(['message' => trans('project.regulation_no_access')], 403);
        }

        try {
            $regulation = $this->regulationService->store($params, Auth::id());

            return response()->json([
                'message'    => trans('project.regulation_created'),
                'regulation' => new ProjectRegulationResource($regulation->load(['project', 'updater'])),
            ]);
        } catch (\Exception $e) {
            Log::error('規章新增失敗', ['error' => $e->getMessage()]);

            return response()->json(['message' => trans('project.regulation_save_failed')], 500);
        }
    }

    /**
     * Ajax 更新規章
     *
     * @param UpdateRegulationRequest $request
     * @param ProjectRegulation       $regulation
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxRegulationUpdate(UpdateRegulationRequest $request, ProjectRegulation $regulation)
    {
        $params = $request->validated();

        if (!$this->canTouchProject((int) $regulation->project_id)) {
            return response()->json(['message' => trans('project.regulation_no_access')], 403);
        }

        try {
            $updated = $this->regulationService->update($regulation, $params, Auth::id());

            return response()->json([
                'message'    => trans('project.regulation_updated'),
                'regulation' => new ProjectRegulationResource($updated->load(['project', 'updater'])),
            ]);
        } catch (\Exception $e) {
            Log::error('規章更新失敗', ['error' => $e->getMessage(), 'regulation_id' => $regulation->id]);

            return response()->json(['message' => trans('project.regulation_save_failed')], 500);
        }
    }

    /**
     * Ajax 刪除規章
     *
     * @param ProjectRegulation $regulation
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxRegulationDestroy(ProjectRegulation $regulation)
    {
        if (!$this->canTouchProject((int) $regulation->project_id)) {
            return response()->json(['message' => trans('project.regulation_no_access')], 403);
        }

        try {
            $this->regulationService->destroy($regulation);

            return response()->json(['message' => trans('project.regulation_deleted')]);
        } catch (\Exception $e) {
            Log::error('規章刪除失敗', ['error' => $e->getMessage(), 'regulation_id' => $regulation->id]);

            return response()->json(['message' => trans('project.regulation_delete_failed')], 500);
        }
    }

    /**
     * 這個人碰得到這個專案嗎
     *
     * ⚠ **每一支規章的 API 都要過這一關。** 只在頁面上過濾專案是擋不住的 ——
     * 規章的網址只有一個 id，沒參與那個專案的人照樣打得到
     * （需求方 2026-10-30 問的就是這個行為）。
     *
     * ⚠ 判準在 `User::canAccessProject()`，跟
     * `ProjectRepository::getVisibleTo()` **是同一份** —— 這裡只是轉呼叫。
     * 各寫一份遲早會分岔，而分岔的那一邊就是資料外洩。
     *
     * @param int $projectId
     * @return bool
     */
    private function canTouchProject($projectId)
    {
        return Auth::user()->canAccessProject($projectId);
    }
}
