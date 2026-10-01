<?php

namespace App\Services;

use App\Models\DailyRate;
use App\Repositories\DailyRateRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 每日匯率報價
 *
 * 每天早上在內部支援群組報一次匯率，附上 MAX 的 4H 均價與上次報的價，
 * 由自己人「引用回覆」決定當日對客報價：回數字就用那個數字，回「好」就用建議值。
 *
 * 沒人回就每 30 分鐘提醒一次並 tag 主管，提醒到當天結束為止。
 *
 * ⚠ 沒有「清空」這件事 —— `daily_rate.date` 是唯一鍵，
 * 過了午夜查不到今天的那筆，自然就等於沒有匯率。舊資料一律留著，
 * 要回頭查「上個月某天報多少」靠的就是它。
 */
class DailyRateService
{
    /** @var array 回覆內容視為「採用建議值」的詞 */
    private const ACCEPT_WORDS = ['好', '好的', 'ok', 'OK', 'Ok', '可以', '就這樣', '同意', 'yes', 'Yes'];

    /** @var int Telegram 圖說的字數上限。超過整則會失敗，要改成圖文分開發 */
    private const CAPTION_MAX = 1024;

    private $rateRepository;
    private $usdtRateService;
    private $supportGroup;
    private $userRepository;
    private $appSettingService;
    private $screenshotService;

    public function __construct(
        DailyRateRepository $rateRepository,
        UsdtRateService $usdtRateService,
        SupportGroupService $supportGroup,
        UserRepository $userRepository,
        AppSettingService $appSettingService,
        ScreenshotService $screenshotService
    ) {
        $this->rateRepository = $rateRepository;
        $this->usdtRateService = $usdtRateService;
        $this->supportGroup = $supportGroup;
        $this->userRepository = $userRepository;
        $this->appSettingService = $appSettingService;
        $this->screenshotService = $screenshotService;
    }

    // ---------------------------------------------------------------
    //  建議值
    // ---------------------------------------------------------------

    /**
     * 由 4H 均價算出建議報價
     *
     * 規則：取到小數點後一位，但**零頭滿 .95 就報 .95**。
     *
     * 單純捨去到一位的話，32.9678 會變成 32.9 —— 中間那 0.05 是白送的。
     * 所以 .95 以上的保留成 .95：
     *
     * | 4H 均價 | 建議值 |
     * |---|---|
     * | 32.9678 | 32.95 |
     * | 32.96   | 32.95 |
     * | 32.95   | 32.95 |
     * | 32.9478 | 32.9  |
     * | 32.92   | 32.9  |
     * | 33.0    | 33.0  |
     *
     * ⚠ 全程用「分」（乘 100 的整數）運算，而且**乘完要先 round 掉浮點誤差再 floor**。
     *
     * 這不是理論上的潔癖，是會少報錢的：
     *
     *     32.3 * 100 = 3229.9999999999995   // PHP 的浮點數就是這樣
     *     floor(3229.99…) = 3229            // 少了 1 分
     *     floor(3229 / 10) / 10 = 32.2      // ← 報價變成 32.2，少報 0.1
     *
     * 在 25~40 這個匯率區間，直接 floor 會算錯的值有 16 個
     * （32.3、32.8、33.3、33.8、34.3…），全都是少報。
     * `floor(round($v * 100, 6))` 就沒事。
     *
     * @param float $avgRate 4H 均價
     * @return float 建議報價；均價 <= 0（抓不到）時回 0
     */
    public function suggestFrom($avgRate)
    {
        $avgRate = (float) $avgRate;

        if ($avgRate <= 0) {
            return 0.0;
        }

        $cents = (int) floor(round($avgRate * 100, 6));
        $integerPart = intdiv($cents, 100);
        $fractionCents = $cents % 100;

        if ($fractionCents >= 95) {
            return $integerPart + 0.95;
        }

        // 其餘無條件捨去到一位小數（捨去而不是四捨五入：寧可少報也不要報高）
        return floor($cents / 10) / 10;
    }

    // ---------------------------------------------------------------
    //  報價
    // ---------------------------------------------------------------

    /**
     * 送出今天的報價詢問
     *
     * @param bool $force 今天已經問過也重送一次
     * @return array 處理結果，給 Command 印出來
     */
    public function ask($force = false)
    {
        $today = now()->toDateString();
        $existing = $this->rateRepository->findByDate($today);

        if (filled($existing) && filled($existing->ask_message_id) && !$force) {
            return ['sent' => false, 'reason' => 'already_asked', 'rate' => $existing->rate];
        }

        if (!$this->supportGroup->isConfigured()) {
            Log::warning('未設定內部支援群組，今日匯率沒有報出去', ['date' => $today]);

            return ['sent' => false, 'reason' => 'no_support_group'];
        }

        $market = $this->usdtRateService->getRateWithHistory();
        $avgRate = (float) Arr::get($market, 'avg_rate', 0);
        $suggested = $this->suggestFrom($avgRate);
        $previous = $this->rateRepository->latestDecided($today);

        $record = $this->rateRepository->firstOrCreateByDate($today);
        $this->rateRepository->update($record, [
            'reference_rate' => $avgRate > 0 ? $avgRate : null,
            'suggested_rate' => $suggested > 0 ? $suggested : null,
            'yesterday_rate' => filled($previous) ? $previous->rate : null,
            'asked_at'       => now(),
        ]);

        $text = $this->buildAskText($record, $previous);
        $shot = $this->captureChart();
        $result = $this->sendAsk($text, $shot);

        // 截圖只是為了送出去，送完就沒用了
        $this->screenshotService->forget($shot);

        $messageId = Arr::get($result, 'result.message_id');

        if (blank($messageId)) {
            Log::error('今日匯率報價送出失敗', ['date' => $today, 'response' => $result]);

            return ['sent' => false, 'reason' => 'send_failed'];
        }

        $this->rateRepository->update($record, ['ask_message_id' => (int) $messageId]);

        Log::info('今日匯率已報出', [
            'date'       => $today,
            'avg_rate'   => $avgRate,
            'suggested'  => $suggested,
            'with_chart' => filled($shot),
        ]);

        return [
            'sent'       => true,
            'suggested'  => $suggested,
            'reference'  => $avgRate,
            'previous'   => filled($previous) ? $previous->rate : null,
            'text'       => $text,
            'with_chart' => filled($shot),
        ];
    }

    /**
     * 截 MAX 的走勢圖
     *
     * 截不到就回 null —— 報價不能因為截圖失敗就整則發不出去，
     * 這是整個截圖功能的前提。
     *
     * @return string|null public disk 的相對路徑
     */
    private function captureChart()
    {
        $config = (array) config('constants.DAILY_RATE.SCREENSHOT');

        if (Arr::get($config, 'ENABLED') !== true) {
            return null;
        }

        return $this->screenshotService->capture(Arr::get($config, 'URL'), [
            'width'  => Arr::get($config, 'WIDTH'),
            'height' => Arr::get($config, 'HEIGHT'),
            // 只要 K 線圖那塊，不要右邊的成交明細與下單面板
            'selector'   => Arr::get($config, 'SELECTOR'),
            // selector 選不到時的備案
            'crop'       => Arr::get($config, 'CROP'),
            'user_agent' => Arr::get($config, 'USER_AGENT'),
            'prefix'     => 'rate',
        ]);
    }

    /**
     * 送出報價訊息（有圖就附圖）
     *
     * ⚠ Telegram 的圖說上限是 **1024 字**，超過整則會失敗。
     * 公版是客服自己維護的，寫長一點很正常 —— 超過就退回「先發圖、再發文字」，
     * 並且回傳**文字那則**的結果：引用回覆要對應的是文字訊息。
     *
     * @param string      $text
     * @param string|null $shot public disk 的相對路徑
     * @return array|null
     */
    private function sendAsk($text, $shot)
    {
        if (blank($shot)) {
            return $this->supportGroup->send($text);
        }

        $photoUrl = asset('storage/' . $shot);

        if (mb_strlen($text) <= self::CAPTION_MAX) {
            return $this->supportGroup->sendPhoto($photoUrl, $text);
        }

        $this->supportGroup->sendPhoto($photoUrl);

        return $this->supportGroup->send($text);
    }

    // ---------------------------------------------------------------
    //  回覆
    // ---------------------------------------------------------------

    /**
     * webhook 的入口：這則內部群組訊息是不是在回覆匯率報價
     *
     * 回 `false` 代表「不干我的事」，呼叫端會繼續往求助單那條路走 ——
     * 兩個功能都靠「引用回覆」運作，所以必須先確認引用的是不是匯率那則訊息。
     *
     * @param array $payload Telegram Update
     * @return bool 有沒有被這裡處理掉
     */
    public function handleSupportReply($payload)
    {
        $message = (array) Arr::get($payload, 'message', []);
        $quotedId = Arr::get($message, 'reply_to_message.message_id');
        $text = trim((string) Arr::get($message, 'text', ''));

        if (blank($quotedId) || blank($text)) {
            return false;
        }

        $record = $this->rateRepository->findByAskMessageId($quotedId);

        // 引用的不是匯率報價訊息 —— 交給別人處理
        if (blank($record)) {
            return false;
        }

        $from = (array) Arr::get($message, 'from', []);
        $user = $this->userRepository->findByTelegramUsername(Arr::get($from, 'username'));

        $this->handleReply(
            $quotedId,
            $text,
            filled($user) ? $user->id : null,
            $this->senderName($from, $user)
        );

        return true;
    }

    /**
     * 回覆者的顯示名稱
     *
     * 優先用後台的暱稱（大家認得），對不到帳號才退回 Telegram 那邊的名字。
     *
     * @param array      $from Telegram 的 message.from
     * @param mixed|null $user 對應到的後台帳號
     * @return string
     */
    private function senderName($from, $user)
    {
        if (filled($user)) {
            return (string) $user->nickname;
        }

        $username = Arr::get($from, 'username');

        if (filled($username)) {
            return "@{$username}";
        }

        $name = trim(Arr::get($from, 'first_name', '') . ' ' . Arr::get($from, 'last_name', ''));

        return filled($name) ? $name : '同仁';
    }

    /**
     * 自己人引用回覆報價訊息
     *
     * @param int         $quotedMessageId 被引用的 message_id
     * @param string      $text            回覆內容
     * @param int|null    $userId          回覆者（對得到後台帳號才有）
     * @param string|null $nickname        回覆者暱稱，純粹用在確認訊息裡
     * @return bool 是否真的更新了匯率
     */
    public function handleReply($quotedMessageId, $text, $userId = null, $nickname = null)
    {
        $record = $this->rateRepository->findByAskMessageId($quotedMessageId);

        if (blank($record)) {
            return false;
        }

        $rate = $this->parseRate($text, $record);

        if (blank($rate)) {
            $this->supportGroup->send(
                (string) config('constants.DAILY_RATE.REPLY_UNPARSED'),
                null,
                $quotedMessageId
            );

            return false;
        }

        $previous = $record->rate;

        $this->rateRepository->update($record, [
            'rate'       => $rate,
            'replied_by' => $userId,
            'replied_at' => now(),
        ]);

        $this->supportGroup->send(
            $this->buildConfirmText($rate, $previous, $nickname),
            null,
            $quotedMessageId
        );

        Log::info('今日匯率已決定', [
            'date'     => $record->date->toDateString(),
            'rate'     => $rate,
            'previous' => $previous,
            'user_id'  => $userId,
        ]);

        return true;
    }

    /**
     * 解析回覆內容
     *
     * 回數字就用那個數字；回「好」這類詞就用建議值。
     *
     * @param string    $text
     * @param DailyRate $record
     * @return float|null 看不懂時回 null
     */
    private function parseRate($text, DailyRate $record)
    {
        $clean = trim((string) $text);

        if (blank($clean)) {
            return null;
        }

        // 純數字（允許小數、允許前後有空白）
        if (preg_match('/^\d+(\.\d+)?$/', $clean)) {
            $value = (float) $clean;

            return $value > 0 ? $value : null;
        }

        if (in_array(mb_strtolower($clean), array_map('mb_strtolower', self::ACCEPT_WORDS), true)) {
            // 建議值是報價當下算好存起來的，不是現在重算 ——
            // 訊息上寫的數字跟實際採用的必須是同一個
            return filled($record->suggested_rate) && $record->suggested_rate > 0
                ? (float) $record->suggested_rate
                : null;
        }

        return null;
    }

    // ---------------------------------------------------------------
    //  提醒
    // ---------------------------------------------------------------

    /**
     * 提醒還沒決定匯率
     *
     * @return array
     */
    public function remind()
    {
        $today = now()->toDateString();
        $record = $this->rateRepository->findUndecided($today);

        if (blank($record)) {
            return ['sent' => false, 'reason' => 'nothing_to_remind'];
        }

        if (!$this->supportGroup->isConfigured()) {
            return ['sent' => false, 'reason' => 'no_support_group'];
        }

        $mentions = $this->buildMentions();

        $this->supportGroup->send(
            $this->buildRemindText($mentions, $record),
            null,
            $record->ask_message_id
        );

        $this->rateRepository->update($record, [
            'remind_count'     => $record->remind_count + 1,
            'last_reminded_at' => now(),
        ]);

        return ['sent' => true, 'count' => $record->remind_count + 1];
    }

    /**
     * 要 tag 的人：主管與老闆
     *
     * 重用求助單升級提醒的那份名單（level <= 主管、且有填 Telegram 帳號）。
     *
     * @return array
     */
    private function buildMentions()
    {
        $mentions = [];

        foreach ($this->userRepository->getManagersForMention() as $user) {
            $mentions[] = '@' . ltrim($user->telegram_username, '@');
        }

        return $mentions;
    }

    // ---------------------------------------------------------------
    //  對客
    // ---------------------------------------------------------------

    /**
     * 今天的對客報價
     *
     * @return float|null 還沒定下來時回 null
     */
    public function todayRate()
    {
        $record = $this->rateRepository->findByDate(now()->toDateString());

        return filled($record) && $record->isDecided() ? (float) $record->rate : null;
    }

    /**
     * 客人問匯率時要回什麼
     *
     * 匯率還沒定下來（含凌晨到早上報價前那段）就請客人稍候 ——
     * 這比回一個過期的昨日匯率安全。
     *
     * @return string
     */
    public function customerAnswer()
    {
        $rate = $this->todayRate();

        if (blank($rate)) {
            return (string) config('constants.DAILY_RATE.CUSTOMER_PENDING');
        }

        return strtr((string) config('constants.DAILY_RATE.CUSTOMER_ANSWER'), [
            '{rate}' => $this->format($rate),
        ]);
    }

    // ---------------------------------------------------------------
    //  後台
    // ---------------------------------------------------------------

    /**
     * 歷史報價（新的在前）
     *
     * @param int $perPage
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function history($perPage = 30)
    {
        return $this->rateRepository->paginate($perPage);
    }

    /**
     * 後台手動設定某一天的匯率
     *
     * 報錯了要能改，也可能某天 Telegram 那邊沒人回、直接在後台補。
     * 那天還沒有紀錄就建一筆（例如補登過去某天）。
     *
     * @param string   $date Y-m-d
     * @param float    $rate
     * @param int|null $userId 操作者
     * @return \App\Models\DailyRate
     */
    public function setRate($date, $rate, $userId = null)
    {
        $record = $this->rateRepository->firstOrCreateByDate($date);
        $previous = $record->rate;

        $this->rateRepository->update($record, [
            'rate'       => (float) $rate,
            'replied_by' => $userId,
            'replied_at' => now(),
        ]);

        Log::info('後台手動設定匯率', [
            'date'     => $date,
            'rate'     => (float) $rate,
            'previous' => $previous,
            'user_id'  => $userId,
        ]);

        return $record;
    }

    /**
     * 截圖測試：截一張發到內部群組
     *
     * 給後台按鈕用。環境裝好 Chrome 之後先按這個，確認截得到、
     * 而且截到的是想要的畫面，再等明天早上的自動報價。
     *
     * @return array 結果說明，給介面顯示
     */
    public function testScreenshot()
    {
        if (!$this->supportGroup->isConfigured()) {
            return ['ok' => false, 'reason' => 'no_support_group'];
        }

        if (!$this->screenshotService->isAvailable()) {
            return ['ok' => false, 'reason' => 'no_chrome'];
        }

        $shot = $this->captureChart();

        if (blank($shot)) {
            return ['ok' => false, 'reason' => 'capture_failed'];
        }

        $caption = strtr((string) config('constants.DAILY_RATE.SCREENSHOT_TEST_CAPTION'), [
            '{time}' => now()->format('Y-m-d H:i'),
            '{url}'  => (string) config('constants.DAILY_RATE.SCREENSHOT.URL'),
        ]);

        $result = $this->supportGroup->sendPhoto(asset('storage/' . $shot), $caption);
        $this->screenshotService->forget($shot);

        if (blank(Arr::get($result, 'result'))) {
            Log::error('截圖測試發送失敗', ['response' => $result]);

            return ['ok' => false, 'reason' => 'send_failed'];
        }

        return ['ok' => true, 'binary' => $this->screenshotService->binaryPath()];
    }

    /**
     * 這台機器能不能截圖（給介面顯示狀態用）
     *
     * 一併回傳主機名稱與 CPU 架構 —— 開發機跑的是 laradock 容器、
     * 正式機是另一台，光看「沒有可用的 Chrome」會分不清是哪一台沒有。
     * arm64 本來就裝不了官方 Chrome，看到架構就知道不用再試。
     *
     * @return array{available: bool, binary: string|null, host: string, arch: string}
     */
    public function screenshotStatus()
    {
        return [
            'available' => $this->screenshotService->isAvailable(),
            'binary'    => $this->screenshotService->binaryPath(),
            'host'      => gethostname() ?: '-',
            'arch'      => php_uname('m'),
        ];
    }

    /**
     * 報價公版（後台沒設過就回 constants 的預設）
     *
     * @return string
     */
    public function askTemplate()
    {
        $template = $this->appSettingService->get(AppSettingService::KEY_DAILY_RATE_TEMPLATE);

        return filled($template)
            ? $template
            : (string) config('constants.DAILY_RATE.ASK_TEMPLATE');
    }

    /**
     * 存報價公版
     *
     * @param string   $template
     * @param int|null $userId
     * @return void
     */
    public function saveAskTemplate($template, $userId = null)
    {
        $this->appSettingService->put(AppSettingService::KEY_DAILY_RATE_TEMPLATE, $template, $userId);
    }

    /**
     * 公版套用變數後的樣子（後台預覽用）
     *
     * 用今天那筆的實際數字；今天還沒報價就用市場現值試算，
     * 讓人看得出「明天早上送出去會長怎樣」。
     *
     * @param string $template
     * @return string
     */
    public function previewAskText($template)
    {
        $today = now()->toDateString();
        $record = $this->rateRepository->findByDate($today);

        if (blank($record) || blank($record->suggested_rate)) {
            $record = $this->buildPreviewRecord($today);
        }

        return $this->renderAskText($template, $record, $this->rateRepository->latestDecided($today));
    }

    /**
     * 預覽用的暫時資料（不存檔）
     *
     * 今天還沒報價時用市場現值試算，讓人看得出明天早上送出去會長怎樣。
     *
     * cast 在未存檔的 model 上就會生效（`$record->date` 讀出來是 Carbon），
     * 所以建構完不用再補一次型別轉換。
     *
     * @param string $date Y-m-d
     * @return DailyRate
     */
    private function buildPreviewRecord($date)
    {
        $avgRate = (float) Arr::get($this->usdtRateService->getRateWithHistory(), 'avg_rate', 0);
        $suggested = $this->suggestFrom($avgRate);

        return new DailyRate([
            'date' => $date,
            // 抓不到行情時 suggestFrom 回 0，存成 null 讓文案顯示「取不到」
            'reference_rate' => $avgRate > 0 ? $avgRate : null,
            'suggested_rate' => $suggested > 0 ? $suggested : null,
        ]);
    }

    // ---------------------------------------------------------------
    //  文案
    // ---------------------------------------------------------------

    /**
     * 報價詢問的內容
     *
     * 公版可以在後台改，沒設定過就用 constants 的預設。
     *
     * @param DailyRate      $record
     * @param DailyRate|null $previous 上次已決定的那筆
     * @return string
     */
    private function buildAskText(DailyRate $record, $previous)
    {
        return $this->renderAskText($this->askTemplate(), $record, $previous);
    }

    /**
     * 把公版套上變數
     *
     * 跟 buildAskText 分開是為了讓後台預覽走同一支 ——
     * 預覽看到的排版必須就是實際送出去的排版。
     *
     * @param string         $template
     * @param DailyRate      $record
     * @param DailyRate|null $previous
     * @return string
     */
    private function renderAskText($template, DailyRate $record, $previous)
    {
        /*
         * 上次報價的「金額」與「日期」都從 $previous 取，不要一個讀
         * $record->yesterday_rate、一個讀 $previous->date ——
         * 那兩個在實際發送時剛好一致（ask() 前一行才把快照寫進去），
         * 但預覽沒有那一步，就會變成「上次報價（2026/09/28）：無」，
         * 日期有、金額卻空著。
         *
         * yesterday_rate 欄位仍然留著：它是報價當下的快照，供事後查歷史，
         * 只是渲染訊息時不依賴它。
         */
        /*
         * 完全沒有上次報價時（第一次啟用、或之前都沒人回覆），
         * 把提到它的那幾行整行拿掉 —— 留著會變成
         * 「上次報價（—）：無」這種半截訊息，看的人會以為壞了。
         *
         * 公版是客服自己維護的，不該要求他們去處理「沒有上次報價」這種情況。
         */
        if (blank($previous)) {
            $template = preg_replace('/^[^\n]*\{yesterday[^\n]*\n?/m', '', $template);
        }

        return strtr($template, [
            '{date}'      => $record->date->format('Y/m/d'),
            '{reference}' => filled($record->reference_rate) ? $this->format($record->reference_rate) : '取不到',
            '{suggested}' => filled($record->suggested_rate) ? $this->format($record->suggested_rate) : '取不到',
            // 完整年月日。上次報價未必是昨天（週末沒人回、或隔了幾天），
            // 只給月日的話看的人得自己想是哪一年、差了多久
            '{yesterday}'      => filled($previous) ? $this->format($previous->rate) : '',
            '{yesterday_date}' => filled($previous) ? $previous->date->format('Y/m/d') : '',
        ]);
    }

    /**
     * 決定之後的確認訊息
     *
     * 覆寫時要講明原本是多少 —— 報錯了要能改，但不能默默改掉。
     *
     * @param float       $rate
     * @param float|null  $previous 這筆原本的值
     * @param string|null $nickname
     * @return string
     */
    private function buildConfirmText($rate, $previous, $nickname)
    {
        $key = filled($previous) ? 'CONFIRM_CHANGED' : 'CONFIRM';

        return strtr((string) config("constants.DAILY_RATE.{$key}"), [
            '{rate}'     => $this->format($rate),
            '{previous}' => filled($previous) ? $this->format($previous) : '',
            '{user}'     => filled($nickname) ? $nickname : '',
        ]);
    }

    /**
     * 提醒內容
     *
     * @param array     $mentions
     * @param DailyRate $record
     * @return string
     */
    private function buildRemindText($mentions, DailyRate $record)
    {
        return strtr((string) config('constants.DAILY_RATE.REMIND'), [
            '{mentions}'  => implode(' ', $mentions),
            '{suggested}' => filled($record->suggested_rate) ? $this->format($record->suggested_rate) : '取不到',
            '{count}'     => $record->remind_count + 1,
        ]);
    }

    /**
     * 匯率的顯示格式
     *
     * 去掉無意義的尾數零：32.9500 顯示成 32.95、33.0000 顯示成 33。
     *
     * @param float $rate
     * @return string
     */
    private function format($rate)
    {
        return rtrim(rtrim(number_format((float) $rate, 4, '.', ''), '0'), '.');
    }
}
