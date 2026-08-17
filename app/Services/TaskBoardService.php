<?php

namespace App\Services;

use App\Models\Task;
use App\Repositories\ProjectRepository;
use App\Repositories\StationRepository;
use App\Repositories\TaskRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\DB;

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

        return $this->taskRepository->create([
            'project_id'  => $params['project_id'],
            'station_id'  => $params['station_id'] ?? null,
            'title'       => $params['title'],
            'description' => $params['description'] ?? null,
            'status'      => (int) ($params['status'] ?? config('constants.TASK.STATUS.PENDING')),
            'priority'    => (int) ($params['priority'] ?? config('constants.TASK.PRIORITY.MEDIUM')),
            'assignee_id' => $params['assignee_id'] ?? null,
            'creator_id'  => $creatorId,
            'due_date'    => $params['due_date'] ?? null,
            'sort_order'  => $maxSort + 1,
        ]);
    }

    /**
     * 更新任務
     *
     * @param Task  $task
     * @param array $params
     * @return Task
     */
    public function updateTask(Task $task, $params)
    {
        return $this->taskRepository->update($task, $params);
    }

    /**
     * 移動任務（拖曳換欄位）
     *
     * @param Task  $task
     * @param array $params ['status' => int, 'sort_order' => int]
     * @return Task
     */
    public function moveTask(Task $task, $params)
    {
        return DB::transaction(function () use ($task, $params) {
            return $this->taskRepository->update($task, [
                'status'     => (int) $params['status'],
                'sort_order' => (int) $params['sort_order'],
            ]);
        });
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
    public function deleteTask(Task $task)
    {
        $this->taskRepository->delete($task);
    }

    /**
     * 取得所有啟用專案
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
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
     * @return \App\Models\TaskComment
     */
    public function addComment($taskId, $userId, $content)
    {
        return $this->taskRepository->createComment([
            'task_id' => $taskId,
            'user_id' => $userId,
            'content' => $content,
        ]);
    }
}
