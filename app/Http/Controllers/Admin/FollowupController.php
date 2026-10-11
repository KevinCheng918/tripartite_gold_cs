<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Followup\DecideFollowupRequest;
use App\Http\Requests\Followup\ListFollowupRequest;
use App\Http\Resources\FollowupResource;
use App\Services\CustomerFollowupService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * 待追蹤事項控制器
 *
 * 對話裡有人說「稍等」就會記成一筆，這一頁是那些事項的待辦清單 ——
 * 平常由當班人員在 Telegram 按按鈕決定，這裡是「想看全部」或
 * 「按鈕訊息被洗掉了」時的入口。
 */
class FollowupController extends Controller
{
    private $followupService;

    public function __construct(CustomerFollowupService $followupService)
    {
        $this->followupService = $followupService;
    }

    /**
     * 列表頁
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        return view('admin.followup.index');
    }

    /**
     * Ajax 取得列表
     *
     * @param ListFollowupRequest $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function ajaxList(ListFollowupRequest $request)
    {
        $params = $request->validated();

        return FollowupResource::collection($this->followupService->list($params));
    }

    /**
     * Ajax 手動追蹤／結案
     *
     * @param DecideFollowupRequest $request
     * @param int                   $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxDecide(DecideFollowupRequest $request, $id)
    {
        $params = $request->validated();

        try {
            $followup = $this->followupService->decide(
                (int) $id,
                Auth::id(),
                (bool) Arr::get($params, 'follow')
            );

            return response()->json([
                'message' => trans('followup.msg.decided'),
                'data'    => new FollowupResource($followup),
            ]);
        } catch (\RuntimeException $e) {
            // 已經被處理過（在 Telegram 按過按鈕、或對方剛好回來了）
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('待追蹤事項處理失敗', [
                'followup_id' => $id,
                'user_id'     => Auth::id(),
                'error'       => $e->getMessage(),
            ]);

            return response()->json(['message' => trans('followup.msg.decide_failed')], 500);
        }
    }
}
