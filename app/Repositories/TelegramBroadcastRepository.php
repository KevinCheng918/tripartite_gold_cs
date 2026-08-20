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
            ->select(['id', 'content', 'target_type', 'target_group_ids', 'send_results', 'total_count', 'success_count', 'fail_count', 'sender_id', 'sent_at', 'created_at'])
            ->with('sender')
            ->orderByDesc('id')
            ->paginate($perPage);
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
