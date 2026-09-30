<?php

namespace App\Services;

use App\Models\Station;
use App\Repositories\StationRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 站台餘點告警
 *
 * 每天同步一次所有站台的系統餘點，低於門檻就發 Telegram 提醒客戶補點 ——
 * 點數用完主系統會自動停用後台，讓客戶在停用後才發現太晚了。
 *
 * 同步本身重用 StationService::syncInfo()（站台管理頁的「同步」按鈕走的是同一支），
 * 這一層只負責「要不要告警、告警給誰」。
 *
 * ⚠ 這個服務會直接發訊息給客戶，誤報的代價比漏報高。所以：
 *   1. API 失敗（syncInfo 回 null）就跳過，**絕不拿上一次的舊點數判斷**
 *   2. 每個站台各自 try/catch，一站發送失敗不影響其他站台
 *   3. 發送成功才寫 credit_alerted_at，失敗的下一輪會重試
 */
class StationCreditAlertService
{
    /** @var string 跳過原因：主系統 API 沒回資料 */
    const SKIP_SYNC_FAILED = 'sync_failed';

    /** @var string 跳過原因：點數還在門檻之上 */
    const SKIP_ABOVE_THRESHOLD = 'above_threshold';

    /** @var string 跳過原因：距上次告警還在冷卻期內 */
    const SKIP_COOLDOWN = 'cooldown';

    /** @var string 跳過原因：站台沒設群組，內部支援群組也沒設 */
    const SKIP_NO_TARGET = 'no_target';

    /** @var string 跳過原因：發送時噴錯 */
    const SKIP_SEND_FAILED = 'send_failed';

    /** @var string 發送目標：站台自己的群組 */
    const TARGET_STATION = 'station';

    /** @var string 發送目標：內部支援群組（站台沒設群組時的退路） */
    const TARGET_INTERNAL = 'internal';

    private $stationRepository;
    private $stationService;
    private $mainSystemApi;
    private $appSettingService;
    private $paymentConfigService;
    private $chatService;
    private $supportGroup;

    public function __construct(
        StationRepository $stationRepository,
        StationService $stationService,
        MainSystemApiService $mainSystemApi,
        AppSettingService $appSettingService,
        PaymentConfigService $paymentConfigService,
        TelegramChatService $chatService,
        SupportGroupService $supportGroup
    ) {
        $this->stationRepository = $stationRepository;
        $this->stationService = $stationService;
        // 只有空跑會直接用它（不寫 DB）；正常跑一律走 StationService::syncInfo()
        $this->mainSystemApi = $mainSystemApi;
        $this->appSettingService = $appSettingService;
        $this->paymentConfigService = $paymentConfigService;
        $this->chatService = $chatService;
        $this->supportGroup = $supportGroup;
    }

    /**
     * 同步所有站台餘點，低於門檻的發告警
     *
     * @param array $options ['dry_run' => bool 只判斷不發送, 'station_id' => int|null 只跑單一站台]
     * @return array 每個站台一筆處理結果，給 Command 印出來看
     *
     * @phpstan-return array<int, array{station:string, credits:float, threshold:float,
     *     alerted:bool, target:string|null, reason:string|null, text:string|null}>
     */
    public function run($options = [])
    {
        $dryRun = (bool) Arr::get($options, 'dry_run', false);
        $stationId = Arr::get($options, 'station_id');

        $stations = $this->stationRepository->getForCreditSync($stationId);

        if (blank($stations)) {
            return [];
        }

        // 全域設定整組讀一次，不要在迴圈裡每站重讀
        $settings = $this->globalSettings();
        $results = [];

        foreach ($stations as $station) {
            $results[] = $this->handleStation($station, $settings, $dryRun);
        }

        return $results;
    }

    /**
     * 啟用中、但因為沒設 API 而不會被檢查的站台
     *
     * 這些站台在 `getForCreditSync()` 的 WHERE 就被篩掉了，不會出現在
     * `run()` 的結果裡 —— 單獨撈出來讓 Command 印出並記 log，
     * 否則「有站台從此不再被檢查」這件事沒有任何地方看得到。
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function unconfiguredStations()
    {
        return $this->stationRepository->getMissingApiForCreditSync();
    }

    /**
     * 全域告警設定（繳款設定頁維護，沒設過就用 constants 的預設）
     *
     * @return array{threshold:float, cooldown_days:int, template:string}
     */
    public function globalSettings()
    {
        $defaults = config('constants.STATION.CREDIT_ALERT');

        return [
            'threshold' => $this->appSettingService->getFloat(
                AppSettingService::KEY_CREDIT_ALERT_THRESHOLD,
                (float) $defaults['THRESHOLD']
            ),
            'cooldown_days' => $this->appSettingService->getInt(
                AppSettingService::KEY_CREDIT_ALERT_COOLDOWN_DAYS,
                (int) $defaults['COOLDOWN_DAYS']
            ),
            'template' => $this->alertTemplate(),
        ];
    }

    /**
     * 告警公版（繳款設定頁維護，沒設過就用 constants 的預設）
     *
     * @return string
     */
    public function alertTemplate()
    {
        $template = $this->appSettingService->get(AppSettingService::KEY_CREDIT_ALERT_TEMPLATE);

        return filled($template)
            ? $template
            : (string) config('constants.STATION.CREDIT_ALERT.TEMPLATE');
    }

    /**
     * 套用公版產生告警文案
     *
     * 給預覽用，也是實際發送走的同一支 —— 客服在設定頁看到的就是客戶會收到的。
     *
     * @param string $template
     * @param string $stationName
     * @param float  $credits
     * @param float  $threshold
     * @return string
     */
    public function renderAlert($template, $stationName, $credits, $threshold)
    {
        return $this->paymentConfigService->renderTemplate($template, [
            'station'   => $stationName,
            'credit'    => $this->formatCredits($credits),
            'threshold' => $this->formatCredits($threshold),
        ]);
    }

    // ---------------------------------------------------------------
    //  單一站台
    // ---------------------------------------------------------------

    /**
     * 處理單一站台：同步 → 判斷 → 發送
     *
     * @param Station $station
     * @param array   $settings 全域設定
     * @param bool    $dryRun
     * @return array 處理結果
     */
    private function handleStation(Station $station, $settings, $dryRun)
    {
        $threshold = $this->thresholdFor($station, $settings['threshold']);

        /*
         * 同步在最前面：沒有新的點數就沒什麼可判斷的。
         *
         * 空跑時只問 API、不走 syncInfo —— syncInfo 會把 credits / settings /
         * synced_at 寫進 DB，那就不是「空跑」了。空跑的用途就是安心確認
         * 「會發給誰、內容長怎樣」，它不該留下任何痕跡。
         */
        $info = $dryRun
            ? $this->mainSystemApi->getStationInfo($station->api_url, $station->api_key)
            : $this->stationService->syncInfo($station);

        if (blank($info)) {
            /*
             * API 掛掉、逾時、或回非成功狀態都會走到這裡。
             *
             * 這時 DB 裡的 credits 是上一次同步的值，可能是好幾天前的 ——
             * 拿它來判斷會發出「客戶其實已經補過點」的錯誤告警，
             * 所以直接跳過，等下一輪。
             */
            Log::warning('站台餘點同步失敗，跳過告警判斷', [
                'station_id' => $station->id,
                'station'    => $station->name,
            ]);

            return $this->result($station, $threshold, false, null, self::SKIP_SYNC_FAILED);
        }

        /*
         * 非空跑時 syncInfo 已經把新點數寫回 model，讀 $station 就是最新值；
         * 空跑沒有寫入，所以直接從 API 的回傳取。
         *
         * 用 Arr::get 的預設值接住「API 回了但沒有 admin_credit 這個欄位」——
         * 這時 syncInfo 的行為也是保留舊值，兩條路徑要一致。
         */
        $credits = $dryRun
            ? (float) Arr::get($info, 'admin_credit', $station->credits)
            : (float) $station->credits;

        /*
         * 空跑沒有經過 syncInfo，model 上還是 DB 的舊值 —— 放回去（只改記憶體、
         * 不 save），後面輸出的結果才是「實際拿來判斷的那個數字」，
         * 否則表格會顯示舊點數、判斷卻用新點數，看起來像 bug。
         */
        if ($dryRun) {
            $station->credits = $credits;
        }

        if ($credits >= $threshold) {
            return $this->result($station, $threshold, false, null, self::SKIP_ABOVE_THRESHOLD);
        }

        if ($this->inCooldown($station, $settings['cooldown_days'])) {
            return $this->result($station, $threshold, false, null, self::SKIP_COOLDOWN);
        }

        $target = $this->targetFor($station);

        if (blank($target)) {
            Log::warning('站台餘點低於門檻，但沒有可發送的群組', [
                'station_id' => $station->id,
                'station'    => $station->name,
                'credits'    => $credits,
            ]);

            return $this->result($station, $threshold, false, null, self::SKIP_NO_TARGET);
        }

        $text = $this->renderAlert($settings['template'], $station->name, $credits, $threshold);

        if ($dryRun) {
            return $this->result($station, $threshold, false, $target, null, $text);
        }

        return $this->send($station, $threshold, $target, $text);
    }

    /**
     * 實際送出告警並記錄時間
     *
     * @param Station $station
     * @param float   $threshold
     * @param string  $target
     * @param string  $text
     * @return array
     */
    private function send(Station $station, $threshold, $target, $text)
    {
        try {
            if ($target === self::TARGET_INTERNAL) {
                $this->sendToInternal($station, $text);
            } else {
                $this->sendToStation($station, $text);
            }
        } catch (\Exception $e) {
            Log::error('站台餘點告警發送失敗', [
                'station_id' => $station->id,
                'station'    => $station->name,
                'target'     => $target,
                'error'      => $e->getMessage(),
            ]);

            // 不寫 credit_alerted_at —— 沒送出去就不該被冷卻擋住，下一輪要能重試
            return $this->result($station, $threshold, false, $target, self::SKIP_SEND_FAILED, $text);
        }

        $this->stationRepository->update($station, ['credit_alerted_at' => now()]);

        Log::info('站台餘點告警已送出', [
            'station_id' => $station->id,
            'station'    => $station->name,
            'credits'    => (float) $station->credits,
            'threshold'  => $threshold,
            'target'     => $target,
        ]);

        return $this->result($station, $threshold, true, $target, null, $text);
    }

    /**
     * 發到站台自己的 Telegram 群組
     *
     * @param Station $station
     * @param string  $text
     * @return void
     */
    private function sendToStation(Station $station, $text)
    {
        /*
         * mark_replied = false：這是系統主動發的通知，
         * 不該把客戶還在等回覆的提問標記成已回覆。
         *
         * 不帶 signature：「-A」是自動回覆的署名，餘點告警不是在回答問題。
         * is_auto = true：對話列表會顯示機器人圖示，客服看得出這則不是人發的。
         */
        $this->chatService->sendReply(
            (int) $station->telegram_group_id,
            $text,
            null,
            (string) config('constants.STATION.CREDIT_ALERT.SENDER_NAME'),
            ['mark_replied' => false, 'is_auto' => true]
        );
    }

    /**
     * 發到內部支援群組（站台沒設群組時的退路）
     *
     * 前面加一段說明，否則客服會以為客戶已經收到了。
     *
     * @param Station $station
     * @param string  $text
     * @return void
     */
    private function sendToInternal(Station $station, $text)
    {
        $prefix = $this->paymentConfigService->renderTemplate(
            (string) config('constants.STATION.CREDIT_ALERT.INTERNAL_PREFIX'),
            ['station' => $station->name]
        );

        $this->supportGroup->send("{$prefix}{$text}");
    }

    // ---------------------------------------------------------------
    //  判斷
    // ---------------------------------------------------------------

    /**
     * 這個站台適用的門檻
     *
     * 站台自己填了就用自己的，沒填沿用全域設定。
     *
     * 注意填 0 是有意義的：`filled(0)` 為 true，所以門檻 0 會被當成
     * 「這站不要告警」（點數不可能小於 0），這是刻意留的關法。
     *
     * @param Station $station
     * @param float   $globalThreshold
     * @return float
     */
    private function thresholdFor(Station $station, $globalThreshold)
    {
        return filled($station->credit_alert_threshold)
            ? (float) $station->credit_alert_threshold
            : $globalThreshold;
    }

    /**
     * 是否還在冷卻期內（上次告警沒過幾天，先不重複發）
     *
     * @param Station $station
     * @param int     $cooldownDays
     * @return bool
     */
    private function inCooldown(Station $station, $cooldownDays)
    {
        if (blank($station->credit_alerted_at) || $cooldownDays <= 0) {
            return false;
        }

        // addDays() 會改到原本那個 Carbon 物件，一定要先 copy()
        return $station->credit_alerted_at->copy()->addDays($cooldownDays)->isFuture();
    }

    /**
     * 這則告警要發到哪裡
     *
     * 站台有自己的群組就發給客戶；沒有的話退到內部支援群組，
     * 讓客服知道要手動通知。兩邊都沒有就沒得發。
     *
     * @param Station $station
     * @return string|null
     */
    private function targetFor(Station $station)
    {
        if (filled($station->telegram_group_id)) {
            return self::TARGET_STATION;
        }

        return $this->supportGroup->isConfigured() ? self::TARGET_INTERNAL : null;
    }

    // ---------------------------------------------------------------
    //  輸出
    // ---------------------------------------------------------------

    /**
     * 組處理結果
     *
     * @param Station     $station
     * @param float       $threshold
     * @param bool        $alerted 是否真的送出了
     * @param string|null $target
     * @param string|null $reason  沒送出的原因
     * @param string|null $text    告警內容（dry-run 預覽用）
     * @return array
     */
    private function result(Station $station, $threshold, $alerted, $target, $reason = null, $text = null)
    {
        return [
            'station_id' => $station->id,
            'station'    => $station->name,
            'credits'    => (float) $station->credits,
            'threshold'  => $threshold,
            'alerted'    => $alerted,
            'target'     => $target,
            'reason'     => $reason,
            'text'       => $text,
        ];
    }

    /**
     * 點數的顯示格式
     *
     * 固定兩位小數、**不加千分位** —— 主系統自己的告警就是
     * 「當前系統餘點：16390.94」這個樣子，客戶對得起來比較好認。
     *
     * @param float $credits
     * @return string
     */
    private function formatCredits($credits)
    {
        return number_format((float) $credits, 2, '.', '');
    }
}
