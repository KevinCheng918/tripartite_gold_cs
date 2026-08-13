<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use App\Services\UsdtRateService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Dashboard 控制器
 *
 * 首頁儀表板，Admin 顯示帳號統計/今日排班/本週概況，
 * 客服顯示自己的排班資訊。
 */
class DashboardController extends Controller
{
    private $dashboardService;
    private $usdtRateService;

    public function __construct(DashboardService $dashboardService, UsdtRateService $usdtRateService)
    {
        $this->dashboardService = $dashboardService;
        $this->usdtRateService = $usdtRateService;
    }

    /**
     * Dashboard 頁面
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $data = Auth::user()->isAdmin()
            ? $this->dashboardService->getAdminData()
            : $this->dashboardService->getCsData(Auth::id());

        return view('admin.dashboard.index', $data);
    }

    /**
     * Ajax 取得 USDT 匯率（當前 + 4 小時 K 線）
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxUsdtRate()
    {
        try {
            $data = $this->usdtRateService->getRateWithHistory();

            return response()->json($data);
        } catch (\Exception $e) {
            Log::error('USDT 匯率取得失敗', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            return response()->json(['message' => '取得匯率失敗：' . $e->getMessage()], 500);
        }
    }
}
