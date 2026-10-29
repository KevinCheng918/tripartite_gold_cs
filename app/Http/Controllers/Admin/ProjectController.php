<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\StoreRegulationRequest;
use App\Http\Requests\Project\UpdateRegulationRequest;
use App\Http\Requests\StaffManage\StoreProjectRequest;
use App\Http\Requests\StaffManage\UpdateProjectRequest;
use App\Http\Resources\ProjectRegulationResource;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\ProjectRegulation;
use App\Repositories\ProjectRepository;
use App\Services\ProjectRegulationService;
use App\Services\ProjectService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

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

    public function __construct(
        ProjectRepository $projectRepository,
        ProjectService $projectService,
        ProjectRegulationService $regulationService
    ) {
        $this->projectRepository = $projectRepository;
        $this->projectService = $projectService;
        // 系統規章掛在每個專案底下（需求方 2026-10-30）
        $this->regulationService = $regulationService;
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

        return view('admin.project.index', [
            'projects'    => $projects,
            // 規章跟著專案一起撈，不要在 Blade 裡逐專案查（N+1）
            'regulations' => $this->regulationService->forProjects($projects->pluck('id')->all()),
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

        try {
            $project = $this->projectService->store($params, Auth::id());

            return response()->json([
                'message' => '專案已新增',
                'project' => new ProjectResource($project->load('creator')),
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

        try {
            $updated = $this->projectService->update($project, $params);

            return response()->json([
                'message' => '專案已更新',
                'project' => new ProjectResource($updated->load('creator')),
            ]);
        } catch (\Exception $e) {
            Log::error('專案更新失敗', ['error' => $e->getMessage(), 'project_id' => $project->id]);

            return response()->json(['message' => '更新失敗'], 500);
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
     * ⚠ 判準跟 `ProjectRepository::getVisibleTo()` **走同一份**：
     * 管理者全部、有勾參與專案的只有勾到的、一個都沒勾就什麼都碰不到。
     *
     * @param int $projectId
     * @return bool
     */
    private function canTouchProject($projectId)
    {
        $user = Auth::user();

        if ($user->isAdmin()) {
            return true;
        }

        return in_array($projectId, array_map('intval', (array) $user->project_ids), true);
    }
}
