<?php

namespace App\Repositories;

use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskComment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * 任務 Repository
 */
class TaskRepository
{
    /** @var array 列表欄位 */
    private const LIST_COLUMNS = [
        'id', 'project_id', 'station_id', 'title', 'status', 'priority',
        'assignee_id', 'assignee_ids', 'participant_ids', 'creator_id', 'due_date', 'sort_order', 'created_at', 'updated_at',
    ];

    /** @var array 詳細欄位 */
    private const DETAIL_COLUMNS = [
        'id', 'project_id', 'station_id', 'title', 'description', 'images', 'status', 'priority',
        'assignee_id', 'assignee_ids', 'participant_ids', 'creator_id', 'due_date', 'sort_order', 'created_at', 'updated_at',
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
            /*
             * ⚠ **不要再把 `assignee` 加回來**：那是舊的單人 `assignee_id`
             * 關聯，自從改成多人指派（`assignee_ids`）之後**全專案沒有一處
             * 讀它** —— `TaskResource` 只用 `assignee_ids`／`assignees`。
             * 留著等於每次載看板都白跑一趟查詢，而 `assignee_id` 是 null 時
             * 那一趟還會是 `select … from user where 0 = 1`。
             */
            ->with(['project', 'station.system', 'creator'])
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
        /*
         * ⚠⚠ **這一行的語意是 fail-open：空的就不過濾。**
         *
         * 那是給管理者用的（他本來就看得到全部），但也因此
         * **呼叫端一旦不小心傳了空陣列，過濾就整個消失**。
         * 2026-11-01 就踩過：`$user->project_ids ?? [-1]` 的 `??` 擋不住
         * 空陣列，於是一個專案都沒勾的帳號看得到全部卡片。
         *
         * 所以非管理者「一個都沒勾」時，呼叫端必須傳 `[-1]`（一個不存在的
         * id）而不是 `[]` —— 見 `TaskBoardController::buildBoard()`。
         *
         * ⚠ 要改這裡的語意（例如改成 null 才是不限制）**得把所有呼叫端
         * 一起改**，而漏掉的那一個就是下一個漏洞。
         */
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

        $this->filterRelatedToMe($query, $criteria);

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
     * 「與我相關」的兩個篩選（需求方 2026-10-30）
     *
     * 需求：「我要能搜尋有參與的卡片**或**指派人是我的卡片。」
     *
     * | 勾選 | 條件 |
     * |---|---|
     * | 我參與的 | `participant_ids` 含我 |
     * | 指派給我的 | `assignee_id` 是我 **或** `assignee_ids` 含我 |
     * | 兩個都勾 | 上面兩者**取聯集**（OR）|
     *
     * ⚠ **兩個都勾一定要 OR 不是 AND**：需求的原文是「或」。做成 AND 的話
     * 只會列出「既是參與人又是指派人」的卡片 —— 那是極少數，
     * 使用者會以為篩選壞了。
     *
     * ⚠ 所以兩個條件**必須包在同一個 `where(function ...)` 裡**。
     * 分開加兩個 `where` 會變成
     * `AND participant… AND assignee…`，兩個都勾就什麼都搜不到。
     *
     * ⚠ `whereJsonContains` **要同時比整數與字串**：前端送上來的可能是
     * `["3"]`，只用整數比會全部對不上 —— 而且**不會報錯，只會搜不到**。
     * `assignee_id` 那邊已經踩過同一個坑。
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array                                 $criteria
     * @return void
     */
    private function filterRelatedToMe($query, $criteria)
    {
        $userId = (int) Arr::get($criteria, 'me_id');
        $participating = (bool) Arr::get($criteria, 'me_participating');
        $assigned = (bool) Arr::get($criteria, 'me_assigned');

        // 短路：沒勾任何一個就不要動查詢
        if ($userId < 1 || (!$participating && !$assigned)) {
            return;
        }

        $query->where(function ($outer) use ($userId, $participating, $assigned) {
            if ($participating) {
                $outer->orWhereJsonContains('participant_ids', $userId)
                    ->orWhereJsonContains('participant_ids', (string) $userId);
            }

            if (!$assigned) {
                return;
            }

            /*
             * ⚠ 指派人員那三個條件要再包一層：它們之間是 OR，但整組跟
             * 「我參與的」之間也是 OR —— 不包的話 `assignee_id = ?` 那一條
             * 會跟外層的其他條件混在一起（Laravel 的 orWhere 不會自己分組）。
             */
            $outer->orWhere(function ($inner) use ($userId) {
                $inner->where('assignee_id', $userId)
                    ->orWhereJsonContains('assignee_ids', $userId)
                    ->orWhereJsonContains('assignee_ids', (string) $userId);
            });
        });
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
     * 查某個人的卡片用：撈還沒封存的（含已解決）
     *
     * ⚠ 跟 `getOpenForNotice()` 差在**要帶已解決的**：這支指令要回報
     * 「兩天內完成了什麼」，而通知那支問的是「手上還剩什麼」。
     *
     * ⚠ 一樣一次撈完再在 PHP 分人（理由同 `getOpenForNotice()`：
     * `assignee_ids` 存的是 JSON 字串而且欄位型別是 longtext，
     * `whereJsonContains` 不保證可用，逐人查又是一人一趟）。
     *
     * @return Collection
     */
    public function getForAssigneeQuery()
    {
        $query = Task::query()
            ->select(['id', 'project_id', 'title', 'status', 'priority', 'assignee_id', 'assignee_ids', 'due_date', 'updated_at'])
            ->with('project')
            ->where('status', '!=', config('constants.TASK.STATUS.ARCHIVED'))
            // 逾期最久的排前面；沒設期限的排最後
            ->orderByRaw('due_date IS NULL, due_date ASC')
            ->orderBy('id');

        $this->onlyActiveProject($query);

        return $query->get();
    }

    /**
     * 最近 N 天內「被移到某個狀態」的紀錄
     *
     * ⚠ **`task` 表沒有 `resolved_at`**，所以「什麼時候完成的」只能從
     * 活動紀錄回推。不能用 `task.updated_at` —— 卡片解決之後任何一次編輯
     * （改標題、改指派人）都會把它往後推，於是一張上個月解決的卡會被算成
     * 「兩天內完成」。
     *
     * ⚠ `changes` 欄位是 longtext 存 JSON，所以**撈回來在 PHP 比對**
     * `狀態.to`，不在 SQL 裡解 JSON。兩天內的活動量很小。
     *
     * @param int $days
     * @return Collection
     */
    public function getRecentStatusMoves($days)
    {
        return TaskActivity::query()
            ->select(['id', 'task_id', 'action', 'changes', 'created_at'])
            ->where('action', 'moved')
            ->where('created_at', '>=', now()->subDays((int) $days))
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * 這幾張卡分別屬於哪些專案（去重）
     *
     * 給「批次操作前先確認每一張都碰得到」用的 —— 例如拖曳排序一次送
     * 一整欄的 id。
     *
     * ⚠ 只撈 `project_id` 一欄：這是權限判斷，不需要卡片的其他內容。
     *
     * @param array<int> $taskIds
     * @return array<int>
     */
    public function getProjectIdsByTaskIds(array $taskIds)
    {
        if (blank($taskIds)) {
            return [];
        }

        return Task::query()
            ->whereIn('id', $taskIds)
            ->distinct()
            ->pluck('project_id')
            ->map(function ($projectId) {
                return (int) $projectId;
            })
            ->all();
    }

    /**
     * 還沒處理過通知的活動紀錄
     *
     * ⚠ **只撈 `notified_at` 是 null 的**，而且 `created_at` 由舊到新 ——
     * 組訊息時要照時間順序講「從什麼改成什麼」。
     *
     * ⚠ **不在這裡篩「安靜夠久了沒」**：那是商業判斷（窗口幾分鐘、
     * 卡片還在被改就要繼續等），放 Service。Repository 只負責把
     * 「還沒處理的」撈出來。
     *
     * ⚠ 要 `user` 關聯：訊息裡要寫異動人是誰。
     * `select` 不含 `description` 之類的大欄位 —— 這支會被每分鐘呼叫。
     *
     * @return Collection
     */
    public function getUnnotifiedActivities()
    {
        return TaskActivity::query()
            ->select(['id', 'task_id', 'user_id', 'action', 'changes', 'created_at'])
            ->with('user')
            ->whereNull('notified_at')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * 把這幾筆活動紀錄標記成「通知處理過了」
     *
     * ⚠ **不論有沒有真的發出去都要標**：只有非通知欄位的異動（例如只改了
     * 描述）也必須標掉，否則排程每分鐘都會再撈到它一次，永遠掃不完。
     *
     * ⚠ 用 `whereIn` 一次更新，不要逐筆 save —— 一次合併可能涵蓋十幾筆。
     *
     * @param array<int> $ids
     * @return void
     */
    public function markActivitiesNotified(array $ids)
    {
        if (blank($ids)) {
            return;
        }

        TaskActivity::query()->whereIn('id', $ids)->update(['notified_at' => now()]);
    }

    /**
     * 還沒處理過通知的留言
     *
     * ⚠ **留言不走 `logActivity()`**（這個專案刻意不把留言寫進活動紀錄），
     * 所以它有自己的 `notified_at`，查詢也是獨立一支。
     *
     * ⚠ `select` **不含 `content`**：這一支每分鐘跑一次，而留言內容可能很長
     * 又帶圖片的 HTML。通知只需要「誰留了幾則」—— 內容要看就回卡片裡看。
     *
     * @return Collection
     */
    public function getUnnotifiedComments()
    {
        return TaskComment::query()
            ->select(['id', 'task_id', 'user_id', 'created_at'])
            ->with('user')
            ->whereNull('notified_at')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * 把這幾則留言標記成「通知處理過了」
     *
     * @param array<int> $ids
     * @return void
     */
    public function markCommentsNotified(array $ids)
    {
        if (blank($ids)) {
            return;
        }

        TaskComment::query()->whereIn('id', $ids)->update(['notified_at' => now()]);
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
            /*
             * ⚠ **不要再把 `assignee` 加回來**：那是舊的單人 `assignee_id`
             * 關聯，自從改成多人指派（`assignee_ids`）之後**全專案沒有一處
             * 讀它** —— `TaskResource` 只用 `assignee_ids`／`assignees`。
             * 留著等於每次載看板都白跑一趟查詢，而 `assignee_id` 是 null 時
             * 那一趟還會是 `select … from user where 0 = 1`。
             */
            ->with(['project', 'station.system', 'creator'])
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

    /**
     * 新增任務活動紀錄
     *
     * @param array $attributes
     * @return TaskActivity
     */
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
