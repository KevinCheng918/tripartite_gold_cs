<?php

namespace App\Repositories;

use App\Models\ScheduledReminder;
use Illuminate\Database\Eloquent\Collection;

/**
 * 預約提醒 Repository
 */
class ScheduledReminderRepository
{
    /** @var array 查詢欄位 */
    private const COLUMNS = [
        'id', 'chat_id', 'message_thread_id', 'content', 'remind_at',
        'status', 'sent_at', 'created_by', 'created_by_telegram_id', 'created_at',
    ];

    /**
     * @param array $attributes
     * @return ScheduledReminder
     */
    public function create(array $attributes)
    {
        return ScheduledReminder::query()->create($attributes);
    }

    /**
     * @param int $id
     * @return ScheduledReminder|null
     */
    public function find($id)
    {
        return ScheduledReminder::query()->select(self::COLUMNS)->find($id);
    }

    /**
     * 該發送的提醒（排程用）
     *
     * ⚠ **沒有下界**：排程掛掉幾小時之後補跑，那幾筆仍然要發出去 ——
     * 「晚了半小時的提醒」比「沒有提醒」有用得多。
     *
     * ⚠ 只撈待發送的。已取消的留在表裡是為了查得到「這筆被誰取消了」。
     *
     * @return Collection<ScheduledReminder>
     */
    public function getDue()
    {
        return ScheduledReminder::query()
            ->select(self::COLUMNS)
            ->where('status', config('constants.REMINDER.STATUS.PENDING'))
            ->where('remind_at', '<=', now())
            ->orderBy('remind_at')
            ->get();
    }

    /**
     * 某個話題裡還沒發的提醒（清單指令用）
     *
     * ⚠ 用 `chat_id` ＋ `message_thread_id` 一起篩，不是只看話題 ——
     * 不同群組的話題 id 會重號。
     *
     * @param int      $chatId
     * @param int|null $threadId
     * @param int      $limit
     * @return Collection<ScheduledReminder>
     */
    public function getPendingInThread($chatId, $threadId, $limit)
    {
        return ScheduledReminder::query()
            ->select(self::COLUMNS)
            ->where('status', config('constants.REMINDER.STATUS.PENDING'))
            ->where('chat_id', $chatId)
            // null 的話題是群組主區，要用 whereNull 才比得到
            ->where(function ($query) use ($threadId) {
                blank($threadId)
                    ? $query->whereNull('message_thread_id')
                    : $query->where('message_thread_id', $threadId);
            })
            ->orderBy('remind_at')
            ->limit($limit)
            ->get();
    }

    /**
     * 標記成已發送
     *
     * ⚠ 狀態條件寫在 where 裡：排程跑的同時可能有人正按取消，
     * 先查後寫會把「已取消」蓋回「已發送」。
     *
     * @param int $id
     * @return int 實際更新幾筆
     */
    public function markSent($id)
    {
        return ScheduledReminder::query()
            ->where('id', $id)
            ->where('status', config('constants.REMINDER.STATUS.PENDING'))
            ->update([
                'status'     => config('constants.REMINDER.STATUS.SENT'),
                'sent_at'    => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * 取消
     *
     * ⚠ 同上：條件帶在 where 裡。回 0 代表這筆剛剛已經發出去了 ——
     * 呼叫端要據此回「來不及了」，而不是說「已取消」。
     *
     * @param int $id
     * @return int 實際更新幾筆
     */
    public function cancel($id)
    {
        return ScheduledReminder::query()
            ->where('id', $id)
            ->where('status', config('constants.REMINDER.STATUS.PENDING'))
            ->update([
                'status'     => config('constants.REMINDER.STATUS.CANCELLED'),
                'updated_at' => now(),
            ]);
    }
}
