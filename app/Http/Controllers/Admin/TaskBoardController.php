<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskCommentResource;
use App\Http\Resources\TaskResource;
use App\Models\Task;
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

    public function __construct(TaskBoardService $taskBoardService)
    {
        $this->taskBoardService = $taskBoardService;
    }

    /**
     * 看板頁面
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $projects = $this->taskBoardService->getProjects();
        $assignees = $this->taskBoardService->getAssignees();

        return view('admin.task-board.index', [
            'projects'  => $projects,
            'assignees' => $assignees,
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
        $params = $request->only(['project_id', 'assignee_id', 'keyword']);
        $board = $this->taskBoardService->getBoard($params);

        return response()->json([
            'pending'     => TaskResource::collection($board['pending']),
            'in_progress' => TaskResource::collection($board['in_progress']),
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

        return new TaskResource($task);
    }

    /**
     * Ajax 新增任務
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxStoreTask(Request $request)
    {
        $params = $request->validate([
            'project_id'  => 'required|integer|exists:project,id',
            'title'       => 'required|string|max:200',
            'description' => 'nullable|string',
            'priority'    => 'nullable|integer|in:1,2,3,4',
            'assignee_id' => 'nullable|integer|exists:user,id',
            'due_date'    => 'nullable|date',
        ]);

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
    public function ajaxUpdateTask(Request $request, Task $task)
    {
        $params = $request->validate([
            'project_id'  => 'sometimes|integer|exists:project,id',
            'title'       => 'sometimes|string|max:200',
            'description' => 'nullable|string',
            'status'      => 'sometimes|integer|in:1,2,3,4',
            'priority'    => 'sometimes|integer|in:1,2,3,4',
            'assignee_id' => 'nullable|integer|exists:user,id',
            'due_date'    => 'nullable|date',
        ]);

        try {
            $task = $this->taskBoardService->updateTask($task, $params);

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
     * Ajax 刪除任務
     *
     * @param Task $task
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxDeleteTask(Task $task)
    {
        try {
            $this->taskBoardService->deleteTask($task);

            return response()->json(['message' => trans('task_board.msg.task_deleted')]);
        } catch (\Exception $e) {
            Log::error('任務刪除失敗', ['error' => $e->getMessage(), 'task_id' => $task->id]);

            return response()->json(['message' => trans('task_board.msg.task_delete_failed')], 500);
        }
    }

    /**
     * Ajax 拖曳移動任務（換狀態）
     *
     * @param Request $request
     * @param Task    $task
     * @return TaskResource
     */
    public function ajaxMoveTask(Request $request, Task $task)
    {
        $params = $request->validate([
            'status'     => 'required|integer|in:1,2,3,4',
            'sort_order' => 'required|integer|min:0',
        ]);

        $task = $this->taskBoardService->moveTask($task, $params);

        return new TaskResource($task->load(['project', 'assignee', 'creator']));
    }

    /**
     * Ajax 批次重排
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxReorder(Request $request)
    {
        $params = $request->validate([
            'orders'              => 'required|array',
            'orders.*.id'         => 'required|integer|exists:task,id',
            'orders.*.sort_order' => 'required|integer|min:0',
        ]);

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
    public function ajaxStoreProject(Request $request)
    {
        $params = $request->validate([
            'name'        => 'required|string|max:100|unique:project,name',
            'description' => 'nullable|string|max:500',
        ]);

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
    public function ajaxStoreComment(Request $request, Task $task)
    {
        $params = $request->validate([
            'content' => 'required|string|max:2000',
        ]);

        $comment = $this->taskBoardService->addComment($task->id, Auth::id(), $params['content']);

        return new TaskCommentResource($comment->load('user'));
    }
}
