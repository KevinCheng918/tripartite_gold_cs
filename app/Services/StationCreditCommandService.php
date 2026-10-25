<?php

namespace App\Services;

use App\Models\StationCreditLog;
use App\Repositories\StationRepository;
use App\Repositories\UserRepository;
use App\Services\AutoReply\StationCreditCommandParser;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 用 Telegram 指令查站台餘點
 *
 * 「幫我查一下 LV_UAT 餘點」→ 確認 → 同步 → 回報點數與同步時間。
 *
 * ⚠ **要先確認才同步**（需求方 2026-10-13）。看起來是唯讀，實際上
 * `StationService::syncInfo()` 會打主系統 API **並把 credits / settings /
 * synced_at 寫回資料庫** —— 不是純查詢，而且外部 API 要花時間。
 *
 * ⚠ **同步失敗不回舊點數。** 資料庫裡那個數字可能是好幾天前的，
 * 拿它當「現在的餘點」會讓人以為點數還夠。餘點告警排程也是同一條規則
 * （`SKIP_SYNC_FAILED`：絕不拿舊點數判斷）。
 *
 * ⚠ **要 `telegram_chat.station_credit` 權限**（獨立的一項）。
 *
 * 一開始用的是後台同步按鈕那個 `station.update`，理由是「同一件事換個入口
 * 做就不檢查等於 RBAC 後門」。需求方否決（2026-10-13）：`station.update`
 * 的意思是**能改站台設定**，範圍比「在群組問一下還剩多少點」大得多 ——
 * 為了讓人能查餘點而給出站台編輯權，是把洞開在另一邊。
 */
class StationCreditCommandService
{
    /**
     * 做這件事需要的權限
     *
     * ⚠ 不是後台同步按鈕的 `station.update`：**後台編輯站台**與
     * **用指令查一下餘點**是兩件不同的事（需求方 2026-10-13）。
     *
     * @var string
     */
    const PERMISSION = 'telegram_chat.station_credit';

    private $parser;
    private $stationRepository;
    private $userRepository;
    private $stationService;
    private $nameMatcher;
    private $supportGroup;

    /**
     * 可同步餘點的站台（一次指令內的暫存）
     *
     * @var \Illuminate\Database\Eloquent\Collection|null
     */
    private $syncable;

    public function __construct(
        StationCreditCommandParser $parser,
        StationRepository $stationRepository,
        UserRepository $userRepository,
        StationService $stationService,
        StationNameMatcher $nameMatcher,
        SupportGroupService $supportGroup
    ) {
        $this->parser = $parser;
        $this->stationRepository = $stationRepository;
        // Telegram 使用者 → 系統帳號，才有權限可查
        $this->userRepository = $userRepository;
        // 同步走站台管理頁「同步」按鈕的同一支，不另外寫一份
        $this->stationService = $stationService;
        // 「他說的名字是哪個站台」四支指令共用同一份規則
        $this->nameMatcher = $nameMatcher;
        $this->supportGroup = $supportGroup;
    }

    /*
     * ⚠ 這裡原本有一支 `looksLikeCommand()`（關鍵字粗篩），2026-10-13 拿掉。
     *
     * 上線當天就漏掉「確認 Rich 還剩多少點」——「餘點」「點數」一個都不中，
     * 這支指令根本沒被問到，訊息掉到分派器最後一棒被排成了明天的提醒。
     * 關鍵字清單永遠追不完講法，詳見 `TelegramCommandRouter::handlers()`。
     */

    /**
     * 處理一句指令
     *
     * @param string   $text     已經去掉 @機器人
     * @param array    $from     Telegram 的 from（對到系統帳號驗權限用）
     * @param int|null $threadId
     * @return bool 有處理就回 true；false 代表「這不是查餘點」，換下一個 handler
     */
    public function handle($text, array $from, $threadId)
    {
        $config = (array) config('constants.STATION_CREDIT_COMMAND');
        $parsed = $this->parser->parse($text);

        if (blank($parsed)) {
            $this->reply((string) Arr::get($config, 'UNAVAILABLE_TEXT'), $threadId);

            return true;
        }

        $intent = (string) Arr::get($parsed, 'intent');

        // 不是在查餘點 —— 讓分派器換下一個
        if ($intent === StationCreditCommandParser::INTENT_NOT_CREDIT) {
            return false;
        }

        if ($intent !== StationCreditCommandParser::INTENT_CREDIT) {
            $this->reply((string) Arr::get($config, 'UNKNOWN_TEXT'), $threadId);

            return true;
        }

        /*
         * ⚠ **權限擋在查站台之前**：沒權限的人不該拿到確認按鈕 ——
         * 給了按鈕再在按下去的時候拒絕，看起來像系統出錯。
         */
        $denied = $this->permissionError($from, $config);

        if (filled($denied)) {
            $this->reply($denied, $threadId);

            return true;
        }

        $raw = (string) Arr::get($parsed, 'station');
        $station = $this->findStation($raw);

        if (filled($station)) {
            $this->replyConfirm($station, $threadId, $config);

            return true;
        }

        /*
         * ⚠ **模型不一定聽話。** prompt 寫了「講了兩個以上就填 unknown」，
         * 實測「幫我查 LV_UAT 跟 T01 的餘點」照樣回
         * `credit` ＋ `station: "LV_UAT、T01"`（2026-10-13 實測）。
         *
         * 所以多站台是在這裡用**實際的站台清單**兜住的 —— 不是去猜
         * 「、」「跟」「和」這些分隔符：站台名稱本身可能含奇怪的字元，
         * 猜分隔符會把合法名稱切壞。
         */
        $matched = $this->nameMatcher->mentioned($raw, $this->syncableStations());

        if (count($matched) > 1) {
            $this->reply(strtr((string) Arr::get($config, 'MULTIPLE_TEXT'), [
                '{names}' => implode('、', $matched),
            ]), $threadId);

            return true;
        }

        $this->replyNotFound($raw, $threadId, $config);

        return true;
    }

    /**
     * 按鈕按下去
     *
     * ⚠ callback_data 直接帶 station id 就夠，不必像預約公告那樣存草稿 ——
     * 這裡要記的只有一個數字。
     *
     * ⚠ **按下去的時候要重新查一次站台**，不能信確認訊息上的那顆按鈕：
     * 確認訊息會一直留在群組裡，三天後有人翻上去再按一次，站台可能
     * 已經停用或 api_key 被清掉了。查詢同樣走 `getForCreditSync()`，
     * 所以那時候會回「找不到」而不是去打一支不存在的 API。
     *
     * ⚠ **按鈕也要檢查權限**，不能只擋在指令那一步：按按鈕的人不一定是
     * 下指令的人。有權限的 A 下了指令，確認訊息留在群組裡，沒權限的 B
     * 按下【查詢】—— 只擋指令的話 B 就看到點數了。
     *
     * @param array    $parts     [action, station_id]
     * @param int|null $messageId
     * @param array    $from      按按鈕的人
     * @param int|null $threadId  按鈕所在的話題
     * @return void
     */
    public function handleCallback(array $parts, $messageId, array $from = [], $threadId = null)
    {
        $config = (array) config('constants.STATION_CREDIT_COMMAND');
        $action = (string) Arr::get($parts, 0);

        // 取消不需要權限 —— 把自己群組裡的一則確認訊息收掉，誰都可以
        if ($action === (string) Arr::get($config, 'ACTION_CANCEL')) {
            $this->supportGroup->editMessage($messageId, (string) Arr::get($config, 'CANCELLED_TEXT'));

            return;
        }

        $denied = $this->permissionError($from, $config);

        /*
         * ⚠ **另發一則，不要 `editMessage()`。** 編輯的話，沒權限的 B 按一下
         * 就把 A 的確認按鈕蓋掉了 —— A 要重打一次指令。
         *
         * （比較理想是用 `answerCallbackQuery` 的文字跳一個只有 B 看到的提示，
         * 但那一次已經在 controller 答掉了，同一個 query 不能答第二次。）
         */
        if (filled($denied)) {
            $this->reply($denied, $threadId);

            return;
        }

        $station = $this->stationRepository->getForCreditSync((int) Arr::get($parts, 1, 0))->first();

        if (blank($station)) {
            $this->supportGroup->editMessage($messageId, strtr(
                (string) Arr::get($config, 'GONE_TEXT'),
                ['{names}' => $this->stationNames()]
            ));

            return;
        }

        $this->sync($station, $messageId, $config);
    }

    /**
     * 真的去同步並回報
     *
     * ⚠ **先把訊息改成「正在同步…」**：主系統 API 要幾秒，不講的話
     * 使用者會以為按鈕沒反應，然後再按一次。
     *
     * @param \App\Models\Station $station
     * @param int|null            $messageId
     * @param array               $config
     * @return void
     */
    private function sync($station, $messageId, array $config)
    {
        $this->supportGroup->editMessage($messageId, strtr(
            (string) Arr::get($config, 'WORKING_TEXT'),
            ['{station}' => (string) $station->name]
        ));

        try {
            $info = $this->stationService->syncInfo($station, StationCreditLog::SOURCE_COMMAND);
        } catch (\Exception $e) {
            Log::error('指令查餘點同步失敗', ['station_id' => $station->id, 'error' => $e->getMessage()]);
            $info = null;
        }

        /*
         * ⚠ 同步失敗時**不回舊點數** —— 那個數字可能是好幾天前的，
         * 當成「現在的餘點」會讓人以為點數還夠。
         */
        if (blank($info)) {
            /*
             * ⚠ 要記一筆。`syncInfo()` 回 null 是「API 沒回資料」——
             * 不丟例外，所以上面那個 catch 接不到。不記的話「有人查了但
             * 查不到」完全無跡可循，事後查不出是主系統掛了還是設定錯。
             */
            Log::warning('指令查餘點：主系統沒回資料', [
                'station_id' => $station->id,
                'station'    => (string) $station->name,
            ]);

            $this->supportGroup->editMessage($messageId, strtr(
                (string) Arr::get($config, 'SYNC_FAILED_TEXT'),
                ['{station}' => (string) $station->name]
            ));

            return;
        }

        /*
         * ⚠ **一定要 refresh()**：`getForCreditSync()` 的 select 裡**沒有
         * `synced_at`**（那支是餘點告警用的，不需要這一欄），不重撈的話
         * 同步時間會是 null。`refresh()` 撈的是全部欄位。
         */
        $station->refresh();

        /*
         * `credits` 的 cast 是 `decimal:2`，轉出來就是「16390.94」這個樣子
         * （兩位小數、不加千分位），跟餘點告警的 `formatCredits()` 同一個
         * 輸出 —— 所以這裡不再格式化一次，格式才不會兩邊分岔。
         */
        $this->supportGroup->editMessage($messageId, strtr((string) Arr::get($config, 'RESULT_TEXT'), [
            '{station}'   => (string) $station->name,
            '{credits}'   => (string) $station->credits,
            '{synced_at}' => filled($station->synced_at)
                ? $station->synced_at->format('Y-m-d H:i:s')
                : '-',
        ]));

        Log::info('指令查餘點完成', [
            'station_id' => $station->id,
            'credits'    => $station->credits,
        ]);
    }

    /**
     * 這個人能不能查 —— 不能的話回要講的那句話
     *
     * 回空字串 ＝ 可以。回字串 ＝ 要把這句話講出去。
     * 分成「回文字」而不是「回 bool 自己發訊息」，是因為指令要回在話題裡、
     * 按鈕要回在按鈕那邊，發送的管道不一樣。
     *
     * @param array $from   Telegram 的 from
     * @param array $config
     * @return string
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
            Log::info('指令查餘點被權限擋下', ['user_id' => $user->id]);

            return (string) Arr::get($config, 'NO_PERMISSION_TEXT');
        }

        return '';
    }

    /**
     * 依名稱找站台
     *
     * ⚠ **走 `getForCreditSync()`**（餘點同步排程的來源）而不是自己下一條查詢：
     * 那支已經篩掉「停用」與「沒設 API」的站台。自己寫一條的話，
     * 會出現「指令找得到這個站台、按下去卻同步不了」那種不一致。
     *
     * ⚠ 比對本身交給 `StationNameMatcher`（四支指令共用）——
     * 原本這裡自己寫一份「不計大小寫的完全相符」，`lv-uat` 會找不到
     * `LV_UAT`。比對規則只能有一份。
     *
     * @param string|null $name
     * @return \App\Models\Station|null
     */
    private function findStation($name)
    {
        return $this->nameMatcher->match($name, $this->syncableStations());
    }

    /**
     * 可以同步餘點的站台（一次指令內只撈一次）
     *
     * ⚠ 一次指令會問三個問題（找站台、數有幾個、列出可查的），
     * 每個都撈一次的話同一條查詢會跑三遍。
     *
     * ⚠ 一筆站台都沒有時這裡會每次都重撈（空集合的 `blank()` 是 true）——
     * 刻意不為它多留一個旗標：那種狀態下指令只會回「目前沒有可查的站台」，
     * 多跑兩次空查詢換掉一個多餘的欄位不值得。
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function syncableStations()
    {
        if (blank($this->syncable)) {
            $this->syncable = $this->stationRepository->getForCreditSync();
        }

        return $this->syncable;
    }

    /**
     * 回確認訊息
     *
     * @param \App\Models\Station $station
     * @param int|null            $threadId
     * @param array               $config
     * @return void
     */
    private function replyConfirm($station, $threadId, array $config)
    {
        $prefix = (string) Arr::get($config, 'CALLBACK');
        $confirm = (string) Arr::get($config, 'ACTION_CONFIRM');
        $cancel = (string) Arr::get($config, 'ACTION_CANCEL');

        $keyboard = [[
            [
                'text'          => (string) Arr::get($config, 'BUTTON_CONFIRM'),
                'callback_data' => "{$prefix}:{$confirm}:{$station->id}",
            ],
            [
                'text'          => (string) Arr::get($config, 'BUTTON_CANCEL'),
                'callback_data' => "{$prefix}:{$cancel}:{$station->id}",
            ],
        ]];

        $this->supportGroup->sendToThread(strtr(
            (string) Arr::get($config, 'CONFIRM_TEXT'),
            ['{station}' => (string) $station->name]
        ), $threadId, $keyboard);
    }

    /**
     * 找不到站台時，列出可查的
     *
     * @param string|null $name
     * @param int|null    $threadId
     * @param array       $config
     * @return void
     */
    private function replyNotFound($name, $threadId, array $config)
    {
        $this->reply(strtr((string) Arr::get($config, 'NOT_FOUND_TEXT'), [
            '{station}' => (string) $name,
            '{names}'   => $this->stationNames(),
        ]), $threadId);
    }

    /**
     * 可以查的站台名稱
     *
     * @return string
     */
    private function stationNames()
    {
        $names = [];

        foreach ($this->syncableStations() as $station) {
            $names[] = (string) $station->name;
        }

        return filled($names) ? implode('、', $names) : '（目前沒有可查的站台）';
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
