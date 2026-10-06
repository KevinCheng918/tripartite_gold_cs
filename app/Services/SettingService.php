<?php

namespace App\Services;

use App\Services\AutoReply\ClaudeCodeMatcher;
use Illuminate\Support\Facades\Log;

/**
 * AI 引擎設定頁的商業邏輯
 *
 * 單純的讀寫在 AppSettingService，這一層負責需要跨服務協調的事：
 * 換 token 要先驗證、頁面資料要合併用量統計。
 *
 * ⚠ 內部支援群組與通知相關的設定 2026-10-06 搬到 `NotificationSettingService`。
 *
 * 憑證驗證需要 ClaudeCodeMatcher，而 Matcher 自己要讀設定 ——
 * 所以協調放這裡，不能塞進 AppSettingService（會變成循環依賴）。
 */
class SettingService
{
    private $appSettingService;
    private $matcher;
    private $llmUsageService;

    public function __construct(
        AppSettingService $appSettingService,
        ClaudeCodeMatcher $matcher,
        LlmUsageService $llmUsageService
    ) {
        $this->appSettingService = $appSettingService;
        $this->matcher = $matcher;
        $this->llmUsageService = $llmUsageService;
    }

    /**
     * 設定頁要顯示的全部資料
     *
     * 憑證一律只給遮罩 —— 明文不離開伺服器。
     *
     * @return array
     */
    public function forPage()
    {
        return [
            'claude'   => $this->claudeSection(),
            'fallback' => $this->fallbackSection(),
            'usage'    => $this->llmUsageService->summary(),
            // Bot 下拉已隨「內部支援群組」搬到通知設定頁，這裡只剩模型清單
            'options'   => [
                'models' => config('auto_reply.models'),
            ],
        ];
    }

    /**
     * 更新 Claude 主要設定
     *
     * token 有填才驗證並更新；留空代表沿用原本的。
     * **驗不過就整筆不存**，避免貼錯一次讓自動回覆停擺。
     *
     * @param array    $params
     * @param int|null $userId
     * @return bool 是否成功
     */
    public function updateClaude($params, $userId = null)
    {
        $token = isset($params['token']) ? trim((string) $params['token']) : '';

        if (filled($token)) {
            if (!$this->matcher->verifyCredential($token, false)) {
                return false;
            }

            $this->appSettingService->put(AppSettingService::KEY_CLAUDE_TOKEN, $token, $userId);
            $this->appSettingService->put(AppSettingService::KEY_CLAUDE_VERIFIED_AT, now()->toDateTimeString(), $userId);
        }

        $this->appSettingService->put(AppSettingService::KEY_CLAUDE_MODEL, $params['model'], $userId);

        return true;
    }

    /**
     * 更新備援設定
     *
     * @param array    $params
     * @param int|null $userId
     * @return bool
     */
    public function updateFallback($params, $userId = null)
    {
        $apiKey = isset($params['api_key']) ? trim((string) $params['api_key']) : '';

        if (filled($apiKey)) {
            if (!$this->matcher->verifyCredential($apiKey, true)) {
                return false;
            }

            $this->appSettingService->put(AppSettingService::KEY_FALLBACK_API_KEY, $apiKey, $userId);
        }

        $this->appSettingService->putMany([
            AppSettingService::KEY_FALLBACK_ENABLED     => $params['enabled'] ? '1' : '0',
            AppSettingService::KEY_FALLBACK_MODEL       => $params['model'],
            AppSettingService::KEY_FALLBACK_DAILY_LIMIT => (string) $params['daily_limit'],
        ], $userId);

        return true;
    }

    /**
     * @return array
     */
    private function claudeSection()
    {
        return [
            'has_token'    => $this->appSettingService->has(AppSettingService::KEY_CLAUDE_TOKEN),
            'token_masked' => $this->appSettingService->masked(AppSettingService::KEY_CLAUDE_TOKEN),
            'model'        => $this->appSettingService->get(AppSettingService::KEY_CLAUDE_MODEL, 'opus'),
            'verified_at'  => $this->appSettingService->get(AppSettingService::KEY_CLAUDE_VERIFIED_AT),
        ];
    }

    /**
     * @return array
     */
    private function fallbackSection()
    {
        $limit = $this->appSettingService->getInt(AppSettingService::KEY_FALLBACK_DAILY_LIMIT, 0);

        return [
            'enabled'       => $this->appSettingService->getBool(AppSettingService::KEY_FALLBACK_ENABLED),
            'has_api_key'   => $this->appSettingService->has(AppSettingService::KEY_FALLBACK_API_KEY),
            'api_key_masked' => $this->appSettingService->masked(AppSettingService::KEY_FALLBACK_API_KEY),
            'model'         => $this->appSettingService->get(AppSettingService::KEY_FALLBACK_MODEL, 'haiku'),
            'daily_limit'   => $limit,
            'used_today'    => $this->llmUsageService->countToday(config('constants.AUTO_REPLY.SOURCE.FALLBACK')),
        ];
    }

}
