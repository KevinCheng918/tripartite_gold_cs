<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceResource;
use App\Models\ClockAmendment;
use App\Services\AttendanceService;
use App\Services\ClockAmendmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * 打卡控制器
 *
 * 客服：上下班打卡、查看自己的出勤紀錄。
 * Admin：查看全體月報表。
 */
class AttendanceController extends Controller
{
    private $attendanceService;
    private $amendmentService;

    public function __construct(AttendanceService $attendanceService, ClockAmendmentService $amendmentService)
    {
        $this->attendanceService = $attendanceService;
        $this->amendmentService = $amendmentService;
    }

    /**
     * 打卡頁面
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        return view('admin.attendance.index');
    }

    /**
     * Ajax 上班打卡
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse|AttendanceResource
     */
    public function ajaxClockIn(Request $request)
    {
        try {
            $record = $this->attendanceService->clockIn(
                Auth::id(),
                $request->ip(),
                $request->userAgent()
            );

            return new AttendanceResource($record);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('上班打卡失敗', ['error' => $e->getMessage(), 'user_id' => Auth::id()]);

            return response()->json(['message' => trans('attendance.msg.clock_in_failed')], 500);
        }
    }

    /**
     * Ajax 下班打卡
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse|AttendanceResource
     */
    public function ajaxClockOut(Request $request)
    {
        try {
            $record = $this->attendanceService->clockOut(
                Auth::id(),
                $request->ip(),
                $request->userAgent()
            );

            return new AttendanceResource($record);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('下班打卡失敗', ['error' => $e->getMessage(), 'user_id' => Auth::id()]);

            return response()->json(['message' => trans('attendance.msg.clock_out_failed')], 500);
        }
    }

    /**
     * Ajax 取得今日打卡狀態
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxTodayStatus()
    {
        $record = $this->attendanceService->getTodayRecord(Auth::id());

        if (!$record) {
            return response()->json(['status' => 'not_clocked']);
        }

        return new AttendanceResource($record);
    }

    /**
     * Ajax 取得指定月份的個人出勤紀錄
     *
     * @param Request $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function ajaxMyMonthly(Request $request)
    {
        $yearMonth = $request->input('month', now()->format('Y-m'));

        $records = $this->attendanceService->getMonthlyRecords(Auth::id(), $yearMonth);

        return AttendanceResource::collection($records);
    }

    /**
     * 個人出勤明細頁面（管理者點擊員工進入）
     *
     * @param int $userId
     * @return \Illuminate\View\View
     */
    public function detail(Request $request, $userId)
    {
        $yearMonth = $request->input('month', now()->format('Y-m'));
        $records = $this->attendanceService->getMonthlyRecords((int) $userId, $yearMonth);

        return view('admin.attendance.detail', [
            'targetUserId' => $userId,
            'records'      => $records,
            'yearMonth'    => $yearMonth,
        ]);
    }

    /**
     * Ajax 取得指定員工某月的出勤紀錄（管理者用）
     *
     * @param Request $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function ajaxUserMonthly(Request $request)
    {
        if (!Auth::user()->isAdmin()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $userId = $request->input('user_id');
        $yearMonth = $request->input('month', now()->format('Y-m'));

        $records = $this->attendanceService->getMonthlyRecords((int) $userId, $yearMonth);

        return AttendanceResource::collection($records);
    }

    /**
     * Ajax 取得全體月報表（管理者用）
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxMonthlyReport(Request $request)
    {
        if (!Auth::user()->isAdmin()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $yearMonth = $request->input('month', now()->format('Y-m'));

        $report = $this->attendanceService->getMonthlyReport($yearMonth);

        return response()->json($report);
    }

    // ---------------------------------------------------------------
    //  補打卡
    // ---------------------------------------------------------------

    /**
     * Ajax 申請補打卡
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxRequestAmend(Request $request)
    {
        $params = $request->validate([
            'date'       => 'required|date',
            'type'       => 'required|integer|in:1,2',
            'clock_time' => 'required|date_format:H:i',
            'reason'     => 'nullable|string|max:500',
        ]);

        try {
            $this->amendmentService->request($params, Auth::id());

            return response()->json(['message' => trans('attendance.amend_submitted')]);
        } catch (\Exception $e) {
            Log::error('補打卡申請失敗', ['error' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Ajax 個人補打卡紀錄
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxMyAmendments()
    {
        $amendments = $this->amendmentService->getByUser(Auth::id());

        return response()->json($amendments->map(function ($a) {
            return [
                'id'          => $a->id,
                'date'        => $a->date->format('Y-m-d'),
                'type'        => $a->type,
                'clock_time'  => $a->clock_time,
                'reason'      => $a->reason,
                'status'      => $a->status,
                'reviewer'    => $a->reviewer ? $a->reviewer->nickname : null,
                'reviewed_at' => $a->reviewed_at ? $a->reviewed_at->format('Y-m-d H:i') : null,
                'created_at'  => $a->created_at->format('Y-m-d H:i'),
            ];
        }));
    }

    /**
     * Ajax 補打卡審核列表（全部）
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxAmendments()
    {
        $amendments = $this->amendmentService->getAll();

        return response()->json($amendments->map(function ($a) {
            return [
                'id'          => $a->id,
                'user'        => $a->user ? $a->user->nickname : '-',
                'date'        => $a->date->format('Y-m-d'),
                'type'        => $a->type,
                'clock_time'  => $a->clock_time,
                'reason'      => $a->reason,
                'status'      => $a->status,
                'reviewer'    => $a->reviewer ? $a->reviewer->nickname : null,
                'reviewed_at' => $a->reviewed_at ? $a->reviewed_at->format('Y-m-d H:i') : null,
                'created_at'  => $a->created_at->format('Y-m-d H:i'),
            ];
        }));
    }

    /**
     * Ajax 審核補打卡
     *
     * @param Request        $request
     * @param ClockAmendment $amendment
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxRespondAmend(Request $request, ClockAmendment $amendment)
    {
        $params = $request->validate([
            'status' => 'required|integer|in:1,2',
        ]);

        try {
            $this->amendmentService->respond($amendment, (int) $params['status'], Auth::id());
            $msg = (int) $params['status'] === 1
                ? trans('attendance.amend_approved')
                : trans('attendance.amend_rejected');

            return response()->json(['message' => $msg]);
        } catch (\Exception $e) {
            Log::error('補打卡審核失敗', ['error' => $e->getMessage(), 'amendment_id' => $amendment->id]);

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
