<?php

namespace App\Http\Resources;

use App\Models\LeaveCancelRequest;
use App\Models\LeaveRequest;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 請假申請 Resource
 *
 * @mixin \App\Models\LeaveRequest
 */
class LeaveRequestResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'          => $this->id,
            'user'        => $this->user ? $this->user->nickname : '-',
            'user_id'     => $this->user_id,
            'start_date'  => $this->start_date ? $this->start_date->format('Y-m-d') : null,
            'end_date'    => $this->end_date ? $this->end_date->format('Y-m-d') : null,
            /*
             * ⚠⚠ **假別**（特休／病假／事假）。需求方 2026-11-24：
             * 「排班管理的請假管理，要標明是請哪一個假別。」
             *
             * ⚠ 這一欄一直存在 `leave_request.type`、repository 也撈了，
             * **只是從來沒放進這個 Resource** —— 所以前端根本拿不到，
             * 畫面上的「類型」欄顯示的是 `is_full_day`（整天／時段），
             * 那是另一件事。
             *
             * ⚠ 可能是 `unclassified`（改版前的舊資料），前端要有對應的字。
             */
            'type'        => $this->type,
            'is_full_day' => $this->is_full_day,
            'start_time'  => $this->start_time,
            'end_time'    => $this->end_time,
            'reason'      => $this->reason,
            'status'      => $this->status,
            'reviewer'    => $this->reviewer ? $this->reviewer->nickname : null,
            'review_note' => $this->review_note,
            'reviewed_at' => $this->reviewed_at ? $this->reviewed_at->format('Y-m-d H:i') : null,
            'created_at'  => $this->created_at->format('Y-m-d H:i'),

            /*
             * 撤假相關（需求方 2026-11-24）。前端要靠這三個決定顯示什麼：
             *
             * - `can_cancel`      能不能申請撤銷（已通過 ＋ 還沒開始）
             * - `cancel_pending`  已經送出撤銷申請、還在等審
             * - `cancel_id`       等審的那一筆的 id（審核時要用）
             *
             * ⚠ **`can_cancel` 的判斷必須跟 `LeaveCancelService` 一致**
             * —— 這裡只決定按鈕畫不畫得出來，真正擋下來的是 Service。
             * 兩邊不一致的話，使用者會看到一顆按下去就被拒絕的按鈕。
             *
             * ⚠ `whenLoaded` 不適用：`latest_cancel` 是呼叫端批次塞進來的
             * （見 `LeaveCancelRequestRepository::latestByLeaveIds()`），
             * 不是 Eloquent 關聯 —— 沒塞的時候就是「沒有撤假紀錄」。
             */
            'can_cancel'     => $this->canCancel(),
            'cancel_pending' => $this->cancelPendingFlag(),
            'cancel_id'      => $this->cancelPendingId(),
        ];
    }

    /**
     * 這張單現在能不能申請撤銷
     *
     * ⚠ 條件要跟 `LeaveCancelService::assertCancellable()` 一模一樣：
     * **已通過** ＋ **開始日還沒到**（不是結束日 —— 請到一半的假不算
     * 還沒開始）。
     *
     * @return bool
     */
    private function canCancel()
    {
        if ((int) $this->status !== LeaveRequest::STATUS_APPROVED) {
            return false;
        }

        if (blank($this->start_date)) {
            return false;
        }

        return $this->start_date->copy()->startOfDay()->isFuture();
    }

    /**
     * 有沒有一筆撤假申請在等審
     *
     * @return bool
     */
    private function cancelPendingFlag()
    {
        /*
         * ⚠ 不用 `??`（專案通則）—— Eloquent 讀一個不存在的屬性本來就回
         * null，不會噴錯，所以直接取就好。
         */
        $cancel = $this->resource->latest_cancel;

        return filled($cancel) && (int) $cancel->status === LeaveCancelRequest::STATUS_PENDING;
    }

    /**
     * 等審中那一筆撤假申請的 id（沒有就 null）
     *
     * @return int|null
     */
    private function cancelPendingId()
    {
        if (!$this->cancelPendingFlag()) {
            return null;
        }

        return (int) $this->resource->latest_cancel->id;
    }
}
