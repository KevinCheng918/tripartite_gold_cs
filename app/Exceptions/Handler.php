<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Session\TokenMismatchException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    /**
     * 登入頁停留太久送出時，把 419 換成「導回登入頁」
     *
     * 三層防護的第二層，接住第一層（`public/js/auth/login-token.js`）補 token
     * 失敗的情況 —— 斷線、逾時、回傳不是 JSON 都算。
     *
     * ⚠ **419 錯誤頁本身解不開這個狀況**：那頁重新整理會重送同一份 POST，
     * 帶的還是同一個舊 token，所以永遠 419，使用者只能自己手動回登入頁。
     * 導回 GET 登入頁才拿得到新的 token。
     *
     * @param \Illuminate\Http\Request $request
     * @param Throwable                $e
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws Throwable
     */
    public function render($request, Throwable $e)
    {
        if ($this->isLoginTokenMismatch($request, $e)) {
            /*
             * ⚠ **只帶帳號回去**。`$dontFlash` 本來就含 `password`，
             * 這裡再用 `only()` 明確一次 —— 把密碼 flash 進 session
             * 是那種「看起來沒事」的外洩。
             *
             * 錯誤訊息掛在 `account`：登入頁是用 `$errors->all()` 不分 key
             * 顯示的，所以掛哪個 key 都看得到，掛 `account` 是為了跟
             * 「帳號或密碼錯誤」同一個位置。
             */
            return redirect()->route('login')
                ->withInput($request->only('account'))
                ->withErrors(['account' => trans('auth.page_expired')]);
        }

        return parent::render($request, $e);
    }

    /**
     * 這是不是「登入頁停留過久」造成的 419
     *
     * ⚠ **只收登入頁的表單 POST**。其他頁面的 419 維持原本的錯誤頁 ——
     * 那些頁面重整不會重送表單，顯示 419 是合理的；AJAX 也不導頁，
     * 讓前端自己判斷（`public/js/common.js` 的 `AuthGuard` 在處理）。
     *
     * @param \Illuminate\Http\Request $request
     * @param Throwable                $e
     * @return bool
     */
    private function isLoginTokenMismatch($request, Throwable $e)
    {
        return $e instanceof TokenMismatchException
            && $request->isMethod('post')
            && $request->is('login')
            && !$request->expectsJson();
    }
}
