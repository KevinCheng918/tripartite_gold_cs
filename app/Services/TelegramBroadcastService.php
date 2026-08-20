<?php

namespace App\Services;

use App\Models\TelegramBroadcast;
use App\Repositories\StationRepository;
use App\Repositories\TelegramBroadcastRepository;
use App\Repositories\TelegramRepository;
use Illuminate\Support\Facades\Log;

/**
 * Telegram 群發公告 Service
 *
 * 處理群發公告的建立、發送、紀錄。
 */
class TelegramBroadcastService
{
    private $broadcastRepository;
    private $telegramRepository;
    private $stationRepository;
    private $botService;

    public function __construct(
        TelegramBroadcastRepository $broadcastRepository,
        TelegramRepository $telegramRepository,
        StationRepository $stationRepository,
        TelegramBotService $botService
    ) {
        $this->broadcastRepository = $broadcastRepository;
        $this->telegramRepository = $telegramRepository;
        $this->stationRepository = $stationRepository;
        $this->botService = $botService;
    }

    /**
     * 取得公告歷史紀錄（分頁）
     *
     * @param int $perPage
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    /**
     * 取得可發送公告的站台列表（啟用且有 Telegram 群組）
     *
     * @return array
     */
    public function getTargetStations()
    {
        return $this->stationRepository->getActiveWithTelegram()->map(function ($s) {
            return [
                'id'        => $s->id,
                'name'      => $s->name,
                'title'     => $s->name . ($s->domain ? " ({$s->domain})" : ''),
                'system_id' => $s->system_id,
                'system'    => $s->system ? $s->system->name : null,
            ];
        })->values()->all();
    }

    public function list($perPage = 20)
    {
        return $this->broadcastRepository->paginate($perPage);
    }

    /**
     * 發送群發公告
     *
     * 從站台列表取得目標（僅啟用且有 Telegram 群組的站台）。
     * group_ids 在這裡是 station.id，不是 telegram_group.id。
     *
     * @param array $params   含 content, target_type, group_ids(station ids, optional)
     * @param int   $senderId 發送者 user_id
     * @return TelegramBroadcast
     */
    public function send($params, $senderId)
    {
        $targetType = (int) $params['target_type'];
        $content = $params['content'];

        // 從站台列表取得目標（僅啟用且有 Telegram 群組的）
        $allStations = $this->stationRepository->getActiveWithTelegram();

        if ($targetType === TelegramBroadcast::TARGET_ALL) {
            $stations = $allStations;
            $stationIds = $stations->pluck('id')->all();
        } else {
            $stationIds = $params['group_ids'] ?? [];
            $stations = $allStations->whereIn('id', $stationIds);
        }

        // 取得對應的 Telegram 群組（透過站台的 telegramGroup 關聯）
        $groups = $stations->map(function ($station) {
            return $station->telegramGroup;
        })->filter();

        // 建立紀錄
        $broadcast = $this->broadcastRepository->create([
            'content'          => $content,
            'target_type'      => $targetType,
            'target_group_ids' => $targetType === TelegramBroadcast::TARGET_SELECTED ? $stationIds : null,
            'total_count'      => $groups->count(),
            'success_count'    => 0,
            'fail_count'       => 0,
            'sender_id'        => $senderId,
            'sent_at'          => now(),
        ]);

        // 查發送者暱稱
        $sender = \App\Models\User::query()->select(['id', 'nickname'])->find($senderId);
        $senderName = $sender ? $sender->nickname : '系統';

        // 逐一發送（根據站台系統切換 Bot Token）
        $success = 0;
        $fail = 0;
        $sendResults = [];

        foreach ($stations as $station) {
            if (!$station->telegramGroup) {
                $sendResults[] = ['station_id' => $station->id, 'name' => $station->name, 'success' => false];
                $fail++;
                continue;
            }

            // 切換到該站台系統的 Bot Token
            if ($station->system && filled($station->system->bot_token)) {
                $this->botService->setToken($station->system->bot_token);
            }

            $imageUrls = $params['image_urls'] ?? [];
            $chatId = $station->telegramGroup->chat_id;

            if (count($imageUrls) > 1) {
                $result = $this->botService->sendMediaGroup($chatId, $imageUrls, $content);
            } elseif (count($imageUrls) === 1) {
                $result = $this->botService->sendPhoto($chatId, $imageUrls[0], $content);
            } else {
                $result = $this->botService->sendMessage($chatId, $content);
            }

            if ($result && isset($result['ok']) && $result['ok']) {
                $sendResults[] = ['station_id' => $station->id, 'name' => $station->name, 'success' => true];
                $success++;

                // 存入 outbound 訊息到對話紀錄（多張取第一張）
                $firstImage = !empty($imageUrls) ? $imageUrls[0] : null;
                $msgId = null;
                if (isset($result['result'])) {
                    // sendMediaGroup 回傳陣列，取第一個
                    $msgResult = is_array($result['result']) && isset($result['result'][0])
                        ? $result['result'][0] : $result['result'];
                    $msgId = $msgResult['message_id'] ?? null;
                }

                $this->telegramRepository->createMessage([
                    'telegram_group_id'  => $station->telegramGroup->id,
                    'direction'          => config('constants.TELEGRAM.DIRECTION.OUTBOUND'),
                    'telegram_message_id' => $msgId,
                    'sender_name'        => $senderName,
                    'sender_user_id'     => $senderId,
                    'content'            => $content ?: '',
                    'media_type'         => filled($firstImage) ? 'photo' : null,
                    'media_url'          => $firstImage,
                    'replied'            => true,
                ]);
            } else {
                $sendResults[] = ['station_id' => $station->id, 'name' => $station->name, 'success' => false];
                $fail++;
                Log::warning('群發公告發送失敗', ['chat_id' => $station->telegramGroup->chat_id, 'broadcast_id' => $broadcast->id]);
            }
        }

        // 更新結果
        $this->broadcastRepository->update($broadcast, [
            'success_count' => $success,
            'fail_count'    => $fail,
            'send_results'  => $sendResults,
        ]);

        return $broadcast->fresh();
    }
}
