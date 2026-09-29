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
        $user = Auth::user();
        $data = $user->isAdmin()
            ? $this->dashboardService->getAdminData()
            : $this->dashboardService->getCsData(Auth::id());

        // 非管理者有 shift.view 權限時，補上排班資料
        if (!$user->isAdmin() && $user->hasPermission('shift.view') && !isset($data['weekUserRanking'])) {
            $shiftData = $this->dashboardService->getShiftData(Auth::id());
            $data = array_merge($data, $shiftData);
        }

        return view('admin.dashboard.index', $data);
    }

    /**
     * Ajax 心跳：把 session 的有效期往後延
     *
     * 客服上班會把頁面掛著一整天，但 SESSION_LIFETIME 只有 120 分鐘 ——
     * 早上打完上班卡之後不再操作，下班要打卡時 session 早就過期了。
     *
     * 這支刻意什麼都不做：能走到這裡就代表 `auth` middleware 通過，
     * 而 session driver 會在請求結束時把 last activity 往後推，
     * 續期的效果來自「有這一次請求」本身，不是回傳的內容。
     *
     * 不綁權限 keyword —— 任何登入中的帳號都要能續期。
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxPing()
    {
        return response()->json(['ok' => true]);
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
