<?php

namespace App\Repositories;

use App\Models\LeaveCancelRequest;
use Illuminate\Database\Eloquent\Collection;

/**
 * 撤銷請假的申請
 *
 * 詳見 .claude/knowledge/features/leave-cancel.md
 */
class LeaveCancelRequestRepository
{
    /*
     * ⚠⚠ **新增欄位時這裡一定要跟著加**（漏加不報錯，那一欄永遠是 null）。
     * 這個專案已經踩過五次，見 `ShiftHandoverGapRepository::COLUMNS` 的註解。
     */
    private const COLUMNS = [
        'id', 'leave_request_id', 'requested_by', 'reason',
        'status', 'reviewed_by', 'reviewed_at', 'review_note',
        'restore_quota', 'created_at',
    ];

    /**
     * 建一筆撤假申請
     *
     * @param array $attributes
     * @return LeaveCancelRequest
     */
    public function create(array $attributes)
    {
        return LeaveCancelRequest::query()->create($attributes);
    }

    /**
     * 更新
     *
     * @param LeaveCancelRequest $cancel
     * @param array              $attributes
     * @return LeaveCancelRequest
     */
    public function update(LeaveCancelRequest $cancel, array $attributes)
    {
        $cancel->update($attributes);

        return $cancel;
    }

    /*
     * ⚠ **這裡刻意沒有 `find()` 與「待審清單」那兩支。**
     *
     * 審核是走**路由模型綁定**（`{leaveCancelRequest}`），
     * 清單則是掛在請假清單那一列上（`latestByLeaveIds()`）——
     * 兩支都用不到，寫了只會變成看起來有人用的死碼。
     * 之後真的需要獨立的撤假清單時再加。
     */

    /**
     * 這張假單有沒有還在等審的撤假申請
     *
     * ⚠ 擋「同一張單被連送好幾次撤假」用的。
     * **不靠 unique index**：同一張單可以被撤銷申請、被拒絕、再申請一次
     * —— 每一次都要留紀錄，所以唯一性只存在於「待審核」這個狀態上。
     *
     * @param int $leaveRequestId
     * @return bool
     */
    public function hasPendingFor($leaveRequestId)
    {
        return LeaveCancelRequest::query()
            ->where('leave_request_id', (int) $leaveRequestId)
            ->where('status', LeaveCancelRequest::STATUS_PENDING)
            ->exists();
    }

    /**
     * 這幾張假單各自最後一筆撤假申請
     *
     * ⚠ **一次撈完再在記憶體裡配對**，不要在清單迴圈裡逐張查 ——
     * 請假清單一頁就十幾張，逐張查就是十幾次查詢。
     *
     * @param array<int> $leaveRequestIds
     * @return Collection keyBy leave_request_id
     */
    public function latestByLeaveIds(array $leaveRequestIds)
    {
        if (blank($leaveRequestIds)) {
            return new Collection();
        }

        return LeaveCancelRequest::query()
            ->select(self::COLUMNS)
            ->whereIn('leave_request_id', $leaveRequestIds)
            ->orderBy('id')
            ->get()
            // 同一張單可能有好幾筆（被拒絕之後再申請），keyBy 會留下最後一筆
            ->keyBy('leave_request_id');
    }

    /**
     * 「已撤銷、但當初選擇不還額度」的那幾張假單 id
     *
     * ⚠⚠ **TEMP-CANCEL-KEEP-QUOTA** —— 額度計算要把這幾張**仍然算進去**。
     * 需求方 2026-11-24 的勾選框拿掉之後，這一支會沒有新資料進來，
     * 但**既有的那幾筆仍然要照舊行為**，所以不要連這支一起刪。
     *
     * @param array<int> $leaveRequestIds
     * @return array<int>
     */
    public function keptQuotaLeaveIds(array $leaveRequestIds)
    {
        if (blank($leaveRequestIds)) {
            return [];
        }

        return LeaveCancelRequest::query()
            ->whereIn('leave_request_id', $leaveRequestIds)
            ->where('status', LeaveCancelRequest::STATUS_APPROVED)
            ->where('restore_quota', false)
            ->pluck('leave_request_id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->unique()
            ->values()
            ->all();
    }
}
