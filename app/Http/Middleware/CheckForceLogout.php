<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * 被管理者踢出登入的人，下一個請求就把他登出
 *
 * 需求方 2026-11-22：「後台的帳號管理要有一個按鈕功能是踢出登入。」
 *
 * ⚠⚠ **為什麼不是直接刪他的 session**：`SESSION_DRIVER=file`，
 * session 檔名是 session id —— **沒辦法從 user_id 找到對應的檔案**。
 * 要能精準刪得改成 `database` driver（多一張表、每個請求多一次讀寫），
 * 為了一個按鈕改掉整個 session 基礎設施不划算。
 *
 * 所以改成比時間：
 *
 * | 什麼時候 | 做什麼 |
 * |---|---|
 * | 登入 | `session(['login_at' => now()])` |
 * | 管理者按踢出 | `user.force_logout_at = now()` |
 * | 每個請求（這裡） | `force_logout_at > login_at` ⇒ 登出 |
 *
 * ⚠ **`force_logout_at` 不需要被清掉**：它記的是「這個時間點之前的登入
 * 一律失效」。被踢的人重新登入時 `login_at` 會比它新，自然就過得去。
 *
 * ⚠ 頁面有心跳（15 分鐘）與對話頁的輪詢（30 秒），所以實際上幾十秒內
 * 那個人就會被踢回登入頁，不必等他自己點什麼。
 *
 * 詳見 .claude/knowledge/features/session-timeout-and-kick.md
 */
class CheckForceLogout
{
    /**
     * @param Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();

        // 短路：沒登入、或這個帳號從來沒被踢過 —— 絕大多數請求走這一條
        if (blank($user) || blank($user->force_logout_at)) {
            return $next($request);
        }

        $loginAt = $request->session()->get('login_at');

        /*
         * ⚠ **舊 session 沒有 `login_at`**（這個功能上線前就登入著的人）。
         * 那種情況當作「比踢出時間早」⇒ 要登出。
         *
         * 反過來設計（沒有就放行）的話，管理者按了踢出卻踢不掉上線前就
         * 登入的那些人 —— 而那正是最可能需要踢的對象。
         */
        if (filled($loginAt) && $user->force_logout_at->lte($loginAt)) {
            return $next($request);
        }

        Log::info('帳號被踢出登入，已強制登出', [
            'user_id'  => $user->id,
            'login_at' => $loginAt,
        ]);

        return $this->logout($request);
    }

    /**
     * 登出並導回登入頁
     *
     * ⚠ **要讓他知道是被踢的**：什麼都不講的話，他只會以為系統壞了，
     * 然後再登入一次（而這次會成功，因為新的 `login_at` 比較新）——
     * 管理者會以為踢人沒有用。
     *
     * @param Request $request
     * @return mixed
     */
    private function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Ajax 要回 401，交給既有的 AuthGuard（common.js）跳提示
        if ($request->expectsJson()) {
            return response()->json(['message' => trans('account.msg.kicked')], 401);
        }

        return redirect()->route('login')->withErrors(['account' => trans('account.msg.kicked')]);
    }
}
