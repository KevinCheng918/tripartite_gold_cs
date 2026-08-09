<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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

    public function __construct(
        TelegramBroadcastService $broadcastService,
        TelegramChatService $chatService
    ) {
        $this->broadcastService = $broadcastService;
        $this->chatService = $chatService;
    }

    /**
     * 群發公告頁面
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $groups = $this->broadcastService->getTargetStations();
        $history = $this->broadcastService->list(20);

        return view('admin.telegram-broadcast.index', [
            'groups'  => $groups,
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
     * Ajax 發送群發公告
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxSend(Request $request)
    {
        $params = $request->validate([
            'content'     => 'required|string|max:4096',
            'target_type' => 'required|integer|in:1,2',
            'group_ids'   => 'nullable|array',
            'group_ids.*' => 'integer',
        ]);

        try {
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
     * Ajax 取得歷史公告紀錄
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxHistory(Request $request)
    {
        $perPage = (int) $request->input('per_page', 20);

        $broadcasts = $this->broadcastService->list($perPage);

        return response()->json($broadcasts);
    }
}
