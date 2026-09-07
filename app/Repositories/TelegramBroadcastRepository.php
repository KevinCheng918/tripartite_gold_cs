<?php

namespace App\Repositories;

use App\Models\TelegramBroadcast;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Telegram 群發公告 Repository
 */
class TelegramBroadcastRepository
{
    /**
     * 分頁查詢（依發送時間倒序）
     *
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function paginate($perPage = 20)
    {
        return TelegramBroadcast::query()
            ->select(['id', 'content', 'target_type', 'target_group_ids', 'send_results', 'status', 'scheduled_at', 'image_urls', 'total_count', 'success_count', 'fail_count', 'sender_id', 'sent_at', 'created_at'])
            ->with('sender')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * 取出已到期的預約公告
     *
     * 用 `<=` 而非等於：排程每分鐘才跑一次，
     * 若機器停過一段時間，落後的預約也要在下次執行時補送出去。
     *
     * @param \Carbon\Carbon $now
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getDueScheduled($now)
    {
        return TelegramBroadcast::query()
            ->select(['id', 'content', 'target_type', 'target_group_ids', 'status', 'scheduled_at', 'image_urls', 'sender_id'])
            ->where('status', TelegramBroadcast::STATUS_PENDING)
            ->where('scheduled_at', '<=', $now)
            ->orderBy('scheduled_at')
            ->get();
    }

    /**
     * 依 ID 取單筆（供取消預約使用）
     *
     * @param int $id
     * @return TelegramBroadcast|null
     */
    public function find($id)
    {
        return TelegramBroadcast::query()
            ->select(['id', 'status', 'scheduled_at', 'sender_id'])
            ->find($id);
    }

    /**
     * 新增
     *
     * @param array $attributes
     * @return TelegramBroadcast
     */
    public function create($attributes)
    {
        return TelegramBroadcast::query()->create($attributes);
    }

    /**
     * 更新
     *
     * @param TelegramBroadcast $broadcast
     * @param array             $attributes
     * @return TelegramBroadcast
     */
    public function update(TelegramBroadcast $broadcast, $attributes)
    {
        $broadcast->update($attributes);

        return $broadcast;
    }
}
