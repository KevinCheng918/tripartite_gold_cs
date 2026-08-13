<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shift\UpdateShiftRequest;
use App\Http\Requests\ShiftAssignment\StoreAssignmentRequest;
use App\Http\Requests\ShiftAssignment\SwapRequest;
use App\Http\Requests\ShiftAssignment\RespondSwapRequest;
use App\Http\Resources\ShiftResource;
use App\Http\Resources\ShiftAssignmentResource;
use App\Http\Resources\ShiftSwapResource;
use App\Models\Shift;
use App\Models\ShiftSwap;
use App\Services\ShiftService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * 排班管理控制器
 *
 * 包含班別設定（Admin）、報班、換班等功能。
 * Controller 僅負責接收 Request → 呼叫 Service → 回傳 Response，
 * 不處理商業邏輯，不直接呼叫 DB。
 */
class ShiftController extends Controller
{
    private $shiftService;

    public function __construct(ShiftService $shiftService)
    {
        $this->shiftService = $shiftService;
    }

    // ---------------------------------------------------------------
    //  頁面
    // ---------------------------------------------------------------

    /**
     * 排班管理頁面
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        return view('admin.shifts.index');
    }

    // ---------------------------------------------------------------
    //  班別管理（Admin）
    // ---------------------------------------------------------------

    /**
     * Ajax 取得所有班別
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function ajaxShiftList()
    {
        $shifts = $this->shiftService->listShifts();

        return ShiftResource::collection($shifts);
    }

    /**
     * Ajax 新增班別（僅 Admin）
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse|ShiftResource
     */
    public function ajaxStoreShift(Request $request)
    {
        $params = $request->validate([
            'display_name' => 'required|string|max:50',
            'start_time'   => 'required|date_format:H:i',
            'end_time'     => 'required|date_format:H:i',
        ]);

        try {
            $shift = $this->shiftService->createShift($params);

            return new ShiftResource($shift);
        } catch (\Exception $e) {
            Log::error('班別新增失敗', ['error' => $e->getMessage()]);

            return response()->json(['message' => trans('shift.msg.create_failed')], 500);
        }
    }

    /**
     * Ajax 更新班別時段（僅 Admin）
     *
     * @param UpdateShiftRequest $request
     * @param Shift              $shift
     * @return \Illuminate\Http\JsonResponse|ShiftResource
     */
    public function ajaxUpdateShift(UpdateShiftRequest $request, Shift $shift)
    {
        $params = $request->validated();

        try {
            $shift = $this->shiftService->updateShift($shift, $params);

            return new ShiftResource($shift);
        } catch (\Exception $e) {
            Log::error('班別更新失敗', ['error' => $e->getMessage(), 'shift_id' => $shift->id]);

            return response()->json(['message' => trans('shift.msg.update_failed')], 500);
        }
    }

    // ---------------------------------------------------------------
    //  排班紀錄
    // ---------------------------------------------------------------

    /**
     * Ajax 取得排班紀錄（分頁）
     *
     * @param Request $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function ajaxAssignmentList(Request $request)
    {
        $params = $request->only(['date_from', 'date_to', 'user_id', 'shift_id', 'per_page']);

        $assignments = $this->shiftService->listAssignments($params);

        return ShiftAssignmentResource::collection($assignments);
    }

    /**
     * Ajax 取得所有客服帳號（供 Admin 排班下拉選單）
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxCsUsers()
    {
        $users = app(\App\Repositories\UserRepository::class)->getCsUsers();

        return response()->json($users);
    }

    /**
     * Ajax 排班
     *
     * Admin：從 request 取 user_id（指定客服排班），不可替自己排班。
     * 客服：user_id 固定為自己。
     *
     * @param StoreAssignmentRequest $request
     * @return \Illuminate\Http\JsonResponse|ShiftAssignmentResource
     */
    public function ajaxAssign(StoreAssignmentRequest $request)
    {
        $params = $request->validated();

        // 客服只能替自己報班；Admin 從前端指定 user_id
        $params['user_id'] = Auth::user()->isAdmin()
            ? ($params['user_id'] ?? null)
            : Auth::id();

        try {
            $assignment = $this->shiftService->assign($params, Auth::user()->isAdmin());

            return new ShiftAssignmentResource($assignment->load(['user', 'shift']));
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('排班失敗', ['error' => $e->getMessage(), 'user_id' => $params['user_id']]);

            return response()->json(['message' => trans('shift.msg.assign_failed')], 500);
        }
    }

    /**
     * Ajax 刪除排班紀錄
     *
     * @param \App\Models\ShiftAssignment $assignment
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxDeleteAssignment(\App\Models\ShiftAssignment $assignment)
    {
        try {
            $this->shiftService->deleteAssignment($assignment->id);

            return response()->json(['message' => trans('shift.assignment_deleted')]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('刪除排班失敗', ['error' => $e->getMessage(), 'assignment_id' => $assignment->id]);

            return response()->json(['message' => trans('shift.msg.delete_failed')], 500);
        }
    }

    // ---------------------------------------------------------------
    //  換班
    // ---------------------------------------------------------------

    /**
     * Ajax 發起換班請求
     *
     * @param SwapRequest $request
     * @return \Illuminate\Http\JsonResponse|ShiftSwapResource
     */
    public function ajaxRequestSwap(SwapRequest $request)
    {
        $params = $request->validated();

        try {
            $swap = $this->shiftService->requestSwap($params, Auth::id());

            return new ShiftSwapResource($swap->load(['requester', 'target', 'requesterAssignment.shift', 'targetAssignment.shift']));
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('換班請求失敗', ['error' => $e->getMessage(), 'user_id' => Auth::id()]);

            return response()->json(['message' => trans('shift.msg.swap_failed')], 500);
        }
    }

    /**
     * Ajax 回應換班請求（同意或拒絕）
     *
     * @param RespondSwapRequest $request
     * @param ShiftSwap          $swap
     * @return \Illuminate\Http\JsonResponse|ShiftSwapResource
     */
    public function ajaxRespondSwap(RespondSwapRequest $request, ShiftSwap $swap)
    {
        $params = $request->validated();

        try {
            $swap = $this->shiftService->respondSwap($swap, (int) $params['status'], Auth::id());

            return new ShiftSwapResource($swap->load(['requester', 'target', 'requesterAssignment.shift', 'targetAssignment.shift']));
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('換班回應失敗', ['error' => $e->getMessage(), 'swap_id' => $swap->id]);

            return response()->json(['message' => trans('shift.msg.respond_failed')], 500);
        }
    }

    /**
     * Ajax 取得我的換班請求列表
     *
     * @param Request $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function ajaxMySwaps(Request $request)
    {
        $params = $request->only(['per_page']);
        $perPage = (int) ($params['per_page'] ?? config('constants.PAGINATION.DEFAULT', 10));

        $swaps = $this->shiftService->listSwaps(Auth::id(), Auth::user()->isAdmin(), $perPage);

        return ShiftSwapResource::collection($swaps);
    }
}
