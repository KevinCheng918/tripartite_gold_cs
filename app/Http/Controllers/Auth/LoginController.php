<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Repositories\UserRepository;
use App\Services\LoginLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * 登入控制器
 *
 * 使用 account 欄位登入，密碼以 Crypt::decrypt 比對（非 bcrypt），
 * 對齊主系統 tripartite_gold 的加密方式。
 */
class LoginController extends Controller
{
    private $userRepository;
    private $loginLogService;

    public function __construct(UserRepository $userRepository, LoginLogService $loginLogService)
    {
        $this->userRepository = $userRepository;
        $this->loginLogService = $loginLogService;
    }

    /**
     * 顯示登入頁面
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\View\View
     */
    public function showLoginForm()
    {
        if (Auth::check()) {
            return redirect()->route('admin.dashboard');
        }

        return view('auth.login');
    }

    /**
     * 處理登入請求
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function login(Request $request)
    {
        $params = $request->validate([
            'account'  => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = $this->userRepository->findByAccountForLogin($params['account']);

        $device = $request->userAgent();

        if (!$user || !$this->verifyPassword($params['password'], $user->password)) {
            Log::warning('Failed login attempt', ['account' => $params['account'], 'ip' => $request->ip()]);

            $this->loginLogService->record([
                'user_id'     => $user->id ?? null,
                'account'     => $params['account'],
                'ip'          => $request->ip(),
                'is_success'  => false,
                'device'      => $device,
                'fail_reason' => '帳號或密碼錯誤',
            ]);

            return back()->withErrors(['account' => trans('auth.failed')])->onlyInput('account');
        }

        // 停用帳號無法登入（鎖定帳號可以登入）
        if ((int) $user->status === config('constants.USER.STATUS.DEACTIVATE')) {
            $this->loginLogService->record([
                'user_id'     => $user->id,
                'account'     => $params['account'],
                'ip'          => $request->ip(),
                'is_success'  => false,
                'device'      => $device,
                'fail_reason' => '帳號已停用',
            ]);

            return back()->withErrors(['account' => trans('auth.disabled')])->onlyInput('account');
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        $this->loginLogService->record([
            'user_id'    => $user->id,
            'account'    => $params['account'],
            'ip'         => $request->ip(),
            'is_success' => true,
            'device'     => $device,
        ]);

        return redirect()->intended(route('admin.dashboard'));
    }

    /**
     * 登出
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * 比對密碼（Crypt::decrypt 解密後比對明文）
     *
     * @param string $input     使用者輸入的明文密碼
     * @param string $encrypted 資料庫中 Crypt::encrypt 加密後的密碼
     * @return bool
     */
    private function verifyPassword($input, $encrypted)
    {
        try {
            return $input === Crypt::decrypt($encrypted);
        } catch (\Exception $e) {
            Log::error('Password decryption failed', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
