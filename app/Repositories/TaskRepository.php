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

        $this->onlyActiveProject($query);

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
     * 每日任務通知要統計的卡片
     *
     * 只撈**還沒結束**的（排除已解決與已封存）—— 通知要講的是「手上還有什麼」，
     * 結案的卡不該出現在明天早上的訊息裡。關閉的專案同理，見 onlyActiveProject()。
     *
     * ⚠ 一次撈完再在 PHP 分組，不逐人查：`assignee_ids` 是 JSON 陣列，
     * 逐人 `whereJsonContains` 就是一人一趟查詢，而卡片總數在內部看板的量級
     * 本來就不大。
     *
     * @return Collection
     */
    public function getOpenForNotice()
    {
        $closed = [
            config('constants.TASK.STATUS.RESOLVED'),
            config('constants.TASK.STATUS.ARCHIVED'),
        ];

        $query = Task::query()
            ->select(['id', 'project_id', 'title', 'status', 'priority', 'assignee_ids', 'due_date'])
            ->with('project')
            ->whereNotIn('status', $closed)
            // 逾期最久的排前面；沒設期限的排最後
            ->orderByRaw('due_date IS NULL, due_date ASC')
            ->orderBy('id');

        $this->onlyActiveProject($query);

        return $query->get();
    }

    /**
     * 只留下專案還開著的卡片
     *
     * 專案停用的意思是「這條線結束了」，它底下的卡片不該再出現在看板，
     * 也不該每天早上私訊提醒誰還有幾張沒做 —— 那會讓人以為還要處理
     * （需求方 2026-10-10）。
     *
     * ⚠ **只是不顯示，資料一筆都不動。** 專案重新啟用，卡片原樣全部回來。
     *
     * ⚠ 用 `whereHas` 而不是 join：`Task::project()` 這個關聯自己帶了
     * `select(['id', 'name'])`，**沒有 `status`**，join 進來讀不到要比的欄位，
     * 還得自己處理 `id` 撞名。`whereHas` 走 EXISTS 子查詢，關聯上的 select
     * 會被覆寫掉，不受影響。
     *
     * ⚠ 不必處理「沒有專案的卡片」：`task.project_id` 是 NOT NULL，
     * 而且 FK 是 cascadeOnDelete —— 專案被刪時卡片跟著刪，不會留下孤兒。
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return void
     */
    private function onlyActiveProject($query)
    {
        $active = config('constants.PROJECT.STATUS.ACTIVE');

        $query->whereHas('project', function ($q) use ($active) {
            $q->where('status', $active);
        });
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
        $query = Task::query()
            ->select(self::LIST_COLUMNS)
            ->with(['project', 'creator', 'latestArchivedActivity'])
            ->where('status', config('constants.TASK.STATUS.ARCHIVED'))
            ->orderByDesc('updated_at');

        /*
         * ⚠ 封存清單也要濾。它是看板同一頁的分頁，而且每一列都有「還原」——
         * 還原會把卡片丟回「待處理」，也就是丟回一個已經關閉、看板上根本
         * 不顯示的專案：按下去之後那張卡就人間蒸發了。
         */
        $this->onlyActiveProject($query);

        return $query->get();
    }

    /**
     * 刪除超過指定天數的封存任務
     *
     * ⚠ **這是真的 delete，不是改狀態。**
     *
     * ⚠ **停用專案的卡片不刪**（2026-10-10）。這支是在打開封存清單時順手跑的
     * （`TaskBoardService::getArchivedTasks()`），不是排程 —— 而封存清單現在
     * 不顯示停用專案的卡片。少了這個條件就會變成：
     *
     *   專案一關 → 它的封存卡從清單上消失（看不到、也救不回來）
     *   → 有人打開封存清單 → 超過 30 天的那些被永久刪掉
     *   → 專案重新啟用，那幾張再也回不來
     *
     * ⚠ **停用那段時間不計入那 30 天**（需求方 2026-10-10）。光是停用期間不刪
     * 還不夠：重新啟用的那一刻，已經放超過 30 天的卡還是會在下一次有人打開
     * 封存清單時立刻被清掉 —— 對使用者來說就是「改回正常了，卡片卻回不來」。
     *
     * 做法是重新啟用後**整個專案重新給 30 天**：`project.reactivated_at`
     * 距今未滿 30 天的專案，它的封存卡一張都不刪。
     *
     * 這比「扣掉實際暫停幾天」寬鬆（停用 2 天也是重新給 30 天），
     * 換來的是只需要一個欄位、而且 `task` 表完全不用動。
     *
     * @param int $days
     * @return void
     */
    public function deleteArchivedOlderThan($days)
    {
        $active = config('constants.PROJECT.STATUS.ACTIVE');
        $graceUntil = now()->subDays($days);

        $query = Task::query()
            ->where('status', config('constants.TASK.STATUS.ARCHIVED'))
            ->where('updated_at', '<', $graceUntil)
            ->whereHas('project', function ($q) use ($active, $graceUntil) {
                $q->where('status', $active)
                  // 從來沒重新啟用過 → 照舊規則；剛啟用不久 → 整個專案先不動
                  ->where(function ($p) use ($graceUntil) {
                      $p->whereNull('reactivated_at')
                        ->orWhere('reactivated_at', '<', $graceUntil);
                  });
            });

        $query->delete();
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
