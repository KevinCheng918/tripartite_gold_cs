<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\TelegramBroadcast\SendBroadcastRequest;
use App\Http\Requests\TelegramBroadcast\StoreTemplateRequest;
use App\Http\Requests\TelegramBroadcast\UpdateTemplateRequest;
use App\Http\Resources\BroadcastTemplateResource;
use App\Models\BroadcastTemplate;
use App\Models\TelegramBroadcast;
use App\Services\StationService;
use App\Services\TelegramBroadcastService;
use App\Services\TelegramChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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

            /*
             * 範本清單隨頁面一起送，不讓前端再打一次 ajax ——
             * 切到「公告管理」分頁時資料已經在手上，不會先空白一下才跳出來。
             * 新增／編輯／刪除之後才走 `ajax-templates` 重載。
             */
            'templates' => BroadcastTemplateResource::collection($this->broadcastService->templates()),
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

        /*
         * 選了建檔公告的話，先把要帶走的附件從**資料庫**撈出來 ——
         * 前端只給 template_id 與索引，路徑絕對不經過它（見 Request 的註解）。
         *
         * 範本的附件排在新上傳的前面：使用者看到的順序就是「原本那幾個，
         * 然後是我剛加的」。
         */
        $template = $this->broadcastService->findTemplate(Arr::get($params, 'template_id'));

        $images = $this->broadcastService->pickTemplateAttachments(
            $template,
            'image_urls',
            Arr::get($params, 'keep_image_indexes')
        );

        $files = $this->broadcastService->pickTemplateAttachments(
            $template,
            'file_urls',
            Arr::get($params, 'keep_file_indexes')
        );

        // 預約的公告到了時間才送，附件必須先存起來並落庫，否則屆時找不到檔案
        if ($request->hasFile('images')) {
            $images = array_merge($images, $this->broadcastService->uploadImages($request->file('images')));
        }

        if ($request->hasFile('files')) {
            $files = array_merge($files, $this->broadcastService->uploadFiles($request->file('files')));
        }

        $params['image_urls'] = filled($images) ? $images : null;
        $params['file_urls'] = filled($files) ? $files : null;

        /*
         * 編號**快照**進發送紀錄，歷史紀錄才看得出這次用的是哪一則公告。
         *
         * ⚠ 存字串不是 FK：公告之後被刪或改了編號，歷史紀錄該留的仍是
         * 「當時用的那一個」—— 那正是歷史紀錄要回答的問題。
         */
        $params['template_code'] = filled($template) ? $template->code : null;

        try {
            if (filled(Arr::get($params, 'scheduled_at'))) {
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

    // ---------------------------------------------------------------
    //  公告管理（建檔的範本）
    // ---------------------------------------------------------------

    /**
     * Ajax 範本清單
     *
     * ⚠ 這支**不綁 `broadcast_template` 權限**：只能發送的人也要看得到清單，
     * 否則「選擇已建檔的公告」那個下拉會是空的。能不能改才是另一個權限。
     *
     * ⚠ 首次進頁面**不會打這支** —— 清單隨頁面一起送（見 `index()`）。
     * 這裡只服務「新增／編輯／刪除之後的重載」。
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function ajaxTemplates()
    {
        return BroadcastTemplateResource::collection($this->broadcastService->templates());
    }

    /**
     * Ajax 建檔一則公告
     *
     * @param StoreTemplateRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxStoreTemplate(StoreTemplateRequest $request)
    {
        $params = $request->validated();

        try {
            $this->broadcastService->createTemplate(
                $params,
                $request->hasFile('images') ? $this->broadcastService->uploadImages($request->file('images')) : [],
                $request->hasFile('files') ? $this->broadcastService->uploadFiles($request->file('files')) : [],
                Auth::id()
            );

            return response()->json(['message' => trans('broadcast.msg.template_saved')]);
        } catch (\Exception $e) {
            Log::error('公告建檔失敗', ['error' => $e->getMessage(), 'user_id' => Auth::id()]);

            return response()->json(['message' => trans('broadcast.msg.template_failed')], 500);
        }
    }

    /**
     * Ajax 修改一則公告
     *
     * @param UpdateTemplateRequest $request
     * @param BroadcastTemplate     $template
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxUpdateTemplate(UpdateTemplateRequest $request, BroadcastTemplate $template)
    {
        $params = $request->validated();

        try {
            $this->broadcastService->updateTemplate(
                $template,
                $params,
                $request->hasFile('images') ? $this->broadcastService->uploadImages($request->file('images')) : [],
                $request->hasFile('files') ? $this->broadcastService->uploadFiles($request->file('files')) : []
            );

            return response()->json(['message' => trans('broadcast.msg.template_saved')]);
        } catch (\Exception $e) {
            Log::error('公告修改失敗', ['error' => $e->getMessage(), 'template_id' => $template->id]);

            return response()->json(['message' => trans('broadcast.msg.template_failed')], 500);
        }
    }

    /**
     * Ajax 刪除一則公告
     *
     * ⚠ 只刪這一筆，**不刪實體檔** —— 同一組路徑可能已經被複製進發送紀錄，
     * 刪檔會讓歷史紀錄的附件變成死連結（需求方 2026-10-13 的選擇）。
     *
     * @param BroadcastTemplate $template
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxDeleteTemplate(BroadcastTemplate $template)
    {
        try {
            $this->broadcastService->deleteTemplate($template);

            return response()->json(['message' => trans('broadcast.msg.template_deleted')]);
        } catch (\Exception $e) {
            Log::error('公告刪除失敗', ['error' => $e->getMessage(), 'template_id' => $template->id]);

            return response()->json(['message' => trans('broadcast.msg.template_failed')], 500);
        }
    }
}
