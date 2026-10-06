<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShiftNotice\UpdateShiftNoticeRequest;
use App\Services\ShiftNoticeService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * 班表通知設定
 *
 * 只有一件事要設定：今日班表的完整版要私訊給誰。
 * 有班的同仁會自動收到自己那一份，不需要設定。
 */
class ShiftNoticeController extends Controller
{
    private $noticeService;

    public function __construct(ShiftNoticeService $noticeService)
    {
        $this->noticeService = $noticeService;
    }

    /**
     * 設定頁
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        return view('admin.shift-notice.index', [
            'initialSettings' => $this->noticeService->forPage(),
        ]);
    }

    /**
     * Ajax 儲存設定
     *
     * @param UpdateShiftNoticeRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxUpdate(UpdateShiftNoticeRequest $request)
    {
        $params = $request->validated();

        try {
            $this->noticeService->updateSetting($params, Auth::id());

            return response()->json(['message' => trans('shift_notice.msg.saved')]);
        } catch (\Exception $e) {
            Log::error('班表通知設定更新失敗', ['error' => $e->getMessage(), 'user_id' => Auth::id()]);

            return response()->json(['message' => trans('shift_notice.msg.save_failed')], 500);
        }
    }

    /**
     * Ajax 測試發送
     *
     * 真的發出去（不是空跑）—— 理由見 `ShiftNoticeService::test()`。
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxTest()
    {
        $result = $this->noticeService->test();

        if (Arr::get($result, 'sent')) {
            return response()->json(['message' => trans('shift_notice.msg.test_sent')]);
        }

        // 失敗原因要講清楚：「沒設定收件人」和「他沒私訊過機器人」的處理方式完全不同
        $reason = (string) Arr::get($result, 'reason');
        $messages = [
            ShiftNoticeService::SKIP_NO_MANAGER  => trans('shift_notice.msg.test_no_manager'),
            ShiftNoticeService::SKIP_NOT_BOUND   => trans('shift_notice.msg.test_not_bound'),
            ShiftNoticeService::SKIP_SEND_FAILED => trans('shift_notice.msg.test_failed'),
        ];

        return response()->json([
            'message' => Arr::get($messages, $reason, trans('shift_notice.msg.test_failed')),
        ], 422);
    }
}
