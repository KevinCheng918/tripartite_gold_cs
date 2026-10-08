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
                    $byUser[$userId] = ['overdue' => [], 'today' => [], 'doing' => 0];
                }

                if ($isOverdue) {
                    $byUser[$userId]['overdue'][] = $task;
                }

                if ($isToday) {
                    $byUser[$userId]['today'][] = $task;
                }

                // 進行中只要數量 —— 個人版不列它們，否則每天都是一份長清單
                if ($isDoing) {
                    $byUser[$userId]['doing']++;
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

        return $text . $this->buildManagerUsers($notice, $byUser);
    }

    /**
     * 完整版的「依人員」那段
     *
     * @param array $notice
     * @param array $byUser
     * @return string
     */
    private function buildManagerUsers(array $notice, array $byUser)
    {
        if (blank($byUser)) {
            return '';
        }

        $names = $this->nicknamesOf(array_keys($byUser));
        $text = (string) Arr::get($notice, 'MANAGER_USER_TITLE');

        foreach ($byUser as $userId => $stat) {
            $replace = [
                '{overdue}' => count($stat['overdue']),
                '{today}'   => count($stat['today']),
                '{doing}'   => $stat['doing'],
            ];

            // 沒有指派人的那一列不掛名字，而且要看得出是警告
            if ((int) $userId === self::NO_ASSIGNEE) {
                $text .= strtr((string) Arr::get($notice, 'MANAGER_NO_ASSIGNEE'), $replace);

                continue;
            }

            $text .= strtr(
                (string) Arr::get($notice, 'MANAGER_USER_LINE'),
                array_merge($replace, ['{name}' => (string) Arr::get($names, (int) $userId, "#{$userId}")])
            );
        }

        return $text . (string) Arr::get($notice, 'MANAGER_MULTI_NOTE');
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

            if ($this->staffDm->send($user, $text)) {
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
        $doing = (int) Arr::get($stat, 'doing', 0);

        // 三個數字都是 0 = 手上真的沒東西
        if (blank($overdue) && blank($today) && $doing < 1) {
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
            '{doing}'   => $doing,
        ]);

        return $text . $this->buildPersonalList($notice, $date, $overdue, $today);
    }

    /**
     * 個人版底下「要留意的」那幾張卡
     *
     * ⚠ 只列逾期與今日到期，**不列進行中** —— 進行中全列出來，手上十張卡的人
     * 每天會收到一份長清單，真正要趕的那幾張反而被埋掉。
     *
     * @param array  $notice
     * @param string $date
     * @param array  $overdue
     * @param array  $today
     * @return string
     */
    private function buildPersonalList(array $notice, $date, array $overdue, array $today)
    {
        $tasks = array_merge($overdue, $today);

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

            $isOverdue = $this->isOverdue($task, $date);

            $text .= strtr((string) Arr::get($notice, 'PERSONAL_LINE'), [
                '{icon}'    => (string) Arr::get($notice, $isOverdue ? 'ICON_OVERDUE' : 'ICON_TODAY'),
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
