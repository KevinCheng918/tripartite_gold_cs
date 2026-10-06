<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setting\UpdateClaudeRequest;
use App\Http\Requests\Setting\UpdateFallbackRequest;
use App\Services\SettingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * AI 引擎設定控制器（選單上叫「AI 引擎」，路由仍是 setting）
 *
 * Claude 憑證、備援 API、用量流量。
 *
 * ⚠ 內部支援群組、話題分流、班表通知 2026-10-06 搬到「通訊管理 → 通知設定」
 * （`NotificationController`）—— 那三組的共同點是「通知發到哪、發給誰」，
 * 跟憑證設定不是同一件事。
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

}
