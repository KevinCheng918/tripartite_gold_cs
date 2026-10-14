<?php

namespace App\Services;

use App\Repositories\TaskRepository;
use App\Repositories\UserRepository;
use App\Services\AutoReply\TaskQueryCommandParser;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * 用 Telegram 指令查某個人的卡片進度
 *
 * ```
 * @機器人 確認一下 @aabb123 的卡片進度狀況
 *   ↓
 * 📋 小明 的卡片進度
 *
 * 🗒 待處理（2）
 * • 修正報表匯出
 * • 補上登入紀錄篩選
 *
 * 🔨 進行中（1）
 * • 虛擬機費用試算　（還有 3 天）
 *
 * ✅ 已解決（2 天內，1）
 * • 公告管理加搜尋框
 * ```
 *
 * ⚠ **沒有卡片的那一段，連大標題一起不印**（需求方 2026-10-14）。
 * 印一個底下空空的「進行中」只會讓人以為載入失敗。
 *
 * ⚠ 純讀本地資料、不發給客戶、沒有副作用 —— **不做二次確認**。
 */
class TaskQueryCommandService
{
    /** @var string 做這件事需要的權限 */
    const PERMISSION = 'telegram_chat.task_query';

    private $parser;
    private $taskRepository;
    private $userRepository;
    private $supportGroup;

    public function __construct(
        TaskQueryCommandParser $parser,
        TaskRepository $taskRepository,
        UserRepository $userRepository,
        SupportGroupService $supportGroup
    ) {
        $this->parser = $parser;
        $this->taskRepository = $taskRepository;
        // @帳號 → 系統帳號，以及驗下指令的人有沒有權限
        $this->userRepository = $userRepository;
        $this->supportGroup = $supportGroup;
    }

    /**
     * 處理一句指令
     *
     * @param string   $text     已經去掉 @機器人
     * @param array    $from     Telegram 的 from（對到系統帳號驗權限用）
     * @param int|null $threadId
     * @return bool 有處理就回 true；false 代表「這不是在問卡片」，換下一個 handler
     */
    public function handle($text, array $from, $threadId)
    {
        $config = (array) config('constants.TASK_QUERY_COMMAND');
        $parsed = $this->parser->parse($text);

        if (blank($parsed)) {
            $this->reply((string) Arr::get($config, 'UNAVAILABLE_TEXT'), $threadId);

            return true;
        }

        $intent = (string) Arr::get($parsed, 'intent');

        // 不是在問卡片 —— 讓分派器換下一個
        if ($intent === TaskQueryCommandParser::INTENT_NOT_TASK) {
            return false;
        }

        $denied = $this->permissionError($from, $config);

        if (filled($denied)) {
            $this->reply($denied, $threadId);

            return true;
        }

        if ($intent !== TaskQueryCommandParser::INTENT_TASK) {
            $this->reply((string) Arr::get($config, 'UNKNOWN_TEXT'), $threadId);

            return true;
        }

        $this->replyProgress((string) Arr::get($parsed, 'account'), $threadId, $config);

        return true;
    }

    /**
     * 查出來並回報
     *
     * @param string   $account
     * @param int|null $threadId
     * @param array    $config
     * @return void
     */
    private function replyProgress($account, $threadId, array $config)
    {
        $target = $this->userRepository->findByTelegramUsername($account);

        if (blank($target)) {
            $this->reply(strtr((string) Arr::get($config, 'NOT_FOUND_TEXT'), [
                '{account}' => $account,
            ]), $threadId);

            return;
        }

        $grouped = $this->groupByStatus($target->id, $config);
        $name = $this->displayName($target);

        if (blank($grouped)) {
            $this->reply(strtr((string) Arr::get($config, 'EMPTY_TEXT'), ['{name}' => $name]), $threadId);

            return;
        }

        $this->reply($this->render($name, $grouped, $config), $threadId);
    }

    /**
     * 把這個人的卡片依狀態分組
     *
     * ⚠ 已解決的**只留最近 N 天完成的**（需求方 2026-10-14）。
     * 「什麼時候完成的」從活動紀錄回推，不能用 `task.updated_at` ——
     * 理由見 `TaskRepository::getRecentStatusMoves()`。
     *
     * @param int   $userId
     * @param array $config
     * @return array<int, array> 狀態 => 卡片陣列（空的那一段不會出現）
     */
    private function groupByStatus($userId, array $config)
    {
        $status = (array) config('constants.TASK.STATUS');
        $resolvedIds = $this->recentlyResolvedTaskIds((int) Arr::get($config, 'RESOLVED_DAYS', 2));

        $grouped = [];

        foreach ($this->taskRepository->getForAssigneeQuery() as $task) {
            if (!in_array($userId, $this->assigneesOf($task), true)) {
                continue;
            }

            $taskStatus = (int) $task->status;

            // 已解決的只留最近完成的；久以前解決的不該出現在「進度」裡
            if ($taskStatus === (int) Arr::get($status, 'RESOLVED')
                && !in_array((int) $task->id, $resolvedIds, true)) {
                continue;
            }

            $grouped[$taskStatus][] = $task;
        }

        return $grouped;
    }

    /**
     * 最近 N 天內被移到「已解決」的卡片 id
     *
     * ⚠ 同一張卡可能被移進移出好幾次，所以用 `in_array` 判斷「有沒有出現過」
     * 就夠 —— 這裡只需要知道「它是不是最近才完成的」。
     *
     * @param int $days
     * @return array<int>
     */
    private function recentlyResolvedTaskIds($days)
    {
        $resolved = (int) config('constants.TASK.STATUS.RESOLVED');
        $ids = [];

        foreach ($this->taskRepository->getRecentStatusMoves($days) as $activity) {
            $to = Arr::get((array) $activity->changes, '狀態.to');

            if ((int) $to === $resolved) {
                $ids[] = (int) $activity->task_id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * 這張卡指派給誰
     *
     * ⚠ `assignee_ids` 是後來加的欄位，舊資料可能只有 `assignee_id`。
     * 兩個都看 —— 做法與 `TaskNoticeService::assigneesOf()` 一致。
     *
     * @param \App\Models\Task $task
     * @return array<int>
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

        return array_values(array_unique($ids));
    }

    /**
     * 組出整則訊息
     *
     * ⚠ **空的段落連大標題一起不印。** 迴圈跑的是「有卡片的那幾段」，
     * 不是「全部五段再判斷要不要印」—— 後者很容易在加第六種狀態時漏掉。
     *
     * @param string $name
     * @param array  $grouped
     * @param array  $config
     * @return string
     */
    private function render($name, array $grouped, array $config)
    {
        $text = strtr((string) Arr::get($config, 'TITLE'), ['{name}' => $name]);

        foreach ($this->sections($config) as $statusValue => $section) {
            $tasks = (array) Arr::get($grouped, $statusValue, []);

            if (blank($tasks)) {
                continue;
            }

            $text .= strtr((string) Arr::get($section, 'title'), [
                '{count}' => count($tasks),
                '{days}'  => (int) Arr::get($config, 'RESOLVED_DAYS', 2),
            ]);

            $text .= $this->sectionLines($tasks, (bool) Arr::get($section, 'with_due'), $config);
        }

        return $text;
    }

    /**
     * 段落的定義：順序、標題、要不要附預計完成時間
     *
     * ⚠ 待處理**不附預計完成時間**：還沒開始做，那個日期沒有參考價值，
     * 列出來只會讓每一行變長。已解決同理 —— 已經做完了。
     *
     * @param array $config
     * @return array<int, array{title: string, with_due: bool}>
     */
    private function sections(array $config)
    {
        $status = (array) config('constants.TASK.STATUS');

        return [
            (int) Arr::get($status, 'PENDING')     => ['title' => Arr::get($config, 'SECTION_PENDING'), 'with_due' => false],
            (int) Arr::get($status, 'IN_PROGRESS') => ['title' => Arr::get($config, 'SECTION_IN_PROGRESS'), 'with_due' => true],
            (int) Arr::get($status, 'TESTING')     => ['title' => Arr::get($config, 'SECTION_TESTING'), 'with_due' => true],
            (int) Arr::get($status, 'IN_REVIEW')   => ['title' => Arr::get($config, 'SECTION_IN_REVIEW'), 'with_due' => true],
            (int) Arr::get($status, 'RESOLVED')    => ['title' => Arr::get($config, 'SECTION_RESOLVED'), 'with_due' => false],
        ];
    }

    /**
     * 一個段落裡的每一行
     *
     * @param array $tasks
     * @param bool  $withDue
     * @param array $config
     * @return string
     */
    private function sectionLines(array $tasks, $withDue, array $config)
    {
        $max = (int) Arr::get($config, 'SECTION_MAX', 15);
        $text = '';
        $shown = 0;

        foreach ($tasks as $task) {
            if ($shown >= $max) {
                break;
            }

            $text .= $withDue
                ? strtr((string) Arr::get($config, 'LINE_DUE'), [
                    '{title}' => (string) $task->title,
                    '{due}'   => $this->dueText($task->due_date, $config),
                ])
                : strtr((string) Arr::get($config, 'LINE_PLAIN'), ['{title}' => (string) $task->title]);

            $shown++;
        }

        if (count($tasks) > $shown) {
            $text .= strtr((string) Arr::get($config, 'LIST_MORE'), ['{count}' => count($tasks) - $shown]);
        }

        return $text;
    }

    /**
     * 預計完成時間還有多久（或逾期多久）
     *
     * ⚠ 三種都要有。只寫「還有 N 天」的話，逾期的會變成「還有 3 天」——
     * 任務卡通知那邊踩過同一個坑。
     *
     * ⚠ 基準截到當天 00:00：`due_date` 是日期（沒有時分），而 `now()` 帶著
     * 時分秒 —— 不截的話今天到期會被算成「逾期 0 天」。
     *
     * @param string|null $dueDate
     * @param array       $config
     * @return string
     */
    private function dueText($dueDate, array $config)
    {
        if (blank($dueDate)) {
            return (string) Arr::get($config, 'DUE_NONE');
        }

        try {
            $due = Carbon::parse($dueDate)->startOfDay();
        } catch (\Exception $e) {
            Log::warning('卡片進度：期限格式看不懂', ['due_date' => $dueDate]);

            return (string) Arr::get($config, 'DUE_NONE');
        }

        $days = now()->startOfDay()->diffInDays($due, false);

        if ($days < 0) {
            return strtr((string) Arr::get($config, 'DUE_OVERDUE'), ['{days}' => abs($days)]);
        }

        if ($days < 1) {
            return (string) Arr::get($config, 'DUE_TODAY');
        }

        return strtr((string) Arr::get($config, 'DUE_FUTURE'), ['{days}' => $days]);
    }

    /**
     * 標題上要顯示的名字
     *
     * 暱稱優先 —— `@帳號` 是拿來查的，不是拿來看的。
     *
     * @param \App\Models\User $user
     * @return string
     */
    private function displayName($user)
    {
        if (filled($user->nickname)) {
            return (string) $user->nickname;
        }

        $username = ltrim((string) $user->telegram_username, '@');

        return "@{$username}";
    }

    /**
     * 這個人能不能查 —— 不能的話回要講的那句話
     *
     * @param array $from
     * @param array $config
     * @return string 空字串 ＝ 可以
     */
    private function permissionError(array $from, array $config)
    {
        $telegramId = Arr::get($from, 'id');

        // 短路：連 Telegram id 都沒有就不必查資料庫
        if (blank($telegramId)) {
            return (string) Arr::get($config, 'NOT_BOUND_TEXT');
        }

        $user = $this->userRepository->findByTelegramUserId((int) $telegramId);

        if (blank($user)) {
            return (string) Arr::get($config, 'NOT_BOUND_TEXT');
        }

        if (!$user->hasPermission(self::PERMISSION)) {
            Log::info('指令查卡片進度被權限擋下', ['user_id' => $user->id]);

            return (string) Arr::get($config, 'NO_PERMISSION_TEXT');
        }

        return '';
    }

    /**
     * @param string   $text
     * @param int|null $threadId
     * @return void
     */
    private function reply($text, $threadId)
    {
        $this->supportGroup->sendToThread($text, $threadId);
    }
}
