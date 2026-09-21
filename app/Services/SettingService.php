<?php

namespace App\Services;

use App\Repositories\StationRepository;
use App\Services\AutoReply\ClaudeCodeMatcher;
use Illuminate\Support\Facades\Log;

/**
 * 全域設定頁的商業邏輯
 *
 * 單純的讀寫在 AppSettingService，這一層負責需要跨服務協調的事：
 * 換 token 要先驗證、測試發送要真的送一則、頁面資料要合併用量統計。
 *
 * 憑證驗證需要 ClaudeCodeMatcher，而 Matcher 自己要讀設定 ——
 * 所以協調放這裡，不能塞進 AppSettingService（會變成循環依賴）。
 */
class SettingService
{
    private $appSettingService;
    private $matcher;
    private $llmUsageService;
    private $supportService;
    private $stationRepository;

    public function __construct(
        AppSettingService $appSettingService,
        ClaudeCodeMatcher $matcher,
        LlmUsageService $llmUsageService,
        AutoReplySupportService $supportService,
        StationRepository $stationRepository
    ) {
        $this->appSettingService = $appSettingService;
        $this->matcher = $matcher;
        $this->llmUsageService = $llmUsageService;
        $this->supportService = $supportService;
        $this->stationRepository = $stationRepository;
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
            'support'  => $this->supportSection(),
            'usage'    => $this->llmUsageService->summary(),
            'options'   => [
                'models'  => config('auto_reply.models'),
                'systems' => $this->stationRepository->getActiveSystems()
                    ->map(function ($system) {
                        return [
                            'id'        => $system->id,
                            'name'      => $system->name,
                            'has_token' => filled($system->bot_token),
                        ];
                    })
                    ->values(),
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
     * 更新內部支援群組設定
     *
     * @param array    $params
     * @param int|null $userId
     * @return void
     */
    public function updateSupport($params, $userId = null)
    {
        $this->appSettingService->putMany([
            AppSettingService::KEY_SUPPORT_CHAT_ID       => isset($params['chat_id']) ? trim((string) $params['chat_id']) : null,
            AppSettingService::KEY_SUPPORT_SYSTEM_ID     => isset($params['system_id']) ? (string) $params['system_id'] : null,
            AppSettingService::KEY_REMIND_FIRST_MINUTES  => (string) $params['remind_first_minutes'],
            AppSettingService::KEY_REMIND_SECOND_MINUTES => (string) $params['remind_second_minutes'],
        ], $userId);
    }

    /**
     * 發一則測試訊息到內部支援群組
     *
     * 設定完 chat_id 與 bot 之後，用這個確認真的送得到 ——
     * 不然要等到第一張求助單才發現 bot 沒被拉進群組。
     *
     * @return bool
     */
    public function testSupport()
    {
        try {
            return $this->supportService->sendTestMessage();
        } catch (\Exception $e) {
            Log::error('支援群組測試訊息失敗', ['error' => $e->getMessage()]);

            return false;
        }
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

    /**
     * @return array
     */
    private function supportSection()
    {
        return [
            'chat_id'               => $this->appSettingService->get(AppSettingService::KEY_SUPPORT_CHAT_ID),
            'system_id'             => $this->appSettingService->getInt(AppSettingService::KEY_SUPPORT_SYSTEM_ID),
            'remind_first_minutes'  => $this->appSettingService->getInt(AppSettingService::KEY_REMIND_FIRST_MINUTES, 10),
            'remind_second_minutes' => $this->appSettingService->getInt(AppSettingService::KEY_REMIND_SECOND_MINUTES, 10),
        ];
    }

}
