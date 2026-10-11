<?php

namespace App\Repositories;

use App\Models\ConversationFollowupRemind;

/**
 * 待追蹤事項的提醒紀錄 Repository
 *
 * 寫入是「一次提醒 → 多列」（tag 到幾個人就幾列），
 * 讀取是統計的兩個維度：按事項、按人。
 */
class ConversationFollowupRemindRepository
{
    /**
     * 記下一次提醒
     *
     * ⚠ 一次提醒會寫多列（每個被 tag 的人一列），所以用 `insert` 批次寫 ——
     * 逐列 `create()` 在一次 tag 五個人時就是五趟來回。
     *
     * ⚠ `$userIds` 是空的時候仍要寫一列（`user_id` = null）：
     * 那代表「問了但沒有人被叫到」，不記的話統計會看起來像那次沒發生。
     *
     * @param int   $followupId
     * @param int   $seq     這筆事項的第幾次提醒
     * @param array $userIds 被 tag 到的 user.id
     * @param int   $stage   見 constants.AUTO_REPLY.REMIND.STAGE
     * @return void
     */
    public function record($followupId, $seq, array $userIds, $stage)
    {
        $now = now();

        // insert 不經過 Model，timestamps 要自己帶
        $base = [
            'conversation_followup_id' => $followupId,
            'seq'                      => $seq,
            'stage'                    => $stage,
            'created_at'               => $now,
            'updated_at'               => $now,
        ];

        $rows = [];

        if (blank($userIds)) {
            $rows[] = array_merge($base, ['user_id' => null]);
        }

        foreach (array_unique($userIds) as $userId) {
            $rows[] = array_merge($base, ['user_id' => (int) $userId]);
        }

        ConversationFollowupRemind::query()->insert($rows);
    }

    /**
     * 某一段期間「沒有人被 tag 到」的次數
     *
     * ⚠ 這是統計裡最該被看見的數字：問了，但那個時段沒有人在班 ——
     * 事項就卡在沒有人負責的狀態。
     *
     * @param string $startDate Y-m-d
     * @param string $endDate   Y-m-d（含當天）
     * @return int
     */
    public function countNoMention($startDate, $endDate)
    {
        return ConversationFollowupRemind::query()
            ->whereNull('user_id')
            /*
             * 用 whereBetween 而不是 whereDate：後者等於
             * `DATE(created_at) = ?`，欄位被函式包住就吃不到索引。
             */
            ->whereBetween('created_at', [
                "{$startDate} 00:00:00",
                "{$endDate} 23:59:59",
            ])
            ->count();
    }

    /**
     * 某一段期間每個人被問了幾次
     *
     * ⚠ 用 `user_id` 分組而不是 join `user` 表取暱稱：帳號被刪時
     * `user_id` 會變 null（nullOnDelete），join 進來那幾列會整個消失。
     * 暱稱由呼叫端另外查。
     *
     * @param string $startDate Y-m-d
     * @param string $endDate   Y-m-d（含當天）
     * @return \Illuminate\Support\Collection<object{user_id:int|null, times:int, followups:int}>
     */
    public function countByUserForRange($startDate, $endDate)
    {
        return ConversationFollowupRemind::query()
            ->selectRaw('user_id, COUNT(*) AS times, COUNT(DISTINCT conversation_followup_id) AS followups')
            ->whereNotNull('user_id')
            ->whereBetween('created_at', [
                "{$startDate} 00:00:00",
                "{$endDate} 23:59:59",
            ])
            ->groupBy('user_id')
            ->orderByDesc('times')
            ->get();
    }
}
