<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\TelegramBroadcast\SendBroadcastRequest;
use App\Models\TelegramBroadcast;
use App\Services\StationService;
use App\Services\TelegramBroadcastService;
use App\Services\TelegramChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Telegram 群發公告控制器
 */
class TelegramBroadcastController extends Controller
{
    private $broadcastService;
    private $chatService;
    private $stationService;

    public function __construct(
        TelegramBroadcastService $broadcastService,
        TelegramChatService $chatService,
        StationService $stationService
    ) {
        $this->broadcastService = $broadcastService;
        $this->chatService = $chatService;
        $this->stationService = $stationService;
    }

    /**
     * 群發公告頁面
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $groups = $this->broadcastService->getTargetStations();
        $systems = $this->stationService->getActiveSystems();
        $history = $this->broadcastService->list(20);

        return view('admin.telegram-broadcast.index', [
            'groups'  => $groups,
            'systems' => $systems,
            'history' => $history,
        ]);
    }

    /**
     * Ajax 取得群組列表（供勾選）
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxGroups()
    {
        $stations = $this->broadcastService->getTargetStations();

        return response()->json($stations);
    }

    /**
     * Ajax 發送群發公告（帶 scheduled_at 則轉為預約）
     *
     * @param SendBroadcastRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxSend(SendBroadcastRequest $request)
    {
        $params = $request->validated();

        // 預約的公告到了時間才送，圖片必須先存起來並落庫，否則屆時找不到圖
        if ($request->hasFile('images')) {
            $params['image_urls'] = $this->broadcastService->uploadImages($request->file('images'));
        }

        try {
            if (filled($params['scheduled_at'] ?? null)) {
                $broadcast = $this->broadcastService->schedule($params, Auth::id());

                return response()->json([
                    'message'   => trans('broadcast.msg.schedule_success', [
                        'time' => $broadcast->scheduled_at->format('Y-m-d H:i'),
                    ]),
                    'scheduled' => true,
                ]);
            }

            $broadcast = $this->broadcastService->send($params, Auth::id());

            return response()->json([
                'message'       => trans('broadcast.msg.send_success', [
                    'total'   => $broadcast->total_count,
                    'success' => $broadcast->success_count,
                    'fail'    => $broadcast->fail_count,
                ]),
                'success_count' => $broadcast->success_count,
                'fail_count'    => $broadcast->fail_count,
            ]);
        } catch (\Exception $e) {
            Log::error('群發公告失敗', ['error' => $e->getMessage(), 'user_id' => Auth::id()]);

            return response()->json(['message' => trans('broadcast.msg.send_failed')], 500);
        }
    }

    /**
     * Ajax 取消預約公告
     *
     * @param TelegramBroadcast $broadcast
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxCancelSchedule(TelegramBroadcast $broadcast)
    {
        // 已送出或已取消的不能再取消，避免歷史紀錄被改動
        if (!$this->broadcastService->cancelSchedule($broadcast)) {
            return response()->json(['message' => trans('broadcast.msg.cancel_not_pending')], 422);
        }

        return response()->json(['message' => trans('broadcast.msg.cancel_success')]);
    }

    /**
     * Ajax 取得歷史公告紀錄
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxHistory(Request $request)
    {
        $perPage = (int) $request->input('per_page', config('constants.PAGINATION.DEFAULT', 10));

        $broadcasts = $this->broadcastService->list($perPage);

        return response()->json($broadcasts);
    }
}
