<?php

namespace App\Repositories;

use App\Models\StaffDmMessage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * 同仁私訊索引 Repository
 *
 * 讀取只有三種形狀：
 *   - 要刪哪幾則（手動：指定某人；自動：超過保留時數的所有人）
 *   - 某人目前「可清幾則／已過期幾則」（介面要誠實顯示後者）
 *   - 過期太久的紀錄清掉（它們已經刪不掉了，留著只是佔空間）
 *
 * ⚠ 所有查詢都要同時夾在**刪除窗口內**（48 小時）—— 窗口外的 message_id
 * 送去 Telegram 一定被拒，那是一趟注定失敗的 API 呼叫。
 */
class StaffDmMessageRepository
{
    /** @var array 欄位（不含內容 —— 這張表刻意不存內容） */
    private const COLUMNS = [
        'id',
        'user_id',
        'telegram_chat_id',
        'telegram_message_id',
        'notice_key',
        'created_at',
    ];

    /**
     * 記一筆（私訊送出成功後）
     *
     * @param array $attributes
     * @return StaffDmMessage
     */
    public function create(array $attributes)
    {
        return StaffDmMessage::query()->create($attributes);
    }

    /**
     * 要清除的私訊
     *
     * ⚠ `$userId` 給 null 代表「所有人」（自動清除用）。
     * ⚠ `$before` 是「只清這個時間點之前送出的」—— 自動清除靠它保留最近的
     * 通知不被清掉（剛發出去就清等於通知沒發）。
     *
     * @param int|null $userId null＝所有人
     * @param Carbon   $before 只取這個時間之前送出的
     * @param Carbon   $windowStart 刪除窗口的起點（48 小時前）
     * @param int      $limit
     * @return Collection|StaffDmMessage[]
     */
    public function getWipeTargets($userId, Carbon $before, Carbon $windowStart, $limit)
    {
        $query = StaffDmMessage::query()
            ->select(self::COLUMNS)
            ->whereNull('result')
            ->where('created_at', '<', $before)
            // 窗口外的送去 Telegram 必然被拒 —— 不要白跑
            ->where('created_at', '>=', $windowStart);

        if (filled($userId)) {
            $query->where('user_id', (int) $userId);
        }

        return $query->orderBy('created_at')->limit($limit)->get();
    }

    /**
     * 把處理結果寫回去
     *
     * ⚠ 一次更新一批（`whereIn`），不要逐筆 save —— 一次清可能上百則。
     *
     * @param array $ids
     * @param int   $result 見 constants.STAFF_DM_WIPE.RESULT
     * @return int 影響筆數
     */
    public function markResult(array $ids, $result)
    {
        if (blank($ids)) {
            return 0;
        }

        return StaffDmMessage::query()
            ->whereIn('id', $ids)
            ->update(['result' => (int) $result, 'wiped_at' => now()]);
    }

    /**
     * 每個人目前「可清幾則」與「已過期刪不掉幾則」
     *
     * ⚠ 兩個數字必須一起給，因為介面要同時顯示 —— 只給「可清 N 則」會讓人
     * 以為清完就乾淨了，而過期的那些**永久留在他手機裡**。
     *
     * ⚠ **一次撈全部人、不要逐人查**：設定頁要列出所有同仁，逐人查就是 N+1
     * （這個專案在 `CustomerFollowupService` 留過同樣的警告）。
     *
     * ⚠ 「已過期」包含兩種：已經被標記成 EXPIRED 的，以及**還沒標記但送出
     * 時間已經超過窗口的**（標記是清除時才做，頁面載入不該寫 DB）。
     *
     * @param Carbon $windowStart 刪除窗口的起點（48 小時前）
     * @return array<int, array{pending: int, expired: int}> user_id => 兩個數字
     */
    public function countByUser(Carbon $windowStart)
    {
        $expired = (int) config('constants.STAFF_DM_WIPE.RESULT.EXPIRED');

        // ⚠ MySQL 專屬：比較運算回傳 1/0 才能 SUM（同 AutoReplyResumeRepository 用 GREATEST 的前例）
        $rows = StaffDmMessage::query()
            ->selectRaw(
                'user_id,'
                . ' SUM(result IS NULL AND created_at >= ?) AS pending_count,'
                . ' SUM(result = ? OR (result IS NULL AND created_at < ?)) AS expired_count',
                [$windowStart, $expired, $windowStart]
            )
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $attributes = $row->getAttributes();

            $counts[(int) Arr::get($attributes, 'user_id')] = [
                'pending' => (int) Arr::get($attributes, 'pending_count'),
                'expired' => (int) Arr::get($attributes, 'expired_count'),
            ];
        }

        return $counts;
    }

    /**
     * 把窗口外、還沒處理的標記成「永久刪不掉」
     *
     * ⚠ 這一步是計數正確的前提：不標記的話，那些 message_id 會永遠留在
     * 「待清除」裡，每一輪排程都去撈、而且永遠清不掉。
     *
     * @param Carbon $windowStart
     * @return int 標記筆數
     */
    public function markExpiredBefore(Carbon $windowStart)
    {
        return StaffDmMessage::query()
            ->whereNull('result')
            ->where('created_at', '<', $windowStart)
            ->update([
                'result'   => (int) config('constants.STAFF_DM_WIPE.RESULT.EXPIRED'),
                'wiped_at' => now(),
            ]);
    }

    /**
     * 清掉太舊的紀錄
     *
     * 這些 message_id 已經**永久刪不掉**（過了 48 小時），留著只是佔空間。
     * ⚠ 但不要留太短：介面要能回答「有幾則已經過期」，那需要看得到它們。
     *
     * @param Carbon $before
     * @return int
     */
    public function purgeOlderThan(Carbon $before)
    {
        return StaffDmMessage::query()->where('created_at', '<', $before)->delete();
    }
}
