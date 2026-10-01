<?php

namespace App\Services;

use App\Models\Station;
use App\Presenters\NumberPresenter;
use App\Repositories\CreditTopupRepository;
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

    /** @var string 跳過原因：這個站台不收費，系統餘點不會被扣 */
    const SKIP_NOT_CHARGED = 'not_charged';

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

    /** @var string 發送目標：內部支援群組（有補點單還沒審核，改催自己人） */
    const TARGET_INTERNAL_PENDING = 'internal_pending';

    /**
     * 今日匯率
     *
     * 一輪告警會掃過所有站台，但「今天的匯率」只有一個 —— 每站各查一次是
     * 白做工。查一次記著，整輪共用。
     *
     * ⚠ 初始值是 **false** 不是 null：`null` 是合法結果（今天還沒決定匯率），
     * 用 null 當「還沒查過」會變成每次都重查。
     *
     * @var float|null|false
     */
    private $todayRate = false;

    /**
     * 系統 id => 繳款設定
     *
     * 同一個系統底下的站台共用同一筆繳款設定，不必每個站台都去查一次。
     *
     * @var array
     */
    private $paymentConfigs = [];

    private $stationRepository;
    private $topupRepository;
    private $stationService;
    private $mainSystemApi;
    private $appSettingService;
    private $paymentConfigService;
    private $chatService;
    private $supportGroup;
    private $dailyRateService;

    public function __construct(
        StationRepository $stationRepository,
        CreditTopupRepository $topupRepository,
        StationService $stationService,
        MainSystemApiService $mainSystemApi,
        AppSettingService $appSettingService,
        PaymentConfigService $paymentConfigService,
        TelegramChatService $chatService,
        SupportGroupService $supportGroup,
        DailyRateService $dailyRateService
    ) {
        $this->stationRepository = $stationRepository;
        $this->topupRepository = $topupRepository;
        $this->stationService = $stationService;
        // 只有空跑會直接用它（不寫 DB）；正常跑一律走 StationService::syncInfo()
        $this->mainSystemApi = $mainSystemApi;
        $this->appSettingService = $appSettingService;
        $this->paymentConfigService = $paymentConfigService;
        $this->chatService = $chatService;
        $this->supportGroup = $supportGroup;
        // 補點訊息要帶今日匯率，匯率還沒定就不附
        $this->dailyRateService = $dailyRateService;
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
     * 測試發送：把這筆繳款設定的補點訊息發到內部支援群組
     *
     * 發的是**完整的告警 + 補點訊息 + 圖**，不是只有補點訊息那一段 ——
     * 補點訊息的意義在於接在告警後面，單獨看看不出實際效果。
     *
     * 一律發內部群組，不會送到客戶那邊。
     *
     * @param \App\Models\PaymentConfig $config
     * @return array{ok: bool, reason: string|null, has_rate: bool, has_image: bool}
     */
    public function testTopupMessage($config)
    {
        if (!$this->supportGroup->isConfigured()) {
            return ['ok' => false, 'reason' => 'no_support_group'];
        }

        if (blank($config->topup_template)) {
            return ['ok' => false, 'reason' => 'no_template'];
        }

        $station = $this->sampleStationFor($config);
        $settings = $this->globalSettings();

        // 先把這個系統的設定塞進快取，topupMessage 就會拿到這一筆而不是重查
        $this->paymentConfigs[(int) $config->system_id] = $config;

        $topup = $this->topupMessage($station);
        $rate = $this->todayRate();

        $text = (string) config('constants.STATION.CREDIT_ALERT.TEST_PREFIX')
            . $this->renderAlert($settings['template'], $station->name, (float) $station->credits, $settings['threshold'])
            . $topup;

        if (blank($topup)) {
            // 匯率還沒決定時補一句，免得看的人以為補點訊息壞了
            $text .= (string) config('constants.STATION.CREDIT_ALERT.TEST_NO_RATE_NOTE');
        }

        $imageUrl = $this->topupImage($station, $topup);

        $sent = filled($imageUrl)
            ? $this->supportGroup->sendPhoto($imageUrl, $text)
            : $this->supportGroup->send($text);

        if (blank(Arr::get($sent, 'result'))) {
            Log::error('補點訊息測試發送失敗', ['config_id' => $config->id, 'response' => $sent]);

            return ['ok' => false, 'reason' => 'send_failed'];
        }

        return [
            'ok'        => true,
            'reason'    => null,
            'has_rate'  => filled($rate),
            'has_image' => filled($imageUrl),
        ];
    }

    /**
     * 測試用的範例站台
     *
     * 優先拿這個系統底下真實的站台（名稱與點數才像真的）。
     * 一個都沒有就捏一筆 —— 測試不該因為「這個系統還沒有站台」就做不了。
     *
     * @param \App\Models\PaymentConfig $config
     * @return Station
     */
    private function sampleStationFor($config)
    {
        $station = $this->stationRepository->firstBySystem((int) $config->system_id);

        if (filled($station)) {
            return $station;
        }

        $sample = new Station();
        $sample->name = (string) config('constants.STATION.CREDIT_ALERT.TEST_STATION_NAME');
        $sample->system_id = $config->system_id;
        $sample->credits = (float) config('constants.STATION.CREDIT_ALERT.TEST_CREDITS');

        return $sample;
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

        /*
         * 不收費的站台不告警。
         *
         * 判斷放在點數算完之後而不是更前面：這樣結果表格上仍看得到它有多少點，
         * 只是標明「不收費」—— 短路省下的只是兩個 Arr::get，不值得犧牲可讀性。
         */
        if (!$this->isCharged($info)) {
            /*
             * 「欄位明確是 false」與「API 根本沒回這個欄位」都會走到這裡，
             * 但後者是另一回事 —— 主系統改了欄位名或版本不同的話，
             * **每個站台**都會被判成不收費，整個告警功能就靜默失效了。
             *
             * 所以這種情況記一筆，不要讓它無聲無息。
             */
            if (!$this->hasChargeInfo($info)) {
                Log::warning('主系統 API 沒有回收費設定，無法判斷是否該告警', [
                    'station_id' => $station->id,
                    'station'    => $station->name,
                    'keys'       => array_keys($info),
                ]);
            }

            return $this->result($station, $threshold, false, null, self::SKIP_NOT_CHARGED);
        }

        if ($credits >= $threshold) {
            return $this->result($station, $threshold, false, null, self::SKIP_ABOVE_THRESHOLD);
        }

        if ($this->inCooldown($station, $settings['cooldown_days'])) {
            return $this->result($station, $threshold, false, null, self::SKIP_COOLDOWN);
        }

        /*
         * 已經有補點單在等審核的話，客戶該做的都做了 ——
         * 再發「請補充點數」是在催一件他已經做完的事，真正卡住的是我們還沒審核。
         */
        $pendingTopups = $this->topupRepository->countPendingByStation($station->id);
        $target = $this->targetFor($station, $pendingTopups);

        if (blank($target)) {
            Log::warning('站台餘點低於門檻，但沒有可發送的群組', [
                'station_id'     => $station->id,
                'station'        => $station->name,
                'credits'        => $credits,
                'pending_topups' => $pendingTopups,
            ]);

            return $this->result($station, $threshold, false, null, self::SKIP_NO_TARGET);
        }

        $topup = $this->topupMessage($station);
        $text = $this->renderAlert($settings['template'], $station->name, $credits, $threshold) . $topup;

        // 發到內部群組的兩種情境（沒設群組／有待審核）各自要先說明為什麼沒發給客戶
        if ($target !== self::TARGET_STATION) {
            $text = $this->internalPrefix($station, $target, $pendingTopups) . $text;
        }

        $imageUrl = $this->topupImage($station, $topup);

        if ($dryRun) {
            return $this->result($station, $threshold, false, $target, null, $text, $imageUrl);
        }

        return $this->send($station, $threshold, $target, $text, $imageUrl);
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
    private function send(Station $station, $threshold, $target, $text, $imageUrl = null)
    {
        try {
            /*
             * 只有 TARGET_STATION 會送到客戶那邊，其餘（沒設群組的退路、
             * 有補點單待審核）一律進內部群組 —— 說明的前綴在 handleStation
             * 就依 target 組好了，這裡只負責送。
             *
             * 圖只跟著給客戶的那則走：內部群組是要客服去處理事情，
             * 再附一張付款地址圖沒有幫助。
             */
            if ($target === self::TARGET_STATION) {
                $this->sendToStation($station, $text, $imageUrl);
            } else {
                $this->supportGroup->send($text);
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
    private function sendToStation(Station $station, $text, $imageUrl = null)
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
            ['mark_replied' => false, 'is_auto' => true, 'image_url' => $imageUrl]
        );
    }

    // ---------------------------------------------------------------
    //  判斷
    // ---------------------------------------------------------------

    /**
     * 這個站台會不會扣系統餘點（也就是「有沒有收費」）
     *
     * 站台詳細資訊頁把費率顯示成「不收費」的依據就是這兩個開關
     * （見 station/index.blade.php 的 depositRateText / withdrawRateText）：
     *
     * | 欄位 | 意思 |
     * |---|---|
     * | `withholding_system`          | 代收的手續費由系統代扣 |
     * | `withdraw_withholding_system` | 代付的手續費由系統代扣 |
     *
     * 代扣才會消耗系統餘點。兩邊都不代扣的站台，餘點根本不會減少 ——
     * 對它發「點數不足將導致系統自動停用後台」只會讓客戶困惑。
     *
     * 只要有一邊收費就要告警（餘點是共用的，一邊扣就會見底）。
     * 代付另外看 `withdraw`：代付功能本身沒開啟時，它的代扣開關沒有意義。
     *
     * ⚠ 判斷用的是 API 剛回的 `$info`，不是 `$station->settings` ——
     * 後者在空跑時沒有被更新，會是上一次同步的舊值。
     *
     * @param array $info 主系統 API 回的 data 區塊
     * @return bool
     */
    private function isCharged($info)
    {
        if ((bool) Arr::get($info, 'withholding_system')) {
            return true;
        }

        /*
         * 不能用 filled() 判斷這幾個值 —— `filled(false)` 是 **true**
         * （blank 對 bool 一律回 false），拿它當開關會把「關閉」讀成「開啟」。
         * 布林設定一律 (bool) cast 後直接判斷。
         */
        $withdrawEnabled = (bool) Arr::get($info, 'withdraw');
        $withdrawCharged = (bool) Arr::get($info, 'withdraw_withholding_system');

        return $withdrawEnabled && $withdrawCharged;
    }

    /**
     * API 的回傳裡到底有沒有收費設定
     *
     * 用 `Arr::has()` 而不是 `filled()` —— 要分的是「key 存不存在」，
     * 不是「值是不是空」。明確設成 `false` 的站台 key 是存在的，
     * 那是正常的「不收費」；key 整個不見才是 API 層面的問題。
     *
     * @param array $info
     * @return bool
     */
    private function hasChargeInfo($info)
    {
        return Arr::has($info, 'withholding_system')
            || Arr::has($info, 'withdraw_withholding_system');
    }

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
     * 1. **有補點單在等審核** → 內部群組。客戶已經申請了，再催他沒有意義，
     *    該催的是我們自己去審核。內部群組沒設定就不發 —— 這種情況**不會**
     *    退回去發給客戶，那正是要避免的事
     * 2. 站台有自己的群組 → 發給客戶
     * 3. 沒有群組 → 退到內部群組，讓客服手動通知
     * 4. 都沒有 → 沒得發
     *
     * @param Station $station
     * @param int     $pendingTopups 這個站台還沒審核的補點單數
     * @return string|null
     */
    private function targetFor(Station $station, $pendingTopups = 0)
    {
        if ($pendingTopups > 0) {
            return $this->supportGroup->isConfigured() ? self::TARGET_INTERNAL_PENDING : null;
        }

        if (filled($station->telegram_group_id)) {
            return self::TARGET_STATION;
        }

        return $this->supportGroup->isConfigured() ? self::TARGET_INTERNAL : null;
    }

    /**
     * 餘點告警後面接的補點訊息
     *
     * ⚠ **只有今天的匯率已經決定時才附上。**
     *
     * 匯率還沒定（含凌晨到早上報價前、或報了還沒人回覆）就只發告警 ——
     * 沒有匯率的補點訊息對客戶沒有意義，他不知道要匯多少台幣；
     * 而附一個過期的昨日匯率更糟。
     *
     * @param Station $station
     * @return string 不附時回空字串，直接接在告警後面不會多出空行
     */
    private function topupMessage(Station $station)
    {
        $rate = $this->todayRate();

        if (blank($rate)) {
            return '';
        }

        $config = $this->paymentConfigFor($station);

        if (blank($config) || blank($config->topup_template)) {
            return '';
        }

        /*
         * 前面補兩個換行當分隔。
         *
         * 公版是客服在表單裡打的，不會（也不該要求他們）自己在開頭留空行 ——
         * 不加的話補點訊息會直接黏在告警的最後一行後面。
         */
        /*
         * 匯率用 trimZeros 而不是 formatCredits：
         * 點數固定兩位小數（16390.94），匯率則是去尾零（30.5 而不是 30.50）——
         * 匯率報價訊息也是這樣顯示，同一個數字在兩個地方要長一樣。
         */
        return "\n\n" . strtr($config->topup_template, [
            '{rate}'    => NumberPresenter::trimZeros($rate, 4),
            '{usdt}'    => $this->usdtForBaseCredit($rate),
            '{content}' => (string) $config->content,
        ]);
    }

    /**
     * 補點訊息要附的圖
     *
     * 就是繳款設定的那張圖（付款地址、二次確認提醒之類），
     * 跟虛擬機繳費通知用的是同一張、同一個欄位。
     *
     * **只有真的附了補點訊息時才給圖** —— 單獨一張付款地址圖配著
     * 「點數不足」的告警，客戶會看不懂那張圖在幹嘛。
     *
     * @param Station $station
     * @param string  $topupText topupMessage() 的結果，空字串表示沒附補點訊息
     * @return string|null
     */
    private function topupImage(Station $station, $topupText)
    {
        if (blank($topupText)) {
            return null;
        }

        $config = $this->paymentConfigFor($station);

        if (blank($config) || blank($config->image)) {
            return null;
        }

        return asset('storage/' . $config->image);
    }

    /**
     * 補一筆基準點數需要多少 USDT
     *
     * 基準是 `constants.STATION.CREDIT_ALERT.TOPUP_USDT_BASE`（預設 50000 點）。
     *
     * **無條件進位到整數** —— 進位的那個零頭是我們這邊收，
     * 四捨五入會讓一半的情況少收。例：
     *
     *     50000 / 31.9 = 1567.398…  →  1568
     *     50000 / 32   = 1562.5     →  1563
     *     50000 / 25   = 2000       →  2000（整除就不動）
     *
     * @param float $rate 今日匯率
     * @return string
     */
    private function usdtForBaseCredit($rate)
    {
        $base = (float) config('constants.STATION.CREDIT_ALERT.TOPUP_USDT_BASE');

        if ($rate <= 0 || $base <= 0) {
            return '—';
        }

        return (string) (int) ceil($base / $rate);
    }

    /**
     * 這個站台所屬系統的繳款設定
     *
     * 補點訊息跟著繳款設定走而不是全域一份 —— **不同系統的收款方式不一樣**，
     * 要講的匯款資訊自然也不同。
     *
     * 同一個系統有多筆時取第一筆啟用的，與繳款通知的取法一致
     * （見 VmController::ajaxSendPaymentNotice）。
     *
     * @param Station $station
     * @return \App\Models\PaymentConfig|null
     */
    private function paymentConfigFor(Station $station)
    {
        if (blank($station->system_id)) {
            return null;
        }

        $systemId = (int) $station->system_id;

        // 用 array_key_exists 而不是 isset：查過但沒設定時存的是 null，
        // isset 會判成「沒查過」而每次重查
        if (!array_key_exists($systemId, $this->paymentConfigs)) {
            $this->paymentConfigs[$systemId] = $this->paymentConfigService
                ->getActiveBySystem($systemId)
                ->first();
        }

        return $this->paymentConfigs[$systemId];
    }

    /**
     * 今日匯率（整輪只查一次）
     *
     * @return float|null
     */
    private function todayRate()
    {
        if ($this->todayRate === false) {
            $this->todayRate = $this->dailyRateService->todayRate();
        }

        return $this->todayRate;
    }

    /**
     * 發到內部群組時，前面那段「為什麼沒發給客戶」的說明
     *
     * 用 strtr 而不是 PaymentConfigService::renderTemplate() ——
     * 這段是系統內部文案（客服不會去後台改它），不需要繳款公版那一整組變數，
     * 也不該為了 {count} 去汙染那邊的變數清單。
     *
     * @param Station $station
     * @param string  $target
     * @param int     $pendingTopups
     * @return string
     */
    private function internalPrefix(Station $station, $target, $pendingTopups)
    {
        $key = $target === self::TARGET_INTERNAL_PENDING
            ? 'INTERNAL_PENDING_PREFIX'
            : 'INTERNAL_PREFIX';

        return strtr((string) config("constants.STATION.CREDIT_ALERT.{$key}"), [
            '{station}' => $station->name,
            '{count}'   => $pendingTopups,
        ]);
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
    private function result(Station $station, $threshold, $alerted, $target, $reason = null, $text = null, $imageUrl = null)
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
            // 空跑要看得出會不會附圖，否則得真的發一次才知道
            'image_url'  => $imageUrl,
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
