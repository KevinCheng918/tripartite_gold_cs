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
            'rates'      => $this->rateService->history($perPage > 0 ? $perPage : 30),
            'todayRate'  => $this->rateService->todayRate(),
            'template'   => $this->rateService->askTemplate(),
            'screenshot' => $this->rateService->screenshotStatus(),
        ]);
    }

    /**
     * Ajax 立即報價：把早上 9 點那套完整跑一次
     *
     * 等同 `rate:ask --force`。跟「測試截圖」不同 —— 這會真的建立今天的紀錄、
     * 送出完整報價訊息，而且**可以直接在群組引用回覆來測整條迴路**。
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxAskNow()
    {
        $result = $this->rateService->ask(true);

        if (Arr::get($result, 'sent') === true) {
            return response()->json([
                'message' => trans(Arr::get($result, 'with_chart') === true
                    ? 'daily_rate.msg.ask_sent_with_chart'
                    : 'daily_rate.msg.ask_sent'),
            ]);
        }

        $reason = (string) Arr::get($result, 'reason');

        return response()->json([
            'message' => trans("daily_rate.msg.ask_{$reason}"),
        ], 422);
    }

    /**
     * Ajax 截圖測試：截一張發到內部群組
     *
     * 環境裝好 Chrome 之後先按這個，確認截得到、而且截到的是想要的畫面，
     * 再等明天早上的自動報價。
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxTestScreenshot()
    {
        $result = $this->rateService->testScreenshot();

        if (Arr::get($result, 'ok') === true) {
            return response()->json([
                'message' => trans('daily_rate.msg.screenshot_sent', [
                    'binary' => Arr::get($result, 'binary'),
                ]),
            ]);
        }

        $reason = (string) Arr::get($result, 'reason');

        return response()->json([
            'message' => trans("daily_rate.msg.screenshot_{$reason}"),
        ], 422);
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
