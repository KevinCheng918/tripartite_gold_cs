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
}
