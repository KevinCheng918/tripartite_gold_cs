<?php

namespace App\Services;

use App\Models\Station;
use App\Models\TelegramGroup;
use App\Repositories\StationRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

/**
 * 站台 Service
 */
class StationService
{
    private $stationRepository;
    private $mainSystemApi;
    private $telegramBot;

    public function __construct(
        StationRepository $stationRepository,
        MainSystemApiService $mainSystemApi,
        TelegramBotService $telegramBot
    ) {
        $this->stationRepository = $stationRepository;
        $this->mainSystemApi = $mainSystemApi;
        $this->telegramBot = $telegramBot;
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
            'system_id'    => $params['system_id'] ?? null,
            'status'       => $params['status'] ?? null,
            'credits_min'  => $params['credits_min'] ?? null,
            'credits_max'  => $params['credits_max'] ?? null,
            'support_shop' => $params['support_shop'] ?? null,
            'score_runner' => $params['score_runner'] ?? null,
        ];

        return $this->stationRepository->paginate($criteria, (int) ($params['per_page'] ?? 20));
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
            'system_id'         => $params['system_id'] ?? null,
            'name'              => $params['name'],
            'domain'            => $params['domain'] ?? null,
            'api_url'           => $params['api_url'] ?? null,
            'api_key'           => $params['api_key'] ?? null,
            'telegram_group_id' => $telegramGroupId,
            'status'            => config('constants.STATION.STATUS.ACTIVE'),
            'note'              => $params['note'] ?? null,
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

        $fields = ['system_id', 'name', 'domain', 'api_url', 'api_key', 'telegram_group_id', 'status', 'note'];
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
     * 從 Telegram API 讀取機器人所在的群組
     *
     * 流程：暫時關閉 webhook → getUpdates → 提取群組 → 恢復 webhook
     *
     * @return array 群組列表 [{chat_id, title, type}]
     */
    public function fetchBotGroups()
    {
        // 記住目前 webhook URL
        $webhookInfo = $this->telegramBot->getWebhookInfo();
        $currentUrl = $webhookInfo['result']['url'] ?? null;

        // 暫時關閉 webhook
        $this->telegramBot->deleteWebhook();

        // 讀取 updates
        $response = $this->telegramBot->getUpdates(100);

        // 恢復 webhook
        if (filled($currentUrl)) {
            $this->telegramBot->setWebhook($currentUrl);
        }

        if (!$response || !($response['ok'] ?? false)) {
            Log::warning('fetchBotGroups: getUpdates 失敗', ['response' => $response]);
            return [];
        }

        // 提取不重複的群組
        $groups = [];
        $seen = [];

        foreach ($response['result'] ?? [] as $update) {
            $chat = $update['message']['chat'] ?? ($update['my_chat_member']['chat'] ?? null);

            if (!$chat) {
                continue;
            }

            $type = $chat['type'] ?? '';
            if ($type !== 'group' && $type !== 'supergroup') {
                continue;
            }

            $chatId = $chat['id'];
            if (isset($seen[$chatId])) {
                continue;
            }

            $seen[$chatId] = true;
            $groups[] = [
                'chat_id' => $chatId,
                'title'   => $chat['title'] ?? "Group {$chatId}",
                'type'    => $type,
            ];
        }

        return $groups;
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

        $group = TelegramGroup::query()
            ->where('chat_id', (int) $chatId)
            ->first(['id']);

        if ($group) {
            return $group->id;
        }

        $newGroup = TelegramGroup::query()->create([
            'chat_id' => (int) $chatId,
            'title'   => $params['name'] ?? 'Group ' . $chatId,
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
     * @param Station $station
     * @return array|null 同步後的資料，失敗回傳 null
     */
    public function syncInfo(Station $station)
    {
        $info = $this->mainSystemApi->getStationInfo($station->api_url, $station->api_key);

        if (!$info) {
            return null;
        }

        // admin_credit → credits 欄位
        $credits = $info['admin_credit'] ?? $station->credits;

        // 整包 data 存入 settings JSON
        $this->stationRepository->update($station, [
            'credits'   => $credits,
            'settings'  => $info,
            'synced_at' => now(),
        ]);

        return $info;
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
     * 新增系統
     *
     * @param string $name
     * @return \App\Models\System
     */
    public function createSystem($name)
    {
        return $this->stationRepository->createSystem([
            'name'   => $name,
            'status' => 1,
        ]);
    }
}
