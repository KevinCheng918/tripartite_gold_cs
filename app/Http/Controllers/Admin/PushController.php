<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Repositories\UserRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Web Push 訂閱控制器
 *
 * 處理瀏覽器推播通知的訂閱與取消。
 */
class PushController extends Controller
{
    private $userRepository;

    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    /**
     * 儲存 Web Push 訂閱
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxSubscribe(Request $request)
    {
        $params = $request->validate([
            'endpoint'    => 'required|string',
            'keys.p256dh' => 'required|string',
            'keys.auth'   => 'required|string',
        ]);

        $this->userRepository->savePushSubscription(
            Auth::user(),
            $params['endpoint'],
            $params['keys']['p256dh'],
            $params['keys']['auth']
        );

        return response()->json(['status' => 'ok']);
    }

    /**
     * 取消 Web Push 訂閱
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxUnsubscribe()
    {
        $this->userRepository->clearPushSubscription(Auth::user());

        return response()->json(['status' => 'ok']);
    }
}
