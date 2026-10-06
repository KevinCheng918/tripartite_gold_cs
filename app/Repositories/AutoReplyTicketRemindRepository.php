<?php

namespace App\Repositories;

use App\Models\AutoReplyTicketRemind;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 求助單提醒紀錄 Repository
 *
 * 寫入是「一次提醒 → 多列」（tag 到幾個人就幾列），
 * 讀取是每日統計的兩個維度：按單、按人。
 */
class AutoReplyTicketRemindRepository
{
    /**
     * 記下一次提醒
     *
     * ⚠ 一次提醒會寫多列（每個被 tag 的人一列），所以用 `insert` 批次寫 ——
     * 逐列 `create()` 在一次 tag 五個人時就是五趟來回。
     *
     * ⚠ `$userIds` 是空的時候仍要寫一列（`user_id` = null）：
     * 那代表「催了但沒人被叫到」，不記的話統計會看起來像那次提醒沒發生。
     *
     * @param int   $ticketId
     * @param int   $seq     這張單的第幾次提醒
     * @param array $userIds 被 tag 到的 user.id
     * @param int   $stage   見 constants.AUTO_REPLY.REMIND.STAGE
     * @return void
     */
    public function record($ticketId, $seq, array $userIds, $stage)
    {
        $now = now();
        $rows = [];

        // insert 不經過 Model，timestamps 要自己帶
        $base = [
            'auto_reply_ticket_id' => $ticketId,
            'seq'                  => $seq,
            'stage'                => $stage,
            'created_at'           => $now,
            'updated_at'           => $now,
        ];

        if (blank($userIds)) {
            $rows[] = array_merge($base, ['user_id' => null]);
        }

        foreach (array_unique($userIds) as $userId) {
            $rows[] = array_merge($base, ['user_id' => (int) $userId]);
        }

        AutoReplyTicketRemind::query()->insert($rows);
    }

    /**
     * 某一天的提醒紀錄（按人統計用）
     *
     * 回傳的是「每個人被催幾次」—— 同一次提醒 tag 五個人就是五個人各一次。
     *
     * ⚠ 用 `user_id` 分組而不是 join `user` 表取暱稱：帳號被刪時
     * `user_id` 會變 null（nullOnDelete），join 進來那幾列會整個消失。
     * 暱稱由呼叫端另外查，查不到的就是離職帳號。
     *
     * @param string $date Y-m-d
     * @return Collection<object{user_id:int|null, times:int, tickets:int}>
     */
    public function countByUserForDate($date)
    {
        return AutoReplyTicketRemind::query()
            ->selectRaw('user_id, COUNT(*) as times, COUNT(DISTINCT auto_reply_ticket_id) as tickets')
            ->whereBetween('created_at', $this->dayRange($date))
            ->groupBy('user_id')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->get();
    }

    /**
     * 某一天被提醒過的求助單（按單統計用）
     *
     * `times` 是「那一天催了幾次」，可能小於 `ticket.remind_count`
     * ——跨夜的單前一天就被催過了。
     *
     * @param string $date Y-m-d
     * @return Collection
     */
    public function getTicketsForDate($date)
    {
        return AutoReplyTicketRemind::query()
            ->selectRaw('auto_reply_ticket_id, COUNT(DISTINCT seq) as times, MAX(stage) as max_stage')
            ->with('ticket.group')
            ->whereBetween('created_at', $this->dayRange($date))
            ->groupBy('auto_reply_ticket_id')
            ->orderByDesc(DB::raw('COUNT(DISTINCT seq)'))
            ->get();
    }

    /**
     * 一天的起訖時間
     *
     * ⚠ 用區間而不是 `whereDate()`：後者等於 `DATE(created_at) = ?`，
     * 欄位被函式包住就吃不到 `created_at` 的索引，這張表會一直長大。
     *
     * @param string $date Y-m-d
     * @return array [起, 訖]
     */
    private function dayRange($date)
    {
        $start = Carbon::parse($date)->startOfDay();

        return [$start, $start->copy()->endOfDay()];
    }
}
