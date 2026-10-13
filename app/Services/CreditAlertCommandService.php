<?php

namespace App\Services;

use App\Repositories\StationRepository;
use App\Repositories\UserRepository;
use App\Services\AutoReply\CreditAlertCommandParser;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 用 Telegram 指令發餘點告警 ＋ 補點通知給客戶
 *
 * ```
 * @機器人 傳餘點告警給 LV_UAT
 *   ↓ 解析 → 權限 → 找站台
 * ⚠️ 要發餘點告警給客戶嗎？
 *    站台：LV_UAT ｜ 發到：某某客戶群      【確認發送】【取消】
 *   ↓ 按確認（再驗一次權限）
 * 正在同步點數並發送…
 *   ↓ StationCreditAlertService::sendManual()
 * ✅ 已發送，當下點數：16390.94
 * ```
 *
 * ⚠ **這是四支指令裡唯一會發訊息給客戶的**（其餘都只回在內部群組），
 * 而且**發出去收不回來**。所以每個環節都往「寧可不發」倒：
 * 要權限、要二次確認、確認訊息寫明發到哪個群組、按鈕三十分鐘過期。
 *
 * ⚠ **真正的送出走 `StationCreditAlertService::sendManual()`** ——
 * 跟站台列表那顆「補點通知」按鈕**同一支**（需求方指定參考它）。
 * 不另外寫一份，免得兩邊的規則（同步失敗不發、不管門檻照發、附繳款圖）
 * 日後分岔。
 */
class CreditAlertCommandService
{
    /** @var string 做這件事需要的權限 */
    const PERMISSION = 'telegram_chat.credit_alert';

    /** @var string 待確認的草稿 */
    const DRAFT_PREFIX = 'credit_alert_command_draft:';

    private $parser;
    private $stationRepository;
    private $userRepository;
    private $nameMatcher;
    private $alertService;
    private $supportGroup;

    /**
     * 站台清單（一次指令內的暫存）
     *
     * @var \Illuminate\Database\Eloquent\Collection|null
     */
    private $stations;

    /**
     * 已經查過的那個 Telegram id（0 ＝ 還沒查過）
     *
     * @var int
     */
    private $resolvedFor = 0;

    /**
     * 上面那個 id 對到的帳號（查不到就是 null）
     *
     * @var \App\Models\User|null
     */
    private $resolvedUser;

    public function __construct(
        CreditAlertCommandParser $parser,
        StationRepository $stationRepository,
        UserRepository $userRepository,
        StationNameMatcher $nameMatcher,
        StationCreditAlertService $alertService,
        SupportGroupService $supportGroup
    ) {
        $this->parser = $parser;
        $this->stationRepository = $stationRepository;
        // Telegram 使用者 → 系統帳號，才有權限可發
        $this->userRepository = $userRepository;
        // 「他說的名字是哪個站台」四支指令共用同一份規則
        $this->nameMatcher = $nameMatcher;
        // 送出走後台那顆按鈕的同一支，不重寫
        $this->alertService = $alertService;
        $this->supportGroup = $supportGroup;
    }

    /**
     * 處理一句指令
     *
     * @param string   $text     已經去掉 @機器人
     * @param array    $from     Telegram 的 from（對到系統帳號驗權限用）
     * @param int|null $threadId
     * @return bool 有處理就回 true；false 代表「這不是要發告警」，換下一個 handler
     */
    public function handle($text, array $from, $threadId)
    {
        $config = (array) config('constants.CREDIT_ALERT_COMMAND');
        $parsed = $this->parser->parse($text);

        if (blank($parsed)) {
            $this->reply((string) Arr::get($config, 'UNAVAILABLE_TEXT'), $threadId);

            return true;
        }

        $intent = (string) Arr::get($parsed, 'intent');

        // 不是要發告警 —— 讓分派器換下一個
        if ($intent === CreditAlertCommandParser::INTENT_NOT_ALERT) {
            return false;
        }

        /*
         * ⚠ **權限擋在最前面**，比找站台還早：沒權限的人連「有哪些站台」
         * 都不該從錯誤訊息裡看到。
         */
        $denied = $this->permissionError($from, $config);

        if (filled($denied)) {
            $this->reply($denied, $threadId);

            return true;
        }

        if ($intent !== CreditAlertCommandParser::INTENT_ALERT) {
            $this->reply((string) Arr::get($config, 'UNKNOWN_TEXT'), $threadId);

            return true;
        }

        $this->replyConfirm((string) Arr::get($parsed, 'station'), $threadId, $config);

        return true;
    }

    /**
     * 回確認訊息
     *
     * @param string   $raw
     * @param int|null $threadId
     * @param array    $config
     * @return void
     */
    private function replyConfirm($raw, $threadId, array $config)
    {
        $match = $this->nameMatcher->match($raw, $this->stations());

        if (blank($match)) {
            $this->replyNoSuchStation($raw, $threadId, $config);

            return;
        }

        // 確認訊息要寫群組名，所以要完整的那一筆（`find()` 會 eager load telegramGroup）
        $station = $this->stationRepository->find($match->id);

        if (blank($station)) {
            $this->replyNoSuchStation($raw, $threadId, $config);

            return;
        }

        /*
         * ⚠ **站台列表那顆按鈕 disable 的情況，這裡也不能往下走**
         * （需求方 2026-10-13）。判斷直接用 `Station::$topup_notice_blockers`
         * ——跟 `partials/topup-notice-button.blade.php` **同一個來源**，
         * 不自己列一份「要哪些欄位」。各寫一份的話，日後多一個必要欄位
         * 只會改到其中一邊：後台按鈕灰了，指令卻還發得出去。
         *
         * ⚠ 擋在**給按鈕之前**。按下去才說「缺設定」等於讓人白按一次，
         * 而且那顆按鈕會留在群組裡誤導後面看到的人。
         */
        $blockers = (array) $station->topup_notice_blockers;

        if (filled($blockers)) {
            $this->reply($this->blockedText($station, $blockers, $config), $threadId);

            return;
        }

        /*
         * ⚠ 用快取草稿而不是把 station id 直接塞進 `callback_data`：
         * 這一支會發訊息給客戶，按鈕**必須會過期**。確認訊息永遠留在群組裡，
         * 沒有期限的話三天後有人翻上去手滑就發出去了。
         */
        $key = Str::random(16);
        $prefix = (string) Arr::get($config, 'CALLBACK');
        $confirm = (string) Arr::get($config, 'ACTION_CONFIRM');
        $cancel = (string) Arr::get($config, 'ACTION_CANCEL');

        Cache::put(self::DRAFT_PREFIX . $key, [
            'station_id' => (int) $station->id,
            'thread_id'  => filled($threadId) ? (int) $threadId : null,
        ], now()->addMinutes((int) Arr::get($config, 'DRAFT_TTL_MINUTES')));

        $keyboard = [[
            [
                'text'          => (string) Arr::get($config, 'BUTTON_CONFIRM'),
                'callback_data' => "{$prefix}:{$confirm}:{$key}",
            ],
            [
                'text'          => (string) Arr::get($config, 'BUTTON_CANCEL'),
                'callback_data' => "{$prefix}:{$cancel}:{$key}",
            ],
        ]];

        $this->supportGroup->sendToThread(strtr((string) Arr::get($config, 'CONFIRM_TEXT'), [
            '{station}' => (string) $station->name,
            '{group}'   => $this->groupName($station),
        ]), $threadId, $keyboard);
    }

    /**
     * 按鈕按下去
     *
     * ⚠ **按鈕要再驗一次權限**：按的人不一定是下指令的人。有權限的 A 下了
     * 指令、確認訊息留在群組裡，沒權限的 B 按下【確認發送】—— 只擋指令的話
     * 告警就被 B 發給客戶了。
     *
     * @param array    $parts     [action, draftKey]
     * @param int|null $messageId
     * @param array    $from      按按鈕的人
     * @return void
     */
    public function handleCallback(array $parts, $messageId, array $from = [])
    {
        $config = (array) config('constants.CREDIT_ALERT_COMMAND');
        $action = (string) Arr::get($parts, 0);
        $key = (string) Arr::get($parts, 1);

        // 取消不需要權限 —— 把一則待確認收掉，誰都可以，而且取消是安全的方向
        if ($action === (string) Arr::get($config, 'ACTION_CANCEL')) {
            Cache::forget(self::DRAFT_PREFIX . $key);
            $this->supportGroup->editMessage($messageId, (string) Arr::get($config, 'CANCELLED_TEXT'));

            return;
        }

        /*
         * ⚠ 先 `get()` 看一眼，**還不要 `pull()`**。
         *
         * 顛倒過來的話，沒權限的 B 按一下就把草稿消耗掉了 —— A 的待確認
         * 跟著失效，得重打一次指令。B 沒有權限做任何事，更不該有能力
         * 把別人的待確認弄掉。
         *
         * ⚠ 草稿不在（過期或已用掉）**不能當成成功**，要照實說過期。
         */
        $draft = (array) Cache::get(self::DRAFT_PREFIX . $key);

        if (blank($draft)) {
            $this->supportGroup->editMessage($messageId, (string) Arr::get($config, 'EXPIRED_TEXT'));

            return;
        }

        $denied = $this->permissionError($from, $config);

        if (filled($denied)) {
            /*
             * ⚠ **另發一則，不要 editMessage** —— 編輯的話 B 按一下就把 A 的
             * 確認按鈕蓋掉了。草稿沒被消耗，所以那顆按鈕仍然有效，
             * 有權限的人可以照常按下去。
             */
            $this->reply($denied, Arr::get($draft, 'thread_id'));

            return;
        }

        /*
         * ⚠ **確定要發了才 `pull()`**：取出並刪掉，連點兩下不會發兩次。
         *
         * 這裡再檢查一次是因為 `get()` 到 `pull()` 之間還有一小段 ——
         * 另一個人同時按下去的話，只有先 pull 到的那次會真的發。
         */
        $draft = (array) Cache::pull(self::DRAFT_PREFIX . $key);

        if (blank($draft)) {
            $this->supportGroup->editMessage($messageId, (string) Arr::get($config, 'EXPIRED_TEXT'));

            return;
        }

        $this->send((int) Arr::get($draft, 'station_id'), $messageId, Arr::get($draft, 'thread_id'), $config, $from);
    }

    /**
     * 真的發出去
     *
     * @param int      $stationId
     * @param int|null $messageId
     * @param int|null $threadId
     * @param array    $config
     * @param array    $from
     * @return void
     */
    private function send($stationId, $messageId, $threadId, array $config, array $from)
    {
        $station = $this->stationRepository->find($stationId);

        if (blank($station)) {
            $this->supportGroup->editMessage($messageId, (string) Arr::get($config, 'FAIL_UNKNOWN'));

            return;
        }

        /*
         * ⚠ **按下去時要再檢查一次缺不缺設定。** 確認訊息留在群組裡的那
         * 三十分鐘內，有人可能把 api_key 或群組清掉了 —— 下指令時能發，
         * 按的時候不一定還能發。
         */
        $blockers = (array) $station->topup_notice_blockers;

        if (filled($blockers)) {
            $this->supportGroup->editMessage($messageId, $this->blockedText($station, $blockers, $config));

            return;
        }

        // 同步 ＋ 兩則訊息要幾秒，不講的話按的人會以為沒反應又按一次
        $this->supportGroup->editMessage($messageId, strtr((string) Arr::get($config, 'WORKING_TEXT'), [
            '{station}' => (string) $station->name,
        ]));

        $user = $this->resolveUser($from);

        try {
            $result = (array) $this->alertService->sendManual($station, filled($user) ? $user->id : null);
        } catch (\Exception $e) {
            Log::error('指令發餘點告警失敗', ['station_id' => $stationId, 'error' => $e->getMessage()]);

            $this->supportGroup->editMessage($messageId, (string) Arr::get($config, 'FAIL_SEND_FAILED'));

            return;
        }

        /*
         * ⚠ `ok` 是布林，要用 `(bool)` 不要用 `filled()` ——
         * `filled(false)` 回的是 true（見 bugfix/2026-10-12-filled-on-boolean）。
         */
        if ((bool) Arr::get($result, 'ok') !== true) {
            $this->supportGroup->editMessage($messageId, $this->failText($station, $result, $config));

            return;
        }

        $this->supportGroup->editMessage($messageId, strtr((string) Arr::get($config, 'SENT_TEXT'), [
            '{station}' => (string) $station->name,
            '{credits}' => number_format((float) Arr::get($result, 'credits'), 2, '.', ''),
            '{group}'   => $this->groupName($station),
            '{notes}'   => $this->notesText($result, $config),
        ]));

        Log::info('指令發餘點告警完成', [
            'station_id' => $station->id,
            'credits'    => Arr::get($result, 'credits'),
            'user_id'    => filled($user) ? $user->id : null,
        ]);
    }

    /**
     * 「發出去了，但不完全如預期」的補充說明
     *
     * ⚠ 一定要講。不講的話按的人會以為客戶收到了完整的兩則，
     * 實際上可能只收到告警那一則。
     *
     * @param array $result
     * @param array $config
     * @return string 沒有要補充就回空字串
     */
    private function notesText(array $result, array $config)
    {
        $notes = '';

        // 這四個都是布林，一律用 (bool)，不要用 filled()
        if ((bool) Arr::get($result, 'below') !== true) {
            $notes .= (string) Arr::get($config, 'NOTE_ABOVE_THRESHOLD');
        }

        if ((bool) Arr::get($result, 'has_topup') !== true) {
            /*
             * ⚠ 「匯率未定」與「這個系統沒填補點訊息」是兩件不同的事。
             * 混成一句的話，看的人會去查匯率，但匯率可能好好的
             * （後台那顆按鈕也是這樣分的）。
             */
            $notes .= (string) Arr::get(
                $config,
                (bool) Arr::get($result, 'has_template') !== true ? 'NOTE_NO_TEMPLATE' : 'NOTE_NO_RATE'
            );

            return $notes;
        }

        if ((bool) Arr::get($result, 'topup_sent') !== true) {
            $notes .= (string) Arr::get($config, 'NOTE_TOPUP_FAILED');
        }

        return $notes;
    }

    /**
     * 失敗時要講哪一句
     *
     * @param \App\Models\Station $station
     * @param array               $result
     * @param array               $config
     * @return string
     */
    private function failText($station, array $result, array $config)
    {
        $reason = (string) Arr::get($result, 'reason');

        $map = [
            'no_group'    => 'FAIL_NO_GROUP',
            'sync_failed' => 'FAIL_SYNC_FAILED',
            'send_failed' => 'FAIL_SEND_FAILED',
        ];

        Log::warning('指令發餘點告警未送出', ['station_id' => $station->id, 'reason' => $reason]);

        return strtr((string) Arr::get($config, Arr::get($map, $reason, 'FAIL_UNKNOWN')), [
            '{station}' => (string) $station->name,
        ]);
    }

    /**
     * 缺設定、發不了的時候要講什麼
     *
     * ⚠ **要把缺的欄位講出來**。只說「無法發送」的話，看的人得自己去開
     * 編輯視窗一欄一欄對 —— 後台那顆 disable 按鈕的 tooltip 也是這樣做的。
     *
     * 欄位名 → 中文走 `station.topup_notice_missing.*`，跟後台同一組語系鍵，
     * 兩邊講的名字才會一樣。
     *
     * @param \App\Models\Station $station
     * @param array<string>       $blockers
     * @param array               $config
     * @return string
     */
    private function blockedText($station, array $blockers, array $config)
    {
        $labels = [];

        foreach ($blockers as $field) {
            $labels[] = trans("station.topup_notice_missing.{$field}");
        }

        Log::info('指令發餘點告警：站台缺設定，未送出', [
            'station_id' => $station->id,
            'blockers'   => $blockers,
        ]);

        return strtr((string) Arr::get($config, 'FAIL_BLOCKED'), [
            '{station}' => (string) $station->name,
            '{missing}' => implode('、', $labels),
        ]);
    }

    /**
     * 對不到站台 —— 分辨「講了兩個」與「名字不存在」
     *
     * @param string   $raw
     * @param int|null $threadId
     * @param array    $config
     * @return void
     */
    private function replyNoSuchStation($raw, $threadId, array $config)
    {
        $mentioned = $this->nameMatcher->mentioned($raw, $this->stations());

        if (count($mentioned) > 1) {
            $this->reply(strtr((string) Arr::get($config, 'MULTIPLE_TEXT'), [
                '{names}' => implode('、', $mentioned),
            ]), $threadId);

            return;
        }

        $this->reply(strtr((string) Arr::get($config, 'NOT_FOUND_TEXT'), [
            '{station}' => (string) $raw,
            '{names}'   => $this->stationNames(),
        ]), $threadId);
    }

    /**
     * 這個站台的客戶群叫什麼
     *
     * ⚠ 這是確認訊息裡**最重要的一行**：站台名打錯時，看到的人要能從
     * 群組名字認出「這不是我要發的那個」。
     *
     * @param \App\Models\Station $station
     * @return string
     */
    private function groupName($station)
    {
        $group = $station->telegramGroup;

        if (filled($group) && filled($group->title)) {
            return (string) $group->title;
        }

        // 查不到名字時至少把 id 講出來，才對得起來
        return "群組 {$station->telegram_group_id}";
    }

    /**
     * 這個人能不能發 —— 不能的話回要講的那句話
     *
     * @param array $from
     * @param array $config
     * @return string 空字串 ＝ 可以
     */
    private function permissionError(array $from, array $config)
    {
        $user = $this->resolveUser($from);

        if (blank($user)) {
            return (string) Arr::get($config, 'NOT_BOUND_TEXT');
        }

        if (!$user->hasPermission(self::PERMISSION)) {
            Log::info('指令發餘點告警被權限擋下', ['user_id' => $user->id]);

            return (string) Arr::get($config, 'NO_PERMISSION_TEXT');
        }

        return '';
    }

    /**
     * Telegram 使用者 → 系統帳號
     *
     * ⚠ 一次按鈕會問兩次（驗權限、記是誰送出的），所以記起來 ——
     * 同一個人查兩遍資料庫沒有意義。
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

        $telegramId = (int) $telegramId;

        /*
         * ⚠ 用「查過了沒」而不是 `blank($this->resolvedUser)` 當判斷 ——
         * 查不到的人回的是 null，用 blank() 的話每次都會重查一遍。
         */
        if ($this->resolvedFor !== $telegramId) {
            $this->resolvedFor = $telegramId;
            $this->resolvedUser = $this->userRepository->findByTelegramUserId($telegramId);
        }

        return $this->resolvedUser;
    }

    /**
     * 站台清單（一次指令內只撈一次）
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function stations()
    {
        if (blank($this->stations)) {
            $this->stations = $this->stationRepository->allForDropdown();
        }

        return $this->stations;
    }

    /**
     * @return string
     */
    private function stationNames()
    {
        $names = [];

        foreach ($this->stations() as $station) {
            $names[] = (string) $station->name;
        }

        return filled($names) ? implode('、', $names) : '（目前沒有站台）';
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
