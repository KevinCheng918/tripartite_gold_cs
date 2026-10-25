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
                // 帳號不存在時 $user 是 null，所以不能直接取 ->id
                'user_id'     => filled($user) ? $user->id : null,
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
     * 給登入頁取一份最新的 CSRF token
     *
     * 三層防護的第三層。登入頁開著不動超過 `SESSION_LIFETIME`（預設 120 分鐘）
     * 之後，表單裡的 `_token` 已經失效，按下登入會吃 419；
     * `public/js/auth/login-token.js` 在送出前先打這一支換掉它。
     *
     * ⚠ **session 過期時這支也拿得到正確的 token**：這個 GET 請求本身會開一個
     * 新 session，`csrf_token()` 回的就是新 session 的那一份 ——
     * 正好是接下來那個 POST 需要的。
     *
     * ⚠ **不能擋未登入的人**（它本來就是給還沒登入的人用的），
     * 也不要在這支上面掛會改回應型別的 middleware —— 前端拿到 HTML
     * 解析不出 JSON 就補不到 token。
     *
     * ⚠ **回 CSRF token 給訪客不會削弱防護**：同網域 GET，而
     * `config/cors.php` 的 `paths` 只有 `api/*`，web 路由不在 CORS 範圍內，
     * 他站讀不到回應。
     * **若日後把 `paths` 放寬到涵蓋 web 路由（例如 `['*']`），這支會變成
     * 跨站可讀，CSRF 防護等於失效** —— `allowed_origins` 目前是 `['*']`，
     * 改 `paths` 時必須連帶評估這裡。
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxToken()
    {
        return response()->json(['token' => csrf_token()]);
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
