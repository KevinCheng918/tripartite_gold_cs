<?php

namespace App\Repositories;

use App\Models\TelegramGroupRead;
use App\Models\TelegramMessage;
use Illuminate\Support\Facades\DB;

/**
 * 「誰讀到哪」Repository
 *
 * 負責 telegram_group_read 表，以及「某個人的未讀數」這個跨表查詢。
 */
class TelegramGroupReadRepository
{
    /**
     * 記下這個人把這個對話讀到哪
     *
     * ⚠ 用 `upsert()` 而**不是** `updateOrCreate()`。後者是「先 SELECT 再
     * INSERT」兩句話，兩個分頁同時點進同一個對話時會雙雙查不到、雙雙 insert，
     * 撞上 unique 索引丟例外。`upsert()` 出的是單一句
     * `INSERT ... ON DUPLICATE KEY UPDATE`，交給 DB 自己處理衝突。
     *
     * ⚠ `created_at` / `updated_at` 要自己填：`upsert()` 不走 Eloquent 的
     * timestamps（它是 query builder 層級的批次寫入）。
     *
     * @param int    $userId
     * @param int    $groupId
     * @param string $readAt Y-m-d H:i:s
     * @return void
     */
    public function touch($userId, $groupId, $readAt)
    {
        $now = now()->toDateTimeString();

        TelegramGroupRead::query()->upsert(
            [[
                'user_id'           => $userId,
                'telegram_group_id' => $groupId,
                'last_read_at'      => $readAt,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]],
            ['user_id', 'telegram_group_id'],
            ['last_read_at', 'updated_at']
        );
    }

    /**
     * 這個人在每個對話各有幾則沒看過的客人訊息
     *
     * ⚠ **一次撈回整批**，不要每個對話問一次 COUNT —— 對話列表每 30 秒重載，
     * 逐群組查等於群組有幾個就打幾次。
     *
     * ⚠ 用 LEFT JOIN 且要處理 `last_read_at IS NULL`：沒有讀取紀錄代表
     * **從來沒點進去過**，那個對話的訊息全部算未讀。INNER JOIN 會讓這些
     * 對話整個消失，新人看到的永遠是 0。
     *
     * ⚠ 只算 INBOUND。自己發出去的訊息不該讓自己的 badge 亮起來。
     *
     * @param int $userId
     * @return array telegram_group_id => 未讀則數（只含有未讀的對話）
     */
    public function getUnreadCountsByUser($userId)
    {
        $rows = TelegramMessage::query()
            ->from('telegram_message as m')
            ->leftJoin('telegram_group_read as r', function ($join) use ($userId) {
                $join->on('r.telegram_group_id', '=', 'm.telegram_group_id')
                    ->where('r.user_id', '=', $userId);
            })
            ->select(['m.telegram_group_id', DB::raw('COUNT(*) as unread')])
            ->where('m.direction', config('constants.TELEGRAM.DIRECTION.INBOUND'))
            ->where(function ($query) {
                $query->whereNull('r.last_read_at')
                    ->orWhereColumn('m.created_at', '>', 'r.last_read_at');
            })
            ->groupBy('m.telegram_group_id')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row->telegram_group_id] = (int) $row->unread;
        }

        return $counts;
    }
}
