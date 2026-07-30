<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Support\Facades\Auth;

/**
 * Dashboard 控制器
 *
 * 首頁儀表板，Admin 顯示帳號統計/今日排班/本週概況，
 * 客服顯示自己的排班資訊。
 */
class DashboardController extends Controller
{
    private $dashboardService;

    public function __construct(DashboardService $dashboardService)
    {
        $this->dashboardService = $dashboardService;
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
}
