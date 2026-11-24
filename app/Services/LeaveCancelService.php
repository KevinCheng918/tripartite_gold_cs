<?php

namespace App\Services;

use App\Models\LeaveCancelRequest;
use App\Models\LeaveRequest;
use App\Repositories\LeaveCancelRequestRepository;
use App\Repositories\LeaveRequestRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * 撤銷請假
 *
 * > 需求方 2026-11-24：「我想要做一個功能是撤銷請假，然後也要經過同意，
 * > 審核撤假也是吃權限，撤假的話要保留請假、撤假紀錄。」
 *
 * ⚠⚠ **「取消」與「撤銷」是兩件事，不要合併**（需求方拍板）：
 *
 * | | 待審核的單 | 已通過的單 |
 * |---|---|---|
 * | 方法 | `cancelPending()` | `requestCancel()` → `respond()` |
 * | 要不要審 | **不用**，按了就掉 | **要** |
 * | 理由 | 還沒生效，撤掉不影響任何人 | 已經生效，而且會動到特休額度 |
 *
 * 合併的話「我剛剛按錯了」也要等人審才撤得掉。
 *
 * ⚠⚠ **特休額度是算出來的，不是存的**（`LeaveQuotaService`）——
 * 所以「把時數加回」實際上是「讓那張單不再被算進已用總和」。
 * 把原單狀態改成 `STATUS_CANCELLED` 就自動達成，不需要任何加減動作。
 *
 * 詳見 .claude/knowledge/features/leave-cancel.md
 */
class LeaveCancelService
{
    private $leaveRepository;
    private $cancelRepository;
    private $approvalNotice;

    public function __construct(
        LeaveRequestRepository $leaveRepository,
        LeaveCancelRequestRepository $cancelRepository,
        ApprovalNoticeService $approvalNotice
    ) {
        $this->leaveRepository = $leaveRepository;
        $this->cancelRepository = $cancelRepository;
        $this->approvalNotice = $approvalNotice;
    }

    /**
     * 取消一張**還在等審**的假單
     *
     * ⚠ 不走審核（需求方拍板）：還沒生效，撤掉不影響任何人，
     * 額度那一側也只算在 `pending_minutes` 裡，取消後自然消失。
     *
     * @param LeaveRequest $leave
     * @param int          $userId 操作者
     * @return LeaveRequest
     * @throws ValidationException
     */
    public function cancelPending(LeaveRequest $leave, $userId)
    {
        $this->assertOwner($leave, $userId);

        if ((int) $leave->status !== LeaveRequest::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'leave' => [trans('leave.msg.cancel_not_pending')],
            ]);
        }

        /*
         * ⚠ 狀態改成「已撤銷」而不是刪掉那一列：需求方要「保留請假紀錄」。
         * 待審核的單沒有撤假紀錄（沒有人審過），所以只動這一張。
         */
        return $this->leaveRepository->update($leave, [
            'status' => LeaveRequest::STATUS_CANCELLED,
        ]);
    }

    /**
     * 對一張**已通過**的假單申請撤銷
     *
     * @param LeaveRequest $leave
     * @param int          $userId 申請人（只會是本人）
     * @param string|null  $reason
     * @return LeaveCancelRequest
     * @throws ValidationException
     */
    public function requestCancel(LeaveRequest $leave, $userId, $reason = null)
    {
        $this->assertOwner($leave, $userId);
        $this->assertCancellable($leave);

        /*
         * ⚠ 同一張單只能有一筆在等審 —— 不擋的話連按兩下就會產生兩筆，
         * 審了其中一筆之後另一筆變成孤兒（它指向的單已經撤了）。
         *
         * ⚠ 這是**唯一性只存在於「待審核」這個狀態上**，所以靠查詢擋、
         * 不靠 unique index：被拒絕之後他還可以再申請一次。
         */
        if ($this->cancelRepository->hasPendingFor((int) $leave->id)) {
            throw ValidationException::withMessages([
                'leave' => [trans('leave.msg.cancel_already_requested')],
            ]);
        }

        $cancel = $this->cancelRepository->create([
            'leave_request_id' => (int) $leave->id,
            'requested_by'     => (int) $userId,
            'reason'           => $reason,
            'status'           => LeaveCancelRequest::STATUS_PENDING,
        ]);

        Log::info('收到撤假申請', [
            'cancel_id' => $cancel->id,
            'leave_id'  => $leave->id,
            'user_id'   => $userId,
        ]);

        // ⚠ 要有人去審才有意義 —— 不通知的話這張單會靜靜躺在那裡
        $this->approvalNotice->leaveCancelApplied($cancel, $leave);

        return $cancel;
    }

    /**
     * 審核一筆撤假申請
     *
     * @param LeaveCancelRequest $cancel
     * @param int                $status 見 LeaveCancelRequest::STATUS_*
     * @param int                $reviewerId
     * @param array              $options note / restore_quota
     * @return LeaveCancelRequest
     * @throws ValidationException
     */
    public function respond(LeaveCancelRequest $cancel, $status, $reviewerId, array $options = [])
    {
        if (!$cancel->isPending()) {
            throw ValidationException::withMessages([
                'leave' => [trans('leave.msg.cancel_already_reviewed')],
            ]);
        }

        $leave = $this->leaveRepository->find((int) $cancel->leave_request_id);

        if (blank($leave)) {
            throw ValidationException::withMessages([
                'leave' => [trans('leave.msg.not_found')],
            ]);
        }

        $approved = (int) $status === LeaveCancelRequest::STATUS_APPROVED;

        /*
         * ⚠⚠ **TEMP-CANCEL-KEEP-QUOTA**
         *
         * 審核視窗那個勾選框，預設打勾（＝把額度還回去）。
         * 不打勾時那張已撤銷的假單**仍然計入已用特休**
         * （見 `LeaveQuotaService::keptCancelledMinutesIn()`）。
         *
         * ⚠ **拒絕時這個值沒有意義**，但仍然照實存下來 ——
         * 之後回頭看才知道當時那個視窗上勾的是什麼。
         */
        $restoreQuota = (bool) Arr::get($options, 'restore_quota', true);

        /*
         * ⚠ 兩張表要一起成功：撤假紀錄標成已通過、原假單改成已撤銷。
         * 只成功一半的話，不是「撤了假但紀錄說還沒審」，
         * 就是「紀錄說撤了但假單還在」—— 兩種都會讓額度算錯。
         */
        DB::transaction(function () use ($cancel, $status, $reviewerId, $options, $approved, $restoreQuota, $leave) {
            $this->cancelRepository->update($cancel, [
                'status'        => (int) $status,
                'reviewed_by'   => (int) $reviewerId,
                'reviewed_at'   => now(),
                'review_note'   => Arr::get($options, 'note'),
                'restore_quota' => $restoreQuota,
            ]);

            // 被拒絕的話原假單一個字都不動 —— 他那天還是請假
            if (!$approved) {
                return;
            }

            $this->leaveRepository->update($leave, [
                'status' => LeaveRequest::STATUS_CANCELLED,
            ]);
        });

        Log::info('撤假審核完成', [
            'cancel_id'     => $cancel->id,
            'leave_id'      => $leave->id,
            'status'        => $status,
            'restore_quota' => $restoreQuota,
            'reviewer_id'   => $reviewerId,
        ]);

        /*
         * ⚠ 擺在 transaction **外面**（同 `LeaveRequestService::respond()`）：
         * 通知失敗不該讓已經審好的結果被 rollback。
         */
        $this->approvalNotice->leaveCancelReviewed($cancel->fresh(), $leave, (int) $status, $restoreQuota);

        return $cancel->fresh();
    }

    /**
     * 這張假單現在撤得掉嗎
     *
     * @param LeaveRequest $leave
     * @return void
     * @throws ValidationException
     */
    private function assertCancellable(LeaveRequest $leave)
    {
        if ((int) $leave->status !== LeaveRequest::STATUS_APPROVED) {
            throw ValidationException::withMessages([
                'leave' => [trans('leave.msg.cancel_not_approved')],
            ]);
        }

        /*
         * ⚠⚠ **只能撤還沒開始的假**（需求方 2026-11-24 拍板）。
         *
         * ⚠ 比的是 **`start_date`**，不是 `end_date`：一張 11/20–11/25 的假，
         * 到了 11/22 已經請掉三天了 —— 那不是「還沒開始」，不該讓人撤。
         */
        if (blank($leave->start_date) || !$leave->start_date->copy()->startOfDay()->isFuture()) {
            throw ValidationException::withMessages([
                'leave' => [trans('leave.msg.cancel_already_started')],
            ]);
        }
    }

    /**
     * 只有本人能動自己的假單
     *
     * ⚠ 需求方拍板「只有本人」—— 主管想讓某張單失效的話，跟他說一聲請他撤，
     * 不要在系統上替他做決定。
     *
     * @param LeaveRequest $leave
     * @param int          $userId
     * @return void
     * @throws ValidationException
     */
    private function assertOwner(LeaveRequest $leave, $userId)
    {
        if ((int) $leave->user_id === (int) $userId) {
            return;
        }

        throw ValidationException::withMessages([
            'leave' => [trans('leave.msg.cancel_not_owner')],
        ]);
    }
}
