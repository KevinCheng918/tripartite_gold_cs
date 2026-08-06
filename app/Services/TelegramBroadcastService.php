<?php

namespace App\Services;

use App\Models\TelegramBroadcast;
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
    private $botService;

    public function __construct(
        TelegramBroadcastRepository $broadcastRepository,
        TelegramRepository $telegramRepository,
        TelegramBotService $botService
    ) {
        $this->broadcastRepository = $broadcastRepository;
        $this->telegramRepository = $telegramRepository;
        $this->botService = $botService;
    }

    /**
     * 取得公告歷史紀錄（分頁）
     *
     * @param int $perPage
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function list($perPage = 20)
    {
        return $this->broadcastRepository->paginate($perPage);
    }

    /**
     * 發送群發公告
     *
     * @param array $params   含 content, target_type, group_ids(optional)
     * @param int   $senderId 發送者 user_id
     * @return TelegramBroadcast
     */
    public function send($params, $senderId)
    {
        $targetType = (int) $params['target_type'];
        $content = $params['content'];

        // 取得目標群組
        if ($targetType === TelegramBroadcast::TARGET_ALL) {
            $groups = $this->telegramRepository->getActiveGroups();
            $groupIds = $groups->pluck('id')->all();
        } else {
            $groupIds = $params['group_ids'] ?? [];
            $groups = $this->telegramRepository->getActiveGroups()
                ->whereIn('id', $groupIds);
        }

        // 建立紀錄
        $broadcast = $this->broadcastRepository->create([
            'content'          => $content,
            'target_type'      => $targetType,
            'target_group_ids' => $targetType === TelegramBroadcast::TARGET_SELECTED ? $groupIds : null,
            'total_count'      => $groups->count(),
            'success_count'    => 0,
            'fail_count'       => 0,
            'sender_id'        => $senderId,
            'sent_at'          => now(),
        ]);

        // 逐一發送
        $success = 0;
        $fail = 0;

        foreach ($groups as $group) {
            $result = $this->botService->sendMessage($group->chat_id, $content);

            if ($result && isset($result['ok']) && $result['ok']) {
                $success++;
            } else {
                $fail++;
                Log::warning('群發公告發送失敗', ['chat_id' => $group->chat_id, 'broadcast_id' => $broadcast->id]);
            }
        }

        // 更新結果
        $this->broadcastRepository->update($broadcast, [
            'success_count' => $success,
            'fail_count'    => $fail,
        ]);

        return $broadcast->fresh();
    }
}
