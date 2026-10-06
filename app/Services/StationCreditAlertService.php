<?php

namespace App\Services;

use App\Models\Station;
use App\Presenters\TelegramUsernamePresenter;
use App\Repositories\CreditTopupRepository;
use App\Repositories\StationRepository;
use App\Repositories\UserRepository;
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
 * 給客戶的通知是**兩則**：先一則餘點告警（純文字），再一則補點訊息（附繳款圖）。
 * 分開發的理由見 sendTopup()。
 *
 * ⚠ 這個服務會直接發訊息給客戶，誤報的代價比漏報高。所以：
 *   1. API 失敗（syncInfo 回 null）就跳過，**絕不拿上一次的舊點數判斷**
 *   2. 每個站台各自 try/catch，一站出錯不影響其他站台（在 run() 的迴圈裡）
 *
 * ⚠ **沒有冷卻期。** 只要低於門檻，每天 10 點都會通知 —— 點數不足是個
 * 持續存在的狀態，不是一次性事件，客戶補點之前每天提醒一次才合理。
 * `credit_alerted_at` 仍然會寫，但**只作紀錄、不參與判斷**
 * （見 [[2026-10-02-remove-credit-alert-cooldown]]）。
 *
 * ⚠ **補點訊息的任何問題都不能影響告警本身。** 告警是「你的點數快沒了」，
 * 補點訊息只是附帶的「要補的話這樣匯款」—— 匯率查不到、繳款設定查不到，
 * 最多就是少發第二則，絕不該讓第一則也發不出去。
 * 所以 todayRate() 與 paymentConfigFor() 各自 try/catch，失敗一律當成
 * 「沒有」而不是往上拋。
 */
class StationCreditAlertService
{
    /*
     * 這支發到內部群組的訊息屬於哪一類通知。
     *
     * 決定它進哪個 Telegram 話題 —— 對應 `constants.SUPPORT_TOPIC.TYPES` 的 key，
     * 由設定頁的話題清單勾選。沒被任何話題勾到就發到群組主區。
     */
    const NOTICE_TYPE = 'station_credit';

    /** @var int Telegram 圖說上限。超過整則會失敗，不是截斷 */
    private const CAPTION_MAX = 1024;

    /** @var string 跳過原因：主系統 API 沒回資料 */
    const SKIP_SYNC_FAILED = 'sync_failed';

    /** @var string 跳過原因：這個站台不收費，系統餘點不會被扣 */
    const SKIP_NOT_CHARGED = 'not_charged';

    /** @var string 跳過原因：點數還在門檻之上 */
    const SKIP_ABOVE_THRESHOLD = 'above_threshold';

    /** @var string 跳過原因：站台沒設群組，內部支援群組也沒設 */
    const SKIP_NO_TARGET = 'no_target';

    /** @var string 跳過原因：發送時噴錯 */
    const SKIP_SEND_FAILED = 'send_failed';

    /**
     * @var string 跳過原因：這個站台處理到一半噴了預期外的錯
     *
     * 最後防線。走到這裡表示有沒被接住的例外，**那是要修的 bug**，
     * 但它只能毀掉這一個站台，不能讓整輪告警中斷。
     */
    const SKIP_ERROR = 'error';

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
    private $userRepository;

    public function __construct(
        StationRepository $stationRepository,
        CreditTopupRepository $topupRepository,
        StationService $stationService,
        MainSystemApiService $mainSystemApi,
        AppSettingService $appSettingService,
        PaymentConfigService $paymentConfigService,
        TelegramChatService $chatService,
        SupportGroupService $supportGroup,
        DailyRateService $dailyRateService,
        UserRepository $userRepository
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
        // 催審核那則要 tag 主管以上
        $this->userRepository = $userRepository;
    }

    /**
     * 同步所有站台餘點，低於門檻的發告警
     *
     * @param array $options ['dry_run' => bool 只判斷不發送, 'station_id' => int|null 只跑單一站台]
     * @return array 每個站台一筆處理結果，給 Command 印出來看
     *
     * @phpstan-return array<int, array{station:string, credits:float, threshold:float,
     *     alerted:bool, target:string|null, reason:string|null, text:string|null,
     *     topup_text:string|null, image_url:string|null}>
     */
    public function run($options = [])
    {
        $dryRun = (bool) Arr::get($options, 'dry_run', false);
        $stationId = Arr::get($options, 'station_id');

        $stations = $this->stationRepository->getForCreditSync($stationId);

        if (blank($stations)) {
            return [];
        }

        // 全站共用的那組設定讀一次，不要在迴圈裡每站重讀
        $settings = $this->globalSettings();
        $results = [];

        foreach ($stations as $station) {
            /*
             * 每個站台各自 try/catch —— 這是**最後防線**。
             *
             * 一個站台處理到一半噴錯（匯率、繳款設定、主系統 API、Telegram…）
             * 不能讓迴圈中斷，否則後面的站台全部收不到告警：
             * 一個不相關的小問題就讓整個功能靜默失效，而點數用完客戶的後台
             * 真的會被停用。
             *
             * 走到這個 catch 表示有沒被接住的例外，那是要修的 bug ——
             * 所以記 error 而不是 warning，並在結果表標成「處理時發生錯誤」。
             */
            try {
                $results[] = $this->handleStation($station, $settings, $dryRun);
            } catch (\Exception $e) {
                Log::error('站台餘點告警處理失敗，跳過這個站台', [
                    'station_id' => $station->id,
                    'station'    => $station->name,
                    'error'      => $e->getMessage(),
                ]);

                $threshold = $this->thresholdFor($station, $settings['threshold']);
                $results[] = $this->result($station, $threshold, false, null, self::SKIP_ERROR);
            }
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
     * 手動發送補點通知給客戶
     *
     * 站台列表那顆按鈕。跟每天的自動告警差在：
     *
     * | | 自動 | 手動 |
     * |---|---|---|
     * | 點數高於門檻 | 不發 | **照發** |
     * | 有補點單待審核 | 改發內部群組 | **照發給客戶** |
     * | 站台沒設群組 | 退到內部群組 | **擋下來並說明** |
     *
     * 自動流程的那些保護是為了「不要亂吵客戶」；手動是人按的，
     * 按的人知道自己要做什麼，不該替他擋。只有「沒有群組」會擋 ——
     * 那不是判斷問題，是真的沒地方發。
     *
     * 會先同步一次點數，訊息裡的數字才是當下的。
     *
     * 跟自動流程一樣發兩則：告警一則、補點訊息一則（附圖）。
     *
     * @param Station  $station
     * @param int|null $userId 操作者，記 log 用
     * @return array{ok: bool, reason: string|null, credits: float|null,
     *     below: bool, has_topup: bool, has_image: bool, topup_sent: bool}
     */
    public function sendManual(Station $station, $userId = null)
    {
        if (blank($station->telegram_group_id)) {
            return ['ok' => false, 'reason' => 'no_group'];
        }

        // 先同步，訊息裡的點數才是當下的；同步不到就不要發一個過期的數字
        if (blank($this->stationService->syncInfo($station))) {
            return ['ok' => false, 'reason' => 'sync_failed'];
        }

        $settings = $this->globalSettings();
        $threshold = $this->thresholdFor($station, $settings['threshold']);
        $credits = (float) $station->credits;

        $topup = $this->topupFor($station);
        $topupText = Arr::get($topup, 'text');
        $imageUrl = Arr::get($topup, 'image_url');
        $text = $this->renderAlert($settings['template'], $station->name, $credits, $threshold);

        /*
         * 補點訊息沒附上的原因要分開回報。
         *
         * 「匯率未定」與「這個系統沒填補點訊息」是兩件完全不同的事，
         * 混成一句話會讓看的人去查錯地方 —— 匯率明明好好的，卻被告知匯率未定。
         *
         * 這兩個查詢都走快取（todayRate / paymentConfigs），不會多打 DB。
         */
        $config = $this->paymentConfigFor($station);

        try {
            $this->sendToStation($station, $text);
        } catch (\Exception $e) {
            Log::error('手動補點通知發送失敗', [
                'station_id' => $station->id,
                'station'    => $station->name,
                'error'      => $e->getMessage(),
            ]);

            return ['ok' => false, 'reason' => 'send_failed'];
        }

        // 第二則補點訊息失敗不算整體失敗 —— 告警已經發出去了，理由見 sendTopup()
        $topupSent = $this->sendTopup($station, $topupText, $imageUrl);

        /*
         * 記 credit_alerted_at 只是**留一筆紀錄**（最後一次告警是什麼時候）。
         *
         * 它曾經會擋掉隔天的自動告警 —— 前一天按過這顆按鈕，隔天 10 點就
         * 被冷卻期吃掉。冷卻期已經移除，手動按不再影響排程。
         */
        $this->stationRepository->update($station, ['credit_alerted_at' => now()]);

        Log::info('手動補點通知已送出', [
            'station_id' => $station->id,
            'station'    => $station->name,
            'credits'    => $credits,
            'threshold'  => $threshold,
            'below'      => $credits < $threshold,
            // 兩個一起看才讀得懂：沒有第二則時 topup_sent 本來就是 true
            'has_topup'  => filled($topupText),
            'topup_sent' => $topupSent,
            'user_id'    => $userId,
        ]);

        return [
            'ok'           => true,
            'reason'       => null,
            'credits'      => $credits,
            'below'        => $credits < $threshold,
            'has_topup'    => filled($topupText),
            'has_image'    => filled($imageUrl),
            // 告警送出了但第二則掛了 —— 按的人要知道客戶只收到一半
            'topup_sent'   => $topupSent,
            // 沒附補點訊息時，這兩個才看得出是哪一邊缺
            'has_rate'     => filled($this->todayRate()),
            'has_template' => filled($config) && filled($config->topup_template),
        ];
    }

    /**
     * 測試發送：把這筆繳款設定的補點訊息發到內部支援群組
     *
     * 發的是**客戶實際會收到的那兩則**（告警一則、補點訊息＋圖一則），
     * 不是只有補點訊息那一段 —— 補點訊息是接在告警之後的第二則，
     * 單獨看看不出實際效果，也看不出兩則合起來的順序對不對。
     *
     * 一律發內部群組，不會送到客戶那邊。
     *
     * @param \App\Models\PaymentConfig $config
     * @return array{ok: bool, reason: string|null, has_rate: bool,
     *     has_image: bool, topup_sent: bool}
     */
    public function testTopupMessage($config)
    {
        if (!$this->supportGroup->isConfigured()) {
            return ['ok' => false, 'reason' => 'no_support_group'];
        }

        if (blank($config->topup_template)) {
            return ['ok' => false, 'reason' => 'no_template'];
        }

        $station = $this->testStation($config);
        $settings = $this->globalSettings();

        // 先把這個系統的設定塞進快取，topupFor 就會拿到這一筆而不是重查
        $this->paymentConfigs[(int) $config->system_id] = $config;

        $topup = $this->topupFor($station);
        $topupText = Arr::get($topup, 'text');
        $rate = $this->todayRate();

        // 第一則：告警。不附圖，跟客戶收到的一樣
        $text = (string) config('constants.STATION.CREDIT_ALERT.TEST_PREFIX')
            . $this->renderAlert($settings['template'], $station->name, (float) $station->credits, $settings['threshold']);

        if (blank($topupText)) {
            // 匯率還沒決定時補一句，免得看的人以為補點訊息壞了
            $text .= (string) config('constants.STATION.CREDIT_ALERT.TEST_NO_RATE_NOTE');
        }

        $sent = $this->supportGroup->send($text, null, null, self::NOTICE_TYPE);

        if (blank(Arr::get($sent, 'result'))) {
            Log::error('補點訊息測試發送失敗', ['config_id' => $config->id, 'response' => $sent]);

            return ['ok' => false, 'reason' => 'send_failed'];
        }

        /*
         * 第二則：補點訊息（附圖）。
         *
         * 匯率未定時文字是空的，這則就不存在 —— 這不是失敗，
         * 客戶在同樣情況下也只會收到告警那一則。
         */
        $imageUrl = Arr::get($topup, 'image_url');
        $topupSent = true;

        if (filled($topupText)) {
            $topupSent = $this->sendTopupToSupportGroup($config, $topupText, $imageUrl);
        }

        return [
            'ok'         => true,
            'reason'     => null,
            'has_rate'   => filled($rate),
            'has_image'  => filled($imageUrl),
            // 第一則出去了、第二則沒有 —— 這時回報「只送出告警」而不是整體成功
            'topup_sent' => $topupSent,
        ];
    }

    /**
     * 測試發送的第二則：補點訊息發到內部支援群組
     *
     * @param \App\Models\PaymentConfig $config
     * @param string                    $topupText
     * @param string|null               $imageUrl
     * @return bool 有沒有送出去
     */
    private function sendTopupToSupportGroup($config, $topupText, $imageUrl)
    {
        $sent = filled($imageUrl)
            ? $this->supportGroup->sendPhoto($imageUrl, $topupText, self::NOTICE_TYPE)
            : $this->supportGroup->send($topupText, null, null, self::NOTICE_TYPE);

        if (filled(Arr::get($sent, 'result'))) {
            return true;
        }

        Log::error('補點訊息測試發送失敗（告警那則已送出）', [
            'config_id' => $config->id,
            'response'  => $sent,
        ]);

        return false;
    }

    /**
     * 測試用的假站台
     *
     * 名稱固定是「測試」、點數是 constants 裡的假值 ——
     * **不拿任何真實站台的資料。**
     *
     * 測試訊息會進內部支援群組，真實客人的站台名稱與當下餘點出現在那裡，
     * 看到的人得先分辨那是不是真的在告警；而要看某個客人的實際狀況，
     * 站台列表的「補點通知」才是對的地方。
     *
     * `system_id` 要帶：`topupFor()` 是靠它去 `$paymentConfigs`
     * 拿這筆繳款設定的公版。
     *
     * @param \App\Models\PaymentConfig $config
     * @return Station
     */
    private function testStation($config)
    {
        $station = new Station();
        $station->name = (string) config('constants.STATION.CREDIT_ALERT.TEST_STATION_NAME');
        $station->system_id = $config->system_id;
        $station->credits = (float) config('constants.STATION.CREDIT_ALERT.TEST_CREDITS');

        return $station;
    }

    /**
     * 全域告警設定（繳款設定頁維護，沒設過就用 constants 的預設）
     *
     * @return array{threshold:float, template:string}
     */
    public function globalSettings()
    {
        $defaults = config('constants.STATION.CREDIT_ALERT');

        return [
            'threshold' => $this->appSettingService->getFloat(
                AppSettingService::KEY_CREDIT_ALERT_THRESHOLD,
                (float) $defaults['THRESHOLD']
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
     * @param array   $settings 全站共用的設定
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

        /*
         * 待審核那則不帶補點訊息，所以**連查都不用查** —— 省掉今日匯率
         * 與繳款設定兩次查詢。這也讓那則完全不受匯率查不到的影響。
         */
        $topup = $target === self::TARGET_INTERNAL_PENDING ? [] : $this->topupFor($station);
        $topupText = Arr::get($topup, 'text');
        $text = $this->renderAlert($settings['template'], $station->name, $credits, $threshold);

        /*
         * 退到內部群組的兩種情況要的東西完全不一樣（需求方 2026-10-03 指定）：
         *
         *   待審核   → 說明與告警**併成一則**，不帶補點訊息也不帶圖。
         *              那則不轉傳給客戶，客服看完就去後台審核。
         *   沒設群組 → **拆成三則**：說明／告警／補點訊息＋圖。
         *              客服要把後兩則原封不動轉傳給客戶，併在一起的話
         *              他得先手動編輯掉開頭那段說明才能轉。
         */
        if ($target === self::TARGET_INTERNAL_PENDING) {
            $text = $this->internalPrefix($station, $target, $pendingTopups) . $text;

            if ($dryRun) {
                return $this->result($station, $threshold, false, $target, null, $text);
            }

            return $this->send($station, $threshold, $target, $text);
        }

        $imageUrl = Arr::get($topup, 'image_url');

        if ($target !== self::TARGET_STATION) {
            // 說明單獨一則，所以不併進 $text
            $prefix = $this->internalPrefix($station, $target, $pendingTopups);

            if ($dryRun) {
                return $this->result($station, $threshold, false, $target, null,
                    $prefix . $text, $topupText, $imageUrl);
            }

            return $this->send($station, $threshold, $target, $text, $topupText, $imageUrl, $prefix);
        }

        if ($dryRun) {
            return $this->result($station, $threshold, false, $target, null, $text, $topupText, $imageUrl);
        }

        return $this->send($station, $threshold, $target, $text, $topupText, $imageUrl);
    }

    /**
     * 實際送出告警並記錄時間
     *
     * @param Station     $station
     * @param float       $threshold
     * @param string      $target
     * @param string      $text      告警那則
     * @param string|null $topupText 補點訊息，當成獨立一則發
     * @param string|null $imageUrl  繳款圖，掛在補點訊息那則上
     * @param string|null $prefix    內部群組的開頭說明，獨立發一則（見下方）
     * @return array
     */
    private function send(Station $station, $threshold, $target, $text, $topupText = null, $imageUrl = null, $prefix = null)
    {
        $toStation = $target === self::TARGET_STATION;

        try {
            /*
             * 內部群組的開頭說明**獨立一則**。
             *
             * 客服要把後面那兩則（告警、補點訊息＋圖）直接轉傳給客戶 ——
             * 併在一起的話他得先手動編輯掉「這則沒有發給客戶」那段說明，
             * 轉傳就變成一件要動手的事（需求方 2026-10-03 指定）。
             *
             * 待審核那則不走這裡（它不轉傳，說明與告警併成一則就好）。
             */
            if (filled($prefix)) {
                $this->supportGroup->send($prefix, null, null, self::NOTICE_TYPE);
            }

            /*
             * 只有 TARGET_STATION 會送到客戶那邊，其餘（沒設群組的退路、
             * 有補點單待審核）一律進內部群組。
             */
            if ($toStation) {
                $this->sendToStation($station, $text);
            } else {
                $this->supportGroup->send($text, null, null, self::NOTICE_TYPE);
            }
        } catch (\Exception $e) {
            Log::error('站台餘點告警發送失敗', [
                'station_id' => $station->id,
                'station'    => $station->name,
                'target'     => $target,
                'has_prefix' => filled($prefix),
                'error'      => $e->getMessage(),
            ]);

            // 不寫 credit_alerted_at —— 沒送出去就不該被冷卻擋住，下一輪要能重試
            return $this->result($station, $threshold, false, $target, self::SKIP_SEND_FAILED, $text, $topupText, $imageUrl);
        }

        /*
         * 補點訊息是獨立的一則，**兩種目的地都發** —— 發客戶時是給他匯款
         * 資訊，發內部群組時是給客服轉傳用的那一則。
         *
         * 待審核時 $topupText 會是 null（連查都沒查），所以不會發。
         */
        $hasSecondMessage = filled($topupText);
        $topupSent = $hasSecondMessage
            ? $this->sendTopup($station, $topupText, $imageUrl, $toStation)
            : null;

        // 只是紀錄「最後一次告警的時間」—— 沒有冷卻期，不參與任何判斷
        $this->stationRepository->update($station, ['credit_alerted_at' => now()]);

        $context = [
            'station_id' => $station->id,
            'station'    => $station->name,
            'credits'    => (float) $station->credits,
            'threshold'  => $threshold,
            'target'     => $target,
        ];

        // 沒有第二則時不記這個欄位 —— 記 true 會讓人以為補點訊息也發出去了
        if ($hasSecondMessage) {
            $context['topup_sent'] = $topupSent;
        }

        Log::info('站台餘點告警已送出', $context);

        return $this->result($station, $threshold, true, $target, null, $text, $topupText, $imageUrl);
    }

    /**
     * 把補點訊息當獨立一則發出去
     *
     * 分開發而不是接在告警後面，是因為這兩段要做的事不一樣：
     * 告警是「你的點數快沒了」，補點訊息是「要補的話這樣匯款」。
     * 分開發，客戶要回頭找匯款資訊時不必在一長串告警裡翻，
     * 繳款圖也只掛在真正需要它的那一則上。
     *
     * 發內部群組時同樣獨立一則 —— 客服要原封不動轉傳這則給客戶。
     *
     * **失敗不會讓整則告警算失敗。** 告警已經出去了，這時回報失敗會讓
     * 下一輪重發一次一模一樣的告警 —— 對客戶是重複打擾。只記 log 讓客服
     * 看得到，缺的那段可以用站台列表的「發送補點通知」補。
     *
     * @param Station     $station
     * @param string|null $topupText 空的就不發（匯率未定或這個系統沒填公版）
     * @param string|null $imageUrl
     * @param bool        $toStation true=發客戶，false=發內部群組
     * @return bool 有沒有送出去（本來就沒有要送時回 true）
     */
    private function sendTopup(Station $station, $topupText, $imageUrl, $toStation = true)
    {
        if (blank($topupText)) {
            return true;
        }

        try {
            if ($toStation) {
                $this->sendToStation($station, $topupText, $imageUrl);
            } else {
                $this->sendTopupToInternal($topupText, $imageUrl);
            }

            return true;
        } catch (\Exception $e) {
            Log::error('補點訊息發送失敗（餘點告警那則已送出）', [
                'station_id' => $station->id,
                'station'    => $station->name,
                'error'      => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 補點訊息那則發到內部支援群組
     *
     * ⚠ Telegram 的圖說上限是 **1024 字，超過整則會失敗**（不是截斷）。
     * 公版是客服自己在繳款設定裡維護的，寫長一點很正常 —— 超過就退回
     * 「先發圖、再發文字」，寧可多一則也不要整則發不出去。
     * 與匯率報價的 `DailyRateService::sendAsk()` 同一個做法。
     *
     * @param string      $topupText
     * @param string|null $imageUrl
     * @return void
     */
    private function sendTopupToInternal($topupText, $imageUrl)
    {
        if (blank($imageUrl)) {
            $this->supportGroup->send($topupText, null, null, self::NOTICE_TYPE);

            return;
        }

        if (mb_strlen($topupText) <= self::CAPTION_MAX) {
            $this->supportGroup->sendPhoto($imageUrl, $topupText, self::NOTICE_TYPE);

            return;
        }

        Log::info('補點訊息超過圖說上限，改成先發圖再發文字', [
            'length' => mb_strlen($topupText),
            'limit'  => self::CAPTION_MAX,
        ]);

        $this->supportGroup->sendPhoto($imageUrl, null, self::NOTICE_TYPE);
        $this->supportGroup->send($topupText, null, null, self::NOTICE_TYPE);
    }

    /**
     * 併成一則時，補點訊息前面的分隔
     *
     * 只有內部群組那則會用到 —— 給客戶的是分開的兩則，不需要分隔。
     * 公版是客服在表單裡打的，不會（也不該要求他們）自己在開頭留空行，
     * 不加的話補點訊息會直接黏在告警的最後一行後面。
     *
     * @param string $topupText
     * @return string
     */
    private function topupSuffix($topupText)
    {
        return filled($topupText) ? "\n\n{$topupText}" : '';
    }

    /**
     * 發到站台自己的 Telegram 群組
     *
     * @param Station     $station
     * @param string      $text
     * @param string|null $imageUrl
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
     * 這個站台要發的補點訊息（文字 + 要附的圖）
     *
     * 查詢在這裡（走整輪快取）、組裝交給 `PaymentConfigService::buildTopupMessage()`
     * —— 客人問匯率時也是用那一支組，兩邊組出來的訊息才會一字不差。
     *
     * ⚠ **只有今天的匯率已經決定時才有內容。** 匯率還沒定（含凌晨到早上報價前、
     * 或報了還沒人回覆）就只發告警 —— 沒有匯率的補點訊息對客戶沒有意義。
     *
     * 回傳的文字**不帶前後分隔**：它自己就是一則訊息。併成一則發（內部群組）
     * 時才需要分隔，那是 topupSuffix() 的事。
     *
     * **圖只掛在補點訊息那則上，餘點告警那則不附。** 告警講的是「你的點數快沒了」，
     * 配一張付款地址圖客戶會看不懂那張圖在幹嘛；真要匯款的資訊在第二則，
     * 圖跟著它才有意義。所以沒有第二則時自然也沒有圖。
     *
     * @param Station $station
     * @return array{text: string, image_url: string|null}
     */
    private function topupFor(Station $station)
    {
        return $this->paymentConfigService->buildTopupMessage(
            $this->paymentConfigFor($station),
            $this->todayRate()
        );
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
     * ⚠ 查不到就當成「這個系統沒設定」，理由同 todayRate()：
     * 繳款設定只影響第二則補點訊息，不該連告警一起拖下水。
     * 失敗也寫進快取（存 null），整輪不會每站重試一次失敗的查詢。
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
            try {
                $this->paymentConfigs[$systemId] = $this->paymentConfigService
                    ->getActiveBySystem($systemId)
                    ->first();
            } catch (\Exception $e) {
                Log::error('查詢繳款設定失敗，這個系統的站台只發告警、不附補點訊息', [
                    'system_id' => $systemId,
                    'error'     => $e->getMessage(),
                ]);

                $this->paymentConfigs[$systemId] = null;
            }
        }

        return $this->paymentConfigs[$systemId];
    }

    /**
     * 今日匯率（整輪只查一次）
     *
     * ⚠ **查不到就當成「還沒決定」，絕不往上拋。**
     *
     * 匯率只決定「要不要附第二則補點訊息」。它查爆了（表不存在、連線中斷、
     * 欄位對不上…）如果讓例外往上走，整輪告警就跟著中斷 —— 變成
     * 「匯率有問題」害得「所有站台都收不到餘點告警」，而後者才是會讓
     * 客戶後台被停用的那件事。
     *
     * 失敗時把 `$todayRate` 設成 null（而不是留著 false）——
     * 這樣整輪不會每個站台都重試一次失敗的查詢。
     *
     * @return float|null
     */
    private function todayRate()
    {
        if ($this->todayRate === false) {
            try {
                $this->todayRate = $this->dailyRateService->todayRate();
            } catch (\Exception $e) {
                Log::error('查詢今日匯率失敗，本輪一律只發告警、不附補點訊息', [
                    'error' => $e->getMessage(),
                ]);

                $this->todayRate = null;
            }
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
        $pending = $target === self::TARGET_INTERNAL_PENDING;
        $key = $pending ? 'INTERNAL_PENDING_PREFIX' : 'INTERNAL_PREFIX';

        return strtr((string) config("constants.STATION.CREDIT_ALERT.{$key}"), [
            '{station}' => $station->name,
            '{count}'   => $pendingTopups,
            /*
             * 只有催審核那則 tag 主管 —— 「沒設群組」那則是請客服自己
             * 轉傳給客戶，不是要主管動手，點名他們只會變成雜訊。
             *
             * `INTERNAL_PREFIX` 的文案裡沒有 {mentions}，所以這裡給空字串
             * 也只是沒東西可換，不會留下殘跡。
             */
            '{mentions}' => $pending ? $this->managerMentions() : '',
        ]);
    }

    /**
     * 要 tag 的主管（含換行）
     *
     * 重用 `getManagersForMention()`（level <= 主管、狀態正常、有填 Telegram
     * 帳號）—— 跟匯率提醒、求助單升級 tag 的是同一批人，名單只有一份。
     *
     * ⚠ 沒人可 tag 時回**空字串而不是空白行** —— 文案裡的 `{mentions}` 緊貼
     * 著下一行，換行是跟著 mention 一起出現的，不然會多一行空的。
     *
     * @return string
     */
    private function managerMentions()
    {
        $line = TelegramUsernamePresenter::mentionLine(
            $this->userRepository->getManagersForMention()->pluck('telegram_username')
        );

        return filled($line) ? "{$line}\n" : '';
    }

    // ---------------------------------------------------------------
    //  輸出
    // ---------------------------------------------------------------

    /**
     * 組處理結果
     *
     * @param Station     $station
     * @param float       $threshold
     * @param bool        $alerted   是否真的送出了
     * @param string|null $target
     * @param string|null $reason    沒送出的原因
     * @param string|null $text      告警那則的內容（dry-run 預覽用）
     * @param string|null $topupText 補點訊息那則；內部群組已併進 $text，所以是 null
     * @param string|null $imageUrl  補點訊息那則會附的圖
     * @return array
     */
    private function result(Station $station, $threshold, $alerted, $target, $reason = null, $text = null, $topupText = null, $imageUrl = null)
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
            // 空跑要看得出第二則長什麼樣、會不會附圖，否則得真的發一次才知道
            'topup_text' => $topupText,
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
