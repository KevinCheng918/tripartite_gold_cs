<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\LeaveRequest\RespondLeaveRequest;
use App\Http\Requests\LeaveRequest\StoreLeaveRequest;
use App\Http\Resources\LeaveRequestResource;
use App\Models\LeaveRequest;
use App\Services\LeaveRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * 請假管理控制器
 */
class LeaveRequestController extends Controller
{
    private $leaveService;

    public function __construct(LeaveRequestService $leaveService)
    {
        $this->leaveService = $leaveService;
    }

    /**
     * Ajax 我的請假列表
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function ajaxMyList()
    {
        $list = $this->leaveService->getMyList(Auth::id());

        return LeaveRequestResource::collection($list);
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

        return LeaveRequestResource::collection($list);
    }

    /**
     * Ajax 申請請假
     *
     * @param StoreLeaveRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxStore(StoreLeaveRequest $request)
    {
        $params = $request->validated();

        try {
            $user = Auth::user();
            $targetUserId = $user->isAdmin() && filled($params['user_id'] ?? null)
                ? (int) $params['user_id']
                : Auth::id();

            $leave = $this->leaveService->apply($params, $targetUserId);

            return response()->json([
                'message' => trans('leave.msg.submitted'),
                'data'    => new LeaveRequestResource($leave->load(['user', 'reviewer'])),
            ]);
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
                $params['review_note'] ?? null
            );

            $msg = (int) $params['status'] === LeaveRequest::STATUS_APPROVED
                ? trans('leave.msg.approved')
                : trans('leave.msg.rejected');

            return response()->json([
                'message' => $msg,
                'data'    => new LeaveRequestResource($leave->load(['user', 'reviewer'])),
            ]);
        } catch (\Exception $e) {
            Log::error('請假審核失敗', ['error' => $e->getMessage(), 'leave_id' => $leaveRequest->id]);

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
