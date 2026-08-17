<?php

namespace App\Repositories;

use App\Models\Task;
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
        'assignee_id', 'creator_id', 'due_date', 'sort_order', 'created_at',
    ];

    /** @var array 詳細欄位 */
    private const DETAIL_COLUMNS = [
        'id', 'project_id', 'station_id', 'title', 'description', 'status', 'priority',
        'assignee_id', 'creator_id', 'due_date', 'sort_order', 'created_at', 'updated_at',
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
            ->orderBy('sort_order');

        if (filled($criteria['project_id'] ?? null)) {
            $query->where('project_id', (int) $criteria['project_id']);
        }

        if (filled($criteria['assignee_id'] ?? null)) {
            $query->where('assignee_id', (int) $criteria['assignee_id']);
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
            ->select(['id', 'task_id', 'user_id', 'content', 'created_at'])
            ->with(['user'])
            ->where('task_id', $taskId)
            ->orderByDesc('created_at')
            ->get();
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
}
