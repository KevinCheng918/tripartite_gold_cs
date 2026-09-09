<?php

namespace App\Services;

use App\Models\Task;
use App\Repositories\ProjectRepository;
use App\Repositories\StationRepository;
use App\Repositories\TaskRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * 任務看板 Service
 */
class TaskBoardService
{
    private $taskRepository;
    private $projectRepository;
    private $userRepository;
    private $stationRepository;

    public function __construct(
        TaskRepository $taskRepository,
        ProjectRepository $projectRepository,
        UserRepository $userRepository,
        StationRepository $stationRepository
    ) {
        $this->taskRepository = $taskRepository;
        $this->projectRepository = $projectRepository;
        $this->userRepository = $userRepository;
        $this->stationRepository = $stationRepository;
    }

    /**
     * 取得看板資料（按四欄分組）
     *
     * @param array $params
     * @return array ['pending' => Collection, 'in_progress' => ..., 'in_review' => ..., 'resolved' => ...]
     */
    public function getBoard($params = [])
    {
        $tasks = $this->taskRepository->getBoard($params);

        $statusMap = config('constants.TASK.STATUS');

        return [
            'pending'     => $tasks->where('status', $statusMap['PENDING'])->values(),
            'in_progress' => $tasks->where('status', $statusMap['IN_PROGRESS'])->values(),
            'testing'     => $tasks->where('status', $statusMap['TESTING'])->values(),
            'in_review'   => $tasks->where('status', $statusMap['IN_REVIEW'])->values(),
            'resolved'    => $tasks->where('status', $statusMap['RESOLVED'])->values(),
        ];
    }

    /**
     * 取得任務詳細
     *
     * @param int $id
     * @return Task|null
     */
    public function getTask($id)
    {
        return $this->taskRepository->find($id);
    }

    /**
     * 新增任務
     *
     * @param array $params
     * @param int   $creatorId
     * @return Task
     */
    public function storeTask($params, $creatorId)
    {
        $maxSort = $this->taskRepository->getMaxSortOrder(
            (int) ($params['status'] ?? config('constants.TASK.STATUS.PENDING'))
        );

        $task = $this->taskRepository->create([
            'project_id'  => $params['project_id'],
            'station_id'  => $params['station_id'] ?? null,
            'title'       => $params['title'],
            'description' => $params['description'] ?? null,
            'status'      => (int) ($params['status'] ?? config('constants.TASK.STATUS.PENDING')),
            'priority'    => (int) ($params['priority'] ?? config('constants.TASK.PRIORITY.MEDIUM')),
            'assignee_ids' => array_map('intval', $params['assignee_ids'] ?? []),
            'creator_id'  => $creatorId,
            'due_date'    => $params['due_date'] ?? null,
            'images'      => $params['images'] ?? [],
            'sort_order'  => $maxSort + 1,
        ]);

        $this->logActivity($task->id, $creatorId, 'created', null);

        return $task;
    }

    /**
     * 更新任務
     *
     * @param Task  $task
     * @param array $params
     * @return Task
     */
    public function updateTask(Task $task, $params, $userId = null)
    {
        if (isset($params['assignee_ids'])) {
            $params['assignee_ids'] = array_map('intval', $params['assignee_ids']);
        }

        // 記錄變更差異
        $changes = [];
        $labelMap = [
            'title' => '標題', 'description' => '描述', 'priority' => '優先順序',
            'status' => '狀態', 'assignee_ids' => '指派人員', 'station_id' => '站台',
            'project_id' => '專案', 'due_date' => '到期日',
        ];
        foreach ($params as $key => $value) {
            if (isset($labelMap[$key]) && $task->{$key} != $value) {
                $changes[$labelMap[$key]] = ['from' => $task->{$key}, 'to' => $value];
            }
        }

        $result = $this->taskRepository->update($task, $params);

        if (!empty($changes)) {
            $this->logActivity($task->id, $userId, 'updated', $changes);
        }

        return $result;
    }

    /**
     * 刪除任務的單一附件
     *
     * 前端拿到的是 Resource 產出的完整 URL，這裡用同樣的方式把每個相對路徑
     * 轉成 URL 來比對，而不是去剝 URL 前綴 —— APP_URL 或子目錄部署變動時
     * 剝前綴會對不上，用同一套產生規則比對才不會有落差。
     *
     * 實體檔案刪除失敗不影響流程（可能已被手動清掉），只記 log；
     * 資料庫的參照移除才是使用者看得到的結果。
     *
     * @param Task        $task
     * @param string      $url    附件的完整 URL
     * @param int|null    $userId 操作者，用於活動紀錄
     * @return bool 找不到該附件時回 false
     */
    public function deleteAttachment(Task $task, $url, $userId = null)
    {
        $images = $task->images ?? [];

        $index = null;
        foreach ($images as $i => $path) {
            if (asset("storage/{$path}") === $url) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            return false;
        }

        $removed = $images[$index];
        unset($images[$index]);

        $this->taskRepository->update($task, ['images' => array_values($images)]);

        if (!Storage::disk('public')->delete($removed)) {
            Log::warning('任務附件實體檔案刪除失敗', [
                'task_id' => $task->id,
                'path'    => $removed,
            ]);
        }

        $this->logActivity($task->id, $userId, 'updated', [
            '附件' => ['from' => basename($removed), 'to' => '已刪除'],
        ]);

        return true;
    }

    /**
     * 更新任務描述中的勾選清單狀態
     *
     * 刻意不寫活動紀錄：勾選是高頻操作，走 updateTask() 會每勾一次就記一筆
     * 「描述變更」，而且 from/to 存的是整段 HTML，活動紀錄很快就被灌爆。
     *
     * @param Task   $task
     * @param string $description 已更新勾選狀態的完整描述 HTML
     * @return Task
     */
    public function updateChecklist(Task $task, $description)
    {
        return $this->taskRepository->update($task, ['description' => $description]);
    }

    /**
     * 移動任務（拖曳換欄位）
     *
     * @param Task  $task
     * @param array $params ['status' => int, 'sort_order' => int]
     * @return Task
     */
    public function moveTask(Task $task, $params, $userId = null)
    {
        $oldStatus = $task->status;
        $newStatus = (int) $params['status'];

        $result = DB::transaction(function () use ($task, $params, $newStatus) {
            return $this->taskRepository->update($task, [
                'status'     => $newStatus,
                'sort_order' => (int) $params['sort_order'],
            ]);
        });

        if ($oldStatus !== $newStatus) {
            $this->logActivity($task->id, $userId, 'moved', [
                '狀態' => ['from' => $oldStatus, 'to' => $newStatus],
            ]);
        }

        return $result;
    }

    /**
     * 批次重排
     *
     * @param array $taskOrders
     * @return void
     */
    public function reorder($taskOrders)
    {
        $this->taskRepository->reorder($taskOrders);
    }

    /**
     * 刪除任務
     *
     * @param Task $task
     * @return void
     */
    /**
     * 取得封存任務（30 天內）
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getArchivedTasks()
    {
        // 自動清理超過 30 天的封存任務
        $this->taskRepository->deleteArchivedOlderThan(30);

        return $this->taskRepository->getArchived();
    }

    /**
     * 還原封存任務
     *
     * @param Task     $task
     * @param int|null $userId
     * @return Task
     */
    public function restoreTask(Task $task, $userId = null)
    {
        $this->logActivity($task->id, $userId, 'restored', null);

        return $this->taskRepository->update($task, [
            'status' => config('constants.TASK.STATUS.PENDING'),
        ]);
    }

    public function archiveTask(Task $task, $userId = null)
    {
        $this->logActivity($task->id, $userId, 'archived', [
            'title' => $task->title,
            '狀態' => ['from' => $task->status, 'to' => config('constants.TASK.STATUS.ARCHIVED')],
        ]);

        return $this->taskRepository->update($task, [
            'status' => config('constants.TASK.STATUS.ARCHIVED'),
        ]);
    }

    /**
     * 取得所有啟用專案
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    /**
     * 記錄任務活動
     *
     * @param int         $taskId
     * @param int|null    $userId
     * @param string      $action
     * @param array|null  $changes
     * @return void
     */
    private function logActivity($taskId, $userId, $action, $changes = null)
    {
        $this->taskRepository->createActivity([
            'task_id' => $taskId,
            'user_id' => $userId,
            'action'  => $action,
            'changes' => $changes,
        ]);
    }

    /**
     * 取得任務活動紀錄
     *
     * @param int $taskId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getActivities($taskId)
    {
        return $this->taskRepository->getActivities($taskId);
    }

    public function getProjects()
    {
        return $this->projectRepository->getActive();
    }

    /**
     * 新增專案
     *
     * @param array $params
     * @param int   $creatorId
     * @return \App\Models\Project
     */
    public function storeProject($params, $creatorId)
    {
        return $this->projectRepository->create([
            'name'        => $params['name'],
            'description' => $params['description'] ?? null,
            'created_by'  => $creatorId,
        ]);
    }

    /**
     * 取得可指派人員列表
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    /**
     * 刪除留言
     *
     * @param int $commentId
     * @return void
     */
    public function deleteComment($commentId)
    {
        $comment = $this->taskRepository->findComment($commentId);
        if ($comment) {
            $this->taskRepository->deleteComment($comment);
        }
    }

    public function getAssignees()
    {
        return $this->userRepository->getActiveForDropdown();
    }

    /**
     * 取得所有站台（下拉選單用）
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getStations()
    {
        return $this->stationRepository->allForDropdown();
    }

    /**
     * 取得所有系統
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getSystems()
    {
        return $this->stationRepository->getActiveSystems();
    }

    /**
     * 取得任務留言
     *
     * @param int $taskId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getComments($taskId)
    {
        return $this->taskRepository->getComments($taskId);
    }

    /**
     * 新增留言
     *
     * @param int    $taskId
     * @param int    $userId
     * @param string $content
     * @param array  $images
     * @return \App\Models\TaskComment
     */
    public function addComment($taskId, $userId, $content, $images = [])
    {
        return $this->taskRepository->createComment([
            'task_id' => $taskId,
            'user_id' => $userId,
            'content' => $content,
            'images'  => $images,
        ]);
    }

    /**
     * 更新留言內容（圖片不受影響）
     *
     * @param \App\Models\TaskComment $comment
     * @param string                  $content
     * @return \App\Models\TaskComment
     */
    public function updateComment($comment, $content)
    {
        return $this->taskRepository->updateComment($comment, $content);
    }
}
