<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StaffManage\StoreProjectRequest;
use App\Http\Requests\StaffManage\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Repositories\ProjectRepository;
use App\Services\ProjectService;
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

    public function __construct(ProjectRepository $projectRepository, ProjectService $projectService)
    {
        $this->projectRepository = $projectRepository;
        $this->projectService = $projectService;
    }

    /**
     * 專案管理頁面
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $projects = $this->projectRepository->getAll();

        return view('admin.project.index', [
            'projects' => $projects,
        ]);
    }

    /**
     * Ajax 取得專案列表
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function ajaxList()
    {
        $projects = $this->projectRepository->getAll();

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
}
