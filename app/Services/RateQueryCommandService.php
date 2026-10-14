<?php

namespace App\Services;

use App\Presenters\NumberPresenter;
use App\Repositories\UserRepository;
use App\Services\AutoReply\RateQueryCommandParser;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 用 Telegram 指令看今天的匯率補點訊息
 *
 * ```
 * @機器人 今天匯率多少
 *   ↓
 * 【A 系統】
 * （客戶會收到的那則補點訊息，含匯率、要匯多少 USDT、匯款資訊、繳款圖）
 *
 * 【B 系統】
 * …
 * ```
 *
 * ⚠ **訊息不是這裡組的** —— 走 `PaymentConfigService::buildTopupMessage()`，
 * 跟餘點告警第二則、繳款設定頁的「測試發送」**同一支**。
 * 自己組一份的話，哪天公版的變數換了只會改到其中一邊，
 * 而這是會被複製貼給客戶的內容。
 *
 * ⚠ **有幾個系統就回幾則**（需求方 2026-10-14）。補點訊息跟著繳款設定走，
 * 不同系統的收款方式不一樣 —— 併成一則會讓人貼錯給客戶。
 *
 * ⚠ 匯率還沒決定就**不組補點訊息**（`buildTopupMessage()` 自己會擋）：
 * 沒有匯率的補點訊息對客戶沒有意義，附一個過期的昨日匯率更糟。
 */
class RateQueryCommandService
{
    /** @var string 做這件事需要的權限 */
    const PERMISSION = 'telegram_chat.rate_query';

    private $parser;
    private $dailyRateService;
    private $paymentConfigService;
    private $userRepository;
    private $supportGroup;

    public function __construct(
        RateQueryCommandParser $parser,
        DailyRateService $dailyRateService,
        PaymentConfigService $paymentConfigService,
        UserRepository $userRepository,
        SupportGroupService $supportGroup
    ) {
        $this->parser = $parser;
        // 今天定下來的對客報價（還沒決定時回 null）
        $this->dailyRateService = $dailyRateService;
        // 補點訊息的公版與組裝都在它身上
        $this->paymentConfigService = $paymentConfigService;
        $this->userRepository = $userRepository;
        $this->supportGroup = $supportGroup;
    }

    /**
     * 處理一句指令
     *
     * @param string   $text     已經去掉 @機器人
     * @param array    $from     Telegram 的 from（對到系統帳號驗權限用）
     * @param int|null $threadId
     * @return bool 有處理就回 true；false 代表「這不是在問匯率」，換下一個 handler
     */
    public function handle($text, array $from, $threadId)
    {
        $config = (array) config('constants.RATE_QUERY_COMMAND');
        $parsed = $this->parser->parse($text);

        if (blank($parsed)) {
            $this->reply((string) Arr::get($config, 'UNAVAILABLE_TEXT'), $threadId);

            return true;
        }

        // 不是在問匯率 —— 讓分派器換下一個
        if ((string) Arr::get($parsed, 'intent') !== RateQueryCommandParser::INTENT_RATE) {
            return false;
        }

        $denied = $this->permissionError($from, $config);

        if (filled($denied)) {
            $this->reply($denied, $threadId);

            return true;
        }

        $this->replyTopupMessages($threadId, $config);

        return true;
    }

    /**
     * 把每個系統的補點訊息發出去
     *
     * @param int|null $threadId
     * @param array    $config
     * @return void
     */
    private function replyTopupMessages($threadId, array $config)
    {
        $rate = $this->dailyRateService->todayRate();

        /*
         * ⚠ 匯率還沒決定時，**講清楚是「還沒決定」而不是「系統壞了」**。
         * 這是每天早上報價之前的正常狀態。
         */
        if (blank($rate)) {
            $this->reply((string) Arr::get($config, 'NO_RATE_TEXT'), $threadId);

            return;
        }

        $sent = 0;

        // 「啟用」的判斷留在查詢裡，跟 getActiveBySystem() 同一套，不在這裡比數字
        foreach ($this->paymentConfigService->getAllActive() as $paymentConfig) {
            $sent += $this->sendOne($paymentConfig, $rate, $threadId, $config) ? 1 : 0;
        }

        /*
         * 一則都沒發出去：有匯率，但沒有任何系統填了補點訊息公版。
         * 要講原因 —— 只回一句「沒有資料」會讓人以為匯率也沒設。
         */
        if ($sent < 1) {
            $this->reply(strtr((string) Arr::get($config, 'NO_TEMPLATE_TEXT'), [
                '{rate}' => $this->rateText($rate),
            ]), $threadId);
        }
    }

    /**
     * 發一個系統的那一則
     *
     * @param \App\Models\PaymentConfig $paymentConfig
     * @param float                     $rate
     * @param int|null                  $threadId
     * @param array                     $config
     * @return bool 有沒有真的發出去
     */
    private function sendOne($paymentConfig, $rate, $threadId, array $config)
    {
        $topup = (array) $this->paymentConfigService->buildTopupMessage($paymentConfig, $rate);
        $text = (string) Arr::get($topup, 'text');

        // 這個系統沒填補點訊息公版 —— 跳過，不要發一則空的
        if (blank($text)) {
            return false;
        }

        $caption = strtr((string) Arr::get($config, 'SYSTEM_PREFIX'), [
            '{system}' => $this->systemName($paymentConfig, $config),
        ]) . $text;

        $imageUrl = Arr::get($topup, 'image_url');

        /*
         * ⚠ 有圖就走 `sendPhotoToThread()`，讓文字當圖說一起送 ——
         * 分成兩則的話，群組裡會看到圖跟文字被別人的訊息插開。
         *
         * ⚠ Telegram 的圖說上限 1024 字，超過整則會失敗。超長時退回純文字，
         * 寧可少一張圖也不要整則發不出去。
         */
        $tooLong = mb_strlen($caption) > (int) Arr::get($config, 'CAPTION_MAX', 1024);

        $result = filled($imageUrl) && !$tooLong
            ? $this->supportGroup->sendPhotoToThread($imageUrl, $caption, $threadId)
            : $this->supportGroup->sendToThread($caption, $threadId);

        if (filled(Arr::get((array) $result, 'result'))) {
            return true;
        }

        Log::warning('指令查匯率：補點訊息送不出去', [
            'config_id' => $paymentConfig->id,
            'has_image' => filled($imageUrl),
            'too_long'  => $tooLong,
        ]);

        return false;
    }

    /**
     * 這筆繳款設定屬於哪個系統
     *
     * @param \App\Models\PaymentConfig $paymentConfig
     * @param array                     $config
     * @return string
     */
    private function systemName($paymentConfig, array $config)
    {
        $system = $paymentConfig->system;

        if (filled($system) && filled($system->name)) {
            return (string) $system->name;
        }

        return (string) Arr::get($config, 'UNKNOWN_SYSTEM');
    }

    /**
     * 匯率的顯示格式
     *
     * 去尾零（30.5 而不是 30.5000）—— 跟補點訊息與匯率報價一致，
     * 同一個數字在幾個地方要長一樣。
     *
     * @param float $rate
     * @return string
     */
    private function rateText($rate)
    {
        return NumberPresenter::trimZeros($rate, 4);
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
            Log::info('指令查匯率被權限擋下', ['user_id' => $user->id]);

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
