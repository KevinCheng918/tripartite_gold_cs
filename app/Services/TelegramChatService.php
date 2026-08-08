<?php

namespace App\Services;

use App\Models\TelegramGroup;
use App\Repositories\ShiftAssignmentRepository;
use App\Repositories\TelegramRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Telegram 客服聊天 Service
 *
 * 處理 Webhook 收訊、後台回覆、告警等核心商業邏輯。
 */
class TelegramChatService
{
    private $telegramRepository;
    private $botService;
    private $assignmentRepository;

    public function __construct(
        TelegramRepository $telegramRepository,
        TelegramBotService $botService,
        ShiftAssignmentRepository $assignmentRepository
    ) {
        $this->telegramRepository = $telegramRepository;
        $this->botService = $botService;
        $this->assignmentRepository = $assignmentRepository;
    }

    // ---------------------------------------------------------------
    //  對話列表
    // ---------------------------------------------------------------

    /**
     * 取得所有啟用群組（含未讀數）
     *
     * @return array
     */
    public function getConversationList()
    {
        $groups = $this->telegramRepository->getActiveGroups();

        return $groups->map(function ($group) {
            return [
                'id'              => $group->id,
                'chat_id'         => $group->chat_id,
                'title'           => $group->title,
                'assigned_user'   => $group->assignedUser ? $group->assignedUser->nickname : null,
                'last_message_at' => $group->last_message_at ? $group->last_message_at->toDateTimeString() : null,
                'unread_count'    => $this->telegramRepository->getUnrepliedCount($group->id),
            ];
        })->all();
    }

    /**
     * 取得群組訊息（分頁）
     *
     * @param int $groupId
     * @param int $perPage
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getMessages($groupId, $perPage = 50)
    {
        return $this->telegramRepository->getMessagesByGroup($groupId, $perPage);
    }

    // ---------------------------------------------------------------
    //  收訊（Webhook）
    // ---------------------------------------------------------------

    /**
     * 處理 Telegram Webhook 收到的訊息
     *
     * @param array $payload Telegram Update 物件
     * @return void
     */
    public function handleIncomingMessage($payload)
    {
        $message = $payload['message'] ?? null;

        if (!$message || !isset($message['chat']['id'])) {
            return;
        }

        $chatId = $message['chat']['id'];
        $chatTitle = $message['chat']['title'] ?? "Chat {$chatId}";
        $text = $message['text'] ?? ($message['caption'] ?? '');
        $senderName = $this->buildSenderName($message['from'] ?? []);
        $telegramMessageId = $message['message_id'] ?? null;

        // 解析媒體（圖片）
        $mediaType = null;
        $mediaUrl = null;

        if (isset($message['photo'])) {
            $mediaType = 'photo';
            $photos = $message['photo'];
            $largest = end($photos);
            $fileId = $largest['file_id'] ?? null;

            if (filled($fileId)) {
                $mediaUrl = $this->downloadTelegramFile($fileId, 'photo');
            }
        } elseif (isset($message['sticker'])) {
            $mediaType = 'sticker';
            $fileId = $message['sticker']['file_id'] ?? null;

            if (filled($fileId)) {
                $mediaUrl = $this->downloadTelegramFile($fileId, 'sticker');
            }
        }

        // 無文字也無媒體則跳過
        if (!filled($text) && !filled($mediaType)) {
            return;
        }

        // 找或建群組
        $group = $this->telegramRepository->findGroupByChatId($chatId);

        if (!$group) {
            $group = $this->telegramRepository->createGroup([
                'chat_id' => $chatId,
                'title'   => $chatTitle,
                'status'  => config('constants.TELEGRAM.GROUP_STATUS.ACTIVE'),
            ]);
        }

        // 群組名稱可能變更，同步更新
        if ($group->title !== $chatTitle) {
            $this->telegramRepository->updateGroup($group, ['title' => $chatTitle]);
        }

        // 自動指派當前值班客服
        $this->autoAssignOnDuty($group);

        // 存入訊息
        $msg = $this->telegramRepository->createMessage([
            'telegram_group_id'  => $group->id,
            'direction'          => config('constants.TELEGRAM.DIRECTION.INBOUND'),
            'telegram_message_id' => $telegramMessageId,
            'sender_name'        => $senderName,
            'content'            => $text,
            'media_type'         => $mediaType,
            'media_url'          => $mediaUrl,
            'replied'            => false,
        ]);

        // Broadcasting — 即時推送到前端
        try {
            event(new \App\Events\TelegramMessageReceived($group->id, [
                'id'          => $msg->id,
                'direction'   => $msg->direction,
                'sender_name' => $msg->sender_name,
                'content'     => $msg->content,
                'media_type'  => $msg->media_type,
                'media_url'   => $msg->media_url,
                'created_at'  => $msg->created_at->toDateTimeString(),
                'group_id'    => $group->id,
                'group_title' => $group->title,
            ]));
        } catch (\Exception $e) {
            Log::warning('Broadcasting 失敗', ['error' => $e->getMessage()]);
        }
    }

    // ---------------------------------------------------------------
    //  回覆
    // ---------------------------------------------------------------

    /**
     * 從後台回覆訊息到 Telegram 群組
     *
     * @param int         $groupId   群組 ID
     * @param string|null $content   回覆內容
     * @param int         $userId    後台使用者 ID
     * @param string      $nickname  後台使用者暱稱
     * @param string|null $imageUrl  圖片 URL（本地上傳後的公開 URL）
     * @return \App\Models\TelegramMessage
     */
    public function sendReply($groupId, $content, $userId, $nickname, $imageUrl = null)
    {
        $group = $this->telegramRepository->findGroup($groupId);

        // 透過 Bot API 發送到 Telegram
        if (filled($imageUrl)) {
            $this->botService->sendPhoto($group->chat_id, $imageUrl, $content);
        } else {
            $this->botService->sendMessage($group->chat_id, $content);
        }

        // 存入 outbound 訊息
        $msg = $this->telegramRepository->createMessage([
            'telegram_group_id' => $group->id,
            'direction'         => config('constants.TELEGRAM.DIRECTION.OUTBOUND'),
            'sender_name'       => $nickname,
            'sender_user_id'    => $userId,
            'content'           => $content ?: '',
            'media_type'        => filled($imageUrl) ? 'photo' : null,
            'media_url'         => $imageUrl,
            'replied'           => true,
        ]);

        // 標記該群組所有未回覆 inbound 訊息為已回覆
        $this->telegramRepository->markMessagesReplied($group->id);

        // Broadcasting
        try {
            event(new \App\Events\TelegramMessageReceived($group->id, [
                'id'          => $msg->id,
                'direction'   => $msg->direction,
                'sender_name' => $msg->sender_name,
                'content'     => $msg->content,
                'media_type'  => $msg->media_type,
                'media_url'   => $msg->media_url,
                'created_at'  => $msg->created_at->toDateTimeString(),
                'group_id'    => $group->id,
                'group_title' => $group->title,
            ]));
        } catch (\Exception $e) {
            Log::warning('Broadcasting 失敗', ['error' => $e->getMessage()]);
        }

        return $msg;
    }

    // ---------------------------------------------------------------
    //  私有方法
    // ---------------------------------------------------------------

    /**
     * 自動指派當前值班客服到群組
     *
     * 從今日排班中找出當前時段正在上班的客服，
     * 如果群組尚未指派或已過期，自動更新。
     *
     * @param TelegramGroup $group
     * @return void
     */
    private function autoAssignOnDuty(TelegramGroup $group)
    {
        $today = now()->format('Y-m-d');
        $nowMinutes = now()->hour * 60 + now()->minute;

        // 取得今日所有排班
        $assignments = $this->assignmentRepository->getByDateRange($today, $today);

        if ($assignments->isEmpty()) {
            return;
        }

        // 找出當前時段正在值班的客服
        $onDutyUserId = null;
        foreach ($assignments as $assignment) {
            if (!$assignment->shift) {
                continue;
            }

            $parts = explode(':', $assignment->shift->start_time);
            $startMin = (int) $parts[0] * 60 + (int) $parts[1];

            $parts = explode(':', $assignment->shift->end_time);
            $endMin = (int) $parts[0] * 60 + (int) $parts[1];

            $inRange = false;
            if ($endMin > $startMin) {
                $inRange = ($nowMinutes >= $startMin && $nowMinutes < $endMin);
            } elseif ($endMin <= $startMin) {
                $inRange = ($nowMinutes >= $startMin || $nowMinutes < $endMin);
            }

            if ($inRange) {
                $onDutyUserId = $assignment->user_id;
                break;
            }
        }

        // 如果找到值班客服且與目前指派不同，更新
        if (filled($onDutyUserId) && (int) $group->assigned_user_id !== (int) $onDutyUserId) {
            $this->telegramRepository->assignGroup($group, $onDutyUserId);
        }
    }

    /**
     * 從 Telegram 下載檔案到本地
     *
     * @param string $fileId Telegram file_id
     * @param string $prefix 檔名前綴（photo / sticker）
     * @return string|null 本地公開 URL
     */
    private function downloadTelegramFile($fileId, $prefix)
    {
        $remoteUrl = $this->botService->getFileUrl($fileId);

        if (!filled($remoteUrl)) {
            return null;
        }

        try {
            // 取得副檔名
            $pathInfo = pathinfo(parse_url($remoteUrl, PHP_URL_PATH));
            $ext = $pathInfo['extension'] ?? 'jpg';
            $filename = "{$prefix}_" . time() . '_' . mt_rand(1000, 9999) . ".{$ext}";

            $content = file_get_contents($remoteUrl);

            if ($content === false) {
                Log::warning('Telegram 檔案下載失敗', ['url' => $remoteUrl]);
                return null;
            }

            Storage::disk('public')->put("uploads/telegram/{$filename}", $content);

            return Storage::disk('public')->url("uploads/telegram/{$filename}");
        } catch (\Exception $e) {
            Log::error('Telegram 檔案下載異常', ['error' => $e->getMessage(), 'file_id' => $fileId]);
            return null;
        }
    }

    /**
     * 組合 Telegram 使用者顯示名稱
     *
     * @param array $from
     * @return string
     */
    private function buildSenderName($from)
    {
        $firstName = $from['first_name'] ?? '';
        $lastName = $from['last_name'] ?? '';
        $name = trim("{$firstName} {$lastName}");

        return filled($name) ? $name : ($from['username'] ?? 'Unknown');
    }
}
