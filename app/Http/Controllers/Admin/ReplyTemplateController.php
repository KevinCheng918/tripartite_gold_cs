<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReplyTemplate\UpdateTemplateRequest;
use App\Services\ReplyTemplateService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * 對客話術控制器
 *
 * 自動回覆送給客人的訊息內容。與 Claude 憑證分開一頁、分開授權 ——
 * 客服要能自己調語氣，但不該碰得到 token。
 */
class ReplyTemplateController extends Controller
{
    private $replyTemplateService;

    public function __construct(ReplyTemplateService $replyTemplateService)
    {
        $this->replyTemplateService = $replyTemplateService;
    }

    /**
     * 話術頁
     *
     * 初始資料直接隨頁面送出，不讓前端再發一次 ajax ——
     * 只是讀八個欄位，多一次往返只會讓畫面先空一拍才填值。
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        return view('admin.reply-template.index', [
            'initialTemplates' => $this->replyTemplateService->forPage(),
        ]);
    }

    /**
     * Ajax 取得全部模板
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxTemplates()
    {
        return response()->json($this->replyTemplateService->forPage());
    }

    /**
     * Ajax 更新模板
     *
     * @param UpdateTemplateRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxUpdate(UpdateTemplateRequest $request)
    {
        $params = $request->validated();

        try {
            $this->replyTemplateService->update($params, Auth::id());

            return response()->json(['message' => trans('reply_template.msg.saved')]);
        } catch (\Exception $e) {
            Log::error('對客話術更新失敗', ['error' => $e->getMessage(), 'user_id' => Auth::id()]);

            return response()->json(['message' => trans('reply_template.msg.save_failed')], 500);
        }
    }
}
