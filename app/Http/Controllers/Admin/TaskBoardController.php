<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\TaskBoard\MoveTaskRequest;
use App\Http\Requests\TaskBoard\ReorderTaskRequest;
use App\Http\Requests\TaskBoard\StoreCommentRequest;
use App\Http\Requests\TaskBoard\StoreProjectRequest;
use App\Http\Requests\TaskBoard\StoreTaskRequest;
use App\Http\Requests\TaskBoard\UpdateTaskRequest;
use App\Http\Resources\TaskActivityResource;
use App\Http\Resources\TaskCommentResource;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Models\TaskComment;
use App\Services\ImageUploadService;
use App\Services\TaskBoardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * 任務看板控制器
 */
class TaskBoardController extends Controller
{
    private $taskBoardService;
    private $imageUploadService;

    public function __construct(TaskBoardService $taskBoardService, ImageUploadService $imageUploadService)
    {
        $this->taskBoardService = $taskBoardService;
        $this->imageUploadService = $imageUploadService;
    }

    /**
     * 看板頁面
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $user = Auth::user();
        $isAdmin = (int) $user->level === config('constants.USER.LEVEL.ADMIN');
        $userProjectIds = $user->project_ids ?? [];
        $allProjects = $this->taskBoardService->getProjects();
        $projects = ($isAdmin || empty($userProjectIds))
            ? ($isAdmin ? $allProjects : collect([]))
            : $allProjects->filter(function ($p) use ($userProjectIds) { return in_array($p->id, $userProjectIds); })->values();
        $assignees = $this->taskBoardService->getAssignees();
        $stations = $this->taskBoardService->getStations();
        $systems = $this->taskBoardService->getSystems();

        return view('admin.task-board.index', [
            'projects'  => $projects,
            'assignees' => $assignees,
            'stations'  => $stations,
            'systems'   => $systems,
        ]);
    }

    /**
     * Ajax 取得看板資料（四欄分組）
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxBoard(Request $request)
    {
        $params = $request->only(['project_id', 'assignee_id', 'priority', 'keyword', 'sort']);
        $user = Auth::user();
        $isAdmin = (int) $user->level === config('constants.USER.LEVEL.ADMIN');
        $params['user_project_ids'] = $isAdmin ? [] : ($user->project_ids ?? [-1]);
        $board = $this->taskBoardService->getBoard($params);

        // 預載所有 assignee 使用者（避免 N+1）
        $allIds = [];
        foreach ($board as $tasks) {
            foreach ($tasks as $task) {
                $ids = $task->assignee_ids ?? [];
                foreach ($ids as $id) { $allIds[] = (int) $id; }
            }
        }
        TaskResource::preloadUsers(array_unique($allIds));

        return response()->json([
            'pending'     => TaskResource::collection($board['pending']),
            'in_progress' => TaskResource::collection($board['in_progress']),
            'testing'     => TaskResource::collection($board['testing']),
            'in_review'   => TaskResource::collection($board['in_review']),
            'resolved'    => TaskResource::collection($board['resolved']),
        ]);
    }

    /**
     * Ajax 取得任務詳細
     *
     * @param Task $task
     * @return TaskResource
     */
    public function ajaxTaskDetail(Task $task)
    {
        $task = $this->taskBoardService->getTask($task->id);
        TaskResource::preloadUsers($task->assignee_ids ?? []);

        return new TaskResource($task);
    }

    /**
     * Ajax 新增任務
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxStoreTask(StoreTaskRequest $request)
    {
        $params = $request->validated();
        $params['images'] = $request->hasFile('images')
            ? $this->imageUploadService->uploadMultiple($request->file('images'), 'task')
            : [];

        try {
            $task = $this->taskBoardService->storeTask($params, Auth::id());

            return response()->json([
                'message' => trans('task_board.msg.task_created'),
                'task'    => new TaskResource($task->load(['project', 'assignee', 'creator'])),
            ]);
        } catch (\Exception $e) {
            Log::error('任務新增失敗', ['error' => $e->getMessage()]);

            return response()->json(['message' => trans('task_board.msg.task_create_failed')], 500);
        }
    }

    /**
     * Ajax 更新任務
     *
     * @param Request $request
     * @param Task    $task
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxUpdateTask(UpdateTaskRequest $request, Task $task)
    {
        $params = $request->validated();
        if ($request->hasFile('images')) {
            $existing = $task->images ?? [];
            $newImages = $this->imageUploadService->uploadMultiple($request->file('images'), 'task');
            $params['images'] = array_merge($existing, $newImages);
        }

        try {
            $task = $this->taskBoardService->updateTask($task, $params, Auth::id());

            return response()->json([
                'message' => trans('task_board.msg.task_updated'),
                'task'    => new TaskResource($task->load(['project', 'assignee', 'creator'])),
            ]);
        } catch (\Exception $e) {
            Log::error('任務更新失敗', ['error' => $e->getMessage(), 'task_id' => $task->id]);

            return response()->json(['message' => trans('task_board.msg.task_update_failed')], 500);
        }
    }

    /**
     * Ajax 封存任務
     *
     * @param Task $task
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxArchiveTask(Task $task)
    {
        try {
            $this->taskBoardService->archiveTask($task, Auth::id());

            return response()->json(['message' => '任務已封存']);
        } catch (\Exception $e) {
            Log::error('任務封存失敗', ['error' => $e->getMessage(), 'task_id' => $task->id]);

            return response()->json(['message' => '封存失敗'], 500);
        }
    }

    /**
     * Ajax 取得封存任務列表
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function ajaxArchivedList()
    {
        $tasks = $this->taskBoardService->getArchivedTasks();
        $allIds = [];
        foreach ($tasks as $task) {
            $ids = $task->assignee_ids ?? [];
            foreach ($ids as $id) { $allIds[] = (int) $id; }
        }
        TaskResource::preloadUsers(array_unique($allIds));

        return TaskResource::collection($tasks);
    }

    /**
     * Ajax 還原封存任務
     *
     * @param Task $task
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxRestoreTask(Task $task)
    {
        try {
            $this->taskBoardService->restoreTask($task, Auth::id());

            return response()->json(['message' => '任務已還原']);
        } catch (\Exception $e) {
            Log::error('任務還原失敗', ['error' => $e->getMessage(), 'task_id' => $task->id]);

            return response()->json(['message' => '還原失敗'], 500);
        }
    }

    /**
     * Ajax 拖曳移動任務（換狀態）
     *
     * @param MoveTaskRequest $request
     * @param Task            $task
     * @return TaskResource
     */
    public function ajaxMoveTask(MoveTaskRequest $request, Task $task)
    {
        $params = $request->validated();

        $task = $this->taskBoardService->moveTask($task, $params, Auth::id());

        return new TaskResource($task->load(['project', 'assignee', 'creator']));
    }

    /**
     * Ajax 批次重排
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxReorder(ReorderTaskRequest $request)
    {
        $params = $request->validated();

        $this->taskBoardService->reorder($params['orders']);

        return response()->json(['message' => 'ok']);
    }

    /**
     * Ajax 取得專案列表
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxProjects()
    {
        return response()->json($this->taskBoardService->getProjects());
    }

    /**
     * Ajax 新增專案
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxStoreProject(StoreProjectRequest $request)
    {
        $params = $request->validated();

        try {
            $project = $this->taskBoardService->storeProject($params, Auth::id());

            return response()->json($project);
        } catch (\Exception $e) {
            Log::error('專案新增失敗', ['error' => $e->getMessage()]);

            return response()->json(['message' => trans('task_board.msg.project_create_failed')], 500);
        }
    }

    /**
     * Ajax 取得可指派人員
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxAssignees()
    {
        return response()->json($this->taskBoardService->getAssignees());
    }

    /**
     * Ajax 編輯器圖片上傳（TinyMCE）
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxUploadEditorImage(Request $request)
    {
        $request->validate(['file' => 'required|image|max:5120']);

        $path = $this->imageUploadService->upload($request->file('file'), 'task-editor');

        return response()->json(['location' => asset("storage/{$path}")]);
    }

    /**
     * Ajax 取得任務留言
     *
     * @param Task $task
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxComments(Task $task)
    {
        $comments = $this->taskBoardService->getComments($task->id);

        return TaskCommentResource::collection($comments);
    }

    /**
     * Ajax 新增留言
     *
     * @param Request $request
     * @param Task    $task
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxStoreComment(StoreCommentRequest $request, Task $task)
    {
        $params = $request->validated();
        $images = $request->hasFile('images')
            ? $this->imageUploadService->uploadMultiple($request->file('images'), 'task-comment')
            : [];

        $comment = $this->taskBoardService->addComment($task->id, Auth::id(), $params['content'], $images);

        return new TaskCommentResource($comment->load('user'));
    }

    /**
     * Ajax 刪除留言
     *
     * @param TaskComment $comment
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxDeleteComment(TaskComment $comment)
    {
        $this->taskBoardService->deleteComment($comment->id);

        return response()->json(['message' => '留言已刪除']);
    }

    /**
     * Ajax 取得任務活動紀錄
     *
     * @param Task $task
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function ajaxActivities(Task $task)
    {
        $activities = $this->taskBoardService->getActivities($task->id);

        return TaskActivityResource::collection($activities);
    }
}
