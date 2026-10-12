<?php

namespace App\Services;

use App\Repositories\TaskRepository;
use App\Repositories\UserRepository;
use App\Services\Notify\NoticeText;
use Illuminate\Support\Arr;

/**
 * 每天早上的任務卡通知
 *
 * 兩種收件人，內容不一樣（比照 `RemindReportService`）：
 *
 * | 收件人 | 內容 |
 * |---|---|
 * | 所有在職同仁（不含管理者） | 自己的：已過期／今日到期／進行中 |
 * | 設定頁勾選的人 | 全部人的：每人一行 + 總計 |
 *
 * ⚠ **三個數字各算各的、會重複**（需求方 2026-10-08）：一張「進行中又今天
 * 到期」的卡在兩邊各算一次。它們各自回答一個問題，加起來本來就不該等於總卡數。
 *
 * ⚠ **一張卡可以指派給多個人**（`assignee_ids` 是 JSON 陣列）。同一張逾期的卡
 * 會出現在每個被指派者的個人版裡 —— 那是對的，他們每個人都該知道。
 * 連帶的結果是完整版裡**各人數字加總會大於卡片總數**，所以那則訊息要附一句說明。
 */
class TaskNoticeService
{
    /** @var int 沒有指派人的那一組，借用這個 key 放在同一張統計表裡 */
    private const NO_ASSIGNEE = 0;

    private $taskRepository;
    private $userRepository;
    private $staffDm;
    private $appSettingService;
    private $noticeText;

    public function __construct(
        TaskRepository $taskRepository,
        UserRepository $userRepository,
        StaffDmService $staffDm,
        AppSettingService $appSettingService,
        NoticeText $noticeText
    ) {
        $this->taskRepository = $taskRepository;
        $this->userRepository = $userRepository;
        $this->staffDm = $staffDm;
        $this->appSettingService = $appSettingService;
        $this->noticeText = $noticeText;
    }

    /**
     * 跑一輪
     *
     * @param string|null $date   以哪一天為準（預設今天）
     * @param bool        $dryRun 只組內容不發送
     * @return array 結果，給 Command 顯示
     */
    public function run($date = null, $dryRun = false)
    {
        $date = filled($date) ? $date : now()->toDateString();

        $tasks = $this->taskRepository->getOpenForNotice();
        $byUser = $this->groupByUser($tasks, $date);

        $managerText = $this->buildManagerText($date, $tasks, $byUser);
        $manager = $this->sendManager($managerText, $dryRun);
        $personal = $this->sendPersonal($date, $byUser, $dryRun);

        return [
            'date'          => $date,
            'total'         => count($tasks),
            'manager_text'  => $managerText,
            'manager_sent'  => Arr::get($manager, 'sent', 0),
            'manager_skip'  => Arr::get($manager, 'reason'),
            'manager_names' => Arr::get($manager, 'names', []),
            'personal_sent' => Arr::get($personal, 'sent', 0),
            'personal_text' => Arr::get($personal, 'sample'),
            'failed'        => array_merge(
                Arr::get($manager, 'failed', []),
                Arr::get($personal, 'failed', [])
            ),
        ];
    }

    /**
     * 測試發送：真的把今天的總覽私訊給勾選的人
     *
     * ⚠ 刻意**不是** dry-run，理由同 `ShiftNoticeService::test()`。
     * **只發完整版**，不會打擾每一位同仁。
     *
     * @return array sent / reason / names / failed
     */
    public function test()
    {
        $date = now()->toDateString();
        $tasks = $this->taskRepository->getOpenForNotice();
        $text = (string) config('constants.TASK_NOTICE.TEST_PREFIX')
            . $this->buildManagerText($date, $tasks, $this->groupByUser($tasks, $date));

        return $this->sendManager($text, false);
    }

    // ---------------------------------------------------------------
    //  統計
    // ---------------------------------------------------------------

    /**
     * 把卡片分到每個被指派的人底下，順便算好三個數字
     *
     * ⚠ 沒有指派人的卡歸到 `NO_ASSIGNEE`，不是直接丟掉 ——
     * 沒人負責的逾期卡是最該被看見的。
     *
     * @param iterable $tasks
     * @param string   $date
     * @return array<int, array{overdue:array, today:array, doing:int}>
     */
    private function groupByUser($tasks, $date)
    {
        $doing = (int) config('constants.TASK.STATUS.IN_PROGRESS');
        $byUser = [];

        foreach ($tasks as $task) {
            $assignees = $this->assigneesOf($task);
            $isOverdue = $this->isOverdue($task, $date);
            $isToday = $this->isDueToday($task, $date);
            $isDoing = (int) $task->status === $doing;

            foreach ($assignees as $userId) {
                if (blank(Arr::get($byUser, $userId))) {
                    $byUser[$userId] = ['overdue' => [], 'today' => [], 'doing' => []];
                }

                if ($isOverdue) {
                    $byUser[$userId]['overdue'][] = $task;
                }

                if ($isToday) {
                    $byUser[$userId]['today'][] = $task;
                }

                /*
                 * ⚠ **進行中也要存卡片本身，不能只記數量**（需求方 2026-10-12）。
                 * 原本這裡只 `$doing++`，因為當初決定「進行中不列清單」——
                 * 現在三類都要逐條列出標題，數字改由 `count()` 得到。
                 */
                if ($isDoing) {
                    $byUser[$userId]['doing'][] = $task;
                }
            }
        }

        return $byUser;
    }

    /**
     * 這張卡指派給誰
     *
     * ⚠ `assignee_ids` 是後來加的欄位，舊資料可能只有 `assignee_id`。
     * 兩個都看，空的就歸到「沒有指派人」。
     *
     * @param object $task
     * @return array<int, int>
     */
    private function assigneesOf($task)
    {
        $ids = [];

        foreach ((array) $task->assignee_ids as $id) {
            if ((int) $id > 0) {
                $ids[] = (int) $id;
            }
        }

        if (blank($ids) && (int) $task->assignee_id > 0) {
            $ids[] = (int) $task->assignee_id;
        }

        return filled($ids) ? array_values(array_unique($ids)) : [self::NO_ASSIGNEE];
    }

    /**
     * 逾期了嗎
     *
     * ⚠ 沒設 `due_date` 的卡不算逾期 —— 沒給期限卻說人家遲到，不合理。
     *
     * @param object $task
     * @param string $date
     * @return bool
     */
    private function isOverdue($task, $date)
    {
        return filled($task->due_date) && $task->due_date->toDateString() < $date;
    }

    /**
     * 今天到期嗎
     *
     * @param object $task
     * @param string $date
     * @return bool
     */
    private function isDueToday($task, $date)
    {
        return filled($task->due_date) && $task->due_date->toDateString() === $date;
    }

    // ---------------------------------------------------------------
    //  完整版
    // ---------------------------------------------------------------

    /**
     * 組完整版
     *
     * @param string   $date
     * @param iterable $tasks
     * @param array    $byUser
     * @return string
     */
    private function buildManagerText($date, $tasks, array $byUser)
    {
        $notice = (array) config('constants.TASK_NOTICE');
        $text = strtr((string) Arr::get($notice, 'MANAGER_HEADER'), [
            '{date}' => $this->noticeText->date($date),
        ]);

        if (blank($tasks)) {
            return $text . (string) Arr::get($notice, 'MANAGER_EMPTY');
        }

        $doing = (int) config('constants.TASK.STATUS.IN_PROGRESS');
        $overdue = 0;
        $today = 0;
        $doingCount = 0;

        foreach ($tasks as $task) {
            $overdue += $this->isOverdue($task, $date) ? 1 : 0;
            $today += $this->isDueToday($task, $date) ? 1 : 0;
            $doingCount += (int) $task->status === $doing ? 1 : 0;
        }

        $text .= strtr((string) Arr::get($notice, 'MANAGER_SUMMARY'), [
            '{total}'   => count($tasks),
            '{overdue}' => $overdue,
            '{today}'   => $today,
            '{doing}'   => $doingCount,
        ]);

        return $text . $this->buildManagerUsers($notice, $date, $byUser);
    }

    /**
     * 完整版的「依人員」那段
     *
     * ⚠ **每個人底下逐條列出卡片標題**（需求方 2026-10-12）。原本每人只有
     * 一行數字 —— 看得出誰積了幾張，但看不出積的是什麼，主管還是得自己去
     * 看板翻。
     *
     * ⚠ **兩層上限，缺一不可**：
     *
     * | 上限 | 擋什麼 |
     * |---|---|
     * | `MANAGER_USER_MAX_LINES` | 一個人卡了二十張時，把整則訊息吃光 |
     * | `MANAGER_MAX_LINES` | 二十個人各卡五張 —— 每個人都沒超標，加起來照樣爆 |
     *
     * Telegram 單則 4096 字，超過就整則送不出去（不是截斷）——
     * 那會變成「最需要被看見的那天，報表反而沒發出來」。
     *
     * @param array  $notice
     * @param string $date
     * @param array  $byUser
     * @return string
     */
    private function buildManagerUsers(array $notice, $date, array $byUser)
    {
        if (blank($byUser)) {
            return '';
        }

        $names = $this->nicknamesOf(array_keys($byUser));
        $text = (string) Arr::get($notice, 'MANAGER_USER_TITLE');
        $budget = (int) Arr::get($notice, 'MANAGER_MAX_LINES');

        foreach ($byUser as $userId => $stat) {
            $replace = [
                '{overdue}' => count($stat['overdue']),
                '{today}'   => count($stat['today']),
                '{doing}'   => count($stat['doing']),
            ];

            // 沒有指派人的那一列不掛名字，而且要看得出是警告
            $text .= (int) $userId === self::NO_ASSIGNEE
                ? strtr((string) Arr::get($notice, 'MANAGER_NO_ASSIGNEE'), $replace)
                : strtr(
                    (string) Arr::get($notice, 'MANAGER_USER_LINE'),
                    array_merge($replace, ['{name}' => (string) Arr::get($names, (int) $userId, "#{$userId}")])
                );

            /*
             * 順序就是急迫度：逾期 → 今日到期 → 進行中。
             * 主管掃到第一行紅的就知道該找誰談。
             */
            // 同一張卡可能逾期又在進行中，去重理由見 uniqueTasks()
            $tasks = $this->uniqueTasks($stat['overdue'], $stat['today'], $stat['doing']);

            // $budget 傳參考，由它自己扣掉實際列出的行數 —— 在這裡再算一次
            // 「列了幾行」就是同一條規則的第二份
            $text .= $this->buildManagerTaskLines($notice, $date, $tasks, $budget);
        }

        return $text . (string) Arr::get($notice, 'MANAGER_MULTI_NOTE');
    }

    /**
     * 完整版裡某個人底下那幾行卡片
     *
     * ⚠ 一行一張（不像個人版拆三行）：完整版是給主管掃的，
     * 每張卡佔三行的話十個人就是一面牆。
     *
     * @param array  $notice
     * @param string $date
     * @param array  $tasks
     * @param int    $budget 整則訊息還剩幾行可以用。**傳參考**，這裡會扣掉實際用掉的
     * @return string
     */
    private function buildManagerTaskLines(array $notice, $date, array $tasks, &$budget)
    {
        if (blank($tasks) || $budget < 1) {
            return '';
        }

        $max = min((int) Arr::get($notice, 'MANAGER_USER_MAX_LINES'), $budget);
        $text = '';
        $shown = 0;

        foreach ($tasks as $task) {
            if ($shown >= $max) {
                break;
            }

            $text .= strtr((string) Arr::get($notice, 'MANAGER_TASK_LINE'), [
                '{icon}'    => $this->taskIcon($notice, $task, $date),
                '{title}'   => $this->noticeText->shorten($task->title, (int) Arr::get($notice, 'TITLE_CHARS')),
                '{project}' => filled($task->project) ? (string) $task->project->name : '-',
                '{due}'     => $this->dueText($notice, $task, $date),
            ]);
            $shown++;
        }

        $budget -= $shown;

        if (count($tasks) > $shown) {
            $text .= strtr((string) Arr::get($notice, 'MANAGER_MORE_LINE'), ['{count}' => count($tasks) - $shown]);
        }

        return $text;
    }

    /**
     * 這張卡在清單上用哪個圖示
     *
     * 逾期優先於今日到期，兩者都不是就是進行中 —— 一張卡可能同時逾期又在
     * 進行中，這時要顯示比較急的那個。
     *
     * @param array  $notice
     * @param object $task
     * @param string $date
     * @return string
     */
    private function taskIcon(array $notice, $task, $date)
    {
        if ($this->isOverdue($task, $date)) {
            return (string) Arr::get($notice, 'ICON_OVERDUE');
        }

        if ($this->isDueToday($task, $date)) {
            return (string) Arr::get($notice, 'ICON_TODAY');
        }

        return (string) Arr::get($notice, 'ICON_DOING');
    }

    /**
     * 發完整版給設定頁勾選的人
     *
     * @param string $text
     * @param bool   $dryRun
     * @return array
     */
    private function sendManager($text, $dryRun)
    {
        $userIds = $this->appSettingService->getIntList(AppSettingService::KEY_TASK_NOTICE_MANAGER);

        if (blank($userIds)) {
            return ['sent' => 0, 'reason' => StaffDmService::SKIP_NO_RECIPIENT, 'failed' => [], 'names' => []];
        }

        // 空跑只回「幾個人會收到」，不判斷綁定狀態 —— 那是測試發送的事
        if ($dryRun) {
            return ['sent' => count($userIds), 'reason' => null, 'failed' => [], 'names' => []];
        }

        return $this->staffDm->sendToUserIds($userIds, $text);
    }

    // ---------------------------------------------------------------
    //  個人版
    // ---------------------------------------------------------------

    /**
     * 發個人版給每位在職同仁
     *
     * ⚠ **手上沒卡的人也要發**（需求方指定）——那則寫「輕鬆一點」，
     * 不是「您沒有任務」（後者聽起來像在說他沒事做）。
     *
     * ⚠ **勾了完整版的人跳過** —— 完整版的「依人員」已經有他自己了，
     * 理由同每日提醒統計。
     *
     * @param string $date
     * @param array  $byUser
     * @param bool   $dryRun
     * @return array sent / sample / failed
     */
    private function sendPersonal($date, array $byUser, $dryRun)
    {
        $fullRecipients = $this->appSettingService->getIntList(AppSettingService::KEY_TASK_NOTICE_MANAGER);

        $sent = 0;
        $failed = [];
        $sample = null;

        foreach ($this->userRepository->getDmCandidates() as $user) {
            $userId = (int) $user->id;

            if (in_array($userId, $fullRecipients, true)) {
                continue;
            }

            $stat = (array) Arr::get($byUser, $userId, []);
            $text = $this->buildPersonalText($date, (string) $user->nickname, $stat);
            $sample = filled($sample) ? $sample : $text;

            if ($dryRun) {
                $sent++;

                continue;
            }

            // 帶 key：這是個人版，收不收由帳號管理那邊的開關決定
            if ($this->staffDm->send($user, $text, config('constants.NOTICE.PERSONAL.TASK'))) {
                $sent++;

                continue;
            }

            $failed[] = (string) $user->nickname;
        }

        return ['sent' => $sent, 'sample' => $sample, 'failed' => $failed];
    }

    /**
     * 組個人版
     *
     * @param string $date
     * @param string $name
     * @param array  $stat 這個人的 overdue / today / doing
     * @return string
     */
    private function buildPersonalText($date, $name, array $stat)
    {
        $notice = (array) config('constants.TASK_NOTICE');
        $overdue = (array) Arr::get($stat, 'overdue', []);
        $today = (array) Arr::get($stat, 'today', []);
        $doing = (array) Arr::get($stat, 'doing', []);

        // 三類都是空的 = 手上真的沒東西
        if (blank($overdue) && blank($today) && blank($doing)) {
            return strtr((string) Arr::get($notice, 'PERSONAL_CLEAR'), [
                '{name}' => $name,
                '{date}' => $this->noticeText->date($date),
            ]);
        }

        $text = strtr((string) Arr::get($notice, 'PERSONAL_HEADER'), [
            '{name}' => $name,
            '{date}' => $this->noticeText->date($date),
        ]);

        $text .= strtr((string) Arr::get($notice, 'PERSONAL_SUMMARY'), [
            '{overdue}' => count($overdue),
            '{today}'   => count($today),
            '{doing}'   => count($doing),
        ]);

        return $text . $this->buildPersonalList($notice, $date, $overdue, $today, $doing);
    }

    /**
     * 個人版底下的卡片清單
     *
     * ⚠ **三類都逐條列出標題**（需求方 2026-10-12）。原本只列逾期與今日到期，
     * 進行中只給數字 —— 當時的理由是「進行中全列出來會變成每天一份長清單」。
     * 需求方要的就是看得到標題，所以改成三類都列，長度由 `MAX_LINES` 控。
     *
     * ⚠ **一份清單而不是分段**：分成「要留意的／進行中」兩段的話，
     * 一張逾期又在跑的卡會在兩段各出現一次。順序本身就是急迫度
     * （逾期 → 今日到期 → 進行中），圖示也看得出是哪一類 —— 不需要分段。
     *
     * @param array  $notice
     * @param string $date
     * @param array  $overdue
     * @param array  $today
     * @param array  $doing
     * @return string
     */
    private function buildPersonalList(array $notice, $date, array $overdue, array $today, array $doing)
    {
        $tasks = $this->uniqueTasks($overdue, $today, $doing);

        if (blank($tasks)) {
            return '';
        }

        $max = (int) Arr::get($notice, 'MAX_LINES');
        $text = (string) Arr::get($notice, 'PERSONAL_LIST_TITLE');
        $shown = 0;

        foreach ($tasks as $task) {
            if ($shown >= $max) {
                break;
            }

            $text .= strtr((string) Arr::get($notice, 'PERSONAL_LINE'), [
                '{icon}'    => $this->taskIcon($notice, $task, $date),
                '{title}'   => $this->noticeText->shorten($task->title, (int) Arr::get($notice, 'TITLE_CHARS')),
                '{project}' => filled($task->project) ? (string) $task->project->name : '-',
                '{due}'     => $this->dueText($notice, $task, $date),
            ]);
            $shown++;
        }

        if (count($tasks) > $shown) {
            $text .= strtr((string) Arr::get($notice, 'MORE_LINE'), ['{count}' => count($tasks) - $shown]);
        }

        return $text;
    }

    /**
     * 把三類合成一份清單，同一張卡只留一次
     *
     * ⚠ **一定要去重**：一張卡可以同時逾期**又**在進行中，三類是各算各的
     * （見 [[task-daily-notice]] 的決策），所以 `array_merge` 之後同一張會
     * 出現兩次。數字重複是刻意的（三個數字各回答一個問題），
     * 但**同一行標題在清單裡出現兩次只會讓人以為系統壞了**。
     *
     * ⚠ 順序就是急迫度（逾期 → 今日到期 → 進行中），而去重保留**第一次**
     * 出現的那一筆 —— 所以逾期又在跑的卡會排在逾期那一段，圖示也跟著是紅的。
     *
     * ⚠ 清單張數會**少於**三個數字相加，那是對的：相加本來就會重複計算。
     *
     * @param array ...$groups
     * @return array
     */
    private function uniqueTasks(...$groups)
    {
        $seen = [];
        $tasks = [];

        foreach (array_merge(...$groups) as $task) {
            $id = (int) $task->id;

            if (array_key_exists($id, $seen)) {
                continue;
            }

            $seen[$id] = true;
            $tasks[] = $task;
        }

        return $tasks;
    }

    /**
     * 到期日怎麼寫
     *
     * @param array  $notice
     * @param object $task
     * @param string $date
     * @return string
     */
    private function dueText(array $notice, $task, $date)
    {
        if (blank($task->due_date)) {
            return (string) Arr::get($notice, 'DUE_NONE');
        }

        $due = $task->due_date->toDateString();

        if ($due === $date) {
            return strtr((string) Arr::get($notice, 'DUE_TODAY'), ['{date}' => $due]);
        }

        return strtr((string) Arr::get($notice, 'DUE_OVERDUE'), [
            // 用日期相減而不是 diffInDays(now())：後者會把今天過了幾小時算進去
            '{days}' => \Illuminate\Support\Carbon::parse($date)->diffInDays($task->due_date),
            '{date}' => $due,
        ]);
    }

    /**
     * user_id => 暱稱
     *
     * ⚠ 一次撈完，不要在迴圈裡逐人查。
     *
     * @param array $ids
     * @return array
     */
    private function nicknamesOf(array $ids)
    {
        $names = [];

        foreach ($this->userRepository->getNamesByIds(array_filter($ids)) as $user) {
            $names[(int) $user->id] = $user->nickname;
        }

        return $names;
    }
}
