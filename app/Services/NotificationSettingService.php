<?php

namespace App\Services;

use App\Repositories\StationRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 通知設定（通訊管理 → 通知設定）
 *
 * 2026-10-06 把三組原本散在兩頁的設定收成一頁三個分頁：
 *
 * | 分頁 | 原本在哪 | 內容 |
 * |---|---|---|
 * | 內部支援群組 | 全域設定頁 | chat_id、用哪個 Bot、求助單提醒間隔／上限、統計收件人 |
 * | 話題分流 | 新增 | 話題清單，逐話題勾要收哪些通知 |
 * | 班表通知 | 班表通知頁（已移除） | 完整班表的收件人 |
 *
 * 全域設定頁只留 Claude 憑證、備援 API、用量 —— 那些跟「通知發到哪」無關。
 */
class NotificationSettingService
{
    private $appSettingService;
    private $stationRepository;
    private $userRepository;
    private $staffDm;
    private $supportGroup;
    private $supportService;
    private $shiftNotice;

    public function __construct(
        AppSettingService $appSettingService,
        StationRepository $stationRepository,
        \App\Repositories\UserRepository $userRepository,
        StaffDmService $staffDm,
        SupportGroupService $supportGroup,
        AutoReplySupportService $supportService,
        ShiftNoticeService $shiftNotice
    ) {
        $this->appSettingService = $appSettingService;
        $this->stationRepository = $stationRepository;
        $this->userRepository = $userRepository;
        $this->staffDm = $staffDm;
        $this->supportGroup = $supportGroup;
        $this->supportService = $supportService;
        $this->shiftNotice = $shiftNotice;
    }

    /**
     * 整頁要的資料
     *
     * 一次送出三個分頁的資料 —— 切換分頁不該再發 ajax，使用者會看到空白一拍。
     *
     * @return array
     */
    public function forPage()
    {
        return [
            'support' => $this->supportSection(),
            'topics'  => $this->topicSection(),
            'shift'   => $this->shiftNotice->forPage(),
            'options' => [
                'systems'       => $this->systemOptions(),
                'dm_candidates' => $this->dmCandidates(),
                'notice_types'  => $this->noticeTypeOptions(),
            ],
        ];
    }

    // ---------------------------------------------------------------
    //  內部支援群組
    // ---------------------------------------------------------------

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

    /**
     * 存內部支援群組
     *
     * @param array    $params
     * @param int|null $userId
     * @return void
     */
    public function updateSupport($params, $userId = null)
    {
        $this->appSettingService->putMany([
            AppSettingService::KEY_SUPPORT_CHAT_ID         => trim((string) Arr::get($params, 'chat_id')),
            AppSettingService::KEY_SUPPORT_SYSTEM_ID       => (string) Arr::get($params, 'system_id'),
            AppSettingService::KEY_REMIND_FIRST_MINUTES    => (string) Arr::get($params, 'remind_first_minutes'),
            AppSettingService::KEY_REMIND_INTERVAL_MINUTES => (string) Arr::get($params, 'remind_interval_minutes'),
            AppSettingService::KEY_REMIND_MAX_COUNT        => (string) Arr::get($params, 'remind_max_count'),
            // 全部取消勾選時存 null —— 轉換與清空的規則都在 idListValue() 裡
            AppSettingService::KEY_REMIND_REPORT_MANAGER   => $this->appSettingService
                ->idListValue(Arr::get($params, 'remind_report_user_ids', [])),
        ], $userId);
    }

    /**
     * 發一則測試訊息到支援群組
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

    // ---------------------------------------------------------------
    //  話題分流
    // ---------------------------------------------------------------

    /**
     * @return array
     */
    private function topicSection()
    {
        $topics = [];

        foreach ($this->supportGroup->topics() as $topic) {
            $topics[] = [
                'name'      => (string) Arr::get($topic, 'name', ''),
                'thread_id' => (int) Arr::get($topic, 'thread_id', 0),
                'types'     => array_values((array) Arr::get($topic, 'types', [])),
            ];
        }

        return [
            'list'        => $topics,
            'id_command'  => (string) config('constants.SUPPORT_TOPIC.ID_COMMAND'),
            'max_topics'  => (int) config('constants.SUPPORT_TOPIC.MAX_TOPICS'),
        ];
    }

    /**
     * 存話題清單
     *
     * ⚠ 只存名稱、id、勾選的類型三個欄位 —— 前端送什麼就存什麼的話，
     * 多餘的 key 會一路寫進資料庫，之後讀的人分不出哪些是真的設定。
     *
     * @param array    $params
     * @param int|null $userId
     * @return void
     */
    public function updateTopics($params, $userId = null)
    {
        $validTypes = array_keys((array) config('constants.SUPPORT_TOPIC.TYPES'));
        $topics = [];

        foreach ((array) Arr::get($params, 'topics', []) as $topic) {
            $threadId = (int) Arr::get($topic, 'thread_id', 0);

            if ($threadId < 1) {
                continue;
            }

            $topics[] = [
                'name'      => trim((string) Arr::get($topic, 'name', '')),
                'thread_id' => $threadId,
                // 擋掉不存在的類型：config 改過名之後，舊資料不該繼續被存回去
                'types'     => array_values(array_intersect(
                    (array) Arr::get($topic, 'types', []),
                    $validTypes
                )),
            ];
        }

        $this->appSettingService->put(
            AppSettingService::KEY_SUPPORT_TOPICS,
            $this->appSettingService->jsonValue($topics),
            $userId
        );
    }

    /**
     * 逐話題各發一則測試
     *
     * ⚠ **一定要每個話題各發一則**：話題 id 填錯 Telegram 會整則拒收，
     * 只測一個等於沒測到其他的 —— 那類通知會從此默默消失。
     *
     * 主區也發一則：沒被任何話題勾到的通知會落在那裡，使用者該看得到。
     *
     * @return array sent / failed
     */
    public function testTopics()
    {
        if (!$this->supportGroup->isConfigured()) {
            return ['sent' => 0, 'failed' => []];
        }

        $sent = 0;
        $failed = [];

        // 主區那則
        if ($this->sendTest((string) config('constants.SUPPORT_TOPIC.TEST_GENERAL'), null)) {
            $sent++;
        } else {
            $failed[] = (string) trans('notification.topic_general');
        }

        foreach ($this->supportGroup->topics() as $topic) {
            $threadId = (int) Arr::get($topic, 'thread_id', 0);
            $name = (string) Arr::get($topic, 'name', '');
            $label = filled($name) ? $name : "#{$threadId}";

            if ($threadId < 1) {
                continue;
            }

            $text = strtr((string) config('constants.SUPPORT_TOPIC.TEST_TEXT'), ['{name}' => $label]);

            if ($this->sendTest($text, $threadId)) {
                $sent++;

                continue;
            }

            $failed[] = $label;
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * 發一則測試到指定話題
     *
     * @param string   $text
     * @param int|null $threadId
     * @return bool
     */
    private function sendTest($text, $threadId)
    {
        try {
            $result = $this->supportGroup->sendToThread($text, $threadId);

            return filled(Arr::get((array) $result, 'result.message_id'));
        } catch (\Exception $e) {
            Log::error('話題測試訊息失敗', ['thread_id' => $threadId, 'error' => $e->getMessage()]);

            return false;
        }
    }

    // ---------------------------------------------------------------
    //  班表通知
    // ---------------------------------------------------------------

    /**
     * 存班表通知
     *
     * @param array    $params
     * @param int|null $userId
     * @return void
     */
    public function updateShift($params, $userId = null)
    {
        $this->shiftNotice->updateSetting((array) $params, $userId);
    }

    /**
     * 班表通知的測試發送
     *
     * @return array
     */
    public function testShift()
    {
        return $this->shiftNotice->test();
    }

    // ---------------------------------------------------------------
    //  下拉／清單選項
    // ---------------------------------------------------------------

    /**
     * 可以當支援群組 Bot 的系統
     *
     * @return array
     */
    private function systemOptions()
    {
        return $this->stationRepository->getActiveSystems()
            ->map(function ($system) {
                return [
                    'id'        => $system->id,
                    'name'      => $system->name,
                    'has_token' => filled($system->bot_token),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * 可以當私訊收件人的帳號
     *
     * ⚠ `dm_ready` 一定要帶：沒私訊過機器人的人收不到任何東西，
     * 要在勾之前就看得出來，不然要等到隔天沒收到才發現。
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
     * 可以勾選的通知類型
     *
     * `needs_reply` 要送到前端 —— 那類只能勾一個話題，前端要能當場提示，
     * 不是等按了儲存才被後端擋下來。
     *
     * @return array
     */
    private function noticeTypeOptions()
    {
        $types = [];

        foreach ((array) config('constants.SUPPORT_TOPIC.TYPES') as $key => $type) {
            $types[] = [
                'key'         => $key,
                'label'       => (string) Arr::get($type, 'label', $key),
                'hint'        => (string) Arr::get($type, 'hint', ''),
                'needs_reply' => (bool) Arr::get($type, 'needs_reply', false),
            ];
        }

        return $types;
    }
}
