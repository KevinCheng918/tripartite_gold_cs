<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\DailyRate\UpdateRateRequest;
use App\Http\Requests\DailyRate\UpdateTemplateRequest;
use App\Services\DailyRateService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * 每日匯率控制器
 *
 * 平常匯率是從 Telegram 回覆決定的，這一頁用來看歷史、以及在需要時手動改。
 */
class DailyRateController extends Controller
{
    private $rateService;

    public function __construct(DailyRateService $rateService)
    {
        $this->rateService = $rateService;
    }

    /**
     * 匯率頁面
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        $params = $request->only(['per_page']);
        $perPage = (int) Arr::get($params, 'per_page', 30);

        return view('admin.daily-rate.index', [
            'rates'     => $this->rateService->history($perPage > 0 ? $perPage : 30),
            'todayRate' => $this->rateService->todayRate(),
            'template'  => $this->rateService->askTemplate(),
        ]);
    }

    /**
     * Ajax 手動設定某日匯率
     *
     * @param UpdateRateRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxUpdateRate(UpdateRateRequest $request)
    {
        $params = $request->validated();

        try {
            $this->rateService->setRate(
                Arr::get($params, 'date'),
                (float) Arr::get($params, 'rate'),
                Auth::id()
            );

            return response()->json(['message' => trans('daily_rate.msg.saved')]);
        } catch (\Exception $e) {
            Log::error('匯率手動設定失敗', ['error' => $e->getMessage()]);

            return response()->json(['message' => trans('daily_rate.msg.save_failed')], 500);
        }
    }

    /**
     * Ajax 儲存報價公版
     *
     * @param UpdateTemplateRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxUpdateTemplate(UpdateTemplateRequest $request)
    {
        $params = $request->validated();

        try {
            $this->rateService->saveAskTemplate(Arr::get($params, 'template'), Auth::id());

            return response()->json(['message' => trans('daily_rate.msg.template_saved')]);
        } catch (\Exception $e) {
            Log::error('匯率公版儲存失敗', ['error' => $e->getMessage()]);

            return response()->json(['message' => trans('daily_rate.msg.template_save_failed')], 500);
        }
    }

    /**
     * Ajax 預覽公版套用後的樣子
     *
     * @param UpdateTemplateRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxPreviewTemplate(UpdateTemplateRequest $request)
    {
        $params = $request->validated();

        return response()->json([
            'text' => $this->rateService->previewAskText(Arr::get($params, 'template')),
        ]);
    }
}
