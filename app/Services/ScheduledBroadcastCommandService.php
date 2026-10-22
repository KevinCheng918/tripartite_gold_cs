<?php

namespace App\Services;

use App\Models\TelegramBroadcast;
use App\Repositories\BroadcastTemplateRepository;
use App\Repositories\UserRepository;
use App\Services\AutoReply\BroadcastCommandParser;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 用 Telegram 指令預約發送公告
 *
 * 「20 分鐘後發送 T01 公告給 LV_UAT」→ 預覽 → 按【確認預約】→ 寫進既有的
 * 預約公告，由既有的 `telegram:send-scheduled` 排程到點發送。
 *
 * ⚠ **這個功能的後果是公告發到客戶群組，而且收不回來。** 所以每個環節都
 * 往「寧可不做」倒：
 *
 * | 環節 | 擋法 |
 * |---|---|
 * | 誰能用 | 要綁定後台帳號 ＋ 有 `telegram_chat.broadcast` 權限 |
 * | 發哪一則 | 範本代號**完全相符**，找不到就列出有哪些，絕不猜 |
 * | 發給誰 | 站台名稱**完全相符**，找不到就列出可發的，絕不猜 |
 * | 真的要發嗎 | **解析完不成立**，先回預覽，按下確認才寫進去 |
 *
 * ⚠ 預覽暫存在 cache 而不是資料庫：那是個三十分鐘內有效的中間狀態，
 * 沒按確認就該自然消失。寫進資料庫還要處理「誰來清掉沒確認的那些」。
 */
class ScheduledBroadcastCommandService
{
    /** @var string cache key 前綴 */
    const DRAFT_PREFIX = 'broadcast_command_draft:';

    private $parser;
    private $templateRepository;
    private $userRepository;
    private $broadcastService;
    private $supportGroup;

    public function __construct(
        BroadcastCommandParser $parser,
        BroadcastTemplateRepository $templateRepository,
        UserRepository $userRepository,
        TelegramBroadcastService $broadcastService,
        SupportGroupService $supportGroup
    ) {
        $this->parser = $parser;
        $this->templateRepository = $templateRepository;
        // 用 Telegram id 反查後台帳號才驗得了權限
        $this->userRepository = $userRepository;
        // 查站台、建立預約都走既有那支，不另外寫一份
        $this->broadcastService = $broadcastService;
        $this->supportGroup = $supportGroup;
    }

    /*
     * ⚠ 這裡原本有一支 `looksLikeCommand()`（關鍵字粗篩「公告」），
     * 2026-10-13 拿掉 —— 餘點指令那邊漏掉「還剩多少點」出事之後，
     * 需求方決定四支指令一律由解析器判斷，不再用關鍵字抄捷徑。
     * 理由見 `TelegramCommandRouter::handlers()`。
     */

    /**
     * 處理一句指令
     *
     * @param string   $text     已經去掉 @機器人
     * @param array    $from     Telegram 的 from
     * @param int|null $threadId 回在哪個話題
     * @return bool 有處理就回 true；回 false 代表「這不是發公告」，呼叫端該往下走
     */
    public function handle($text, array $from, $threadId)
    {
        $parsed = $this->parser->parse($text);

        if (blank($parsed)) {
            $this->reply((string) config('constants.BROADCAST_COMMAND.UNAVAILABLE_TEXT'), $threadId);

            return true;
        }

        $intent = (string) Arr::get($parsed, 'intent');

        // 不是在講發公告 —— 讓呼叫端退回預約提醒那條路
        if ($intent === BroadcastCommandParser::INTENT_NOT_BROADCAST) {
            return false;
        }

        $isMessage = $intent === BroadcastCommandParser::INTENT_MESSAGE;

        if (!$isMessage && $intent !== BroadcastCommandParser::INTENT_BROADCAST) {
            $this->reply((string) config('constants.BROADCAST_COMMAND.UNKNOWN_TEXT'), $threadId);

            return true;
        }

        /*
         * ⚠ **權限擋在這裡，不是擋在確認那一步。** 沒權限的人連預覽都不該看到
         * —— 那份預覽會把公告全文貼在群組裡。
         */
        $user = $this->resolveUser($from);

        if (blank($user)) {
            $this->reply((string) config('constants.BROADCAST_COMMAND.NOT_BOUND_TEXT'), $threadId);

            return true;
        }

        if (!$user->hasPermission('telegram_chat.broadcast')) {
            $this->reply((string) config('constants.BROADCAST_COMMAND.NO_PERMISSION_TEXT'), $threadId);

            return true;
        }

        /*
         * 自己打的訊息沒有範本，內容就是句子裡那段字。
         *
         * ⚠ 底層完全共用 —— `TelegramBroadcastService::schedule()` 本來就吃
         * `content`，`template_code` 是選填。所以這裡只是把「內容從哪來」
         * 換掉，預約、送出、歷史紀錄、取消全部沿用同一條管線。
         */
        $templates = [];

        if (!$isMessage) {
            $codes = (array) Arr::get($parsed, 'template_codes', []);
            $missing = [];

            /*
             * ⚠ **一次可以發好幾份**（需求方 2026-10-22：「1 分鐘後發 T11 和
             * T12 公告給 ED」）。每一個代號各自查，查不到的全部列出來 ——
             * 只講第一個查不到的，他改完再送一次又卡在第二個。
             */
            foreach ($codes as $code) {
                $template = $this->templateRepository->findByCode($code);

                if (blank($template)) {
                    $missing[] = $code;

                    continue;
                }

                $templates[] = $template;
            }

            if (filled($missing)) {
                $this->replyTemplateNotFound($missing, $threadId);

                return true;
            }
        }

        $target = $this->resolveTarget(Arr::get($parsed, 'target'));

        if (blank($target)) {
            $this->replyTargetNotFound(Arr::get($parsed, 'target'), $threadId);

            return true;
        }

        if ($isMessage) {
            $this->replyMessagePreview(
                (string) Arr::get($parsed, 'content'),
                $target,
                Arr::get($parsed, 'send_at'),
                $user,
                $threadId
            );

            return true;
        }

        $this->replyPreview($templates, $target, Arr::get($parsed, 'send_at'), $user, $threadId);

        return true;
    }

    /**
     * 按鈕按下去（確認或取消）
     *
     * @param array    $parts     callback_data 去掉前綴後的片段：[action, draftKey]
     * @param int|null $messageId 按鈕所在的訊息
     * @return void
     */
    public function handleCallback(array $parts, $messageId)
    {
        $action = (string) Arr::get($parts, 0);
        $key = (string) Arr::get($parts, 1);
        $config = (array) config('constants.BROADCAST_COMMAND');

        if ($action === (string) Arr::get($config, 'ACTION_CANCEL')) {
            Cache::forget(self::DRAFT_PREFIX . $key);
            $this->supportGroup->editMessage($messageId, (string) Arr::get($config, 'CANCELLED_TEXT'));

            return;
        }

        $draft = Cache::pull(self::DRAFT_PREFIX . $key);

        /*
         * ⚠ 草稿不在了（過了 30 分鐘、或已經按過一次）——
         * **不能當成成功**。使用者按了確認，一定要知道結果。
         */
        if (blank($draft)) {
            $this->supportGroup->editMessage($messageId, (string) Arr::get($config, 'EXPIRED_TEXT'));

            return;
        }

        $this->confirm($draft, $messageId, $config);
    }

    /**
     * 真的寫進預約公告
     *
     * @param array    $draft
     * @param int|null $messageId
     * @param array    $config
     * @return void
     */
    private function confirm(array $draft, $messageId, array $config)
    {
        $items = (array) Arr::get($draft, 'items', []);
        $ids = [];

        /*
         * ⚠ **一份公告建一筆預約**，不是把內容併成一筆。
         *
         * 歷史紀錄、取消、重送全都以「一筆預約」為單位 —— 併成一筆的話
         * 那些功能會變成只能整組動，而且公告編號會不知道要填哪一個。
         *
         * ⚠ 中途失敗時**不往下建**：剩下的那幾份沒建到，而已經建好的也不該
         * 回頭刪（時間可能只剩幾分鐘，刪了反而兩頭落空）。
         * 所以回報要講清楚「建了幾份、哪一份卡住」。
         */
        foreach ($items as $item) {
            try {
                $broadcast = $this->broadcastService->schedule([
                    'content'      => Arr::get($item, 'content'),
                    'target_type'  => (int) Arr::get($draft, 'target_type'),
                    'group_ids'    => (array) Arr::get($draft, 'station_ids', []),
                    'image_urls'   => Arr::get($item, 'image_urls'),
                    'file_urls'    => Arr::get($item, 'file_urls'),
                    'scheduled_at' => Arr::get($draft, 'send_at'),
                ], (int) Arr::get($draft, 'user_id'));
            } catch (\Exception $e) {
                Log::error('指令預約公告建立失敗', [
                    'error' => $e->getMessage(),
                    'code'  => Arr::get($item, 'code'),
                    'done'  => $ids,
                ]);

                $this->supportGroup->editMessage($messageId, strtr(
                    (string) Arr::get($config, blank($ids) ? 'UNAVAILABLE_TEXT' : 'PARTIAL_FAILED_TEXT'),
                    [
                        '{count}' => (string) count($ids),
                        '{code}'  => (string) Arr::get($item, 'code'),
                    ]
                ));

                return;
            }

            $ids[] = $broadcast->id;
        }

        $this->supportGroup->editMessage($messageId, $this->confirmedText($draft, $items, $config));

        Log::info('指令預約公告已建立', [
            'broadcast_ids' => $ids,
            'codes'         => array_column($items, 'code'),
            'target'        => Arr::get($draft, 'target_label'),
            'send_at'       => Arr::get($draft, 'send_at'),
            'user_id'       => Arr::get($draft, 'user_id'),
        ]);
    }

    /**
     * 建好之後要回的那句話
     *
     * ⚠ 三種講法：自訂訊息（沒有代號）、一份公告、好幾份公告。
     * `is_message` 是布林，用 `(bool)` 不用 `filled()`
     * （`filled(false)` 回的是 true）。
     *
     * @param array $draft
     * @param array $items
     * @param array $config
     * @return string
     */
    private function confirmedText(array $draft, array $items, array $config)
    {
        $replace = [
            '{code}'   => (string) Arr::get($items, '0.code'),
            '{title}'  => (string) Arr::get($items, '0.title'),
            '{codes}'  => implode('、', array_column($items, 'code')),
            '{count}'  => (string) count($items),
            '{time}'   => (string) Arr::get($draft, 'send_at'),
            '{target}' => (string) Arr::get($draft, 'target_label'),
        ];

        if ((bool) Arr::get($draft, 'is_message')) {
            return strtr((string) Arr::get($config, 'MESSAGE_CONFIRMED_TEXT'), $replace);
        }

        $key = count($items) > 1 ? 'MULTI_CONFIRMED_TEXT' : 'CONFIRMED_TEXT';

        return strtr((string) Arr::get($config, $key), $replace);
    }

    /**
     * 回預覽並把草稿存進 cache
     *
     * ⚠ **一份草稿可以帶好幾份公告**（需求方 2026-10-22）。按下確認時
     * 一份建一筆預約 —— `TelegramBroadcastService::schedule()` 一次只吃一份
     * 內容，而且歷史紀錄、取消、重送都是以「一筆預約」為單位，
     * 硬把兩份併成一筆反而會讓那些功能全部失準。
     *
     * @param array<\App\Models\BroadcastTemplate> $templates
     * @param array                                $target resolveTarget() 的結果
     * @param \Illuminate\Support\Carbon           $sendAt
     * @param \App\Models\User                     $user
     * @param int|null                             $threadId
     * @return void
     */
    private function replyPreview(array $templates, array $target, $sendAt, $user, $threadId)
    {
        $config = (array) config('constants.BROADCAST_COMMAND');
        $key = Str::random(16);

        $items = [];

        foreach ($templates as $template) {
            $items[] = [
                'code'       => (string) $template->code,
                'title'      => (string) $template->title,
                'content'    => (string) $template->content,
                'image_urls' => $template->image_urls,
                'file_urls'  => $template->file_urls,
            ];
        }

        Cache::put(self::DRAFT_PREFIX . $key, [
            'items'        => $items,
            'target_type'  => (int) Arr::get($target, 'type'),
            'station_ids'  => (array) Arr::get($target, 'station_ids', []),
            'target_label' => (string) Arr::get($target, 'label'),
            'send_at'      => $sendAt->format('Y-m-d H:i'),
            'user_id'      => (int) $user->id,
        ], now()->addMinutes((int) Arr::get($config, 'DRAFT_TTL_MINUTES')));

        $text = strtr((string) Arr::get($config, 'PREVIEW_TEXT'), [
            '{count}'  => $this->countLine(count($items), $config),
            '{target}' => (string) Arr::get($target, 'label'),
            '{time}'   => $sendAt->format('Y-m-d H:i'),
            '{when}'   => $this->whenText($sendAt),
            '{items}'  => $this->previewItems($items, $config),
        ]);

        $this->supportGroup->sendToThread($text, $threadId, $this->confirmKeyboard($key, $config));
    }

    /**
     * 預覽裡每一份公告的那幾行
     *
     * @param array $items
     * @param array $config
     * @return string
     */
    private function previewItems(array $items, array $config)
    {
        $lines = [];

        foreach ($items as $item) {
            $lines[] = strtr((string) Arr::get($config, 'PREVIEW_ITEM'), [
                '{code}'        => (string) Arr::get($item, 'code'),
                '{title}'       => (string) Arr::get($item, 'title'),
                '{attachments}' => $this->attachmentLine($item, $config),
                '{preview}'     => $this->contentPreview((string) Arr::get($item, 'content'), $config),
            ]);
        }

        return implode('', $lines);
    }

    /**
     * 「共 N 份」那一行
     *
     * ⚠ 只有一份時**不印** —— 每次都寫「共 1 份」是多餘的噪音。
     *
     * @param int   $count
     * @param array $config
     * @return string
     */
    private function countLine($count, array $config)
    {
        if ($count < 2) {
            return '';
        }

        return strtr((string) Arr::get($config, 'PREVIEW_COUNT'), ['{count}' => (string) $count]);
    }

    /**
     * 自己打的訊息：預覽 ＋ 確認按鈕
     *
     * 草稿的形狀跟公告那條**完全一樣**，只是 `code` 空白、沒有附件 ——
     * 這樣 `confirm()` 不必分兩種走法。
     *
     * ⚠ **預覽要貼完整內容，不截斷**（跟公告那條不同）。公告是建檔好的，
     * 截斷只是看不到全文；這一段是模型剛剛從句子裡抄出來的，**抄錯、漏字、
     * 多抄了「給 lv-uat」那段都有可能**，而它會原封不動發給客戶。
     * 唯一能在發出去之前抓到的機會就是這份預覽。
     *
     * @param string                     $content
     * @param array                      $target
     * @param \Illuminate\Support\Carbon $sendAt
     * @param \App\Models\User           $user
     * @param int|null                   $threadId
     * @return void
     */
    private function replyMessagePreview($content, array $target, $sendAt, $user, $threadId)
    {
        $config = (array) config('constants.BROADCAST_COMMAND');
        $key = Str::random(16);

        Cache::put(self::DRAFT_PREFIX . $key, [
            /*
             * 草稿的形狀跟公告那條完全一樣（`items` 陣列），只是只有一份、
             * 沒有代號也沒有附件 —— 這樣 `confirm()` 不必分兩種走法。
             */
            'items' => [[
                // 自己打的訊息沒有代號，歷史紀錄上的「公告編號」會顯示「-」
                'code'       => '',
                'title'      => (string) Arr::get($config, 'MESSAGE_TITLE'),
                'content'    => (string) $content,
                'image_urls' => null,
                'file_urls'  => null,
            ]],
            // 確認訊息要挑對模板：公告講「範本《標題》」，這一種沒有代號可講
            'is_message'   => true,
            'target_type'  => (int) Arr::get($target, 'type'),
            'station_ids'  => (array) Arr::get($target, 'station_ids', []),
            'target_label' => (string) Arr::get($target, 'label'),
            'send_at'      => $sendAt->format('Y-m-d H:i'),
            'user_id'      => (int) $user->id,
        ], now()->addMinutes((int) Arr::get($config, 'DRAFT_TTL_MINUTES')));

        /*
         * ⚠ **不要在這裡 `e()`。** `TelegramBotService::sendMessage()` 送出前
         * 已經跑過 `escapeHtml()`（先全轉義、再還原 b/i/u/s/code/pre/a）——
         * 這裡再跳脫一次，`<` 會變成 `&amp;lt;` 顯示成亂碼。
         *
         * 而且不跳脫才**忠實**：預覽與真正發出去的那則走的是同一支
         * `sendMessage()`，看到什麼樣子就是客戶會看到的樣子。
         */
        $text = strtr((string) Arr::get($config, 'MESSAGE_PREVIEW_TEXT'), [
            '{target}'  => (string) Arr::get($target, 'label'),
            '{time}'    => $sendAt->format('Y-m-d H:i'),
            '{when}'    => $this->whenText($sendAt),
            '{content}' => $content,
        ]);

        $this->supportGroup->sendToThread($text, $threadId, $this->confirmKeyboard($key, $config));
    }

    /**
     * 「發給誰」解析成預約公告要的形狀
     *
     * @param string|null $target
     * @return array{type:int, station_ids:array, label:string}|null
     */
    private function resolveTarget($target)
    {
        $config = (array) config('constants.BROADCAST_COMMAND');

        if (mb_strtolower(trim((string) $target)) === BroadcastCommandParser::TARGET_ALL) {
            $count = count($this->broadcastService->getTargetStations());

            return [
                'type'        => TelegramBroadcast::TARGET_ALL,
                'station_ids' => [],
                // ⚠ 標明會發給幾個站台 —— 「全部」兩個字看不出規模
                'label'       => strtr((string) Arr::get($config, 'TARGET_ALL_TEXT'), ['{count}' => $count]),
            ];
        }

        $station = $this->broadcastService->findTargetStationByName($target);

        if (blank($station)) {
            return null;
        }

        return [
            'type'        => TelegramBroadcast::TARGET_SELECTED,
            'station_ids' => [(int) Arr::get($station, 'id')],
            'label'       => (string) Arr::get($station, 'name'),
        ];
    }

    /**
     * 說話的人是哪個後台帳號
     *
     * @param array $from
     * @return \App\Models\User|null
     */
    private function resolveUser(array $from)
    {
        $telegramId = Arr::get($from, 'id');

        if (blank($telegramId)) {
            return null;
        }

        return $this->userRepository->findByTelegramUserId((int) $telegramId);
    }

    /**
     * 找不到範本時，把現有的代號列出來
     *
     * @param string|null $code
     * @param int|null    $threadId
     * @return void
     */
    private function replyTemplateNotFound(array $missing, $threadId)
    {
        $config = (array) config('constants.BROADCAST_COMMAND');
        $codes = [];

        foreach ($this->templateRepository->getAll() as $template) {
            if (filled($template->code)) {
                $codes[] = (string) $template->code;
            }
        }

        if (blank($codes)) {
            $this->reply((string) Arr::get($config, 'TEMPLATE_NONE_TEXT'), $threadId);

            return;
        }

        $this->reply(strtr((string) Arr::get($config, 'TEMPLATE_NOT_FOUND_TEXT'), [
            // 查不到的全部列出來 —— 只講第一個的話，他改完又卡在第二個
            '{code}'  => implode('、', $missing),
            '{codes}' => implode('、', $codes),
        ]), $threadId);
    }

    /**
     * 找不到站台時，把可以發的列出來
     *
     * @param string|null $target
     * @param int|null    $threadId
     * @return void
     */
    private function replyTargetNotFound($target, $threadId)
    {
        $names = [];

        foreach ($this->broadcastService->getTargetStations() as $station) {
            $names[] = (string) Arr::get($station, 'name');
        }

        $this->reply(strtr((string) config('constants.BROADCAST_COMMAND.TARGET_NOT_FOUND_TEXT'), [
            '{target}' => (string) $target,
            '{names}'  => filled($names) ? implode('、', $names) : '（目前沒有可發送的站台）',
        ]), $threadId);
    }

    /**
     * 附件那一行
     *
     * @param array $item   草稿裡的一份公告（含 image_urls／file_urls）
     * @param array $config
     * @return string
     */
    private function attachmentLine(array $item, array $config)
    {
        $images = count((array) Arr::get($item, 'image_urls'));
        $files = count((array) Arr::get($item, 'file_urls'));

        // 沒有附件就整行不出現，不要印「0 張圖、0 個檔案」
        if ($images < 1 && $files < 1) {
            return '';
        }

        return strtr((string) Arr::get($config, 'PREVIEW_ATTACHMENTS'), [
            '{images}' => $images,
            '{files}'  => $files,
        ]);
    }

    /**
     * 內容預覽（截短）
     *
     * ⚠ 要給得夠多才看得出是不是拿錯範本，但整篇貼進群組會洗版。
     *
     * @param string $content
     * @param array  $config
     * @return string
     */
    private function contentPreview($content, array $config)
    {
        $max = (int) Arr::get($config, 'PREVIEW_CHARS');

        if (mb_strlen($content) <= $max) {
            return $content;
        }

        return mb_substr($content, 0, $max) . strtr((string) Arr::get($config, 'PREVIEW_MORE'), [
            '{count}' => mb_strlen($content) - $max,
        ]);
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
     * 還有多久
     *
     * ⚠ 基準截到整分鐘，理由同 `ScheduledReminderService::whenText()`
     * （兩邊精度不一致會差出整整一個單位）。
     *
     * @param \Illuminate\Support\Carbon $date
     * @return string
     */
    private function whenText($date)
    {
        $minutes = now()->startOfMinute()->diffInMinutes($date, false);
        $reminder = (array) config('constants.REMINDER');

        if ($minutes < 1) {
            return (string) Arr::get($reminder, 'WHEN_NOW');
        }

        if ($minutes < 60) {
            return strtr((string) Arr::get($reminder, 'WHEN_MINS'), ['{minutes}' => $minutes]);
        }

        if ($minutes < 1440) {
            return strtr((string) Arr::get($reminder, 'WHEN_HOURS'), ['{hours}' => intdiv($minutes, 60)]);
        }

        return strtr((string) Arr::get($reminder, 'WHEN_DAYS'), ['{days}' => intdiv($minutes, 1440)]);
    }

    /**
     * 回話給使用者（一定回在他說話的那個話題）
     *
     * @param string   $text
     * @param int|null $threadId
     * @return void
     */
    private function reply($text, $threadId)
    {
        $this->supportGroup->sendToThread($text, $threadId);
    }
}
