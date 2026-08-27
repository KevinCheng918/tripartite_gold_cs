<?php

namespace App\Repositories;

use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskComment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 任務 Repository
 */
class TaskRepository
{
    /** @var array 列表欄位 */
    private const LIST_COLUMNS = [
        'id', 'project_id', 'station_id', 'title', 'status', 'priority',
        'assignee_id', 'assignee_ids', 'creator_id', 'due_date', 'sort_order', 'created_at', 'updated_at',
    ];

    /** @var array 詳細欄位 */
    private const DETAIL_COLUMNS = [
        'id', 'project_id', 'station_id', 'title', 'description', 'images', 'status', 'priority',
        'assignee_id', 'assignee_ids', 'creator_id', 'due_date', 'sort_order', 'created_at', 'updated_at',
    ];

    /**
     * 取得看板資料（按 status 分組）
     *
     * @param array $criteria 篩選條件
     * @return Collection
     */
    public function getBoard($criteria = [])
    {
        $query = Task::query()
            ->select(self::LIST_COLUMNS)
            ->with(['project', 'station.system', 'assignee', 'creator'])
            ->where('status', '!=', config('constants.TASK.STATUS.ARCHIVED'));

        // 排序
        $sort = $criteria['sort'] ?? 'created_desc';
        $sortMap = [
            'priority_desc' => ['priority', 'desc'],
            'priority_asc'  => ['priority', 'asc'],
            'created_desc'  => ['created_at', 'desc'],
            'created_asc'   => ['created_at', 'asc'],
            'updated_desc'  => ['updated_at', 'desc'],
            'updated_asc'   => ['updated_at', 'asc'],
            'due_date_desc' => ['due_date', 'desc'],
            'due_date_asc'  => ['due_date', 'asc'],
            'sort_order'    => ['sort_order', 'asc'],
        ];
        if (isset($sortMap[$sort])) {
            $query->orderBy($sortMap[$sort][0], $sortMap[$sort][1]);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        // 只顯示用戶參與的專案
        if (!empty($criteria['user_project_ids'])) {
            $query->whereIn('project_id', $criteria['user_project_ids']);
        }

        if (filled($criteria['project_id'] ?? null)) {
            $query->where('project_id', (int) $criteria['project_id']);
        }

        if (filled($criteria['assignee_id'] ?? null)) {
            $id = (int) $criteria['assignee_id'];
            $query->where(function ($q) use ($id) {
                $q->where('assignee_id', $id)
                  ->orWhereJsonContains('assignee_ids', $id)
                  ->orWhereJsonContains('assignee_ids', (string) $id);
            });
        }

        if (filled($criteria['priority'] ?? null)) {
            $query->where('priority', (int) $criteria['priority']);
        }

        if (filled($criteria['keyword'] ?? null)) {
            $query->where('title', 'like', "%{$criteria['keyword']}%");
        }

        // 已解決只載入近 30 天
        $query->where(function ($q) {
            $q->where('status', '!=', config('constants.TASK.STATUS.RESOLVED'))
              ->orWhere('updated_at', '>=', now()->subDays(30));
        });

        return $query->get();
    }

    /**
     * 依 ID 查詢（含詳細描述）
     *
     * @param int $id
     * @return Task|null
     */
    public function find($id)
    {
        return Task::query()
            ->select(self::DETAIL_COLUMNS)
            ->with(['project', 'station.system', 'assignee', 'creator'])
            ->find($id);
    }

    /**
     * 新增
     *
     * @param array $attributes
     * @return Task
     */
    public function create($attributes)
    {
        return Task::query()->create($attributes);
    }

    /**
     * 更新
     *
     * @param Task  $task
     * @param array $attributes
     * @return Task
     */
    public function update(Task $task, $attributes)
    {
        $task->update($attributes);

        return $task->refresh();
    }

    /**
     * 刪除
     *
     * @param Task $task
     * @return void
     */
    public function delete(Task $task)
    {
        $task->delete();
    }

    /**
     * 取得某狀態欄位最大排序值
     *
     * @param int $status
     * @return int
     */
    public function getMaxSortOrder($status)
    {
        return (int) Task::query()
            ->where('status', $status)
            ->max('sort_order');
    }

    /**
     * 批次更新排序（DB Transaction）
     *
     * @param array $taskOrders [['id' => 1, 'sort_order' => 0], ...]
     * @return void
     */
    public function reorder(array $taskOrders)
    {
        DB::transaction(function () use ($taskOrders) {
            foreach ($taskOrders as $item) {
                Task::query()
                    ->where('id', $item['id'])
                    ->update(['sort_order' => $item['sort_order']]);
            }
        });
    }

    /**
     * 取得任務留言
     *
     * @param int $taskId
     * @return Collection
     */
    public function getComments($taskId)
    {
        return TaskComment::query()
            ->select(['id', 'task_id', 'user_id', 'content', 'images', 'created_at', 'updated_at'])
            ->with(['user'])
            ->where('task_id', $taskId)
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * 查詢留言
     *
     * @param int $commentId
     * @return TaskComment|null
     */
    public function findComment($commentId)
    {
        return TaskComment::query()->find($commentId);
    }

    /**
     * 刪除留言
     *
     * @param TaskComment $comment
     * @return void
     */
    public function deleteComment(TaskComment $comment)
    {
        $comment->delete();
    }

    /**
     * 新增留言
     *
     * @param array $attributes
     * @return TaskComment
     */
    public function createComment($attributes)
    {
        return TaskComment::query()->create($attributes);
    }

    /**
     * 更新留言（僅內容，圖片維持原樣）
     *
     * @param TaskComment $comment
     * @param string      $content
     * @return TaskComment
     */
    public function updateComment(TaskComment $comment, $content)
    {
        $comment->update(['content' => $content]);

        return $comment;
    }

    /**
     * 新增任務活動紀錄
     *
     * @param array $attributes
     * @return TaskActivity
     */
    /**
     * 取得封存任務
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getArchived()
    {
        return Task::query()
            ->select(self::LIST_COLUMNS)
            ->with(['project', 'creator', 'latestArchivedActivity'])
            ->where('status', config('constants.TASK.STATUS.ARCHIVED'))
            ->orderByDesc('updated_at')
            ->get();
    }

    /**
     * 刪除超過指定天數的封存任務
     *
     * @param int $days
     * @return void
     */
    public function deleteArchivedOlderThan($days)
    {
        Task::query()
            ->where('status', config('constants.TASK.STATUS.ARCHIVED'))
            ->where('updated_at', '<', now()->subDays($days))
            ->delete();
    }

    public function createActivity($attributes)
    {
        return TaskActivity::query()->create($attributes);
    }

    /**
     * 取得任務活動紀錄
     *
     * @param int $taskId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getActivities($taskId)
    {
        return TaskActivity::query()
            ->select(['id', 'task_id', 'user_id', 'action', 'changes', 'created_at'])
            ->with(['user'])
            ->where('task_id', $taskId)
            ->orderByDesc('created_at')
            ->get();
    }
}
