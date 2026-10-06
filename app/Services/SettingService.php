<?php

namespace App\Services;

use App\Repositories\StationRepository;
use App\Repositories\UserRepository;
use App\Services\AutoReply\ClaudeCodeMatcher;
use Illuminate\Support\Arr;
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
    private $userRepository;
    private $staffDm;

    public function __construct(
        AppSettingService $appSettingService,
        ClaudeCodeMatcher $matcher,
        LlmUsageService $llmUsageService,
        AutoReplySupportService $supportService,
        StationRepository $stationRepository,
        UserRepository $userRepository,
        StaffDmService $staffDm
    ) {
        $this->appSettingService = $appSettingService;
        $this->matcher = $matcher;
        $this->llmUsageService = $llmUsageService;
        $this->supportService = $supportService;
        $this->stationRepository = $stationRepository;
        $this->userRepository = $userRepository;
        // 統計收件人下拉要標出「還沒私訊過機器人」的人，判斷在這支
        $this->staffDm = $staffDm;
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
                // 統計收件人候選，帶綁定狀態 —— 選了沒綁定的人會默默發不出去
                'dm_candidates' => $this->dmCandidates(),
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
            AppSettingService::KEY_SUPPORT_CHAT_ID        => trim((string) Arr::get($params, 'chat_id')),
            AppSettingService::KEY_SUPPORT_SYSTEM_ID      => (string) Arr::get($params, 'system_id'),
            AppSettingService::KEY_REMIND_FIRST_MINUTES   => (string) Arr::get($params, 'remind_first_minutes'),
            AppSettingService::KEY_REMIND_INTERVAL_MINUTES => (string) Arr::get($params, 'remind_interval_minutes'),
            AppSettingService::KEY_REMIND_MAX_COUNT       => (string) Arr::get($params, 'remind_max_count'),
            // 全部取消勾選時存 null —— 轉換與清空的規則都在 idListValue() 裡
            AppSettingService::KEY_REMIND_REPORT_MANAGER  => $this->appSettingService
                ->idListValue(Arr::get($params, 'remind_report_user_ids', [])),
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
     * 可以當統計收件人的帳號（下拉用）
     *
     * ⚠ `dm_ready` 一定要帶：沒私訊過機器人的人收不到任何東西，
     * 要在選之前就看得出來，不然要等到隔天沒收到才發現。
     *
     * @return array
     */
    private function dmCandidates()
    {
        $candidates = [];

        foreach ($this->userRepository->getDmCandidates() as $user) {
            $candidates[] = [
                'id'       => (int) $user->id,
                'nickname' => (string) $user->nickname,
                'dm_ready' => $this->staffDm->canDm($user),
            ];
        }

        return $candidates;
    }

    /**
     * @return array
     */
    private function supportSection()
    {
        return [
            'chat_id'                 => $this->appSettingService->get(AppSettingService::KEY_SUPPORT_CHAT_ID),
            'system_id'               => $this->appSettingService->getInt(AppSettingService::KEY_SUPPORT_SYSTEM_ID),
            'remind_first_minutes'    => $this->appSettingService->getInt(AppSettingService::KEY_REMIND_FIRST_MINUTES, 10),
            'remind_interval_minutes' => $this->appSettingService->getInt(AppSettingService::KEY_REMIND_INTERVAL_MINUTES, 10),
            'remind_max_count'        => $this->appSettingService->getInt(
                AppSettingService::KEY_REMIND_MAX_COUNT,
                (int) config('constants.AUTO_REPLY.REMIND.MAX_COUNT')
            ),
            'remind_report_user_ids'  => $this->appSettingService->getIntList(AppSettingService::KEY_REMIND_REPORT_MANAGER),
            'escalate_at'             => (int) config('constants.AUTO_REPLY.REMIND.ESCALATE_AT'),
            'report_at'               => (string) config('constants.AUTO_REPLY.REMIND.REPORT_AT'),
        ];
    }

}
