<?php

namespace App\Services;

use App\Models\Station;
use App\Models\StationCreditLog;
use App\Repositories\StationCreditLogRepository;
use App\Repositories\StationRepository;
use App\Repositories\TelegramRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 站台 Service
 */
class StationService
{
    private $stationRepository;
    private $telegramRepository;
    private $mainSystemApi;
    private $telegramBot;
    private $creditLogRepository;

    public function __construct(
        StationRepository $stationRepository,
        TelegramRepository $telegramRepository,
        MainSystemApiService $mainSystemApi,
        TelegramBotService $telegramBot,
        StationCreditLogRepository $creditLogRepository
    ) {
        $this->stationRepository = $stationRepository;
        $this->telegramRepository = $telegramRepository;
        $this->mainSystemApi = $mainSystemApi;
        $this->telegramBot = $telegramBot;
        // 每次同步記一筆，點數報表的曲線圖靠這個
        $this->creditLogRepository = $creditLogRepository;
    }

    /**
     * 查詢站台列表（分頁）
     *
     * @param array $params 可含 keyword, status, per_page
     * @return LengthAwarePaginator
     */
    public function list($params)
    {
        $criteria = [
            'keyword'      => $params['keyword'] ?? null,
            'domain'       => $params['domain'] ?? null,
            'system_id'    => $params['system_id'] ?? null,
            'status'       => $params['status'] ?? null,
            'credits_min'  => $params['credits_min'] ?? null,
            'credits_max'  => $params['credits_max'] ?? null,
            'support_shop' => $params['support_shop'] ?? null,
            'score_runner' => $params['score_runner'] ?? null,
        ];

        return $this->stationRepository->paginate($criteria, (int) ($params['per_page'] ?? config('constants.PAGINATION.DEFAULT', 10)));
    }

    /**
     * 新增站台
     *
     * @param array $params
     * @return Station
     */
    public function create($params)
    {
        $telegramGroupId = $this->resolveTelegramGroupId($params);

        return $this->stationRepository->create([
            'system_id'         => Arr::get($params, 'system_id'),
            'name'              => Arr::get($params, 'name'),
            'domain'            => Arr::get($params, 'domain'),
            'api_url'           => Arr::get($params, 'api_url'),
            'api_key'           => Arr::get($params, 'api_key'),
            'telegram_group_id' => $telegramGroupId,
            'status'            => config('constants.STATION.STATUS.ACTIVE'),
            // null = 沿用全域告警門檻
            'credit_alert_threshold' => Arr::get($params, 'credit_alert_threshold'),
            'note'              => Arr::get($params, 'note'),
        ]);
    }

    /**
     * 更新站台
     *
     * @param Station $station
     * @param array   $params
     * @return Station
     */
    public function update(Station $station, $params)
    {
        // telegram_chat_id → telegram_group_id
        if (array_key_exists('telegram_chat_id', $params)) {
            $params['telegram_group_id'] = $this->resolveTelegramGroupId($params);
            unset($params['telegram_chat_id']);
        }

        $fields = ['system_id', 'name', 'domain', 'api_url', 'api_key', 'telegram_group_id', 'status', 'credit_alert_threshold', 'note'];
        $attributes = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $params)) {
                $attributes[$field] = $params[$field];
            }
        }

        if (array_key_exists('status', $attributes)) {
            $attributes['status'] = (int) $attributes['status'];
        }

        return $this->stationRepository->update($station, $attributes);
    }

    /**
     * 取得已知的 Telegram 群組列表（從 DB 中讀取，由 webhook 自動建立）
     *
     * @return array 群組列表 [{chat_id, title}]
     */
    public function fetchBotGroups()
    {
        $groups = $this->telegramRepository->getAllGroups();

        return $groups->map(function ($g) {
            return [
                'chat_id' => $g->chat_id,
                'title'   => $g->title,
            ];
        })->values()->all();
    }

    /**
     * 根據 telegram_chat_id 查找或建立 TelegramGroup，回傳其 id
     *
     * @param array $params
     * @return int|null
     */
    private function resolveTelegramGroupId($params)
    {
        $chatId = $params['telegram_chat_id'] ?? null;

        if (!filled($chatId)) {
            return null;
        }

        $group = $this->telegramRepository->findGroupByChatId((int) $chatId);

        if ($group) {
            return $group->id;
        }

        $newGroup = $this->telegramRepository->createGroup([
            'chat_id' => (int) $chatId,
            'title'   => $params['name'] ?? "Group {$chatId}",
            'status'  => 1,
        ]);

        return $newGroup->id;
    }

    /**
     * 從主系統 API 同步站台資訊（點數、費率、功能開關等）
     *
     * API 回傳的 data 結構：
     * - admin_credit：點數餘額
     * - system_rate / system_rate_withdraw：代收/代付費率
     * - usdt_deposit / atm_deposit / cvs_deposit / cc_deposit / qr_deposit / withdraw：功能開關
     * - 其他欄位全部存入 settings JSON
     *
     * ⚠ **所有同步路徑都走這一支**（每日排程、列表的同步按鈕、小幫手查餘點、
     * 餘點告警、虛擬機試算），所以點數報表的紀錄只掛在這裡就全部涵蓋，
     * 日後新增路徑也不必記得補（需求方 2026-10-26：「無論是自動去同步，
     * 或是手動去按同步，都要做紀錄」）。
     *
     * @param Station $station
     * @param string  $source 怎麼觸發的（見 StationCreditLog::SOURCE_*）
     * @return array|null 同步後的資料，失敗回傳 null
     */
    public function syncInfo(Station $station, $source = StationCreditLog::SOURCE_MANUAL)
    {
        $info = $this->mainSystemApi->getStationInfo($station->api_url, $station->api_key);

        if (!$info) {
            /*
             * ⚠ **失敗也要留一筆**（需求方 2026-10-26）：沒有這一筆的話，
             * 圖上某天沒有點就分不出是「沒人同步」還是「同步失敗」。
             * `credits` 留 null，曲線上是斷點，下方清單才列出來。
             */
            $this->logCredit($station, null, $source);

            return null;
        }

        // admin_credit → credits 欄位
        $credits = Arr::get($info, 'admin_credit', $station->credits);

        // 整包 data 存入 settings JSON
        $this->stationRepository->update($station, [
            'credits'   => $credits,
            'settings'  => $info,
            'synced_at' => now(),
        ]);

        /*
         * ⚠ **點數沒變也照記**（需求方 2026-10-26）：原話是「如果有去同步，
         * 就寫一筆資料」。圖上會有一串平點，但那正好表達「這幾天確實有在同步，
         * 只是客戶沒動」—— 不記的話看不出有沒有同步過。
         */
        $this->logCredit($station, $credits, $source);

        return $info;
    }

    /**
     * 補點審核通過之後，把結果反映到本地
     *
     * 兩件事：在點數報表上留一個點，並把站台列表上的**餘額與最後同步時間
     * 一起更新**（需求方 2026-11-01：「我補完點，會馬上有現在點數，所以該
     * 站台的最後同步時間和點數應該要是最新的」）。
     *
     * ⚠ 原本只記紀錄、不動 `station` —— 所以補完點之後列表上還是舊餘額、
     * 舊的同步時間，要等下一次同步才會對。補點回應裡的 `data.credit` 就是
     * 權威的新餘額，手上有卻不用，等於讓畫面故意落後一輪。
     *
     * ⚠ **這一支刻意不走 `syncInfo()`**：那會再打一次主系統 API，而補點
     * 回應裡的 `data.credit` 已經是權威的新餘額了 —— 再查一次只是多一次
     * 外部請求，而且兩次之間客戶又消耗了點數的話，圖上那一跳反而對不起來。
     *
     * ⚠ `$creditType` **一定要照補的那一種傳**：主系統補點 API 回的
     * `data.credit` 是「被補的那一種」的餘額，商城補點回的是商城餘額 ——
     * 傳錯的話那個數字會掉到另一條曲線上，變成一個憑空的大跳。
     *
     * ⚠ **整支不能往外丟例外。** 呼叫點在 `CreditTopupService::approve()`
     * 的交易**之外**，而且主系統那邊的點數**已經加扣完成**了 ——
     * 這裡丟例外會讓審核的人看到一個紅色錯誤，然後以為補點失敗而再補一次。
     *
     * @param Station     $station
     * @param string|null $credits      補完之後的餘額
     * @param string|null $changeAmount 這次的異動金額（帶正負號）
     * @param string      $creditType   見 StationCreditLog::CREDIT_TYPE_*
     * @return void
     */
    public function applyTopupCredit(Station $station, $credits, $changeAmount, $creditType)
    {
        try {
            /*
             * 兩張表（`station_credit_log` 與 `station`）包在同一個交易裡。
             *
             * ⚠ 這不是形式上的：`logCredit()` 自己會吞掉寫入失敗（報表少一個
             * 點不該影響別的），所以**能讓這個交易回滾的只有站台更新失敗** ——
             * 那時候紀錄也一起退掉，不會留下「圖上有這個點，但列表沒更新」
             * 的半套狀態。
             */
            DB::transaction(function () use ($station, $credits, $changeAmount, $creditType) {
                $this->logCredit($station, $credits, StationCreditLog::SOURCE_TOPUP, $changeAmount, $creditType);
                $this->refreshCreditsFromTopup($station, $credits, $creditType);
            });
        } catch (\Exception $e) {
            Log::error('補點後更新站台餘額失敗', [
                'station_id'  => $station->id,
                'credit_type' => $creditType,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * 把補點後的餘額寫回站台（只有系統點數那一種）
     *
     * ⚠⚠ **商城補點不能寫進 `station.credits`。** 那一欄存的是**系統點數**
     * （`syncInfo()` 取的是 `admin_credit`），而商城補點回的是商城餘額 ——
     * 寫進去的話列表上的點數會突然變成另一個數字，而且下一次同步又會變回來，
     * 看起來像系統在亂跳。
     *
     * ⚠ 同理，商城補點**也不要動 `synced_at`**：那一欄的意思是「列表上這個
     * 餘額是什麼時候拿到的」。餘額沒更新卻把時間推到現在，等於謊稱資料是新的。
     *
     * ⚠ 沒拿到餘額（`blank($credits)`）時什麼都不做 —— 呼叫端已經為了同樣的
     * 理由略過紀錄了（填 0 或填舊值都比缺一筆難查）。
     *
     * @param Station     $station
     * @param string|null $credits
     * @param string      $creditType
     * @return void
     */
    private function refreshCreditsFromTopup(Station $station, $credits, $creditType)
    {
        // 短路：商城點數與拿不到餘額的情況都不該碰站台那一列
        if ($creditType !== StationCreditLog::CREDIT_TYPE_CREDIT || blank($credits)) {
            return;
        }

        $this->stationRepository->update($station, [
            'credits'   => $credits,
            'synced_at' => now(),
        ]);
    }

    /**
     * 記一筆餘點紀錄
     *
     * ⚠ **寫紀錄失敗不能讓同步失敗**：點數本身已經更新好了，為了一筆報表用的
     * 紀錄把整個同步往上拋，會讓排程、告警、指令全部跟著掛掉。
     *
     * @param Station     $station
     * @param string|null $credits      null ＝ 這次同步失敗
     * @param string      $source
     * @param string|null $changeAmount 只有補點那幾筆有值；同步一律 null
     * @param string      $creditType   見 StationCreditLog::CREDIT_TYPE_*
     * @return void
     */
    private function logCredit(
        Station $station,
        $credits,
        $source,
        $changeAmount = null,
        $creditType = StationCreditLog::CREDIT_TYPE_CREDIT
    ) {
        try {
            $this->creditLogRepository->create([
                'station_id'    => $station->id,
                'credits'       => $credits,
                'credit_type'   => $creditType,
                'change_amount' => $changeAmount,
                'source'        => $source,
            ]);
        } catch (\Exception $e) {
            Log::warning('站台餘點紀錄寫入失敗', [
                'station_id'  => $station->id,
                'source'      => $source,
                'credit_type' => $creditType,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * 取得所有站台名稱（下拉選單用）
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function allForDropdown()
    {
        return $this->stationRepository->allForDropdown();
    }

    /**
     * 取得所有啟用中的系統（下拉選單用）
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getActiveSystems()
    {
        return $this->stationRepository->getActiveSystems();
    }

    /**
     * 取得各系統正常狀態站台數量統計
     *
     * @return array [ ['name' => '...', 'count' => N], ... ]
     */
    public function getSystemStats()
    {
        return $this->stationRepository->countActiveBySystem();
    }

    /**
     * 更新系統
     *
     * @param \App\Models\System $system
     * @param array              $params
     * @return \App\Models\System
     */
    public function updateSystem($system, $params)
    {
        return $this->stationRepository->updateSystem($system, $params);
    }

    /**
     * 新增系統
     *
     * @param string      $name
     * @param string|null $botToken
     * @return \App\Models\System
     */
    public function createSystem($name, $botToken = null)
    {
        $attributes = [
            'name'   => $name,
            'status' => 1,
        ];

        if (filled($botToken)) {
            $attributes['bot_token'] = $botToken;
        }

        return $this->stationRepository->createSystem($attributes);
    }
}
