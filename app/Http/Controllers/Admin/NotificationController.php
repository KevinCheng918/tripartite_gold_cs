<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\UpdateGroupRequest;
use App\Http\Requests\Notification\UpdateRemindRequest;
use App\Http\Requests\Notification\UpdateReportRequest;
use App\Http\Requests\Notification\UpdateShiftNoticeRequest;
use App\Http\Requests\Notification\UpdateTopicRequest;
use App\Services\NotificationSettingService;
use App\Services\ShiftNoticeService;
use App\Services\StaffDmService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * 通知設定（通訊管理 → 通知設定）
 *
 * 四個分頁：支援群組與話題、求助單提醒、班表通知、超時提醒統計。
 * 2026-10-06 從「全域設定」（現已改名「AI 引擎」）與「班表通知」兩頁合併過來。
 *
 * ⚠ 每個分頁各自一支 ajax —— 合成一支的話，存一個分頁會把其他分頁的值
 * 一起寫掉（那些欄位在這次送出裡是空的）。
 */
class NotificationController extends Controller
{
    private $settingService;

    public function __construct(NotificationSettingService $settingService)
    {
        $this->settingService = $settingService;
    }

    /**
     * 設定頁
     *
     * 四個分頁的資料隨頁面一起送出 —— 切分頁不該再等一次 ajax。
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        return view('admin.notification.index', [
            'initialSettings' => $this->settingService->forPage(),
        ]);
    }

    /**
     * Ajax 取得全部設定
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxSettings()
    {
        return response()->json($this->settingService->forPage());
    }

    /**
     * Ajax 更新群組本身（chat_id 與用哪個 Bot）
     *
     * @param UpdateGroupRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxUpdateGroup(UpdateGroupRequest $request)
    {
        $params = $request->validated();

        try {
            $this->settingService->updateGroup($params, Auth::id());

            return response()->json(['message' => trans('notification.msg.saved')]);
        } catch (\Exception $e) {
            Log::error('支援群組設定更新失敗', ['error' => $e->getMessage(), 'user_id' => Auth::id()]);

            return response()->json(['message' => trans('notification.msg.save_failed')], 500);
        }
    }

    /**
     * Ajax 更新求助單超時提醒
     *
     * @param UpdateRemindRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxUpdateRemind(UpdateRemindRequest $request)
    {
        $params = $request->validated();

        try {
            $this->settingService->updateRemind($params, Auth::id());

            return response()->json(['message' => trans('notification.msg.saved')]);
        } catch (\Exception $e) {
            Log::error('求助單提醒設定更新失敗', ['error' => $e->getMessage(), 'user_id' => Auth::id()]);

            return response()->json(['message' => trans('notification.msg.save_failed')], 500);
        }
    }

    /**
     * Ajax 更新每日統計的收件人
     *
     * @param UpdateReportRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxUpdateReport(UpdateReportRequest $request)
    {
        $params = $request->validated();

        try {
            $this->settingService->updateReport($params, Auth::id());

            return response()->json(['message' => trans('notification.msg.saved')]);
        } catch (\Exception $e) {
            Log::error('提醒統計設定更新失敗', ['error' => $e->getMessage(), 'user_id' => Auth::id()]);

            return response()->json(['message' => trans('notification.msg.save_failed')], 500);
        }
    }

    /**
     * Ajax 每日統計測試發送
     *
     * 真的發出去（不是空跑）—— 理由見 `RemindReportService::test()`。
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxTestReport()
    {
        $result = $this->settingService->testReport();
        $sent = (int) Arr::get($result, 'sent', 0);

        if ($sent > 0) {
            // 有人收到就算成功，但沒收到的人也要講 —— 理由同 ajaxTestShift()
            $failed = (array) Arr::get($result, 'failed', []);

            if (filled($failed)) {
                return response()->json([
                    'message' => trans('notification.msg.report_test_partial', [
                        'sent'   => $sent,
                        'failed' => implode('、', $failed),
                    ]),
                ]);
            }

            return response()->json([
                'message' => trans('notification.msg.report_test_sent', ['sent' => $sent]),
            ]);
        }

        $reason = (string) Arr::get($result, 'reason');
        $messages = [
            StaffDmService::SKIP_NO_RECIPIENT => trans('notification.msg.report_test_no_user'),
            StaffDmService::SKIP_NOT_BOUND    => trans('notification.msg.report_test_not_bound'),
            StaffDmService::SKIP_SEND_FAILED  => trans('notification.msg.report_test_failed'),
        ];

        return response()->json([
            'message' => Arr::get($messages, $reason, trans('notification.msg.report_test_failed')),
        ], 422);
    }

    /**
     * Ajax 發測試訊息到內部支援群組
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxTestSupport()
    {
        $result = $this->settingService->testSupport();

        if (Arr::get($result, 'sent')) {
            return response()->json(['message' => trans('notification.msg.support_test_sent')]);
        }

        /*
         * 群組被升級成 supergroup（開話題功能就會）時，Telegram 會在錯誤回應裡
         * 附上新的 chat_id。把它直接寫進訊息讓人複製貼上 ——
         * 使用者這時正好站在 chat_id 欄位前面。
         */
        $migrated = Arr::get($result, 'migrated_chat_id');

        if (filled($migrated)) {
            return response()->json([
                'message' => trans('notification.msg.chat_id_migrated', ['id' => $migrated]),
            ], 422);
        }

        return response()->json(['message' => trans('notification.msg.support_test_failed')], 422);
    }

    /**
     * Ajax 更新話題分流
     *
     * @param UpdateTopicRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxUpdateTopics(UpdateTopicRequest $request)
    {
        $params = $request->validated();

        try {
            $this->settingService->updateTopics($params, Auth::id());

            return response()->json(['message' => trans('notification.msg.saved')]);
        } catch (\Exception $e) {
            Log::error('話題分流設定更新失敗', ['error' => $e->getMessage(), 'user_id' => Auth::id()]);

            return response()->json(['message' => trans('notification.msg.save_failed')], 500);
        }
    }

    /**
     * Ajax 逐話題發測試訊息
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxTestTopics()
    {
        $result = $this->settingService->testTopics();
        $sent = (int) Arr::get($result, 'sent', 0);
        $failed = (array) Arr::get($result, 'failed', []);

        if ($sent < 1) {
            return response()->json(['message' => trans('notification.msg.topic_test_failed')], 422);
        }

        /*
         * 有的話題發成功、有的失敗 —— 失敗的要點名。
         * 那代表那個話題 id 填錯或話題被刪，該類通知會從此默默消失。
         */
        if (filled($failed)) {
            return response()->json([
                'message' => trans('notification.msg.topic_test_partial', [
                    'sent'   => $sent,
                    'failed' => implode('、', $failed),
                ]),
            ]);
        }

        return response()->json([
            'message' => trans('notification.msg.topic_test_sent', ['sent' => $sent]),
        ]);
    }

    /**
     * Ajax 更新班表通知
     *
     * @param UpdateShiftNoticeRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxUpdateShift(UpdateShiftNoticeRequest $request)
    {
        $params = $request->validated();

        try {
            $this->settingService->updateShift($params, Auth::id());

            return response()->json(['message' => trans('notification.msg.saved')]);
        } catch (\Exception $e) {
            Log::error('班表通知設定更新失敗', ['error' => $e->getMessage(), 'user_id' => Auth::id()]);

            return response()->json(['message' => trans('notification.msg.save_failed')], 500);
        }
    }

    /**
     * Ajax 班表通知測試發送
     *
     * 真的發出去（不是空跑）—— 理由見 `ShiftNoticeService::test()`。
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxTestShift()
    {
        $result = $this->settingService->testShift();
        $sent = (int) Arr::get($result, 'sent', 0);

        if ($sent > 0) {
            /*
             * 有人收到就算成功，但**沒收到的人也要講** —— 勾了五個人只有三個
             * 收到時，只回「已送出」會讓另外兩個無聲消失。
             */
            $failed = (array) Arr::get($result, 'failed', []);

            if (filled($failed)) {
                return response()->json([
                    'message' => trans('notification.msg.shift_test_partial', [
                        'sent'   => $sent,
                        'failed' => implode('、', $failed),
                    ]),
                ]);
            }

            return response()->json([
                'message' => trans('notification.msg.shift_test_sent', ['sent' => $sent]),
            ]);
        }

        // 失敗原因要講清楚：「沒勾收件人」和「他沒私訊過機器人」的處理方式完全不同
        $reason = (string) Arr::get($result, 'reason');
        $messages = [
            ShiftNoticeService::SKIP_NO_MANAGER  => trans('notification.msg.shift_test_no_manager'),
            ShiftNoticeService::SKIP_NOT_BOUND   => trans('notification.msg.shift_test_not_bound'),
            ShiftNoticeService::SKIP_SEND_FAILED => trans('notification.msg.shift_test_failed'),
        ];

        return response()->json([
            'message' => Arr::get($messages, $reason, trans('notification.msg.shift_test_failed')),
        ], 422);
    }
}
