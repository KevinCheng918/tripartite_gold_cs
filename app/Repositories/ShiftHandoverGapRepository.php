<?php

namespace App\Repositories;

use App\Models\ShiftHandoverGap;
use Illuminate\Database\Eloquent\Collection;

/**
 * 交接漏項 Repository
 *
 * 詳見 .claude/knowledge/features/handover-gap-review.md
 */
class ShiftHandoverGapRepository
{
    /** @var array 漏項欄位 */
    private const COLUMNS = [
        'id', 'shift_handover_id', 'source_type', 'source_id',
        'title', 'detail', 'covered', 'status', 'seq',
    ];

    /**
     * 整批寫入這一次盤點的結果
     *
     * ⚠ **一次 insert 而不是逐筆 create**：一次交接可能有十幾項，
     * 逐筆就是十幾次查詢，而這支跑在 Job 裡、後面還要等模型與 Telegram。
     *
     * ⚠ `insert()` **不會自動填 timestamps**（那是 Eloquent 的 `create()`
     * 才有的事），所以呼叫端要自己帶 —— 少了的話 `created_at` 會是
     * `0000-00-00`，之後想查「這張單是什麼時候被問的」就沒有依據。
     *
     * @param array<int, array> $rows
     * @return bool
     */
    public function insertMany(array $rows)
    {
        if (blank($rows)) {
            return false;
        }

        return ShiftHandoverGap::query()->insert($rows);
    }

    /**
     * 這一次交接盤出來的全部項目（照選單編號排）
     *
     * @param int $handoverId
     * @return Collection
     */
    public function getByHandover($handoverId)
    {
        return ShiftHandoverGap::query()
            ->select(self::COLUMNS)
            ->where('shift_handover_id', $handoverId)
            ->orderBy('seq')
            ->get();
    }

    /**
     * 這一次交接裡還沒被決定的項目
     *
     * 「全部補上」要用 —— 已經按過的不要再貼一次。
     *
     * @param int $handoverId
     * @return Collection
     */
    public function getPendingByHandover($handoverId)
    {
        return ShiftHandoverGap::query()
            ->select(self::COLUMNS)
            ->where('shift_handover_id', $handoverId)
            ->where('status', config('constants.HANDOVER_REVIEW.STATUS.PENDING'))
            ->orderBy('seq')
            ->get();
    }

    /**
     * 選單上的第 N 項
     *
     * ⚠ 一定要**兩個條件一起比**：`seq` 只在同一次交接裡唯一
     * （表上的 unique index 也是這兩欄）—— 只用 seq 會撈到別場交接的項目。
     *
     * @param int $handoverId
     * @param int $seq
     * @return ShiftHandoverGap|null
     */
    public function findBySeq($handoverId, $seq)
    {
        return ShiftHandoverGap::query()
            ->select(self::COLUMNS)
            ->where('shift_handover_id', $handoverId)
            ->where('seq', $seq)
            ->first();
    }

    /**
     * 這一次交接已經盤過了嗎
     *
     * 盤點是 Job 跑的，而 Job 可能因為佇列重送而跑第二次 ——
     * 有紀錄就不要再盤一輪（也不要再發一次選單）。
     *
     * @param int $handoverId
     * @return bool
     */
    public function existsForHandover($handoverId)
    {
        return ShiftHandoverGap::query()
            ->where('shift_handover_id', $handoverId)
            ->exists();
    }

    /**
     * 把這一次交接盤出來的項目整批刪掉
     *
     * ⚠ **只在「選單沒送出去」時用。** 有紀錄就代表問過了
     * （`existsForHandover()` 靠它擋重跑）—— 訊息發不出去卻把紀錄留著的話，
     * 這張交接會變成「永遠問不到、也永遠不會再問」的孤兒。
     *
     * @param int $handoverId
     * @return int 刪掉幾筆
     */
    public function deleteByHandover($handoverId)
    {
        return ShiftHandoverGap::query()
            ->where('shift_handover_id', $handoverId)
            ->delete();
    }

    /**
     * 整批改狀態
     *
     * @param array $ids
     * @param int   $status 見 constants.HANDOVER_REVIEW.STATUS
     * @return int 影響筆數
     */
    public function markStatus(array $ids, $status)
    {
        if (blank($ids)) {
            return 0;
        }

        return ShiftHandoverGap::query()
            ->whereIn('id', $ids)
            // 再確認一次：撈出來到更新之間，可能剛好被另一顆按鈕處理掉了
            ->where('status', config('constants.HANDOVER_REVIEW.STATUS.PENDING'))
            ->update(['status' => $status]);
    }

    /**
     * 把答案退回「還沒答」
     *
     * 答完一題之後，下一題（或總結）送不出去時用這支把狀態還原，
     * 他再按一次就是重試。
     *
     * ⚠⚠ **不能用 `markStatus($ids, PENDING)` 代替。** 那一支帶了
     * `where('status', PENDING)` 的樂觀鎖，條件剛好把這個方向的轉移
     * （FILLED／SKIPPED → PENDING）整個過濾掉 —— 更新 0 筆、不報錯。
     * 第一版就是這樣寫的，實測顯示狀態完全沒退回去，那一輪盤點
     * **永遠停在那一題**（按鈕再按會回「已經答過了」，下一題也不會出現）。
     *
     * @param array $ids
     * @return int 影響筆數
     */
    public function revertToPending(array $ids)
    {
        if (blank($ids)) {
            return 0;
        }

        $pending = (int) config('constants.HANDOVER_REVIEW.STATUS.PENDING');

        return ShiftHandoverGap::query()
            ->whereIn('id', $ids)
            // 已經是「還沒答」的就不必動（也省掉一次無謂的寫入）
            ->where('status', '<>', $pending)
            ->update(['status' => $pending]);
    }
}
