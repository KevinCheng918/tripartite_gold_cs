<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setting\UpdateClaudeRequest;
use App\Http\Requests\Setting\UpdateFallbackRequest;
use App\Http\Requests\Setting\UpdateSupportRequest;
use App\Services\SettingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * 全域設定控制器
 *
 * Claude 憑證、內部支援群組、對客話術模板、用量流量都在這一頁。
 * 權限走既有 RBAC（setting.view / setting.manage），路由層已掛 can:。
 */
class SettingController extends Controller
{
    private $settingService;

    public function __construct(SettingService $settingService)
    {
        $this->settingService = $settingService;
    }

    /**
     * 設定頁
     *
     * 初始資料直接隨頁面送出，不讓前端再發一次 ajax ——
     * 組這份資料只要幾毫秒，多一次往返只會讓畫面先空一拍才填值。
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        return view('admin.setting.index', [
            'initialSettings' => $this->settingService->forPage(),
        ]);
    }

    /**
     * Ajax 取得全部設定與用量
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxSettings()
    {
        return response()->json($this->settingService->forPage());
    }

    /**
     * Ajax 更新 Claude 主要設定
     *
     * @param UpdateClaudeRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxUpdateClaude(UpdateClaudeRequest $request)
    {
        $params = $request->validated();

        try {
            if (!$this->settingService->updateClaude($params, Auth::id())) {
                return response()->json(['message' => trans('setting.msg.token_invalid')], 422);
            }

            return response()->json(['message' => trans('setting.msg.saved')]);
        } catch (\Exception $e) {
            Log::error('Claude 設定更新失敗', ['error' => $e->getMessage(), 'user_id' => Auth::id()]);

            return response()->json(['message' => trans('setting.msg.save_failed')], 500);
        }
    }

    /**
     * Ajax 更新備援設定
     *
     * @param UpdateFallbackRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxUpdateFallback(UpdateFallbackRequest $request)
    {
        $params = $request->validated();

        try {
            if (!$this->settingService->updateFallback($params, Auth::id())) {
                return response()->json(['message' => trans('setting.msg.api_key_invalid')], 422);
            }

            return response()->json(['message' => trans('setting.msg.saved')]);
        } catch (\Exception $e) {
            Log::error('備援設定更新失敗', ['error' => $e->getMessage(), 'user_id' => Auth::id()]);

            return response()->json(['message' => trans('setting.msg.save_failed')], 500);
        }
    }

    /**
     * Ajax 更新內部支援群組設定
     *
     * @param UpdateSupportRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxUpdateSupport(UpdateSupportRequest $request)
    {
        $params = $request->validated();

        try {
            $this->settingService->updateSupport($params, Auth::id());

            return response()->json(['message' => trans('setting.msg.saved')]);
        } catch (\Exception $e) {
            Log::error('支援群組設定更新失敗', ['error' => $e->getMessage(), 'user_id' => Auth::id()]);

            return response()->json(['message' => trans('setting.msg.save_failed')], 500);
        }
    }

    /**
     * Ajax 發測試訊息到內部支援群組
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxTestSupport()
    {
        if (!$this->settingService->testSupport()) {
            return response()->json(['message' => trans('setting.msg.support_test_failed')], 422);
        }

        return response()->json(['message' => trans('setting.msg.support_test_sent')]);
    }
}
