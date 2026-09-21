<?php

namespace App\Repositories;

use App\Models\TelegramGroup;
use App\Models\TelegramMessage;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Telegram Repository
 *
 * 負責 telegram_group 和 telegram_message 表的所有 DB 操作。
 */
class TelegramRepository
{
    /** @var array 群組欄位（含自動回覆開關與狀態） */
    private const GROUP_COLUMNS = [
        'id', 'chat_id', 'title', 'status', 'assigned_user_id', 'last_message_at',
        'auto_reply', 'auto_reply_at', 'auto_reply_item_id', 'auto_reply_pending',
    ];

    // ---------------------------------------------------------------
    //  群組
    // ---------------------------------------------------------------

    /**
     * 取得所有啟用中的群組（依最後訊息時間倒序）
     *
     * @return Collection
     */
    public function getActiveGroups()
    {
        return TelegramGroup::query()
            ->select(self::GROUP_COLUMNS)
            ->with('assignedUser')
            ->where('status', config('constants.TELEGRAM.GROUP_STATUS.ACTIVE'))
            ->orderByDesc('last_message_at')
            ->get();
    }

    /**
     * 取得所有群組（供站台選擇用）
     *
     * @return Collection
     */
    public function getAllGroups()
    {
        return TelegramGroup::query()
            ->select(['id', 'chat_id', 'title', 'status'])
            ->orderBy('title')
            ->get();
    }

    /**
     * 依 Telegram chat_id 查詢群組
     *
     * @param int $chatId
     * @return TelegramGroup|null
     */
    public function findGroupByChatId($chatId)
    {
        return TelegramGroup::query()
            ->select(self::GROUP_COLUMNS)
            ->where('chat_id', $chatId)
            ->first();
    }

    /**
     * 依 ID 查詢群組
     *
     * @param int $id
     * @return TelegramGroup|null
     */
    public function findGroup($id)
    {
        return TelegramGroup::query()
            ->select(self::GROUP_COLUMNS)
            ->with('assignedUser')
            ->find($id);
    }

    /**
     * 新增群組
     *
     * @param array $attributes
     * @return TelegramGroup
     */
    public function createGroup($attributes)
    {
        return TelegramGroup::query()->create($attributes);
    }

    /**
     * 更新群組
     *
     * @param TelegramGroup $group
     * @param array         $attributes
     * @return TelegramGroup
     */
    public function updateGroup(TelegramGroup $group, $attributes)
    {
        $group->update($attributes);

        return $group;
    }

    /**
     * 切換自動回覆開關
     *
     * @param TelegramGroup $group
     * @param bool          $enabled
     * @return TelegramGroup
     */
    public function setAutoReply(TelegramGroup $group, $enabled)
    {
        $group->update(['auto_reply' => $enabled]);

        return $group->refresh();
    }

    /**
     * 記錄本次自動回覆的狀態
     *
     * auto_reply_at 同時服務三件事：問候語要用完整版還是精簡版、
     * 「稍等」的冷卻、以及反問後等客人回答的時限。
     *
     * @param TelegramGroup $group
     * @param int|null      $itemId  命中的題庫 id；null 代表這次回的是「稍等」
     * @param string|null   $pending 反問中的候選 id（逗號分隔）；null 代表沒有在等回答
     * @return TelegramGroup
     */
    public function updateAutoReplyState(TelegramGroup $group, $itemId, $pending = null)
    {
        $group->update([
            'auto_reply_at'      => now(),
            'auto_reply_item_id' => $itemId,
            'auto_reply_pending' => $pending,
        ]);

        return $group->refresh();
    }

    /**
     * 清掉反問的等待狀態
     *
     * 客人答了、或超過時限當成全新問題時呼叫。
     *
     * @param TelegramGroup $group
     * @return TelegramGroup
     */
    public function clearAutoReplyPending(TelegramGroup $group)
    {
        $group->update(['auto_reply_pending' => null]);

        return $group->refresh();
    }

    /**
     * 刪除群組
     *
     * @param TelegramGroup $group
     * @return void
     */
    public function deleteGroup(TelegramGroup $group)
    {
        $group->delete();
    }

    /**
     * 指派值班客服
     *
     * @param TelegramGroup $group
     * @param int           $userId
     * @return TelegramGroup
     */
    public function assignGroup(TelegramGroup $group, $userId)
    {
        return $this->updateGroup($group, ['assigned_user_id' => $userId]);
    }

    // ---------------------------------------------------------------
    //  訊息
    // ---------------------------------------------------------------

    /**
     * 取得群組訊息（分頁，依時間正序）
     *
     * @param int $groupId
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getMessagesByGroup($groupId, $perPage = 50)
    {
        return TelegramMessage::query()
            // reply_to_* 漏掉會讓引用回覆永遠顯示不出來（欄位有值也讀不到）
            ->select(['id', 'telegram_group_id', 'direction', 'telegram_message_id', 'sender_name', 'sender_user_id', 'content', 'edited_at', 'reply_to_sender', 'reply_to_text', 'media_type', 'media_url', 'media_name', 'reactions', 'replied', 'created_at'])
            ->where('telegram_group_id', $groupId)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * 取送出者（暱稱與 Telegram 署名）
     *
     * 兩個欄位一起撈：署名用於訊息結尾，nickname 存進 sender_name。
     *
     * @param int $userId
     * @return User|null
     */
    public function findSender($userId)
    {
        return User::query()
            ->select(['id', 'nickname', 'telegram_nickname'])
            ->find($userId);
    }

    /**
     * 新增訊息並更新群組的最後訊息時間
     *
     * @param array $attributes
     * @return TelegramMessage
     */
    public function createMessage($attributes)
    {
        $message = TelegramMessage::query()->create($attributes);

        // 更新群組最後訊息時間
        TelegramGroup::query()
            ->where('id', $attributes['telegram_group_id'])
            ->update(['last_message_at' => now()]);

        return $message;
    }

    /**
     * 將群組內所有未回覆的 inbound 訊息標記為已回覆
     *
     * @param int $groupId
     * @return int 更新筆數
     */
    public function markMessagesReplied($groupId)
    {
        return TelegramMessage::query()
            ->where('telegram_group_id', $groupId)
            ->where('direction', config('constants.TELEGRAM.DIRECTION.INBOUND'))
            ->where('replied', false)
            ->update(['replied' => true]);
    }

    /**
     * 依 ID 查詢訊息（含群組關聯）
     *
     * @param int $id
     * @return TelegramMessage|null
     */
    public function findMessageWithGroup($id)
    {
        return TelegramMessage::query()
            ->select(['id', 'telegram_group_id', 'telegram_message_id', 'reactions'])
            ->with(['group:id,chat_id'])
            ->find($id);
    }

    /**
     * 依群組 chat_id 和 Telegram message_id 查詢訊息
     *
     * @param int $chatId           Telegram chat_id
     * @param int $telegramMsgId    Telegram message_id
     * @return TelegramMessage|null
     */
    public function findByTelegramMessageId($chatId, $telegramMsgId)
    {
        return TelegramMessage::query()
            ->select(['id', 'telegram_group_id', 'telegram_message_id', 'reactions'])
            ->whereHas('group', function ($q) use ($chatId) {
                $q->where('chat_id', $chatId);
            })
            ->where('telegram_message_id', $telegramMsgId)
            ->first();
    }

    /**
     * 取被引用的訊息（客服引用回覆用）
     *
     * 前端傳的是後台的訊息 id，要換成 Telegram 那邊的 message_id 才送得出去；
     * 同時取回發送者與內容，寫進新訊息的 reply_to_* 供後台顯示。
     *
     * @param int $groupId
     * @param int $messageId 後台的訊息 id
     * @return TelegramMessage|null
     */
    public function findQuoted($groupId, $messageId)
    {
        return TelegramMessage::query()
            ->select(['id', 'telegram_group_id', 'telegram_message_id', 'sender_name', 'content'])
            ->where('telegram_group_id', $groupId)
            ->where('id', $messageId)
            ->first();
    }

    /**
     * 依 chat_id 與 telegram_message_id 取訊息（處理編輯事件用）
     *
     * 與 findByTelegramMessageId() 分開：那支只為了改 reactions，
     * select 沒有 content，拿來寫編輯內容會把其他欄位當成沒載入。
     *
     * @param int $chatId
     * @param int $telegramMsgId
     * @return TelegramMessage|null
     */
    public function findMessageForEdit($chatId, $telegramMsgId)
    {
        return TelegramMessage::query()
            ->select(['id', 'telegram_group_id', 'telegram_message_id', 'direction', 'content', 'edited_at'])
            ->whereHas('group', function ($q) use ($chatId) {
                $q->where('chat_id', $chatId);
            })
            ->where('telegram_message_id', $telegramMsgId)
            ->first();
    }

    /**
     * 更新訊息內容（編輯事件用）
     *
     * @param TelegramMessage $message
     * @param array           $attributes
     * @return void
     */
    public function updateMessage(TelegramMessage $message, $attributes)
    {
        $message->update($attributes);
    }

    /**
     * 更新訊息的 reactions
     *
     * @param TelegramMessage $message
     * @param array|null      $reactions
     * @return TelegramMessage
     */
    public function updateReactions(TelegramMessage $message, $reactions)
    {
        $message->update(['reactions' => $reactions]);

        return $message;
    }

    /**
     * 刪除超過指定天數的訊息
     *
     * @param int $days
     * @return int 刪除筆數
     */
    public function deleteOlderThan($days)
    {
        return TelegramMessage::query()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }

    /**
     * 刪除群組所有訊息
     *
     * @param int $groupId
     * @return int 刪除筆數
     */
    public function deleteMessagesByGroup($groupId)
    {
        return TelegramMessage::query()
            ->where('telegram_group_id', $groupId)
            ->delete();
    }

    /**
     * 取得超過指定分鐘未回覆的 inbound 訊息（告警用）
     *
     * @param int $minutes
     * @return Collection
     */
    public function getUnrepliedMessages($minutes)
    {
        return TelegramMessage::query()
            ->select(['id', 'telegram_group_id', 'sender_name', 'content', 'created_at'])
            ->with(['group:id,chat_id,title'])
            ->where('direction', config('constants.TELEGRAM.DIRECTION.INBOUND'))
            ->where('replied', false)
            ->where('created_at', '<', now()->subMinutes($minutes))
            ->get();
    }

    /**
     * 取得群組內未回覆 inbound 訊息數量
     *
     * @param int $groupId
     * @return int
     */
    public function getUnrepliedCount($groupId)
    {
        return TelegramMessage::query()
            ->where('telegram_group_id', $groupId)
            ->where('direction', config('constants.TELEGRAM.DIRECTION.INBOUND'))
            ->where('replied', false)
            ->count();
    }
}
