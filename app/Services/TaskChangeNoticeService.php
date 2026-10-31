<?php

namespace App\Services;

use App\Presenters\TelegramUsernamePresenter;
use App\Repositories\TaskRepository;
use App\Repositories\UserRepository;
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
    private $userRepository;
    private $supportGroup;

    public function __construct(
        TaskRepository $taskRepository,
        UserRepository $userRepository,
        SupportGroupService $supportGroup
    ) {
        $this->taskRepository = $taskRepository;
        // 指派人員存的是 id 陣列，要換成名字才看得懂
        $this->userRepository = $userRepository;
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

        $activitiesByTask = $this->taskRepository->getUnnotifiedActivities()->groupBy('task_id');
        $commentsByTask = $this->taskRepository->getUnnotifiedComments()->groupBy('task_id');

        /*
         * ⚠ 兩個來源要**聯集**成同一份卡片清單：狀態變更在 `task_activity`、
         * 新留言在 `task_comment`，而同一張卡可能只有其中一邊有東西。
         * 只看一邊的話另一邊永遠不會被通知。
         */
        $taskIds = $activitiesByTask->keys()->merge($commentsByTask->keys())->unique();

        // 短路：沒有待處理的就不要再往下（絕大多數分鐘都走這條）
        if ($taskIds->isEmpty()) {
            return $result;
        }

        $quietUntil = now()->subMinutes((int) config('constants.TASK.CHANGE_NOTICE.QUIET_MINUTES'));
        $maxPerRun = (int) config('constants.TASK.CHANGE_NOTICE.MAX_PER_RUN');

        foreach ($taskIds as $taskId) {
            $activities = $activitiesByTask->get($taskId, collect());
            $comments = $commentsByTask->get($taskId, collect());
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
             *
             * ⚠ **兩個來源都要看，取最晚的那一筆**：只看活動紀錄的話，
             * 一張卡「改完狀態、兩分鐘後又留言」會在留言還熱的時候就把通知
             * 發出去，然後那則留言又要等下一輪 —— 等於沒合併。
             */
            if ($this->lastTouchedAt($activities, $comments)->gt($quietUntil)) {
                $result['pending']++;

                continue;
            }

            $this->flushTask((int) $taskId, $activities, $comments, $dryRun, $result);
        }

        return $result;
    }

    /**
     * 這張卡最後被動到是什麼時候（兩個來源取較晚的）
     *
     * @param \Illuminate\Support\Collection $activities
     * @param \Illuminate\Support\Collection $comments
     * @return \Illuminate\Support\Carbon
     */
    private function lastTouchedAt($activities, $comments)
    {
        // Repository 兩支都照 `created_at` 由舊到新排，所以 `last()` 就是最晚的
        $times = collect([
            filled($activities) ? $activities->last()->created_at : null,
            filled($comments) ? $comments->last()->created_at : null,
        ])->filter();

        return $times->sortDesc()->first();
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
    private function flushTask($taskId, $activities, $comments, $dryRun, array &$result)
    {
        $activityIds = $activities->pluck('id')->all();
        $commentIds = $comments->pluck('id')->all();
        $text = $this->buildMessage($taskId, $activities, $comments);

        /*
         * ⚠ 沒有要通知的內容時**照樣要標記**（只改了描述、標題、優先順序
         * 那些不在通知名單裡的欄位）—— 不標的話排程每分鐘都會再撈到同一批，
         * 永遠掃不完。
         */
        if (blank($text)) {
            if (!$dryRun) {
                $this->taskRepository->markActivitiesNotified($activityIds);
                $this->taskRepository->markCommentsNotified($commentIds);
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

        $this->taskRepository->markActivitiesNotified($activityIds);
        $this->taskRepository->markCommentsNotified($commentIds);
    }

    /**
     * 組一張卡的合併訊息
     *
     * 版面是需求方 2026-11-01 看過實際範例後指定的四段：
     *
     * ```
     * 🔔 任務狀態更新通知
     *
     * #12 主系統登入異常        ← 編號＋標題整串都是連結，點下去直接開那張卡
     * 指派：李小華、張三 ｜ 負責人：王小明
     * 專案：三方金流 ｜ 優先：緊急 ｜ 到期：2026-11-05
     * 狀態：進行中 → 測試中
     * 觸發者：李小華
     * ```
     *
     * 編號同時也顯示在看板卡片與側邊面板上 —— 群組裡講「第 12 號卡」時
     * 對得起來。
     *
     * ⚠ **這是固定版面，不要改成「有變更的欄位才出現」**：
     * 前兩行是「這張卡現在長什麼樣」，第三段才是「這次改了什麼」。
     * 固定下來才掃得快 —— 每則訊息的第一行永遠是標題與該找誰。
     *
     * ⚠ **刻意不帶站台**（需求方指定）：任務卡的站台常常是空的，
     * 而且看通知的人要的是「這張卡是誰的事」，不是它掛在哪個站台。
     *
     * ⚠ **指派人員與專案負責人每則都帶**：看到通知的第一個問題是
     * 「這要找誰」—— 只有狀態變更的那種通知如果不帶，就得自己回看板查。
     *
     * ⚠ 到期日與優先順序**同時出現在第二行（現狀）與第三段（變更）**
     * 是刻意的：第二行回答「現在幾號到期」，第三段回答「剛剛被改成幾號」。
     * 為了省一行而把現狀拿掉的話，版面就不固定了。
     *
     * @param int                            $taskId
     * @param \Illuminate\Support\Collection $activities
     * @param \Illuminate\Support\Collection $comments
     * @return string 沒有任何該通知的內容時回空字串
     */
    private function buildMessage($taskId, $activities, $comments)
    {
        $task = $this->taskRepository->find($taskId);

        // 卡片在這段窗口內被真的刪掉了（不是封存）—— 沒有東西可報
        if (blank($task)) {
            return '';
        }

        $lines = $this->changeLines($activities);
        $states = $this->stateLines($activities);
        $commentLine = $this->commentLine($comments);

        /*
         * ⚠ 三種都沒有就不發。會走到這裡的是「只改了不在通知名單裡的欄位」
         * （描述、標題、優先順序⋯⋯）—— 那些照樣會進活動紀錄，
         * 所以撈得到，但不該通知。
         *
         * ⚠ **建立卡片不通知**（需求方 2026-11-01 收斂：「只有任務卡狀態更改、
         * 新留言，才會需要通知到話題」）。`created` 的 `changes` 是 null，
         * 所以它自然會落在這個 return —— 不必特別排除，但也**不要**為它
         * 加回「新任務卡」的抬頭。
         */
        if (blank($lines) && blank($states) && blank($commentLine)) {
            return '';
        }

        $out = ['🔔 任務狀態更新通知', ''];

        /*
         * 第一行：標題，自己獨佔一行（需求方 2026-11-01）。
         *
         * ⚠ 標題是連結，**不要跟其他欄位擠在同一行** —— 可點的範圍只有標題
         * 那幾個字，旁邊再接「指派：…」的話，使用者會以為整行都能點。
         */
        $out[] = $this->titleLink($task);

        /*
         * 第二行：該找誰。
         *
         * ⚠ 這兩個是 **`@mention`**（需求方 2026-11-01：「我要用標注的，
         * 這樣 Telegram 才會 @ 到」）—— 所以 Telegram 會真的推播給他們，
         * 不只是顯示名字。
         */
        $out[] = $this->joinInline([
            "指派：{$this->assigneeNames($task)}",
            "負責人：{$this->projectOwnerName($task)}",
        ]);

        // 第三行：歸屬與時程
        $out[] = $this->joinInline([
            "專案：{$this->projectName($task)}",
            "到期：{$this->dueDateText($task)}",
        ]);

        /*
         * 第四行：優先序，自己一行而且**擺在狀態的前面**（需求方 2026-11-01）。
         *
         * 燈號在文字之前（`優先序：🟡 中`）—— 滑過去的時候先看到顏色，
         * 不必讀完字才知道急不急。
         */
        $out[] = "優先序：{$this->priorityWithLight($task->priority)}";

        // 第五段：這次改了什麼（封存／還原、以及新留言也算在這裡）
        $out = array_merge($out, $states, $lines);

        if (filled($commentLine)) {
            $out[] = $commentLine;
        }

        // 最後一行：這次是誰動的
        $out[] = "觸發者：{$this->actorNames($activities, $comments)}";

        return implode("\n", $out);
    }

    /**
     * 標題，而且**標題本身就是連結**（需求方 2026-11-01）
     *
     * 點下去會開看板並自動展開這張卡的側邊面板
     * （看板讀 `?task=`，見 `resources/views/admin/task-board/index.blade.php`）。
     *
     * ⚠ **`<a>` 標籤可以直接送出去**：`TelegramBotService::sendMessage()` 用的是
     * `parse_mode: HTML`，而它的 `escapeHtml()` 轉義之後會把白名單內的標籤
     * （含 `a`）還原回來。所以**不要自己先 escape 標題** —— 那會變成
     * 雙重轉義，`&` 會顯示成 `&amp;`。
     *
     * ⚠⚠ **網址裡絕對不能出現 `&`。**
     * `escapeHtml()` 還原標籤的 regex 是
     * `/&lt;(a)((?:\s[^&]*?)?)&gt;/` —— 屬性部分不吃 `&`。
     * 所以 `?task=3&from=tg` 這種網址會讓整個 `<a>` **還原失敗**，
     * 群組裡收到的是 `&lt;a href="..."&gt;標題</a>` 這樣的可見亂碼。
     * （2026-11-01 實測確認過：單一參數正常、加第二個參數就壞。）
     *
     * 要加第二個參數的話，得先改 `escapeHtml()` 的 regex —— 不要在這裡硬塞。
     *
     * ⚠ **`APP_URL` 一定要是對外的網址**：排程在 console 跑，`route()` 只能
     * 靠 `config('app.url')`，沒有「目前的網域」可以推斷。設成 localhost 的話
     * 群組裡的連結點了打不開。
     *
     * @param \App\Models\Task $task
     * @return string
     */
    private function titleLink($task)
    {
        $url = route('admin.task-board.index') . "?task={$task->id}";

        /*
         * ⚠ 標題**不加「」框**（需求方 2026-11-01）：它已經是藍色帶底線的
         * 連結、而且自己獨佔一行，一眼就看得出是標題 —— 再加括號只是雜訊。
         *
         * ⚠ **編號放進連結裡面**，不要擺在 `<a>` 外面：整串
         * 「#3 主系統登入異常」都可以點，點擊範圍大得多。擺外面的話
         * 使用者很容易點在編號上而沒反應。
         */
        return "<a href=\"{$url}\">#{$task->id} {$task->title}</a>";
    }

    /**
     * 新留言那一行
     *
     * ```
     * 新留言：2 則（王小明、李小華）
     * ```
     *
     * ⚠ **不帶留言內容**：留言可能很長、而且帶圖片的 HTML 貼進 Telegram 是
     * 亂碼。通知的任務是「讓你知道有人留言了」，內容回卡片裡看 ——
     * 所以 `getUnnotifiedComments()` 的 select 連 `content` 都沒撈。
     *
     * ⚠ **只算新增的留言**：編輯與刪除留言不在這裡，需求方要的是「新留言」。
     * 編輯既有留言不會新增一筆 `task_comment`，所以天生就不會被算到。
     *
     * @param \Illuminate\Support\Collection $comments
     * @return string 沒有新留言時回空字串
     */
    private function commentLine($comments)
    {
        if (blank($comments)) {
            return '';
        }

        $names = [];

        foreach ($comments as $comment) {
            $names[filled($comment->user) ? $comment->user->nickname : '系統'] = true;
        }

        return sprintf('新留言：%d 則（%s）', $comments->count(), implode('、', array_keys($names)));
    }

    /**
     * 把同一行的幾個欄位接起來
     *
     * ⚠ 分隔符號用 `｜`（全角）而不是 `|`：中文之間用半角豎線會黏在字上，
     * 在 Telegram 的字體下幾乎看不出是分隔。
     *
     * @param array<string> $parts
     * @return string
     */
    private function joinInline(array $parts)
    {
        return implode(' ｜ ', $parts);
    }

    /**
     * 卡片所屬專案的名稱
     *
     * ⚠ 抽成一支是為了讓 `buildMessage()` 那幾行都能用插值
     * （`"專案：{$this->projectName($task)}"`）—— 三元運算式塞不進
     * `{...}` 裡面，而這個專案的慣例是插值不是字串串接。
     *
     * @param \App\Models\Task $task
     * @return string
     */
    private function projectName($task)
    {
        return filled($task->project) ? (string) $task->project->name : '-';
    }

    /**
     * 到期日（沒設定就說沒設定，不要留空）
     *
     * @param \App\Models\Task $task
     * @return string
     */
    private function dueDateText($task)
    {
        return filled($task->due_date) ? $task->due_date->format('Y-m-d') : '未設定';
    }

    /**
     * 優先序：燈號 ＋ 文字
     *
     * ⚠ 燈號在**文字之前**（`優先序：🟡 中`，需求方 2026-11-01 指定的格式）。
     *
     * ⚠ 燈號在 `constants.TASK.CHANGE_NOTICE.PRIORITY_LIGHTS`，
     * **不要寫死在這裡** —— 需求方日後要換顏色就不必動程式。
     * 查不到燈號時只顯示文字，不要印一個空白佔位。
     *
     * @param mixed $priority
     * @return string
     */
    private function priorityWithLight($priority)
    {
        $text = $this->readable(TaskBoardService::FIELD_LABELS['priority'], $priority);
        $light = Arr::get(
            (array) config('constants.TASK.CHANGE_NOTICE.PRIORITY_LIGHTS'),
            strtoupper($this->priorityKey((int) $priority))
        );

        return filled($light) ? "{$light} {$text}" : $text;
    }

    /**
     * 這張卡所屬專案的負責人
     *
     * ⚠ `loadMissing` 而不是在 `Task::project()` 上 eager load：
     * 看板每張卡都走那個關聯，而看板不顯示負責人 —— 在那裡載是白撈。
     * 這一支一輪最多跑 `MAX_PER_RUN` 次，多一趟查詢可以接受。
     *
     * ⚠ `Project::owner()` 是 `withTrashed()` 的，所以負責人已離職時
     * **仍然查得到名字** —— 這裡刻意不標「已離職」：那是專案管理頁的事，
     * 群組通知只要回答「這個專案歸誰」。
     *
     * @param \App\Models\Task $task
     * @return string
     */
    private function projectOwnerName($task)
    {
        if (blank($task->project)) {
            return '-';
        }

        $task->loadMissing('project.owner');

        if (blank($task->project->owner)) {
            return '未指派';
        }

        return $this->mentionOrName($task->project->owner);
    }

    /**
     * 一個人在訊息裡怎麼稱呼：有 Telegram username 就 `@mention`，沒有就暱稱
     *
     * 需求方 2026-11-01：「指派人跟負責人，我要用標注的，這樣 Telegram
     * 才會 @ 到。」
     *
     * ⚠ **沒有 username 的人一定要退回暱稱，不能跳過** —— 跳過的話那個人
     * 就從通知上消失了，而看訊息的人會以為「這張卡沒有指派人」。
     *
     * ⚠ 不要用 `UserRepository::getMentionableByIds()` 來拿人：那支會濾掉
     * 工程、非正常狀態、以及沒有 username 的人 —— 用在這裡會讓那些人整個
     * 不見（它自己的 docblock 就寫著這個警告）。
     *
     * ⚠ `mentionLine()` 會保留使用者填的大小寫（`normalize()` 是給比對用的，
     * 會轉小寫）。
     *
     * ⚠ **`@` 只有在那個人也在這個群組裡才會真的推播。** 不在群組的話
     * Telegram 只會把它畫成藍字 —— 這不是程式能補的，要請那個人加入群組。
     *
     * @param \App\Models\User $user
     * @return string
     */
    private function mentionOrName($user)
    {
        $mention = TelegramUsernamePresenter::mentionLine([$user->telegram_username]);

        return filled($mention) ? $mention : (string) $user->nickname;
    }

    /**
     * 這張卡目前指派給誰
     *
     * ⚠ 用 `getNamesByIds()`：它**不加任何狀態條件**，所以已停用或離職的人
     * 也顯示得出名字 —— 卡片還掛在他身上，通知上寫「無」會更難追。
     *
     * @param \App\Models\Task $task
     * @return string
     */
    private function assigneeNames($task)
    {
        $ids = array_filter((array) $task->assignee_ids);

        if (blank($ids)) {
            return '未指派';
        }

        $names = [];

        foreach ($this->userRepository->getNamesByIds($ids) as $user) {
            $names[] = $this->mentionOrName($user);
        }

        sort($names);

        /*
         * ⚠ 分隔符號用**空白**不要用「、」：`@mary、@john` 這樣寫的話
         * Telegram 會把「、」當成 username 的一部分，兩個 @ 都解析失敗。
         * 空白分隔是 Telegram mention 的唯一安全寫法
         * （`TelegramUsernamePresenter::mentionLine()` 多人時也是用空白）。
         */
        return blank($names) ? '未指派' : implode(' ', $names);
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
     * 觸發者 —— 這段窗口內動過這張卡的人
     *
     * ⚠ 可能是好幾個人（合併窗口內誰都能改），所以去重之後全部列出來。
     *
     * ⚠ 跟「指派人員」是兩回事，不要混：指派人員是**這張卡該由誰做**，
     * 觸發者是**這次是誰動的**。同一則訊息裡兩者都有，而且常常不一樣。
     *
     * ⚠ **留言者也算觸發者**：一則通知可能只因為有人留言而發出去，
     * 那時候 `$activities` 是空的 —— 只看活動紀錄的話觸發者會變成空白。
     *
     * @param \Illuminate\Support\Collection $activities
     * @param \Illuminate\Support\Collection $comments
     * @return string
     */
    private function actorNames($activities, $comments)
    {
        $names = [];

        foreach ($activities->concat($comments) as $row) {
            // 排程或指令造成的異動沒有 user_id
            $names[filled($row->user) ? $row->user->nickname : '系統'] = true;
        }

        return blank($names) ? '系統' : implode('、', array_keys($names));
    }
}
