<?php

namespace App\Services;

use App\Repositories\TaskRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 任務卡異動通知
 *
 * 需求方 2026-11-01：「我有新增一個話題，他叫做任務卡專區，
 * 如果有更動任務卡的話，要通知。」
 *
 * ⚠ **不是即時發，是合併之後才發**（需求方裁示）。原因在詳情面板：
 * 它的 inline 編輯是**逐欄位存檔**（`saveField()` → 一次一支
 * `ajaxUpdateTask`），同仁整理一張卡（改優先、換指派、補到期日）會連續觸發
 * 三次 `updateTask()` —— 每次都發一則的話，群組會被幾乎一樣的訊息灌滿。
 *
 * 所以改成：異動先只記在 `task_activity`（本來就會記），
 * 排程每分鐘看一次，**同一張卡安靜滿 N 分鐘**才把累積的異動合併成一則發出去。
 *
 * ⚠ **為什麼不用 delayed queue**：這個專案的 `QUEUE_CONNECTION` 是 `sync`，
 * 延遲派送不會生效（會當場同步執行，等於沒合併）。排程指令是這個專案既有
 * 的做法，而且重開機之後不會掉資料（狀態在資料庫，不在記憶體）。
 *
 * ⚠ **跟 `TaskNoticeService` 是兩回事，不要混**：那一支是「每天早上私訊
 * 每個人自己的卡片」（走 `StaffDmService`）—— 不同的收件人、不同的觸發方式。
 */
class TaskChangeNoticeService
{
    /*
     * 這支發到內部群組的訊息屬於哪一類通知。
     *
     * 決定它進哪個 Telegram 話題 —— 對應 `constants.SUPPORT_TOPIC.TYPES` 的
     * key，由設定頁的話題清單勾選。沒被任何話題勾到就發到群組主區。
     */
    const NOTICE_TYPE = 'task_change';

    private $taskRepository;
    private $supportGroup;

    public function __construct(
        TaskRepository $taskRepository,
        SupportGroupService $supportGroup
    ) {
        $this->taskRepository = $taskRepository;
        $this->supportGroup = $supportGroup;
    }

    /**
     * 把安靜夠久的卡片異動合併發出去
     *
     * @param bool $dryRun 只回報會發什麼，不真的送
     * @return array{sent: int, skipped: int, pending: int, messages: array<string>}
     */
    public function run($dryRun = false)
    {
        $result = ['sent' => 0, 'skipped' => 0, 'pending' => 0, 'messages' => []];

        /*
         * 短路：群組沒設好就整支不要跑 —— 不然每分鐘都會撈一次活動紀錄、
         * 組好訊息，然後在最後一步發現沒地方發。
         *
         * ⚠ **這時候不要標記 `notified_at`**：設定補上之後，這段期間的異動
         * 應該還是要發得出去。
         */
        if (!$this->supportGroup->isConfigured()) {
            return $result;
        }

        $byTask = $this->taskRepository->getUnnotifiedActivities()->groupBy('task_id');

        // 短路：沒有待處理的就不要再往下（絕大多數分鐘都走這條）
        if ($byTask->isEmpty()) {
            return $result;
        }

        $quietUntil = now()->subMinutes((int) config('constants.TASK.CHANGE_NOTICE.QUIET_MINUTES'));
        $maxPerRun = (int) config('constants.TASK.CHANGE_NOTICE.MAX_PER_RUN');

        foreach ($byTask as $taskId => $activities) {
            /*
             * ⚠ 一次只處理這麼多張卡。排程停過一陣子（主機重開、指令掛掉）
             * 之後累積的卡片會一次湧出來，沒有上限就是幾百則訊息同時打進
             * Telegram —— 直接撞上 API 的速率限制。沒處理完的留到下一分鐘。
             */
            if ($result['sent'] + $result['skipped'] >= $maxPerRun) {
                $result['pending']++;

                continue;
            }

            /*
             * ⚠ **還在被改就繼續等**：這是「合併窗口」不是「延遲下限」——
             * 只要最後一筆異動還在窗口內，就讓它繼續累積。
             * 用 `last()` 是因為 Repository 已經照 `created_at` 由舊到新排。
             */
            if ($activities->last()->created_at->gt($quietUntil)) {
                $result['pending']++;

                continue;
            }

            $this->flushTask((int) $taskId, $activities, $dryRun, $result);
        }

        return $result;
    }

    /**
     * 處理一張卡累積的異動
     *
     * @param int                                      $taskId
     * @param \Illuminate\Support\Collection           $activities
     * @param bool                                     $dryRun
     * @param array                                    $result 以參考傳入，直接累加
     * @return void
     */
    private function flushTask($taskId, $activities, $dryRun, array &$result)
    {
        $ids = $activities->pluck('id')->all();
        $text = $this->buildMessage($taskId, $activities);

        /*
         * ⚠ 沒有要通知的內容時**照樣要標記**（只改了描述、標題的那些）——
         * 不標的話排程每分鐘都會再撈到同一批，永遠掃不完。
         */
        if (blank($text)) {
            if (!$dryRun) {
                $this->taskRepository->markActivitiesNotified($ids);
            }

            $result['skipped']++;

            return;
        }

        if ($dryRun) {
            $result['messages'][] = $text;
            $result['sent']++;

            return;
        }

        /*
         * ⚠ 發送包 try/catch，而且**不論成功失敗都標記**。
         *
         * 失敗了還留著 null 的話，下一分鐘會再試一次 —— 聽起來像重試，
         * 實際上 Telegram 的失敗多半是設定問題（話題被刪、Bot 被踢），
         * 不會自己好，結果是每分鐘重試一次直到有人發現。
         * 寧可漏掉一則通知，也不要讓排程卡在同一張卡上。
         */
        try {
            $this->supportGroup->send($text, null, null, self::NOTICE_TYPE);
            $result['sent']++;
        } catch (\Exception $e) {
            Log::error('任務卡異動通知發送失敗', [
                'task_id' => $taskId,
                'error'   => $e->getMessage(),
            ]);

            $result['skipped']++;
        }

        $this->taskRepository->markActivitiesNotified($ids);
    }

    /**
     * 組一張卡的合併訊息
     *
     * ```
     * 🗂 任務卡異動
     *
     * 「主系統登入異常」
     * 專案：三方金流 ／ 站台：LV
     * 狀態：進行中 → 審核中
     * 到期日：2026-11-05 → 2026-11-03
     * 異動人：王小明
     * ```
     *
     * ⚠ **要帶卡片標題與專案／站台**：只說「有一張卡片被改了」等於沒通知。
     *
     * @param int                            $taskId
     * @param \Illuminate\Support\Collection $activities
     * @return string 沒有任何該通知的內容時回空字串
     */
    private function buildMessage($taskId, $activities)
    {
        $task = $this->taskRepository->find($taskId);

        // 卡片在這段窗口內被真的刪掉了（不是封存）—— 沒有東西可報
        if (blank($task)) {
            return '';
        }

        $created = $activities->firstWhere('action', 'created');
        $lines = $this->changeLines($activities);
        $states = $this->stateLines($activities);

        /*
         * ⚠ 三種都沒有就不發：新建卡片一定有 `created`；
         * 只改描述／標題的那些，`changeLines()` 會是空的。
         */
        if (blank($created) && blank($lines) && blank($states)) {
            return '';
        }

        $out = [filled($created) ? '🗂 新任務卡' : '🗂 任務卡異動', ''];
        $out[] = "「{$task->title}」";

        $station = filled($task->station) ? $task->station->name : null;
        $place = '專案：' . (filled($task->project) ? $task->project->name : '-');
        $out[] = filled($station) ? "{$place} ／ 站台：{$station}" : $place;

        $out = array_merge($out, $states, $lines);
        $out[] = '異動人：' . $this->actorNames($activities);

        return implode("\n", $out);
    }

    /**
     * 欄位變更那幾行（只取該通知的欄位）
     *
     * ⚠ **同一個欄位被改好幾次只輸出一行**：取**第一筆的 from** 與
     * **最後一筆的 to**。不合的話「待處理→進行中、進行中→審核中」會變兩行，
     * 而看的人只想知道「從待處理變成審核中」。
     *
     * @param \Illuminate\Support\Collection $activities
     * @return array<string>
     */
    private function changeLines($activities)
    {
        $labels = $this->notifiableLabels();
        $merged = [];

        foreach ($activities as $activity) {
            foreach ($labels as $label) {
                $change = Arr::get((array) $activity->changes, $label);

                if (blank($change)) {
                    continue;
                }

                // 第一次看到這個欄位才記 from，之後只更新 to
                if (!Arr::has($merged, $label)) {
                    $merged[$label] = ['from' => Arr::get($change, 'from')];
                }

                $merged[$label]['to'] = Arr::get($change, 'to');
            }
        }

        $lines = [];

        foreach ($merged as $label => $change) {
            $from = $this->readable($label, Arr::get($change, 'from'));
            $to = $this->readable($label, Arr::get($change, 'to'));

            // 改來改去又改回原值：不必報
            if ($from === $to) {
                continue;
            }

            $lines[] = "{$label}：{$from} → {$to}";
        }

        return $lines;
    }

    /**
     * 封存／還原那一行
     *
     * ⚠ 這兩個動作的 `changes` 不是「欄位變更」的形狀
     * （`archiveTask()` 存的是 `['title' => …]`、`restoreTask()` 是 null），
     * 所以不能靠 `changeLines()` 處理。
     *
     * @param \Illuminate\Support\Collection $activities
     * @return array<string>
     */
    private function stateLines($activities)
    {
        $lines = [];

        if (filled($activities->firstWhere('action', 'archived'))) {
            $lines[] = '狀態：已封存';
        }

        if (filled($activities->firstWhere('action', 'restored'))) {
            $lines[] = '狀態：已從封存還原';
        }

        return $lines;
    }

    /**
     * 要通知的欄位在 `changes` 裡的中文 key
     *
     * ⚠ 標籤取自 `TaskBoardService::FIELD_LABELS`（寫入端用的就是那一份），
     * **不要在這裡自己抄一份** —— 抄了之後改標籤就會對不上，而結果是
     * 安靜地不通知。
     *
     * @return array<string>
     */
    private function notifiableLabels()
    {
        $labels = [];

        foreach ((array) config('constants.TASK.CHANGE_NOTICE.FIELDS') as $field) {
            $label = Arr::get(TaskBoardService::FIELD_LABELS, $field);

            if (filled($label)) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /**
     * 把 `changes` 裡的值變成人看得懂的字
     *
     * ⚠ **狀態與優先順序在 `changes` 裡是數字**（`activityValue()` 刻意不翻
     * 它們 —— `TaskQueryCommandService` 要拿 `狀態.to` 去跟常數比對），
     * 所以要在這裡才翻成文字。
     *
     * ⚠ 指派人員與參與人**已經是名字陣列**（寫入時就翻好了），
     * 這裡只負責把陣列接成一行。
     *
     * @param string $label
     * @param mixed  $value
     * @return string
     */
    private function readable($label, $value)
    {
        if (is_array($value)) {
            return blank($value) ? '無' : implode('、', $value);
        }

        if (blank($value)) {
            return '無';
        }

        if ($label === TaskBoardService::FIELD_LABELS['status']) {
            return (string) trans('task_board.status_' . $this->statusKey((int) $value));
        }

        if ($label === TaskBoardService::FIELD_LABELS['priority']) {
            return (string) trans('task_board.priority_' . $this->priorityKey((int) $value));
        }

        return (string) $value;
    }

    /**
     * 狀態數字 → 語系 key 的後半
     *
     * ⚠ 從 config 反查，不要寫死 `[1 => 'pending', …]` ——
     * 狀態常數加一個就得記得回來改，而漏改只會讓訊息顯示成原始數字。
     *
     * @param int $status
     * @return string
     */
    private function statusKey($status)
    {
        $map = [
            'PENDING'     => 'pending',
            'IN_PROGRESS' => 'in_progress',
            'TESTING'     => 'testing',
            'IN_REVIEW'   => 'in_review',
            'RESOLVED'    => 'resolved',
        ];

        foreach ($map as $constant => $key) {
            if ((int) config("constants.TASK.STATUS.{$constant}") === $status) {
                return $key;
            }
        }

        return 'pending';
    }

    /**
     * 優先順序數字 → 語系 key 的後半
     *
     * @param int $priority
     * @return string
     */
    private function priorityKey($priority)
    {
        $map = ['LOW' => 'low', 'MEDIUM' => 'medium', 'HIGH' => 'high', 'URGENT' => 'urgent'];

        foreach ($map as $constant => $key) {
            if ((int) config("constants.TASK.PRIORITY.{$constant}") === $priority) {
                return $key;
            }
        }

        return 'medium';
    }

    /**
     * 這段窗口內動過這張卡的人
     *
     * ⚠ 可能是好幾個人（合併窗口內誰都能改），所以去重之後全部列出來。
     *
     * @param \Illuminate\Support\Collection $activities
     * @return string
     */
    private function actorNames($activities)
    {
        $names = [];

        foreach ($activities as $activity) {
            // 排程或指令造成的異動沒有 user_id
            $name = filled($activity->user) ? $activity->user->nickname : '系統';

            $names[$name] = true;
        }

        return implode('、', array_keys($names));
    }
}
