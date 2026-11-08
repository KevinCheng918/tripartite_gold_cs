<?php

namespace App\Repositories;

use App\Models\ShiftHandover;
use Illuminate\Database\Eloquent\Collection;

/**
 * 交接紀錄 Repository
 *
 * 一個交接窗口一列。寫入只有三種：建窗口、記交接、記催過一次。
 */
class ShiftHandoverRepository
{
    /** @var array 欄位 */
    private const COLUMNS = [
        'id', 'date', 'from_shift_id', 'to_shift_id', 'window_start', 'window_end',
        'handed_at', 'handed_by_user_id', 'handed_by_name', 'handed_message_id',
        'remind_count', 'last_reminded_at', 'notified_next_at', 'status',
    ];

    /**
     * 取這個窗口的紀錄，沒有就建一筆
     *
     * ⚠ 排程每分鐘跑，所以一定要 `firstOrCreate` 而不是 `create` ——
     * 三欄的 unique 索引擋得住重複，但靠例外來處理正常流程會洗出一堆
     * SQL 錯誤 log。
     *
     * @param array $keys   date / from_shift_id / to_shift_id
     * @param array $values 其餘欄位（窗口時間、初始狀態）
     * @return ShiftHandover
     */
    public function firstOrCreateWindow(array $keys, array $values)
    {
        return ShiftHandover::query()->firstOrCreate($keys, $values);
    }

    /**
     * 更新一筆
     *
     * @param ShiftHandover $handover
     * @param array         $attributes
     * @return ShiftHandover
     */
    public function update(ShiftHandover $handover, array $attributes)
    {
        $handover->update($attributes);

        return $handover;
    }

    /**
     * 還沒交接、而且已經該開始催的那幾筆
     *
     * ⚠ **沒有「窗口結束就不撈」的條件**（需求方 2026-11-08：「就是催到他
     * 完成交接事項」）。所以這支撈得到的是「從開始提醒到完成之間」的全部，
     * 包含昨天忘記交接的那一筆 —— 那是刻意的。
     *
     * ⚠ 間隔從 `last_reminded_at` 算，所以是「每隔 N 分鐘」而不是
     * 「開始後第 N 分鐘」。還沒催過的（`remind_count` = 0）只看
     * `window_start - lead` 到了沒。
     *
     * @param int $leadMinutes     提前幾分鐘開始催
     * @param int $intervalMinutes 之後每隔幾分鐘再催
     * @return Collection<ShiftHandover>
     */
    public function getDueForRemind($leadMinutes, $intervalMinutes)
    {
        $now = now();

        return ShiftHandover::query()
            ->select(self::COLUMNS)
            ->with(['fromShift', 'toShift'])
            ->where('status', config('constants.SHIFT_HANDOVER_TOPIC.STATUS.PENDING'))
            ->where(function ($query) use ($now, $leadMinutes, $intervalMinutes) {
                // 還沒催過：提前 lead 分鐘開始
                $query->where(function ($first) use ($now, $leadMinutes) {
                    $first->where('remind_count', 0)
                        ->where('window_start', '<=', $now->copy()->addMinutes($leadMinutes));
                })
                // 催過了：從上次催的時間起算
                ->orWhere(function ($again) use ($now, $intervalMinutes) {
                    $again->where('remind_count', '>', 0)
                        ->whereNotNull('last_reminded_at')
                        ->where('last_reminded_at', '<=', $now->copy()->subMinutes($intervalMinutes));
                })
                /*
                 * 催過了卻沒有 last_reminded_at —— 不該發生，但真的發生時
                 * 這筆會永遠撈不到、永遠不再被催。寧可當成「該催了」。
                 * 比照 `ConversationFollowupRepository::getDueForAsk()`。
                 */
                ->orWhere(function ($broken) {
                    $broken->where('remind_count', '>', 0)->whereNull('last_reminded_at');
                });
            })
            ->orderBy('window_start')
            ->get();
    }

    /**
     * 上一班沒人、而且還沒跟下一班講過的那幾筆
     *
     * @param int $leadMinutes 提前幾分鐘通知
     * @return Collection<ShiftHandover>
     */
    public function getNoStaffPendingNotice($leadMinutes)
    {
        return ShiftHandover::query()
            ->select(self::COLUMNS)
            ->with(['fromShift', 'toShift'])
            ->where('status', config('constants.SHIFT_HANDOVER_TOPIC.STATUS.NO_STAFF'))
            ->whereNull('notified_next_at')
            ->where('window_start', '<=', now()->copy()->addMinutes($leadMinutes))
            ->orderBy('window_start')
            ->get();
    }

    /**
     * 現在正在進行中（或已經開始）而且還沒交接的那一筆
     *
     * 給「有人在交接話題發言」時用：要把那句話算到哪一個窗口上。
     *
     * ⚠ **取 `window_start` 最晚、但已經開始提醒的那一筆** —— 同時可能有
     * 兩筆未交接（昨天忘了、今天又到了），而他現在打的交接事項講的是
     * **他這一班**的狀況，所以要算在最新那一筆上。
     *
     * @param array $shiftIds    這個人今天（與昨天）排到的班別 id
     * @param int   $leadMinutes 提前幾分鐘就算在窗口內
     * @return ShiftHandover|null
     */
    public function findPendingForShifts(array $shiftIds, $leadMinutes)
    {
        if (blank($shiftIds)) {
            return null;
        }

        return ShiftHandover::query()
            ->select(self::COLUMNS)
            ->with(['fromShift', 'toShift'])
            ->where('status', config('constants.SHIFT_HANDOVER_TOPIC.STATUS.PENDING'))
            ->whereIn('from_shift_id', $shiftIds)
            ->where('window_start', '<=', now()->copy()->addMinutes($leadMinutes))
            ->orderByDesc('window_start')
            ->first();
    }

    /**
     * 最近一筆已完成、而且留得下引用的交接
     *
     * 「上一班沒人」那則要引用它（`handed_message_id` 當 `reply_to`）。
     *
     * ⚠ 刻意**不限定班別**：需求方說的是「昨天晚班的交接」，但那是大夜班
     * 沒人時的現況 —— 規則綁死班別名稱的話，哪天大夜班補了人、或哪天
     * 午班沒人，同一套邏輯就錯了。「最近一筆」永遠是對的那一份。
     *
     * @return ShiftHandover|null
     */
    public function findLatestHanded()
    {
        return ShiftHandover::query()
            ->select(self::COLUMNS)
            ->with('fromShift')
            ->where('status', config('constants.SHIFT_HANDOVER_TOPIC.STATUS.HANDED'))
            ->whereNotNull('handed_message_id')
            ->orderByDesc('handed_at')
            ->first();
    }
}
