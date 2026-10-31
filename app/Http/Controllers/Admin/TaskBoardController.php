<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\TaskBoard\DeleteAttachmentRequest;
use App\Http\Requests\TaskBoard\MoveTaskRequest;
use App\Http\Requests\TaskBoard\ReorderTaskRequest;
use App\Http\Requests\TaskBoard\StoreCommentRequest;
use App\Http\Requests\TaskBoard\StoreTaskRequest;
use App\Http\Requests\TaskBoard\UpdateChecklistRequest;
use App\Http\Requests\TaskBoard\UpdateCommentRequest;
use App\Http\Requests\TaskBoard\UpdateTaskRequest;
use App\Http\Requests\TaskBoard\UploadEditorFileRequest;
use App\Http\Requests\TaskBoard\UploadEditorImageRequest;
use App\Http\Resources\TaskActivityResource;
use App\Http\Resources\TaskCommentResource;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Models\TaskComment;
use App\Services\ImageUploadService;
use App\Services\TaskBoardService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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
        $allProjects = $this->taskBoardService->getProjects();

        /*
         * 篩選下拉只列得出這個人參與的專案。
         *
         * ⚠ 判準用 `canAccessProject()`（它對管理者一律回 true），
         * **不要自己比 `level` 或 `in_array(project_ids)`** —— 原本這裡是
         * 自己寫的一份，而 `buildBoard()` 又是另一份，兩邊的空陣列處理
         * 還不一樣（見那邊的註解：看板那份有漏洞）。
         */
        $projects = $allProjects->filter(function ($project) use ($user) {
            return $user->canAccessProject($project->id);
        })->values();
        $assignees = $this->taskBoardService->getAssignees();
        $stations = $this->taskBoardService->getStations();
        $systems = $this->taskBoardService->getSystems();

        return view('admin.task-board.index', [
            'projects'  => $projects,
            'assignees' => $assignees,
            'stations'  => $stations,
            'systems'   => $systems,
            // 首屏的卡片直接跟著頁面送出去，前端就不必再等一趟 ajax。
            // 用預設條件（無篩選、created_desc）—— 前端若記著別的排序會自己重載
            'board'     => $this->buildBoard([]),
        ]);
    }

    /**
     * 這個人碰得到這張卡嗎
     *
     * ⚠ **只擋畫面擋不住。** 看板列表會依「參與專案」過濾
     * （`buildBoard()` 的 `user_project_ids`），但**吃單筆的 API 網址上
     * 只有一個 id** —— 沒參與那個專案的人只要改一下數字就打得到。
     *
     * 2026-11-01 補上這一關之前，十幾支單筆端點（看詳細、移動、封存、
     * 留言、刪附件⋯⋯）全部沒有判斷。系統規章那次的結論就是這一句：
     * **每一支都要再過一次，連唯讀的也要**（見 features/project-regulation）。
     *
     * ⚠ 判準用 `User::canAccessProject()`，**不要在這裡自己比
     * `project_ids`** —— 那是同一條規則的第二份實作，分岔的那一邊就是漏洞。
     * 它對管理者一律回 true。
     *
     * @param Task $task
     * @return bool
     */
    private function canTouchTask(Task $task)
    {
        return Auth::user()->canAccessProject((int) $task->project_id);
    }

    /**
     * 這一整批卡片都碰得到嗎
     *
     * ⚠ **一張碰不到就回 false**（整批拒絕）：正常的前端不會送出看不到的
     * 卡片 id，會送就代表有問題 —— 悄悄濾掉一半只會讓那個問題更難發現。
     *
     * ⚠ 先去查每一張卡的 `project_id` 再判斷，**不要相信前端送來的任何
     * 專案資訊** —— 那是使用者送的，改得動。
     *
     * @param array<int> $taskIds
     * @return bool
     */
    private function canTouchAllTasks(array $taskIds)
    {
        $user = Auth::user();

        foreach ($this->taskBoardService->projectIdsOfTasks($taskIds) as $projectId) {
            if (!$user->canAccessProject($projectId)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 這個人碰得到這則留言所屬的卡片嗎
     *
     * ⚠ 留言的網址上只有 `comment` 的 id，所以要繞一層回卡片才知道專案。
     *
     * ⚠ 卡片查不到時**回 false 不是 true**：那代表資料不一致
     * （FK 是 cascadeOnDelete，正常不會發生），而「判斷不出來」的時候
     * 要擋住而不是放行。
     *
     * @param TaskComment $comment
     * @return bool
     */
    private function canTouchComment(TaskComment $comment)
    {
        $task = $comment->task;

        return filled($task) && $this->canTouchTask($task);
    }

    /**
     * 擋下來時回的 403
     *
     * ⚠ 訊息是「沒參與這個專案」不是「沒有權限」—— 兩者的下一步不同：
     * 前者去找管理者加參與專案，後者去要權限。
     *
     * @return \Illuminate\Http\JsonResponse
     */
    private function noAccessResponse()
    {
        return response()->json(['message' => trans('task_board.msg.no_access')], 403);
    }

    /**
     * 組五欄的看板資料
     *
     * 頁面首屏與 ajax 共用同一份，格式必須一致 ——
     * 前端拿到哪一種都是走同一支 render。
     *
     * @param array $params project_id / assignee_id / priority / keyword / sort
     * @return array<string, \Illuminate\Http\Resources\Json\AnonymousResourceCollection>
     */
    private function buildBoard(array $params)
    {
        $user = Auth::user();

        /*
         * ⚠⚠ **`?: [-1]` 的 `?:` 不可以寫回 `??`。**
         *
         * 原本是 `$user->project_ids ?? [-1]` —— 而 `??` 只擋 null，
         * **擋不住空陣列**。一個專案都沒勾的帳號 `project_ids` 是 `[]`，
         * 於是傳給 Repository 的是 `[]`，而那邊的判斷是
         * `if (!empty($criteria['user_project_ids']))` ——
         * 空陣列直接跳過過濾，**那個人就看得到全部卡片**。
         *
         * 文件寫的規則正好相反：「沒勾任何參與專案＝**一個都看不到**，
         * 不是『不限制』」。2026-11-01 補單筆 API 的權限時實測發現的
         * （那之前畫面上的篩選下拉說「沒有專案」、看板卻列出全部，
         * 兩邊自相矛盾也沒有人注意到）。
         *
         * ⚠ `[]` 這個值在 Repository 的語意是「**不限制**」，所以它只能給
         * 管理者用。非管理者沒勾任何專案時要給 `[-1]`（一個不存在的 id），
         * 才會真的篩成空的。
         *
         * ⚠ 判準用 `isAdmin()` 與 `projectIdList()` 這兩支 helper，
         * **不要自己比 `level` 或讀 `project_ids`** —— 同一條規則的第二份
         * 實作遲早會分岔，而分岔的那一邊就是資料外洩。
         */
        $params['user_project_ids'] = $user->isAdmin() ? [] : ($user->projectIdList() ?: [-1]);
        $board = $this->taskBoardService->getBoard($params);

        /*
         * 預載所有人員（避免 N+1）。
         *
         * ⚠ **指派人員與參與人都要收進來**：少收一邊的話，那一邊在畫面上會
         * 安靜地變成空的（`TaskResource::namesOf()` 快取沒命中就跳過），
         * 而且不會有任何錯誤 —— 2026-10-06 就是為同一類問題修過一次。
         */
        $allIds = [];

        foreach ($board as $tasks) {
            foreach ($tasks as $task) {
                $ids = array_merge((array) $task->assignee_ids, (array) $task->participant_ids);

                foreach ($ids as $id) {
                    $allIds[] = (int) $id;
                }
            }
        }

        TaskResource::preloadUsers(array_unique($allIds));

        return [
            'pending'     => TaskResource::collection($board['pending']),
            'in_progress' => TaskResource::collection($board['in_progress']),
            'testing'     => TaskResource::collection($board['testing']),
            'in_review'   => TaskResource::collection($board['in_review']),
            'resolved'    => TaskResource::collection($board['resolved']),
        ];
    }

    /**
     * Ajax 取得看板資料（四欄分組）
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxBoard(Request $request)
    {
        $params = $request->only([
            'project_id', 'assignee_id', 'priority', 'keyword', 'sort',
            // 「與我相關」的兩個勾選（需求方 2026-10-30）
            'me_participating', 'me_assigned',
        ]);

        /*
         * ⚠ **「我」是誰由後端決定，不要從前端收 user id**：
         * 收的話任何人都能改網址去看別人的卡片。前端只送「要不要篩」，
         * 是誰永遠是 `Auth::id()`。
         */
        $params['me_id'] = Auth::id();

        return response()->json($this->buildBoard($params));
    }

    /**
     * Ajax 取得任務詳細
     *
     * @param Task $task
     * @return TaskResource
     */
    public function ajaxTaskDetail(Task $task)
    {
        // ⚠ 連唯讀的也要擋：網址上只有一個 id（理由見 `canTouchTask()`）
        if (!$this->canTouchTask($task)) {
            return $this->noAccessResponse();
        }

        $task = $this->taskBoardService->getTask($task->id);
        TaskResource::preloadUsers($task->assignee_ids ?? []);

        return new TaskResource($task);
    }

    /**
     * Ajax 新增任務
     *
     * @param StoreTaskRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxStoreTask(StoreTaskRequest $request)
    {
        $params = $request->validated();
        $params['images'] = $request->hasFile('images')
            // 附件不限圖片，檔名保留原始名稱才看得出是什麼檔
            ? $this->imageUploadService->uploadMultipleKeepName($request->file('images'), 'task')
            : [];

        try {
            $task = $this->taskBoardService->storeTask($params, Auth::id());

            return response()->json([
                'message' => trans('task_board.msg.task_created'),
                'task'    => new TaskResource($task->load(['project', 'creator'])),
            ]);
        } catch (\Exception $e) {
            Log::error('任務新增失敗', ['error' => $e->getMessage()]);

            return response()->json(['message' => trans('task_board.msg.task_create_failed')], 500);
        }
    }

    /**
     * Ajax 更新任務
     *
     * @param UpdateTaskRequest $request
     * @param Task              $task
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxUpdateTask(UpdateTaskRequest $request, Task $task)
    {
        if (!$this->canTouchTask($task)) {
            return $this->noAccessResponse();
        }

        $params = $request->validated();
        if ($request->hasFile('images')) {
            $existing = $task->images ?? [];
            $newImages = $this->imageUploadService->uploadMultipleKeepName($request->file('images'), 'task');
            $params['images'] = array_merge($existing, $newImages);
        }

        try {
            $task = $this->taskBoardService->updateTask($task, $params, Auth::id());

            return response()->json([
                'message' => trans('task_board.msg.task_updated'),
                'task'    => new TaskResource($task->load(['project', 'creator'])),
            ]);
        } catch (\Exception $e) {
            Log::error('任務更新失敗', ['error' => $e->getMessage(), 'task_id' => $task->id]);

            return response()->json(['message' => trans('task_board.msg.task_update_failed')], 500);
        }
    }

    /**
     * Ajax 刪除任務單一附件
     *
     * @param DeleteAttachmentRequest $request
     * @param Task                    $task
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxDeleteAttachment(DeleteAttachmentRequest $request, Task $task)
    {
        if (!$this->canTouchTask($task)) {
            return $this->noAccessResponse();
        }

        $params = $request->validated();

        try {
            $deleted = $this->taskBoardService->deleteAttachment($task, $params['url'], Auth::id());

            if (!$deleted) {
                return response()->json(['message' => trans('task_board.msg.attachment_not_found')], 404);
            }

            return response()->json(['message' => trans('task_board.msg.attachment_deleted')]);
        } catch (\Exception $e) {
            Log::error('任務附件刪除失敗', ['error' => $e->getMessage(), 'task_id' => $task->id]);

            return response()->json(['message' => trans('task_board.msg.attachment_delete_failed')], 500);
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
        if (!$this->canTouchTask($task)) {
            return $this->noAccessResponse();
        }

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
        if (!$this->canTouchTask($task)) {
            return $this->noAccessResponse();
        }

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
        if (!$this->canTouchTask($task)) {
            return $this->noAccessResponse();
        }

        $params = $request->validated();

        $task = $this->taskBoardService->moveTask($task, $params, Auth::id());

        return new TaskResource($task->load(['project', 'creator']));
    }

    /**
     * Ajax 批次重排
     *
     * @param ReorderTaskRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxReorder(ReorderTaskRequest $request)
    {
        $params = $request->validated();

        /*
         * ⚠ 批次操作也要擋。這一支一次收一整欄的 id，而排序雖然只是
         * `sort_order`，仍然是「改別人專案的資料」。
         *
         * ⚠ **有一張碰不到就整批拒絕**，不要只把碰不到的濾掉 ——
         * 正常的前端不會送出看不到的卡片 id，會送就代表有問題，
         * 悄悄做掉一半只會讓那個問題更難發現。
         */
        if (!$this->canTouchAllTasks(Arr::pluck($params['orders'], 'id'))) {
            return $this->noAccessResponse();
        }

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
     * @param UploadEditorImageRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxUploadEditorImage(UploadEditorImageRequest $request)
    {
        $path = $this->imageUploadService->upload($request->file('file'), 'task-editor');

        return response()->json(['location' => asset("storage/{$path}")]);
    }

    /**
     * Ajax 上傳任務描述的附件（圖片以外的檔案）
     *
     * @param UploadEditorFileRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxUploadEditorFile(UploadEditorFileRequest $request)
    {
        try {
            $file = $request->file('file');

            // 附件必須看得出原始檔名，走 uploadKeepName 而非 upload 的 uniqid 命名
            $path = $this->imageUploadService->uploadKeepName($file, 'task-editor');

            return response()->json([
                'location' => asset("storage/{$path}"),
                'name'     => $file->getClientOriginalName(),
            ]);
        } catch (\Exception $e) {
            Log::error('任務附件上傳失敗', ['error' => $e->getMessage()]);

            return response()->json(['message' => trans('task_board.msg.file_upload_failed')], 500);
        }
    }

    /**
     * Ajax 更新描述中的勾選清單狀態
     *
     * 走獨立端點而非 ajaxUpdateTask，避免每勾一次就寫一筆活動紀錄。
     *
     * @param UpdateChecklistRequest $request
     * @param Task                   $task
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxUpdateChecklist(UpdateChecklistRequest $request, Task $task)
    {
        if (!$this->canTouchTask($task)) {
            return $this->noAccessResponse();
        }

        $params = $request->validated();

        try {
            $this->taskBoardService->updateChecklist($task, $params['description']);

            return response()->json(['message' => trans('task_board.msg.checklist_updated')]);
        } catch (\Exception $e) {
            Log::error('勾選清單更新失敗', ['error' => $e->getMessage(), 'task_id' => $task->id]);

            return response()->json(['message' => trans('task_board.msg.checklist_update_failed')], 500);
        }
    }

    /**
     * Ajax 取得任務留言
     *
     * @param Task $task
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxComments(Task $task)
    {
        // ⚠ 唯讀也要擋：留言裡常常有客戶資訊與處理細節
        if (!$this->canTouchTask($task)) {
            return $this->noAccessResponse();
        }

        $comments = $this->taskBoardService->getComments($task->id);

        return TaskCommentResource::collection($comments);
    }

    /**
     * Ajax 新增留言
     *
     * @param StoreCommentRequest $request
     * @param Task                $task
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxStoreComment(StoreCommentRequest $request, Task $task)
    {
        if (!$this->canTouchTask($task)) {
            return $this->noAccessResponse();
        }

        $params = $request->validated();
        // 用 KeepName 而非 uploadMultiple：留言附件不限圖片，
        // uniqid 命名會讓下載下來的檔案完全看不出是什麼
        $images = $request->hasFile('images')
            ? $this->imageUploadService->uploadMultipleKeepName($request->file('images'), 'task-comment')
            : [];

        $comment = $this->taskBoardService->addComment($task->id, Auth::id(), $params['content'], $images);

        return new TaskCommentResource($comment->load('user'));
    }

    /**
     * Ajax 更新留言（僅本人可編輯自己的留言，圖片不受影響）
     *
     * @param UpdateCommentRequest $request
     * @param TaskComment          $comment
     * @return \Illuminate\Http\JsonResponse|TaskCommentResource
     */
    public function ajaxUpdateComment(UpdateCommentRequest $request, TaskComment $comment)
    {
        /*
         * ⚠ 這一關**不能靠下面的「只有本人能編輯」代替**：補上
         * `ajaxStoreComment()` 的檢查之前，沒參與專案的人留得了言 ——
         * 那些舊留言的作者就是本人，照樣改得動。
         */
        if (!$this->canTouchComment($comment)) {
            return $this->noAccessResponse();
        }

        if ($comment->user_id !== Auth::id()) {
            return response()->json(['message' => trans('task_board.msg.comment_not_owner')], 403);
        }

        $params = $request->validated();

        try {
            $comment = $this->taskBoardService->updateComment($comment, $params['content']);

            return new TaskCommentResource($comment->load('user'));
        } catch (\Exception $e) {
            Log::error('留言更新失敗', ['error' => $e->getMessage(), 'comment_id' => $comment->id]);

            return response()->json(['message' => trans('task_board.msg.comment_update_failed')], 500);
        }
    }

    /**
     * Ajax 刪除留言
     *
     * @param TaskComment $comment
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxDeleteComment(TaskComment $comment)
    {
        /*
         * ⚠ 這一支原本**完全沒有判斷** —— 有 `task_board.delete_comment`
         * 權限的人，只要改一下網址上的 id 就刪得掉任何一則留言，
         * 包含他沒參與的專案裡的。
         */
        if (!$this->canTouchComment($comment)) {
            return $this->noAccessResponse();
        }

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
        // ⚠ 唯讀也要擋：異動紀錄看得出這張卡的標題、經手人與時間軸
        if (!$this->canTouchTask($task)) {
            return $this->noAccessResponse();
        }

        $activities = $this->taskBoardService->getActivities($task->id);

        return TaskActivityResource::collection($activities);
    }
}
