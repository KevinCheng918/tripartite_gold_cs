<?php

namespace App\Repositories;

use App\Models\TelegramGroup;
use App\Models\TelegramMessage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Telegram Repository
 *
 * 負責 telegram_group 和 telegram_message 表的所有 DB 操作。
 */
class TelegramRepository
{
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
            ->select(['id', 'chat_id', 'title', 'status', 'assigned_user_id', 'last_message_at'])
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
        return TelegramGroup::query()->where('chat_id', $chatId)->first();
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
            ->select(['id', 'chat_id', 'title', 'status', 'assigned_user_id', 'last_message_at'])
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
            ->select(['id', 'telegram_group_id', 'direction', 'sender_name', 'sender_user_id', 'content', 'replied', 'created_at'])
            ->where('telegram_group_id', $groupId)
            ->orderByDesc('created_at')
            ->paginate($perPage);
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
