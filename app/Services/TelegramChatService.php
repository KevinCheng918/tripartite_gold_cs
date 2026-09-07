<?php

namespace App\Services;

use App\Models\TelegramGroup;
use App\Repositories\SharedFileRepository;
use App\Repositories\ShiftAssignmentRepository;
use App\Repositories\TelegramRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
    private $webPushService;
    private $sharedFileRepository;

    public function __construct(
        TelegramRepository $telegramRepository,
        TelegramBotService $botService,
        ShiftAssignmentRepository $assignmentRepository,
        WebPushService $webPushService,
        SharedFileRepository $sharedFileRepository
    ) {
        $this->telegramRepository = $telegramRepository;
        $this->botService = $botService;
        $this->assignmentRepository = $assignmentRepository;
        $this->webPushService = $webPushService;
        $this->sharedFileRepository = $sharedFileRepository;
    }

    /**
     * 根據群組的站台系統切換 Bot Token
     *
     * @param \App\Models\TelegramGroup $group
     * @return void
     */
    private function switchBotToken($group)
    {
        $station = $group->station;
        if (!$station || !$station->system_id) {
            return;
        }

        $system = $station->system;
        if ($system && filled($system->bot_token)) {
            $this->botService->setToken($system->bot_token);
        }
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
        $onDutyUsers = $this->getOnDutyUsers();

        return $groups->map(function ($group) use ($onDutyUsers) {
            return [
                'id'              => $group->id,
                'chat_id'         => $group->chat_id,
                'title'           => $group->title,
                'on_duty_users'   => $onDutyUsers,
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

    /**
     * 標記群組訊息為已讀
     *
     * @param int $groupId
     * @return void
     */
    public function markAsRead($groupId)
    {
        $this->telegramRepository->markMessagesReplied($groupId);
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
    /**
     * 處理 Telegram 的 edited_message 事件
     *
     * 客人改了訊息內容，後台不同步的話客服會照舊內容回覆。
     *
     * 註：Bot API **沒有**刪除訊息的事件，客人「收回」是收不到通知的；
     * 實務上不少人是改用編輯來更正，這支能涵蓋那部分情境。
     *
     * @param array $payload Telegram update payload
     * @return void
     */
    public function handleEditedMessage($payload)
    {
        $edited = $payload['edited_message'] ?? null;
        $chatId = $edited['chat']['id'] ?? null;
        $messageId = $edited['message_id'] ?? null;

        if (!filled($chatId) || !filled($messageId)) {
            return;
        }

        $message = $this->telegramRepository->findMessageForEdit($chatId, $messageId);

        // 找不到多半是訊息已超過保留天數被清掉，不是異常
        if (!filled($message)) {
            Log::info('收到編輯事件但查無對應訊息', [
                'chat_id'    => $chatId,
                'message_id' => $messageId,
            ]);

            return;
        }

        // caption 是圖片／檔案的說明文字，改圖說也會觸發編輯事件
        $newText = $edited['text'] ?? $edited['caption'] ?? '';

        $this->telegramRepository->updateMessage($message, [
            'content'   => $newText,
            'edited_at' => now(),
            // 內容變了就當成新的未回覆，避免客服看到舊內容已回覆就略過
            'replied'   => false,
        ]);

        $this->broadcastEdited($message->telegram_group_id, $message->id, $newText);
    }

    /**
     * 廣播編輯事件，讓正在看該對話的客服即時看到新內容
     *
     * @param int    $groupId
     * @param int    $messageId
     * @param string $content
     * @return void
     */
    private function broadcastEdited($groupId, $messageId, $content)
    {
        try {
            event(new \App\Events\TelegramMessageReceived($groupId, [
                'id'        => $messageId,
                'edited'    => true,
                'content'   => $content,
                'group_id'  => $groupId,
            ]));
        } catch (\Exception $e) {
            Log::error('編輯訊息廣播失敗', ['group_id' => $groupId, 'error' => $e->getMessage()]);
        }
    }

    public function handleIncomingMessage($payload)
    {
        $message = $payload['message'] ?? null;

        if (!$message || !isset($message['chat']['id'])) {
            return;
        }

        // 只接收群組對話，忽略私人訊息
        $chatType = $message['chat']['type'] ?? '';
        if ($chatType === 'private') {
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
        $mediaName = null;

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
            $sticker = $message['sticker'];
            $isAnimated = $sticker['is_animated'] ?? false;
            $isVideo = $sticker['is_video'] ?? false;

            // 動態 / 影片貼圖原始檔為 .tgs / .webm，<img> 無法顯示，改用縮圖
            if (($isAnimated || $isVideo) && isset($sticker['thumbnail']['file_id'])) {
                $fileId = $sticker['thumbnail']['file_id'];
            } else {
                $fileId = $sticker['file_id'] ?? null;
            }

            if (filled($fileId)) {
                $mediaUrl = $this->downloadTelegramFile($fileId, 'sticker');
            }
        } elseif (isset($message['document'])) {
            $doc = $message['document'];
            $fileId = $doc['file_id'] ?? null;
            $fileName = $doc['file_name'] ?? 'file';

            // wav、mp4 這類會被 Telegram 當成 document 送來（voice 只用於按住錄音的訊息）。
            // 依 MIME 判斷才能讓它們在對話裡直接播，而不是只給一個下載連結
            $mediaType = $this->documentMediaType($doc['mime_type'] ?? null);

            if (filled($fileId)) {
                $mediaUrl = $this->downloadTelegramFile($fileId, 'document');
            }

            // 本地存檔名是 document_時間戳_亂數，原始檔名只有這裡拿得到，
            // 不留下來的話下載時就還原不了
            $mediaName = $fileName;

            // 檔名放到 text 前面方便顯示
            if (!filled($text)) {
                $text = $fileName;
            }
        } else {
            // 影片、語音、音訊等。沒有這段的話 mediaType 會是 null，
            // 客人只傳影片不打字時整則訊息會被下面的「無文字也無媒體」直接丟掉
            $parsed = $this->parseOtherMedia($message);

            if (filled($parsed)) {
                $mediaType = $parsed['type'];
                $mediaUrl = $parsed['url'];
                $mediaName = $parsed['name'];

                if (!filled($text)) {
                    $text = $parsed['fallback_text'];
                }
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

        // 根據站台系統切換 Bot Token（用於下載圖片等）
        $this->switchBotToken($group);

        // 群組名稱可能變更，同步更新
        if ($group->title !== $chatTitle) {
            $this->telegramRepository->updateGroup($group, ['title' => $chatTitle]);
        }

        // 自動指派當前值班客服
        $this->autoAssignOnDuty($group);

        // 解析引用回覆
        $replyToSender = null;
        $replyToText = null;
        Log::info('Telegram 訊息收到', ['has_reply' => isset($message['reply_to_message']), 'msg_id' => $telegramMessageId]);
        if (isset($message['reply_to_message'])) {
            $replyMsg = $message['reply_to_message'];
            $replyToSender = $this->buildSenderName($replyMsg['from'] ?? []);
            $replyToText = $replyMsg['text'] ?? ($replyMsg['caption'] ?? '');
            // 截取前 200 字元
            if (mb_strlen($replyToText) > 200) {
                $replyToText = mb_substr($replyToText, 0, 200) . '...';
            }
        }

        // 存入訊息
        $msg = $this->telegramRepository->createMessage([
            'telegram_group_id'  => $group->id,
            'direction'          => config('constants.TELEGRAM.DIRECTION.INBOUND'),
            'telegram_message_id' => $telegramMessageId,
            'sender_name'        => $senderName,
            'content'            => $text,
            'media_type'         => $mediaType,
            'media_url'          => $mediaUrl,
            'media_name'         => $mediaName,
            'reply_to_sender'    => $replyToSender,
            'reply_to_text'      => $replyToText,
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
                'media_name'  => $msg->media_name,
                'created_at'  => $msg->created_at->toDateTimeString(),
                'group_id'    => $group->id,
                'group_title' => $group->title,
            ]));
        } catch (\Exception $e) {
            Log::warning('Broadcasting 失敗', ['error' => $e->getMessage()]);
        }

        // Web Push 推播通知（通知所有已訂閱的客服）
        // 無文字時依媒體類型給替代文案，否則影片／語音都會被顯示成「[貼圖]」
        $pushBody = filled($text) ? $text : $this->mediaLabel($mediaType);
        $this->webPushService->sendToAll(
            "{$group->title} — {$senderName}",
            mb_substr($pushBody, 0, 100),
            '/admin/telegram-chat'
        );
    }

    // ---------------------------------------------------------------
    //  表情回應（Reaction）
    // ---------------------------------------------------------------

    /**
     * 處理 Telegram Webhook 收到的 message_reaction 事件
     *
     * @param array $payload Telegram Update 物件
     * @return void
     */
    public function handleReactionUpdate($payload)
    {
        $reaction = $payload['message_reaction'] ?? null;

        if (!$reaction) {
            return;
        }

        $chatId = $reaction['chat']['id'] ?? null;
        $telegramMsgId = $reaction['message_id'] ?? null;

        if (!filled($chatId) || !filled($telegramMsgId)) {
            return;
        }

        $message = $this->telegramRepository->findByTelegramMessageId($chatId, $telegramMsgId);

        if (!$message) {
            return;
        }

        // 合併 new_reaction 到現有 reactions
        $newReactions = $reaction['new_reaction'] ?? [];
        $reactions = $this->mergeReactions($message->reactions, $newReactions);

        $this->telegramRepository->updateReactions($message, $reactions);

        // Broadcasting — 通知前端更新
        try {
            event(new \App\Events\TelegramMessageReceived($message->telegram_group_id, [
                'type'                => 'reaction_update',
                'telegram_message_id' => $telegramMsgId,
                'reactions'           => $reactions,
                'group_id'            => $message->telegram_group_id,
            ]));
        } catch (\Exception $e) {
            Log::warning('Broadcasting reaction 失敗', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 從後台對 Telegram 訊息送出表情回應
     *
     * @param int    $messageId 本地訊息 ID
     * @param string $emoji     表情符號
     * @return array|null 更新後的 reactions
     */
    public function sendReaction($messageId, $emoji)
    {
        $message = $this->telegramRepository->findMessageWithGroup($messageId);

        if (!$message || !filled($message->telegram_message_id)) {
            return null;
        }

        // 根據群組對應的站台系統切換 Bot Token
        if ($message->group) {
            $this->switchBotToken($message->group);
        }

        $result = $this->botService->setMessageReaction(
            $message->group->chat_id,
            $message->telegram_message_id,
            $emoji
        );

        if (!$result || !($result['ok'] ?? false)) {
            return null;
        }

        // Bot 的 reaction 是覆蓋邏輯（一次只有一個），直接替換
        $reactions = $this->replaceBotReaction($message->reactions, $emoji);

        $this->telegramRepository->updateReactions($message, $reactions);

        return $reactions;
    }

    // ---------------------------------------------------------------
    //  回覆
    // ---------------------------------------------------------------

    /**
     * 從後台回覆訊息到 Telegram 群組
     *
     * 選填項目用 $options 陣列而非一路往後加參數 ——
     * 呼叫端寫成 `null, false` 這種裸值時，看不出哪個是什麼。
     *
     * @param int         $groupId  群組 ID
     * @param string|null $content  回覆內容
     * @param int         $userId   後台使用者 ID
     * @param string      $nickname 後台使用者暱稱
     * @param array       $options  {
     *     @type string|null $image_url    圖片 URL（本地上傳後的公開 URL）
     *     @type bool        $mark_replied 是否把該群組未回覆的客戶訊息標記為已回覆。
     *                                     系統自動發出的通知要傳 false，否則會消掉未回覆告警，
     *                                     讓客戶還在等的問題被誤判成已處理。預設 true
     *     @type int|null    $reply_to_id  引用的訊息 id（後台的，非 Telegram 的）
     * }
     * @return \App\Models\TelegramMessage
     */
    public function sendReply($groupId, $content, $userId, $nickname, $options = [])
    {
        $imageUrl = $options['image_url'] ?? null;
        $markReplied = $options['mark_replied'] ?? true;
        $replyToId = $options['reply_to_id'] ?? null;

        $group = $this->telegramRepository->findGroup($groupId);

        // 根據站台系統切換 Bot Token
        $this->switchBotToken($group);

        // 署名在送出前加上，寫進紀錄的內容才會與客戶看到的一致
        $content = $this->appendSignature($content, $userId);

        // 引用的是後台的訊息 id，要換成 Telegram 那邊的 message_id 才送得出去。
        // 沒有 telegram_message_id 就當作沒引用 —— 否則後台會留下一筆
        // 客戶端根本看不到的假引用（修正前送出的舊訊息都沒存這個 id）
        $quoted = filled($replyToId) ? $this->telegramRepository->findQuoted($group->id, $replyToId) : null;

        if (filled($quoted) && !filled($quoted->telegram_message_id)) {
            Log::info('引用目標沒有 telegram_message_id，改以一般訊息送出', [
                'group_id'   => $group->id,
                'message_id' => $replyToId,
            ]);

            $quoted = null;
        }

        // 透過 Bot API 發送到 Telegram
        $result = filled($imageUrl)
            ? $this->botService->sendPhoto($group->chat_id, $imageUrl, $content)
            : $this->botService->sendMessage($group->chat_id, $content, $quoted ? $quoted->telegram_message_id : null);

        // Telegram 沒收到就不要留下「已送出」的紀錄，否則客服會以為回覆成功
        if (!filled($result)) {
            Log::error('Telegram 回覆發送失敗，不寫入訊息紀錄', [
                'group_id' => $group->id,
                'chat_id'  => $group->chat_id,
                'has_image' => filled($imageUrl),
            ]);

            throw new \RuntimeException(trans('telegram_chat.msg.reply_failed'));
        }

        // 超規格的截圖會被改以檔案送出，紀錄要跟著標成 document，
        // 否則前端會當圖片渲染而顯示成破圖
        $mediaType = null;
        if (filled($imageUrl)) {
            $mediaType = isset($result['result']['document']) ? 'document' : 'photo';
        }

        // 存入 outbound 訊息
        $msg = $this->telegramRepository->createMessage([
            'telegram_group_id' => $group->id,
            'direction'         => config('constants.TELEGRAM.DIRECTION.OUTBOUND'),
            'telegram_message_id' => $result['result']['message_id'] ?? null,
            'sender_name'       => $nickname,
            'sender_user_id'    => $userId,
            'content'           => $content ?: '',
            'media_type'        => $mediaType,
            'media_url'         => $imageUrl,
            // 引用資訊也存進來，後台才看得到自己引用了哪一則
            'reply_to_sender'   => $quoted ? $quoted->sender_name : null,
            'reply_to_text'     => $quoted ? Str::limit($quoted->content, 200) : null,
            'replied'           => true,
        ]);

        // 標記該群組所有未回覆 inbound 訊息為已回覆
        if ($markReplied) {
            $this->telegramRepository->markMessagesReplied($group->id);
        }

        // Broadcasting
        try {
            event(new \App\Events\TelegramMessageReceived($group->id, [
                'id'          => $msg->id,
                'direction'   => $msg->direction,
                'sender_name' => $msg->sender_name,
                'content'     => $msg->content,
                'media_type'  => $msg->media_type,
                'media_url'   => $msg->media_url,
                'media_name'  => $msg->media_name,
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
     * 取得當前時段所有值班中的客服暱稱
     *
     * @return array 暱稱陣列（如 ['客服一號', 'localCS04']），無人值班時回傳空陣列
     */
    private function getOnDutyUsers()
    {
        $today = now()->format('Y-m-d');
        $nowMinutes = now()->hour * 60 + now()->minute;

        $assignments = $this->assignmentRepository->getByDateRange($today, $today);

        if ($assignments->isEmpty()) {
            return [];
        }

        $users = [];
        foreach ($assignments as $assignment) {
            if (!$assignment->shift || !$assignment->user) {
                continue;
            }

            if ($this->isTimeInShiftRange($assignment->shift, $nowMinutes)) {
                $nickname = $assignment->user->nickname;
                if (!in_array($nickname, $users, true)) {
                    $users[] = $nickname;
                }
            }
        }

        return $users;
    }

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

        $assignments = $this->assignmentRepository->getByDateRange($today, $today);

        if ($assignments->isEmpty()) {
            return;
        }

        // 找出第一個當前值班的客服
        foreach ($assignments as $assignment) {
            if (!$assignment->shift) {
                continue;
            }

            if ($this->isTimeInShiftRange($assignment->shift, $nowMinutes)) {
                if ((int) $group->assigned_user_id !== (int) $assignment->user_id) {
                    $this->telegramRepository->assignGroup($group, $assignment->user_id);
                }
                return;
            }
        }
    }

    /**
     * 判斷當前分鐘數是否在班別時段範圍內
     *
     * @param \App\Models\Shift $shift
     * @param int               $nowMinutes 當前時間（分鐘數）
     * @return bool
     */
    private function isTimeInShiftRange($shift, $nowMinutes)
    {
        $start = $shift->reply_start_time ?? $shift->start_time;
        $end = $shift->reply_end_time ?? $shift->end_time;

        $parts = explode(':', $start);
        $startMin = (int) $parts[0] * 60 + (int) $parts[1];

        $parts = explode(':', $end);
        $endMin = (int) $parts[0] * 60 + (int) $parts[1];

        if ($endMin > $startMin) {
            return $nowMinutes >= $startMin && $nowMinutes < $endMin;
        }

        // 跨日班
        return $nowMinutes >= $startMin || $nowMinutes < $endMin;
    }

    /**
     * 從 Telegram 下載檔案到本地
     *
     * @param string $fileId Telegram file_id
     * @param string $prefix 檔名前綴（photo / sticker）
     * @return string|null 本地公開 URL
     */
    /**
     * 解析影片／語音／音訊等 photo・sticker・document 以外的媒體
     *
     * Bot API 的 getFile 只能下載 20MB 以內的檔案，超過就抓不下來。
     * 抓不到時仍回傳結果（url 為 null），讓訊息本身還是進得了資料庫 ——
     * 客服至少要知道「客人傳了一段影片」，而不是整則訊息憑空消失。
     *
     * @param array $message Telegram message payload
     * @return array|null {type, url, name, fallback_text}
     */
    private function parseOtherMedia($message)
    {
        // Telegram 的 key => 內部 media_type。
        // 下載檔名前綴沿用 Telegram 的 key，替代文字統一查 MEDIA_LABELS
        $map = [
            'video'      => 'video',
            'animation'  => 'video',
            'video_note' => 'video',
            'voice'      => 'audio',
            'audio'      => 'audio',
        ];

        foreach ($map as $key => $type) {
            if (!isset($message[$key])) {
                continue;
            }

            $media = $message[$key];
            $fileId = $media['file_id'] ?? null;

            return [
                'type'          => $type,
                'url'           => filled($fileId) ? $this->downloadTelegramFile($fileId, $key) : null,
                'name'          => $media['file_name'] ?? null,
                'fallback_text' => $this->mediaLabel($key),
            ];
        }

        return null;
    }

    /**
     * 在訊息結尾附上送出者的 Telegram 署名
     *
     * 讓客戶知道是哪位客服回的。暱稱由管理者在帳號管理設定，
     * 沒設定就原樣送出 —— 這功能是逐一帳號開啟的。
     *
     * @param string   $content
     * @param int|null $userId
     * @return string
     */
    private function appendSignature($content, $userId)
    {
        if (!filled($userId)) {
            return $content;
        }

        $user = $this->telegramRepository->findSender($userId);
        $nickname = $user ? $user->telegram_nickname : null;

        if (!filled($nickname)) {
            return $content;
        }

        // 空內容（例如只傳圖片）也要署名，否則客戶不知道是誰傳的
        return filled($content) ? "{$content} -{$nickname}" : "-{$nickname}";
    }

    /**
     * 依 MIME 決定 document 要用哪種 media_type
     *
     * 瀏覽器不是每種音訊／影片格式都放得出來（例如 wmv、flac），
     * 只挑常見且 <audio> / <video> 普遍支援的，其餘維持 document 走下載。
     *
     * @param string|null $mimeType
     * @return string audio / video / document
     */
    private function documentMediaType($mimeType)
    {
        $playable = [
            'audio/wav', 'audio/x-wav', 'audio/wave',
            'audio/mpeg', 'audio/mp3',
            'audio/ogg', 'audio/opus',
            'audio/mp4', 'audio/aac', 'audio/webm',
            'video/mp4', 'video/webm', 'video/ogg',
        ];

        if (!filled($mimeType) || !in_array(strtolower($mimeType), $playable, true)) {
            return 'document';
        }

        return strpos(strtolower($mimeType), 'video/') === 0 ? 'video' : 'audio';
    }

    /**
     * 取媒體類型的替代文字
     *
     * @param string $key Telegram 的媒體 key 或內部 media_type
     * @return string
     */
    private function mediaLabel($key)
    {
        $labels = config('constants.TELEGRAM.MEDIA_LABELS');

        return $labels[$key] ?? $labels['default'];
    }

    private function downloadTelegramFile($fileId, $prefix)
    {
        $remoteUrl = $this->botService->getFileUrl($fileId);

        if (!filled($remoteUrl)) {
            // getFile 失敗最常見的原因是超過 Bot API 的 20MB 下載上限
            Log::warning('Telegram 取檔案位址失敗，可能超過 20MB 下載上限', [
                'file_id' => $fileId,
                'prefix'  => $prefix,
            ]);

            return null;
        }

        try {
            // 取得副檔名。Telegram 的 file_path 通常帶副檔名，沒有時依類型給預設值 ——
            // 一律 fallback 成 jpg 會讓影片／語音存成 .jpg 而播不出來
            $defaultExt = [
                'video'      => 'mp4',
                'animation'  => 'mp4',
                'video_note' => 'mp4',
                'voice'      => 'oga',
                'audio'      => 'mp3',
                'document'   => 'bin',
            ];

            $pathInfo = pathinfo(parse_url($remoteUrl, PHP_URL_PATH));
            $ext = $pathInfo['extension'] ?? ($defaultExt[$prefix] ?? 'jpg');
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
     * 合併 reactions（累加相同 emoji 的計數）
     *
     * @param array|null $existing    現有 reactions [{emoji: "👍", count: 1}, ...]
     * @param array      $newReactions Telegram 格式 [{type: "emoji", emoji: "👍"}, ...]
     * @return array
     */
    private function mergeReactions($existing, $newReactions)
    {
        $map = [];

        // 載入現有
        if (filled($existing)) {
            foreach ($existing as $r) {
                $map[$r['emoji']] = $r['count'] ?? 1;
            }
        }

        // 合併新的（Telegram reaction 是替換邏輯，不是累加）
        // message_reaction 的 new_reaction 代表該使用者目前的全部 reaction
        // 簡化處理：將新的 emoji 計數設為 1（若已存在則保留較大值）
        foreach ($newReactions as $r) {
            $emoji = $r['emoji'] ?? null;
            if (!filled($emoji)) {
                continue;
            }

            if (isset($map[$emoji])) {
                $map[$emoji] = $map[$emoji] + 1;
            } else {
                $map[$emoji] = 1;
            }
        }

        // 轉回陣列格式
        $result = [];
        foreach ($map as $emoji => $count) {
            $result[] = ['emoji' => $emoji, 'count' => $count];
        }

        return count($result) > 0 ? $result : null;
    }

    /**
     * 替換 Bot 的 reaction（覆蓋邏輯）
     *
     * Bot 一次只能對一則訊息有一個 reaction，
     * 移除 Bot 上次的 reaction（count=1 且由 Bot 設定的），換成新的。
     * 保留其他來源（Telegram 用戶）的 reaction 不動。
     *
     * @param array|null $existing 現有 reactions
     * @param string     $emoji    新的 emoji
     * @return array
     */
    private function replaceBotReaction($existing, $emoji)
    {
        // 目前無法區分 Bot vs 使用者的 reaction，
        // 直接以「覆蓋」方式處理：只保留新的 emoji
        $result = [['emoji' => $emoji, 'count' => 1]];

        return $result;
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

    /**
     * 從文件區傳送檔案到 Telegram 群組
     *
     * @param int         $groupId
     * @param int         $fileId
     * @param string|null $caption
     * @param int|null    $senderId
     * @return bool
     */
    public function sendDocumentFromSharedFile($groupId, $fileId, $caption = null, $senderId = null)
    {
        $group = $this->telegramRepository->findGroup($groupId);
        if (!$group) {
            return false;
        }

        $file = $this->sharedFileRepository->findFile($fileId);
        if (!$file) {
            return false;
        }

        $this->switchBotToken($group);

        $diskPath = Storage::disk('public')->path($file->file_path);
        if (!file_exists($diskPath)) {
            Log::warning('sendDocument: 檔案不存在', ['path' => $diskPath]);

            return false;
        }

        $caption = $this->appendSignature($caption, $senderId);
        $result = $this->botService->sendDocument($group->chat_id, $diskPath, $file->original_name, $caption);

        if ($result && isset($result['ok']) && $result['ok']) {
            $sender = $senderId ? $this->telegramRepository->findSender($senderId) : null;
            $msgId = $result['result']['message_id'] ?? null;

            $this->telegramRepository->createMessage([
                'telegram_group_id'   => $group->id,
                'direction'           => config('constants.TELEGRAM.DIRECTION.OUTBOUND'),
                'telegram_message_id' => $msgId,
                'sender_name'         => $sender ? $sender->nickname : '系統',
                'sender_user_id'      => $senderId,
                // content 只放說明文字。檔名由 media_name 提供，前端的檔案卡片直接讀它
                'content'             => $caption ?: '',
                'media_type'          => 'document',
                'media_url'           => asset("storage/{$file->file_path}"),
                'media_name'          => $file->original_name,
                'replied'             => true,
            ]);

            return true;
        }

        return false;
    }

    /**
     * 客服直接從輸入區上傳的檔案送到 Telegram 群組
     *
     * 與 sendDocumentFromSharedFile() 的差別：那支是從文件區挑既有檔案，
     * 這支收的是當下上傳、只為了這次對話存下來的檔案。
     *
     * @param int         $groupId
     * @param string      $filePath     storage/app/public 底下的相對路徑
     * @param string      $originalName 客戶端看到的檔名
     * @param string|null $caption
     * @param int         $userId
     * @param string      $nickname
     * @return \App\Models\TelegramMessage
     */
    public function sendFileReply($groupId, $filePath, $originalName, $caption, $userId, $nickname)
    {
        $group = $this->telegramRepository->findGroup($groupId);

        $this->switchBotToken($group);

        $diskPath = Storage::disk('public')->path($filePath);
        if (!file_exists($diskPath)) {
            Log::error('Telegram 檔案發送失敗：上傳後找不到檔案', [
                'group_id' => $groupId,
                'path'     => $diskPath,
            ]);

            throw new \RuntimeException(trans('telegram_chat.msg.file_send_failed'));
        }

        $caption = $this->appendSignature($caption, $userId);
        $result = $this->botService->sendDocument($group->chat_id, $diskPath, $originalName, $caption);

        // Telegram 沒收到就不要留下「已送出」的紀錄，否則客服會以為傳送成功
        if (!$result || empty($result['ok'])) {
            Log::error('Telegram 檔案發送失敗，不寫入訊息紀錄', [
                'group_id' => $group->id,
                'chat_id'  => $group->chat_id,
                'filename' => $originalName,
            ]);

            throw new \RuntimeException(trans('telegram_chat.msg.file_send_failed'));
        }

        $msg = $this->telegramRepository->createMessage([
            'telegram_group_id'   => $group->id,
            'direction'           => config('constants.TELEGRAM.DIRECTION.OUTBOUND'),
            'telegram_message_id' => $result['result']['message_id'] ?? null,
            'sender_name'         => $nickname,
            'sender_user_id'      => $userId,
            'content'             => $caption ?: '',
            'media_type'          => 'document',
            'media_url'           => asset("storage/{$filePath}"),
            'media_name'          => $originalName,
            'replied'             => true,
        ]);

        $this->telegramRepository->markMessagesReplied($group->id);

        // 廣播失敗不影響已送出的訊息，前端還有輪詢可以補上
        try {
            event(new \App\Events\TelegramMessageReceived($group->id, [
                'id'          => $msg->id,
                'direction'   => $msg->direction,
                'sender_name' => $msg->sender_name,
                'content'     => $msg->content,
                'media_type'  => $msg->media_type,
                'media_url'   => $msg->media_url,
                'media_name'  => $msg->media_name,
                'created_at'  => $msg->created_at->toDateTimeString(),
                'group_id'    => $group->id,
                'group_title' => $group->title,
            ]));
        } catch (\Exception $e) {
            Log::error('Telegram 檔案訊息廣播失敗', [
                'group_id' => $group->id,
                'error'    => $e->getMessage(),
            ]);
        }

        return $msg;
    }

    /**
     * 刪除群組對話紀錄
     *
     * @param int $groupId
     * @return int 刪除筆數
     */
    public function deleteConversation($groupId)
    {
        $count = $this->telegramRepository->deleteMessagesByGroup($groupId);

        $group = $this->telegramRepository->findGroup($groupId);
        if ($group) {
            $this->telegramRepository->deleteGroup($group);
        }

        return $count;
    }
}
