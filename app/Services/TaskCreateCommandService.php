<?php

namespace App\Services;

use App\Repositories\ProjectRepository;
use App\Repositories\UserRepository;
use App\Services\AutoReply\TaskCreateCommandParser;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 在內部群組 tag 機器人開一張任務卡
 *
 * 需求方 2026-11-01：「我小幫手這邊，我要新增一個功能是，請他幫我開任務版
 * 的卡片。」
 *
 * ```
 * @bot 幫我開一張卡，三方金流的登入頁一直 500，指派給 @mary，很急
 *   → 複述一次 ＋【確認建立】【取消】
 *   → 按確認才真的寫進看板
 * ```
 *
 * ⚠ **一定要二次確認**（需求方裁示）。怕的不是誤觸，是**模型讀錯** ——
 * 讀錯會留下一張標題怪怪、指派給錯的人的卡片，而那種錯要等有人翻到才發現。
 * 同 `ScheduledReminderService` 一律複述的理由。
 *
 * ⚠ **能用講的只有五個欄位**：標題／專案／指派／優先序／到期日（需求方裁示）。
 * 描述、站台、參與人、附件都要進後台補 —— 句子裡塞越多，模型拆錯的機會越高。
 *
 * ⚠ 狀態固定「待處理」，不開放用講的。
 */
class TaskCreateCommandService
{
    /**
     * 用指令開卡的權限
     *
     * ⚠ **不沿用後台的 `task_board.create`**：理由同 `telegram_chat.task_query`
     * —— 用講的開卡沒有表單擋著（專案、標題全靠模型拆），誰能用應該單獨決定。
     *
     * @var string
     */
    const PERMISSION = 'telegram_chat.task_create';

    /** @var string 草稿的 cache key 前綴 */
    const DRAFT_PREFIX = 'task_create_command:draft:';

    private $supportGroup;
    private $parser;
    private $userRepository;
    private $projectRepository;
    private $taskBoardService;

    public function __construct(
        SupportGroupService $supportGroup,
        TaskCreateCommandParser $parser,
        UserRepository $userRepository,
        ProjectRepository $projectRepository,
        TaskBoardService $taskBoardService
    ) {
        $this->supportGroup = $supportGroup;
        $this->parser = $parser;
        $this->userRepository = $userRepository;
        // 專案是必填，而且只能開在發話者看得到的那幾個
        $this->projectRepository = $projectRepository;
        $this->taskBoardService = $taskBoardService;
    }

    /**
     * 試著處理這一句
     *
     * @param string   $text     已經去掉 @機器人
     * @param array    $from     Telegram 的 from
     * @param int|null $threadId
     * @return bool true ＝ 我處理了，分派器不要再問下一支
     */
    public function handle($text, array $from, $threadId)
    {
        $config = (array) config('constants.TASK_CREATE_COMMAND');
        $parsed = $this->parser->parse($text);

        // 模型不可用 —— 不要往下丟給別人，使用者要知道現在問不了
        if (blank($parsed)) {
            $this->reply((string) Arr::get($config, 'UNAVAILABLE_TEXT'), $threadId);

            return true;
        }

        $intent = (string) Arr::get($parsed, 'intent');

        // 不是要開卡 —— 讓分派器換下一個
        if ($intent === TaskCreateCommandParser::INTENT_NOT_CREATE) {
            return false;
        }

        $user = $this->resolveUser($from);

        if (blank($user)) {
            $this->reply((string) Arr::get($config, 'NOT_BOUND_TEXT'), $threadId);

            return true;
        }

        if (!$user->hasPermission(self::PERMISSION)) {
            Log::info('指令開卡被權限擋下', ['user_id' => $user->id]);
            $this->reply((string) Arr::get($config, 'NO_PERMISSION_TEXT'), $threadId);

            return true;
        }

        if ($intent !== TaskCreateCommandParser::INTENT_CREATE) {
            $this->reply((string) Arr::get($config, 'NEED_TITLE_TEXT'), $threadId);

            return true;
        }

        $this->askConfirm($parsed, $user, $threadId, $config);

        return true;
    }

    /**
     * 按了按鈕
     *
     * 三種：選專案（`pj`）、確認建立（`ok`）、取消（`no`）。
     *
     * @param array    $parts     callback_data 去掉前綴後的片段：
     *                            `[action, draftKey]`，選專案時多一個 `projectId`
     * @param int|null $messageId
     * @return void
     */
    public function handleCallback(array $parts, $messageId)
    {
        $action = (string) Arr::get($parts, 0);
        $key = (string) Arr::get($parts, 1);
        $config = (array) config('constants.TASK_CREATE_COMMAND');

        if ($action === (string) Arr::get($config, 'ACTION_CANCEL')) {
            Cache::forget(self::DRAFT_PREFIX . $key);
            $this->supportGroup->editMessage($messageId, (string) Arr::get($config, 'CANCELLED_TEXT'));

            return;
        }

        if ($action === (string) Arr::get($config, 'ACTION_PROJECT')) {
            $this->pickProject($key, (int) Arr::get($parts, 2), $messageId, $config);

            return;
        }

        $draft = Cache::pull(self::DRAFT_PREFIX . $key);

        /*
         * ⚠ 草稿不在了（過期、或已經按過一次）——**不能當成成功**。
         * 按了確認的人一定要知道結果（同 `ScheduledBroadcastCommandService`）。
         */
        if (blank($draft)) {
            $this->supportGroup->editMessage($messageId, (string) Arr::get($config, 'EXPIRED_TEXT'));

            return;
        }

        /*
         * ⚠ 防呆：專案還沒選就按到確認（例如舊訊息上的按鈕）。
         * 沒有這一道的話 `project_id` 會是 null，寫入時才撞 not null，
         * 使用者只看到「建立失敗了」。
         */
        if (blank(Arr::get($draft, 'project_id'))) {
            $this->supportGroup->editMessage($messageId, (string) Arr::get($config, 'NEED_PROJECT_TEXT'));

            return;
        }

        $this->createFromDraft($draft, $messageId, $config);
    }

    /**
     * 選了專案 —— 把草稿補完，原地換成確認卡
     *
     * ⚠ **用 `Cache::get` 不是 `pull`**：選完專案之後還要等他按確認，
     * 草稿不能在這一步被拿走。
     *
     * ⚠ **一定要驗這個 id 在不在 `allowed_project_ids` 裡**：`callback_data`
     * 是從外面送進來的，不能直接拿來當 `project_id` 寫入 —— 否則任何人
     * 只要改一個數字就能把卡片開到他看不到的專案裡。
     *
     * @param string   $key
     * @param int      $projectId
     * @param int|null $messageId
     * @param array    $config
     * @return void
     */
    private function pickProject($key, $projectId, $messageId, array $config)
    {
        $draft = Cache::get(self::DRAFT_PREFIX . $key);

        if (blank($draft)) {
            $this->supportGroup->editMessage($messageId, (string) Arr::get($config, 'EXPIRED_TEXT'));

            return;
        }

        $allowed = (array) Arr::get($draft, 'allowed_project_ids', []);
        $names = (array) Arr::get($draft, 'project_names', []);

        if (!in_array($projectId, $allowed, true)) {
            Log::warning('指令開卡：選到不在允許清單裡的專案', [
                'project_id' => $projectId,
                'creator_id' => Arr::get($draft, 'creator_id'),
            ]);

            $this->supportGroup->editMessage($messageId, (string) Arr::get($config, 'EXPIRED_TEXT'));

            return;
        }

        $draft['project_id'] = $projectId;
        $draft['project_name'] = (string) Arr::get($names, $projectId, '');

        $this->putDraft($key, $draft, $config);

        $this->supportGroup->editMessage(
            $messageId,
            $this->previewText($draft, $config),
            $this->confirmKeyboard($key, $config)
        );
    }

    /**
     * 真的寫進看板
     *
     * ⚠ 整支包 try/catch：寫入失敗要把失敗講回群組。沒有這一層的話，
     * webhook 吞掉例外之後按鈕只會停在「確認建立」，使用者以為開好了。
     *
     * @param array    $draft
     * @param int|null $messageId
     * @param array    $config
     * @return void
     */
    private function createFromDraft(array $draft, $messageId, array $config)
    {
        try {
            $assigneeId = Arr::get($draft, 'assignee_id');

            $task = $this->taskBoardService->storeTask([
                'project_id'   => (int) Arr::get($draft, 'project_id'),
                'title'        => (string) Arr::get($draft, 'title'),
                'priority'     => (int) Arr::get($draft, 'priority'),
                'due_date'     => Arr::get($draft, 'due_date'),
                'assignee_ids' => filled($assigneeId) ? [(int) $assigneeId] : [],
            ], (int) Arr::get($draft, 'creator_id'));
        } catch (\Exception $e) {
            Log::error('指令開卡寫入失敗', [
                'draft' => Arr::only($draft, ['project_id', 'title', 'creator_id']),
                'error' => $e->getMessage(),
            ]);

            $this->supportGroup->editMessage($messageId, (string) Arr::get($config, 'FAILED_TEXT'));

            return;
        }

        $this->supportGroup->editMessage($messageId, strtr((string) Arr::get($config, 'CREATED_TEXT'), [
            '{link}' => $this->taskLink($task->id, $task->title),
        ]));
    }

    /**
     * 複述一次並附上確認鈕
     *
     * @param array            $parsed
     * @param \App\Models\User $user
     * @param int|null         $threadId
     * @param array            $config
     * @return void
     */
    private function askConfirm(array $parsed, $user, $threadId, array $config)
    {
        $projects = $this->projectRepository->getVisibleTo($user);

        if (blank($projects)) {
            $this->reply((string) Arr::get($config, 'NO_PROJECT_TEXT'), $threadId);

            return;
        }

        $assignee = null;
        $wanted = Arr::get($parsed, 'assignee');

        if (filled($wanted)) {
            $assignee = $this->userRepository->findByTelegramUsername($wanted);

            /*
             * ⚠ 指派對象查不到就**停下來問**，不要默默開成未指派：
             * 他明明講了要給誰，拿到一張沒人認領的卡會以為系統壞了。
             */
            if (blank($assignee)) {
                $this->reply(strtr((string) Arr::get($config, 'ASSIGNEE_NOT_FOUND_TEXT'), [
                    '{account}' => $wanted,
                ]), $threadId);

                return;
            }
        }

        $project = $this->matchOne($projects, Arr::get($parsed, 'project'));
        $priority = $this->priorityValue(Arr::get($parsed, 'priority'));
        // 跟 `ScheduledBroadcastCommandService` 一致：callback_data 有長度上限
        $key = Str::random(16);

        /*
         * ⚠ 草稿裡**同時存 id 與顯示名稱**。只存 id 的話，選完專案要重畫
         * 確認卡時得再查一次人名與專案名 —— 而那時候手上只有 callback，
         * 沒有原本那句話了。
         *
         * ⚠ `allowed_project_ids` 是給選完之後驗證用的：按鈕上的 id 不能
         * 直接信（callback_data 是外面送進來的）。存在草稿裡就不必再查一次
         * 這個人看得到哪些專案，也不可能被動手腳 —— 快取在伺服器這邊。
         */
        $draft = [
            'title'         => (string) Arr::get($parsed, 'title'),
            'project_id'    => filled($project) ? (int) $project->id : null,
            'project_name'  => filled($project) ? (string) $project->name : null,
            'assignee_id'   => filled($assignee) ? (int) $assignee->id : null,
            'assignee_name' => filled($assignee) ? (string) $assignee->nickname : null,
            'priority'      => $priority,
            'due_date'      => Arr::get($parsed, 'due_date'),
            'creator_id'    => (int) $user->id,
            'allowed_project_ids' => $projects->pluck('id')->map('intval')->all(),
            // 選完專案要重畫確認卡時得有名字，那時候已經查不到了
            'project_names'       => $projects->pluck('name', 'id')->all(),
        ];

        $this->putDraft($key, $draft, $config);

        // 專案講得清楚 —— 直接進確認
        if (filled($project)) {
            $this->supportGroup->sendToThread(
                $this->previewText($draft, $config),
                $threadId,
                $this->confirmKeyboard($key, $config)
            );

            return;
        }

        $this->askWhichProject($projects, $key, $threadId, $config);
    }

    /**
     * 把句子裡的專案名對到唯一一個專案
     *
     * @param \Illuminate\Support\Collection $projects
     * @param string|null                    $wanted
     * @return \App\Models\Project|null null ＝ 沒講、對不到、或對到兩個以上
     */
    private function matchOne($projects, $wanted)
    {
        if (blank($wanted)) {
            return null;
        }

        $matched = $this->matchProjects($projects, $wanted);

        /*
         * ⚠ 兩個以上也當成對不到。「金流」同時對到「三方金流」與「金流測試」
         * 時隨便挑一個，跟猜沒有兩樣 —— 讓他用按鈕選。
         */
        return count($matched) === 1 ? Arr::first($matched) : null;
    }

    /**
     * 名稱比對：先求完全相同，沒有再求包含
     *
     * ⚠ 先比完全相同是必要的：專案叫「金流」與「金流測試」時，
     * 只做包含比對的話講「金流」會同時中兩個，然後被當成有歧義而回問 ——
     * 但他其實講得很清楚。
     *
     * @param \Illuminate\Support\Collection $projects
     * @param string                         $wanted
     * @return array<\App\Models\Project>
     */
    private function matchProjects($projects, $wanted)
    {
        $needle = $this->normalizeName($wanted);
        $exact = [];
        $partial = [];

        foreach ($projects as $project) {
            $name = $this->normalizeName((string) $project->name);

            if ($name === $needle) {
                $exact[] = $project;

                continue;
            }

            if (filled($needle) && mb_strpos($name, $needle) !== false) {
                $partial[] = $project;
            }
        }

        return filled($exact) ? $exact : $partial;
    }

    /**
     * 比對用的正規化：去空白、轉小寫
     *
     * ⚠ 用 `mb_strtolower`，`strtolower` 只處理 ASCII —— 專案名混著英文
     * 大小寫時會比不到。
     *
     * @param string $name
     * @return string
     */
    private function normalizeName($name)
    {
        return mb_strtolower(preg_replace('/\s+/u', '', trim($name)));
    }

    /**
     * 問他要開在哪個專案 —— **用按鈕選，不要叫他重打一次**
     *
     * 需求方 2026-11-01：「如果沒有帶入專案的話，讓他用選擇的，不要用再次
     * 輸入的方式。」叫人重打整句話的代價不只是麻煩 —— 重打一次模型會**重新
     * 解析一次**，標題可能被拆成不一樣的字，於是他得再看一次確認卡。
     * 按鈕則是沿用同一份草稿，只補上專案。
     *
     * ⚠ 專案太多時退回「請帶上專案名再說一次」：Telegram 的 inline keyboard
     * 塞幾十顆按鈕會長到要捲動，比重打還難用。上限在
     * `MAX_PROJECT_BUTTONS`。
     *
     * ⚠ 一行一顆。專案名長度不一，兩顆並排時寬度會對不齊而且字被截掉。
     *
     * @param \Illuminate\Support\Collection $projects
     * @param string                         $key
     * @param int|null                       $threadId
     * @param array                          $config
     * @return void
     */
    private function askWhichProject($projects, $key, $threadId, array $config)
    {
        $max = (int) Arr::get($config, 'MAX_PROJECT_BUTTONS');

        if ($projects->count() > $max) {
            Cache::forget(self::DRAFT_PREFIX . $key);
            $this->reply((string) Arr::get($config, 'TOO_MANY_PROJECTS_TEXT'), $threadId);

            return;
        }

        $prefix = (string) Arr::get($config, 'CALLBACK');
        $action = (string) Arr::get($config, 'ACTION_PROJECT');
        $rows = [];

        foreach ($projects as $project) {
            $rows[] = [[
                'text'          => (string) $project->name,
                'callback_data' => "{$prefix}:{$action}:{$key}:{$project->id}",
            ]];
        }

        $rows[] = [[
            'text'          => (string) Arr::get($config, 'BUTTON_CANCEL'),
            'callback_data' => "{$prefix}:" . Arr::get($config, 'ACTION_CANCEL') . ":{$key}",
        ]];

        $this->supportGroup->sendToThread(
            (string) Arr::get($config, 'PICK_PROJECT_TEXT'),
            $threadId,
            $rows
        );
    }

    /**
     * 存草稿（TTL 統一在這裡）
     *
     * @param string $key
     * @param array  $draft
     * @param array  $config
     * @return void
     */
    private function putDraft($key, array $draft, array $config)
    {
        Cache::put(
            self::DRAFT_PREFIX . $key,
            $draft,
            now()->addMinutes((int) Arr::get($config, 'DRAFT_TTL_MINUTES'))
        );
    }

    /**
     * 確認卡的內容
     *
     * ⚠ **全部從草稿組**，不要從原本那句話組：選完專案之後要重畫這張卡，
     * 而那時候只有 callback，原句早就不在手上了。
     *
     * @param array $draft
     * @param array $config
     * @return string
     */
    private function previewText(array $draft, array $config)
    {
        $assignee = Arr::get($draft, 'assignee_name');
        $dueDate = Arr::get($draft, 'due_date');

        return strtr((string) Arr::get($config, 'PREVIEW_TEXT'), [
            '{title}'    => $this->escape((string) Arr::get($draft, 'title')),
            '{project}'  => $this->escape((string) Arr::get($draft, 'project_name')),
            '{assignee}' => filled($assignee)
                ? $this->escape((string) $assignee)
                : (string) Arr::get($config, 'ASSIGNEE_NONE'),
            '{priority}' => $this->priorityLabel((int) Arr::get($draft, 'priority')),
            '{due_date}' => filled($dueDate) ? (string) $dueDate : (string) Arr::get($config, 'DUE_DATE_NONE'),
        ]);
    }

    /**
     * 優先序文字 → 常數值（沒講就用預設）
     *
     * @param string|null $priority
     * @return int
     */
    private function priorityValue($priority)
    {
        $map = [
            'low'    => 'LOW',
            'medium' => 'MEDIUM',
            'high'   => 'HIGH',
            'urgent' => 'URGENT',
        ];

        $key = Arr::get($map, (string) $priority, 'MEDIUM');

        return (int) config("constants.TASK.PRIORITY.{$key}");
    }

    /**
     * 優先序的燈號＋文字（跟任務卡通知同一套，見 `TaskChangeNoticeService`）
     *
     * @param int $priority
     * @return string
     */
    private function priorityLabel($priority)
    {
        $map = [1 => 'low', 2 => 'medium', 3 => 'high', 4 => 'urgent'];
        $key = 'medium';

        foreach ($map as $constant => $name) {
            if ((int) config('constants.TASK.PRIORITY.' . strtoupper($name)) === (int) $priority) {
                $key = $name;

                break;
            }
        }

        $light = Arr::get(
            (array) config('constants.TASK.CHANGE_NOTICE.PRIORITY_LIGHTS'),
            strtoupper($key)
        );
        $text = (string) trans("task_board.priority_{$key}");

        return filled($light) ? "{$light} {$text}" : $text;
    }

    /**
     * 卡片連結
     *
     * ⚠ 網址裡**不能出現 `&`**，理由見 `TaskChangeNoticeService::titleLink()`
     * （`escapeHtml()` 還原 `<a>` 的 regex 不吃 `&`，整個標籤會變成亂碼）。
     *
     * ⚠ `APP_URL` 要是對外網址 —— webhook 在背景跑，`route()` 只能靠
     * `config('app.url')`。
     *
     * @param int    $taskId
     * @param string $title
     * @return string
     */
    private function taskLink($taskId, $title)
    {
        $url = route('admin.task-board.index') . "?task={$taskId}";

        return "<a href=\"{$url}\">#{$taskId} {$this->escape($title)}</a>";
    }

    /**
     * 使用者填的字要轉義
     *
     * ⚠ 標題與專案名是人打的，可能有 `<` `&` —— 不轉義的話 `parse_mode: HTML`
     * 會把整則訊息判成壞掉的標籤而**整則發不出去**。
     *
     * @param string $text
     * @return string
     */
    private function escape($text)
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * 確認／取消兩顆按鈕
     *
     * @param string $key
     * @param array  $config
     * @return array
     */
    private function confirmKeyboard($key, array $config)
    {
        $prefix = (string) Arr::get($config, 'CALLBACK');

        return [[
            [
                'text'          => (string) Arr::get($config, 'BUTTON_CONFIRM'),
                'callback_data' => "{$prefix}:" . Arr::get($config, 'ACTION_CONFIRM') . ":{$key}",
            ],
            [
                'text'          => (string) Arr::get($config, 'BUTTON_CANCEL'),
                'callback_data' => "{$prefix}:" . Arr::get($config, 'ACTION_CANCEL') . ":{$key}",
            ],
        ]];
    }

    /**
     * 發話者是誰
     *
     * ⚠ **查不到系統帳號就擋下來**，不要用「系統」當建立者：卡片上的建立者
     * 是之後追責任的依據，掛成系統等於沒人知道是誰開的。
     *
     * @param array $from
     * @return \App\Models\User|null
     */
    private function resolveUser(array $from)
    {
        $telegramId = Arr::get($from, 'id');

        // 短路：連 Telegram id 都沒有就不必查資料庫
        if (blank($telegramId)) {
            return null;
        }

        return $this->userRepository->findByTelegramUserId((int) $telegramId);
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
