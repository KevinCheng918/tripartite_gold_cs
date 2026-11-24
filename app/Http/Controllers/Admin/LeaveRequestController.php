<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\LeaveRequest\RespondLeaveCancelRequest;
use App\Http\Requests\LeaveRequest\RespondLeaveRequest;
use App\Http\Requests\LeaveRequest\StoreLeaveCancelRequest;
use App\Http\Requests\LeaveRequest\StoreLeaveRequest;
use App\Http\Resources\LeaveRequestResource;
use App\Models\LeaveCancelRequest;
use App\Models\LeaveRequest;
use App\Services\LeaveCancelService;
use App\Services\LeaveQuotaService;
use App\Services\LeaveRequestService;
use Illuminate\Http\Request;
use App\Repositories\LeaveCancelRequestRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * 請假管理控制器
 */
class LeaveRequestController extends Controller
{
    private $leaveService;
    // 申請表單要顯示「還剩多少特休」（需求方 2026-11-05）
    private $leaveQuota;
    private $userRepository;
    // 撤銷請假（需求方 2026-11-24）
    private $cancelService;
    // 清單要一次撈完撤假紀錄再配對，避免 N+1（見 withCancels()）
    private $cancelRepository;

    public function __construct(
        LeaveRequestService $leaveService,
        LeaveQuotaService $leaveQuota,
        UserRepository $userRepository,
        LeaveCancelService $cancelService,
        LeaveCancelRequestRepository $cancelRepository
    ) {
        $this->leaveService = $leaveService;
        $this->leaveQuota = $leaveQuota;
        $this->userRepository = $userRepository;
        $this->cancelService = $cancelService;
        $this->cancelRepository = $cancelRepository;
    }

    /**
     * Ajax 取得特休額度
     *
     * ⚠ **預設看自己的**。管理者在請假視窗裡可以替別人申請，那時候要看
     * 那個人的額度 —— 但只有管理者能指定 `user_id`，否則任何人都可以
     * 查別人的請假狀況。
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxQuota(Request $request)
    {
        $params = ['user_id' => $request->input('user_id')];
        $user = Auth::user();

        if (filled(Arr::get($params, 'user_id')) && $user->isAdmin()) {
            $target = $this->userRepository->find((int) Arr::get($params, 'user_id'));
            $user = filled($target) ? $target : $user;
        }

        return response()->json($this->leaveQuota->summaryFor($user));
    }

    /**
     * Ajax 我的請假列表
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function ajaxMyList()
    {
        $list = $this->leaveService->getMyList(Auth::id());

        return LeaveRequestResource::collection($this->withCancels($list));
    }

    /**
     * Ajax 全部請假列表（審核用）
     *
     * @param Request $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function ajaxList(Request $request)
    {
        $params = $request->only(['user_id', 'status']);
        $list = $this->leaveService->getAll($params);

        return LeaveRequestResource::collection($this->withCancels($list));
    }

    /**
     * 把每張假單最後一筆撤假紀錄掛上去
     *
     * ⚠⚠ **一次撈完再在記憶體裡配對，不要在 Resource 裡逐張查** ——
     * 請假清單一頁十幾張，逐張查就是十幾次查詢（這個專案在
     * `withMore()` 踩過同一類問題）。
     *
     * ⚠ 掛的是一個**自訂屬性**（`latest_cancel`）而不是 Eloquent 關聯：
     * 關聯會讓每個呼叫端都要記得 `with()`，忘了就靜靜變成 N+1。
     *
     * @param \Illuminate\Support\Collection|\Illuminate\Database\Eloquent\Collection $list
     * @return mixed 原樣回傳（已就地掛上屬性）
     */
    private function withCancels($list)
    {
        // 短路：空清單不必碰 DB
        if (blank($list)) {
            return $list;
        }

        $cancels = $this->cancelRepository->latestByLeaveIds($list->pluck('id')->all());

        foreach ($list as $leave) {
            $leave->latest_cancel = $cancels->get((int) $leave->id);
        }

        return $list;
    }

    /**
     * Ajax 申請請假
     *
     * ⚠ `ValidationException` 要**單獨接著往上丟**，不能被下面那個 `\Exception`
     * 吃掉：它的 `getMessage()` 寫死是 `'The given data was invalid.'`，
     * 真正的訊息（「特休額度不足」「這個時段已經有假單」）只在 `errors` 裡。
     * 被吃掉的話操作者只看到那句廢話，不知道該改什麼。
     * 丟上去由 `App\Exceptions\Handler::invalidJson()` 統一組 `message`。
     *
     * @param StoreLeaveRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxStore(StoreLeaveRequest $request)
    {
        $params = $request->validated();

        try {
            $user = Auth::user();
            $targetUserId = $user->isAdmin() && filled(Arr::get($params, 'user_id'))
                ? (int) Arr::get($params, 'user_id')
                : Auth::id();

            $leave = $this->leaveService->apply($params, $targetUserId);

            return response()->json([
                'message' => trans('leave.msg.submitted'),
                'data'    => new LeaveRequestResource($leave->load(['user', 'reviewer'])),
            ]);
        } catch (ValidationException $e) {
            // 不是系統錯誤，不記 log：使用者填錯本來就會走到這裡
            throw $e;
        } catch (\Exception $e) {
            Log::error('請假申請失敗', ['error' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Ajax 審核請假
     *
     * @param RespondLeaveRequest $request
     * @param LeaveRequest        $leaveRequest
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxRespond(RespondLeaveRequest $request, LeaveRequest $leaveRequest)
    {
        $params = $request->validated();

        try {
            $leave = $this->leaveService->respond(
                $leaveRequest,
                (int) $params['status'],
                Auth::id(),
                Arr::get($params, 'review_note')
            );

            $msg = (int) $params['status'] === LeaveRequest::STATUS_APPROVED
                ? trans('leave.msg.approved')
                : trans('leave.msg.rejected');

            return response()->json([
                'message' => $msg,
                'data'    => new LeaveRequestResource($leave->load(['user', 'reviewer'])),
            ]);
        } catch (ValidationException $e) {
            // 理由同 ajaxStore()：審核也會丟業務訊息（例如「這張單已經審過了」）
            throw $e;
        } catch (\Exception $e) {
            Log::error('請假審核失敗', ['error' => $e->getMessage(), 'leave_id' => $leaveRequest->id]);

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Ajax 取消一張**還在等審**的假單
     *
     * ⚠ 不走審核（需求方 2026-11-24）：還沒生效，撤掉不影響任何人。
     * 跟「撤銷已通過的假」是兩支，不要合併 —— 合併的話「我剛剛按錯了」
     * 也要等人審才撤得掉。
     *
     * @param LeaveRequest $leaveRequest
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxCancelPending(LeaveRequest $leaveRequest)
    {
        try {
            $leave = $this->cancelService->cancelPending($leaveRequest, Auth::id());

            return response()->json([
                'message' => trans('leave.msg.cancel_pending_done'),
                'data'    => new LeaveRequestResource($leave->load(['user', 'reviewer'])),
            ]);
        } catch (ValidationException $e) {
            // 同 ajaxRespond()：業務訊息要原樣帶到前端
            throw $e;
        } catch (\Exception $e) {
            Log::error('取消請假失敗', ['error' => $e->getMessage(), 'leave_id' => $leaveRequest->id]);

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Ajax 對一張**已通過**的假單申請撤銷
     *
     * @param StoreLeaveCancelRequest $request
     * @param LeaveRequest            $leaveRequest
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxRequestCancel(StoreLeaveCancelRequest $request, LeaveRequest $leaveRequest)
    {
        $params = $request->validated();

        try {
            $this->cancelService->requestCancel(
                $leaveRequest,
                Auth::id(),
                Arr::get($params, 'reason')
            );

            return response()->json(['message' => trans('leave.msg.cancel_requested')]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('撤假申請失敗', ['error' => $e->getMessage(), 'leave_id' => $leaveRequest->id]);

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Ajax 審核一筆撤假申請
     *
     * @param RespondLeaveCancelRequest $request
     * @param LeaveCancelRequest        $leaveCancelRequest
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxRespondCancel(RespondLeaveCancelRequest $request, LeaveCancelRequest $leaveCancelRequest)
    {
        $params = $request->validated();

        try {
            $cancel = $this->cancelService->respond(
                $leaveCancelRequest,
                (int) Arr::get($params, 'status'),
                Auth::id(),
                [
                    'note' => Arr::get($params, 'review_note'),
                    /*
                     * ⚠⚠ **TEMP-CANCEL-KEEP-QUOTA**：沒帶就是 true（還額度）。
                     * 那是刻意的預設 —— 絕大多數撤假都該把額度還回去。
                     */
                    'restore_quota' => (bool) Arr::get($params, 'restore_quota', true),
                ]
            );

            $msg = (int) Arr::get($params, 'status') === LeaveCancelRequest::STATUS_APPROVED
                ? trans('leave.msg.cancel_approved')
                : trans('leave.msg.cancel_rejected');

            return response()->json(['message' => $msg, 'data' => ['id' => $cancel->id]]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('撤假審核失敗', [
                'error'     => $e->getMessage(),
                'cancel_id' => $leaveCancelRequest->id,
            ]);

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Ajax 檢查指定日期是否有請假（供排班檢查）
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxCheck(Request $request)
    {
        $params = $request->only(['user_id', 'date']);

        $blocked = $this->leaveService->isBlockedByLeave(
            (int) $params['user_id'],
            $params['date']
        );

        return response()->json(['blocked' => $blocked]);
    }
}
