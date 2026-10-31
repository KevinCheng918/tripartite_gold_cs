<?php

namespace App\Services;

use App\Models\Task;
use App\Repositories\ProjectRepository;
use App\Repositories\StationRepository;
use App\Repositories\TaskRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * 任務看板 Service
 */
class TaskBoardService
{
    /**
     * 活動紀錄裡「欄位名稱」的對照
     *
     * ⚠ **寫入與讀取共用這一份**（`updateTask()` 產生 `changes`、
     * `translateLegacyChanges()` 回頭找舊紀錄）—— 各寫一份的話，哪天改了
     * 其中一個標籤，舊紀錄的翻譯就會安靜地失效。
     *
     * ⚠ key 是欄位名、value 是**存進資料庫的中文標籤**。改 value 等於改
     * 資料格式：既有紀錄的 key 還是舊標籤，`translateLegacyChanges()` 會
     * 對不上。真要改就得連既有資料一起處理。
     *
     * ⚠ **public**：`TaskChangeNoticeService` 要靠它把
     * `constants.TASK.CHANGE_NOTICE.FIELDS` 那幾個欄位名換成 `changes` 裡的
     * 中文 key。那邊自己抄一份的話，改了標籤就會對不上而安靜地不通知。
     *
     * @var array<string, string>
     */
    public const FIELD_LABELS = [
        'title'        => '標題',
        'description'  => '描述',
        'priority'     => '優先順序',
        'status'       => '狀態',
        'assignee_ids' => '指派人員',
        // 參與人（需求方 2026-10-30）—— 跟指派人員一樣是 id 陣列，翻譯共用同一支
        'participant_ids' => '參與人',
        'station_id'   => '站台',
        'project_id'   => '專案',
        'due_date'     => '到期日',
    ];

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
            /*
             * 參與人（需求方 2026-10-30）—— 新增時就可以填。
             *
             * ⚠ `intval` 不可以省：前端的勾選框送上來是字串（`["3"]`），
             * 存成字串的話 `whereJsonContains(..., 3)` 就比不到，
             * 而且**不會報錯、只會搜不到**（篩選那邊因此要同時比兩種型別）。
             */
            'participant_ids' => array_map('intval', Arr::get($params, 'participant_ids', [])),
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
        /*
         * ⚠ 兩個人員欄位都要 `intval`，理由見 `storeTask()` ——
         * 存成字串的話篩選比不到，而且不會報錯。
         */
        foreach (['assignee_ids', 'participant_ids'] as $peopleField) {
            if (blank(Arr::get($params, $peopleField))) {
                continue;
            }

            $params[$peopleField] = array_map('intval', $params[$peopleField]);
        }

        // 記錄變更差異
        $changes = [];

        /*
         * ⚠ **比對與儲存都要用 `activityValue()` 正規化過的值**，不要直接拿
         * `$task->{$key}`（需求方 2026-10-30 回報了兩個症狀，同一個原因）：
         *
         *   - 到期日顯示成「2026-10-29T16:00:00.000000Z」—— `due_date` 的 cast
         *     是 `date`，取出來是 Carbon，寫進 JSON 欄位就變成 ISO-8601 **UTC**
         *   - 指派人員只有數字 —— `assignee_ids` 存的是 id 陣列
         *
         * ⚠ 而且不正規化還有第三個看不出來的問題：`$task->due_date != $value`
         * 是拿 Carbon 跟 '2026-10-30' 這種字串比，Carbon 轉字串是
         * 'Y-m-d H:i:s'，**永遠不相等** —— 所以每次編輯任務都會多記一筆
         * 「到期日沒有變但被改過」的假異動。
         */
        foreach ($params as $key => $value) {
            if (!Arr::has(self::FIELD_LABELS, $key)) {
                continue;
            }

            $before = $this->activityValue($key, $task->{$key});
            $after = $this->activityValue($key, $value);

            if ($before == $after) {
                continue;
            }

            $changes[self::FIELD_LABELS[$key]] = ['from' => $before, 'to' => $after];
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
     * 這幾張卡分別屬於哪些專案（去重）
     *
     * 給 Controller 在批次操作前確認「每一張都碰得到」用的。
     *
     * @param array<int> $taskIds
     * @return array<int>
     */
    public function projectIdsOfTasks(array $taskIds)
    {
        return $this->taskRepository->getProjectIdsByTaskIds($taskIds);
    }

    /**
     * 取得封存任務（30 天內）
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getArchivedTasks()
    {
        // 自動清理放太久的封存任務（天數走 config，前端算剩餘天數也是讀同一個值）
        $this->taskRepository->deleteArchivedOlderThan(
            (int) config('constants.TASK.ARCHIVE_PURGE_DAYS')
        );

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

    /**
     * 封存任務
     *
     * @param Task     $task
     * @param int|null $userId
     * @return Task
     */
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
     * 活動紀錄裡「值是人員清單」的那幾個欄位標籤
     *
     * ⚠ 指派人員與參與人在紀錄裡的形狀一樣（名字陣列，舊紀錄是 id 陣列），
     * 所以翻譯與批次查名字都共用同一條路 —— 兩邊各寫一份的話，
     * 改了其中一個的處理方式另一個就會分岔。
     *
     * @return array<string>
     */
    private static function peopleLabels()
    {
        return [
            self::FIELD_LABELS['assignee_ids'],
            self::FIELD_LABELS['participant_ids'],
        ];
    }

    /**
     * 把欄位值轉成活動紀錄要顯示的樣子
     *
     * ⚠ **在寫入時就換成人看得懂的值**，不是在畫面上才翻譯 ——
     * 這是一份異動紀錄：「當時指派給誰」不應該因為那個人後來改了暱稱、
     * 停用或離職就跟著變。站台與專案同理。
     *
     * ⚠ 這一支同時是**比對的基準**（見 `updateTask()` 的註解）：正規化之後
     * 兩邊才比得出「到底有沒有變」，不然 Carbon 跟字串永遠不相等。
     *
     * @param string $key   欄位名稱
     * @param mixed  $value 原始值
     * @return mixed 顯示用的值（字串、字串陣列，或原值）
     */
    private function activityValue($key, $value)
    {
        if ($key === 'due_date') {
            // ⚠ 只留日期：到期日沒有時間的意義，而 Carbon 進 JSON 會變 ISO UTC
            return blank($value) ? null : Carbon::parse($value)->format('Y-m-d');
        }

        /*
         * ⚠ 指派人員與參與人**共用同一支翻譯**：兩者的形狀一模一樣
         * （id 陣列 → 名字陣列）。為參與人另寫一份的話，哪天改了排序或
         * 「查不到名字怎麼辦」的處理方式，就會只改到一邊。
         */
        if ($key === 'assignee_ids' || $key === 'participant_ids') {
            return $this->assigneeNames($value);
        }

        if ($key === 'station_id') {
            return $this->relatedName($this->stationRepository, $value);
        }

        if ($key === 'project_id') {
            return $this->relatedName($this->projectRepository, $value);
        }

        /*
         * 標題、描述、優先順序、狀態：原值就夠。
         *
         * ⚠ **狀態千萬不要翻成文字。**
         * `TaskQueryCommandService::recentlyResolvedTaskIds()` 會拿
         * `Arr::get($activity->changes, '狀態.to')` 去跟
         * `constants.TASK.STATUS.RESOLVED` 比對 —— 一旦這裡翻成「已解決」，
         * 那支比對永遠不成立，「查卡片進度」就**安靜地**再也查不到近期完成的
         * 卡片，而且不會報任何錯。
         *
         * 要給人看的文字在顯示端才翻：前端有 `statusLabels` 對照表，
         * 群組通知有 `TaskChangeNoticeService::readable()`。
         */
        return $value;
    }

    /**
     * 指派人員的暱稱清單
     *
     * ⚠ 用 `getNamesByIds()`：它**不加任何狀態條件**，所以已經停用或離職的人
     * 留下的紀錄仍然顯示得出是誰 —— 異動紀錄要的正是這個。
     *
     * ⚠ **要排序**：不排的話，同一批人只因為勾選順序不同就會被判成「有變更」，
     * 多記一筆假的異動。
     *
     * @param mixed $ids
     * @return array<string>
     */
    private function assigneeNames($ids)
    {
        $ids = array_filter((array) $ids);

        if (blank($ids)) {
            return [];
        }

        $names = $this->userRepository->getNamesByIds($ids)->pluck('nickname')->all();

        sort($names);

        return $names;
    }

    /**
     * 關聯資料的名稱（站台／專案）
     *
     * ⚠ 查不到就回原本的 id，不要回 null —— 站台或專案被刪掉時，
     * 留一個 id 至少還查得出是哪一筆，回 null 會變成「從空的改成空的」。
     *
     * @param mixed $repository 有 `find($id)` 且撈得到 `name` 的 Repository
     * @param mixed $value
     * @return string|int|null
     */
    private function relatedName($repository, $value)
    {
        if (blank($value)) {
            return null;
        }

        $model = $repository->find($value);

        return filled($model) ? $model->name : $value;
    }

    /**
     * 記錄任務活動
     *
     * @param int        $taskId
     * @param int|null   $userId
     * @param string     $action
     * @param array|null $changes
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
        return $this->translateLegacyChanges($this->taskRepository->getActivities($taskId));
    }

    /**
     * 把**舊**紀錄裡的原始值翻成人看得懂的樣子
     *
     * 2026-10-30 起 `updateTask()` 寫進去的就已經是顯示用的值了，但在那之前
     * 存的是原始值 —— 到期日是 ISO-8601 的 UTC 字串、指派人員是 id 陣列。
     * 不翻的話，使用者打開任何一個舊任務，看到的還是
     * 「2026-10-29T16:00:00.000000Z」與「3, 5」。
     *
     * ⚠ **只改記憶體裡的值，不寫回資料庫**：這是一份異動紀錄，
     * 為了好看去改寫歷史資料是另一回事（而且不可逆）。
     *
     * ⚠ **要能重複套用**：新紀錄已經是 'Y-m-d' 與名字陣列了，
     * 這一支碰到它們必須原樣放過。
     *
     * ⚠ 名字**一次批次查完**再分配：逐筆查就是 N+1，而活動紀錄是一次撈全部的。
     *
     * @param \Illuminate\Database\Eloquent\Collection $activities
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function translateLegacyChanges($activities)
    {
        $nameMap = $this->legacyAssigneeNameMap($activities);

        foreach ($activities as $activity) {
            $changes = $activity->changes;

            // 短路：建立／封存／還原這些沒有 changes
            if (blank($changes)) {
                continue;
            }

            $dueLabel = self::FIELD_LABELS['due_date'];

            // 兩個人員欄位同一種處理（id 陣列 → 名字），不要只做一邊
            foreach (self::peopleLabels() as $label) {
                if (blank(Arr::get($changes, $label))) {
                    continue;
                }

                $changes[$label] = $this->mapBothSides(
                    $changes[$label],
                    function ($value) use ($nameMap) {
                        return $this->legacyAssigneeSide($value, $nameMap);
                    }
                );
            }

            if (filled(Arr::get($changes, $dueLabel))) {
                $changes[$dueLabel] = $this->mapBothSides($changes[$dueLabel], function ($value) {
                    return $this->legacyDate($value);
                });
            }

            $activity->changes = $changes;
        }

        return $activities;
    }

    /**
     * 對 `['from' => …, 'to' => …]` 的兩邊各跑一次
     *
     * @param array    $change
     * @param callable $callback
     * @return array
     */
    private function mapBothSides($change, callable $callback)
    {
        foreach (['from', 'to'] as $side) {
            if (!Arr::has($change, $side)) {
                continue;
            }

            $change[$side] = $callback($change[$side]);
        }

        return $change;
    }

    /**
     * 舊紀錄裡出現過的指派人員 id → 暱稱
     *
     * @param \Illuminate\Database\Eloquent\Collection $activities
     * @return \Illuminate\Support\Collection
     */
    private function legacyAssigneeNameMap($activities)
    {
        $ids = [];

        foreach ($activities as $activity) {
            foreach (self::peopleLabels() as $label) {
                $change = Arr::get($activity->changes, $label);

                if (blank($change)) {
                    continue;
                }

                foreach (['from', 'to'] as $side) {
                    foreach ((array) Arr::get($change, $side) as $one) {
                        // 新紀錄存的是名字（字串），只有舊紀錄是數字 id
                        if (is_numeric($one)) {
                            $ids[] = (int) $one;
                        }
                    }
                }
            }
        }

        // 短路：沒有舊紀錄就不必查
        if (blank($ids)) {
            return collect();
        }

        return $this->userRepository->getNamesByIds(array_unique($ids))->pluck('nickname', 'id');
    }

    /**
     * 把一邊的指派人員換成名字
     *
     * ⚠ 查不到名字就留原本的 id：那個帳號可能已經被硬刪除了，
     * 留 id 至少還看得出「換過人」，變成空的會讓那一筆紀錄失去意義。
     *
     * @param mixed                            $value
     * @param \Illuminate\Support\Collection   $nameMap
     * @return array<string>
     */
    private function legacyAssigneeSide($value, $nameMap)
    {
        $names = [];

        foreach ((array) $value as $one) {
            $names[] = is_numeric($one) ? (string) $nameMap->get((int) $one, $one) : $one;
        }

        sort($names);

        return $names;
    }

    /**
     * 把舊紀錄的日期值換成 `Y-m-d`
     *
     * ⚠ **一定要先轉成本地時區再取日期**：舊紀錄存的是 Carbon 序列化出來的
     * ISO-8601 **UTC**，例如 `2026-10-29T16:00:00.000000Z` —— 那其實是
     * 台北時間的 2026-10-30。直接取前 10 個字會**少一天**。
     *
     * @param mixed $value
     * @return string|null
     */
    private function legacyDate($value)
    {
        if (blank($value)) {
            return null;
        }

        return Carbon::parse($value)->setTimezone(config('app.timezone'))->format('Y-m-d');
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
