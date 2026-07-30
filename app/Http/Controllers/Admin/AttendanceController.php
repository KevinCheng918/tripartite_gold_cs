<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceResource;
use App\Services\AttendanceService;
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

    public function __construct(AttendanceService $attendanceService)
    {
        $this->attendanceService = $attendanceService;
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
    public function detail($userId)
    {
        return view('admin.attendance.detail', ['targetUserId' => $userId]);
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
}
