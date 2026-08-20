<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\LoginLogResource;
use App\Services\LoginLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 登入紀錄控制器
 */
class LoginLogController extends Controller
{
    private $loginLogService;

    public function __construct(LoginLogService $loginLogService)
    {
        $this->loginLogService = $loginLogService;
    }

    /**
     * 登入紀錄頁面
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        return view('admin.login-log.index');
    }

    /**
     * Ajax 取得登入紀錄列表
     *
     * @param Request $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function ajaxList(Request $request)
    {
        $params = $request->only(['account', 'is_success', 'ip', 'start_date', 'end_date', 'per_page']);

        $logs = $this->loginLogService->list($params);

        return LoginLogResource::collection($logs);
    }

    /**
     * Ajax 取得自己的登入紀錄
     *
     * @param Request $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function ajaxMyLog(Request $request)
    {
        $params = $request->only(['per_page']);
        $perPage = $params['per_page'] ?? 10;

        $logs = $this->loginLogService->listByUser(Auth::id(), $perPage);

        return LoginLogResource::collection($logs);
    }
}
