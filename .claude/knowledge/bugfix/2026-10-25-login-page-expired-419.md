# 登入頁停留過久送出變 419

`SESSION_LIFETIME` 是 120 分鐘（`.env`）。登入頁開著不動超過這個時間，
session 過期、表單裡的 `_token` 就失效，按下登入直接吃 419。

⚠ **關鍵是 419 錯誤頁本身解不開這個狀況** —— 那頁重新整理會**重送同一份
POST**，帶的還是同一個舊 token，所以永遠 419。使用者只能自己手動回登入頁，
而多數人只會一直按重整。

> 主專案 `tripartite_gold` 已經修好，這邊照同一套做（需求方 2026-10-25 提供
> 整理好的做法）。

## 三層防護，缺一不可

| 層 | 位置 | 作用 |
|---|---|---|
| 1. 前端補 token | `public/js/auth/login-token.js` | 攔一次 submit，先取最新 token 換掉 `_token` 再送 |
| 2. 後端兜底 | `Handler::render()` | 第一層失敗時，把 419 換成「導回 GET 登入頁」 |
| 3. token 端點 | `LoginController::ajaxToken()` | 提供最新 token，回 JSON |

第一層是主要手段，第二層接住第一層失敗（斷線、逾時、回傳不是 JSON），
第三層讓第一層拿得到 JSON。

## 第一層：為什麼攔 submit 而不是定時刷新

定時刷新 token 要猜間隔 —— 猜短了浪費請求，猜長了還是會過期。
攔 submit 的時機最準：只在真的要送出時取一次。

⚠ **取不到 token 一律照原本的送出去**（逾時 5 秒、斷線、JSON 解析失敗都算）。
不要因為補 token 失敗就讓使用者連送都送不出去 —— 那比 419 更糟。
失敗交給第二層。

⚠ `form.submit()`（JS 方法）依規範**不會觸發 submit 事件**，所以不會回到
handler 形成迴圈。程式裡仍留 `submitting` 旗標當保險 ——
哪天改用 `requestSubmit()` 就真的會再觸發一次。

## 第二層：只收登入頁的表單 POST

```php
$e instanceof TokenMismatchException
    && $request->isMethod('post')
    && $request->is('login')
    && !$request->expectsJson()
```

⚠ 其他頁面的 419 **維持原本的錯誤頁** —— 那些頁面重整不會重送表單，
顯示 419 是合理的。AJAX 也不導頁，讓前端自己判斷
（`public/js/common.js` 的 `AuthGuard` 在處理 401／419，
見 [[2026-09-29-session-expired-silent]]）。

⚠ **只帶帳號回去**：`$dontFlash` 本來就含 `password`，`withInput()` 再用
`only('account')` 明確一次。把密碼 flash 進 session 是那種「看起來沒事」的外洩。

錯誤訊息掛在 `account` —— 登入頁是用 `$errors->all()` 不分 key 顯示的，
掛哪個 key 都看得到，掛 `account` 只是為了跟「帳號或密碼錯誤」同一個位置。

## 第三層：端點不能被任何會導頁的 middleware 包住

⚠ 會導頁的 middleware（`guest`、`auth`）會讓回應變成 HTML，
前端解析不出 JSON 就補不到 token。

本專案的 `LoginController` 沒有掛 `guest`（`showLoginForm()` 自己檢查
`Auth::check()`），所以只要**不要放進 `auth` 群組**即可。
主專案那邊是 `$this->middleware('guest')->except([..., 'ajaxToken'])`，
`except` 吃的是**方法名**不是路由名 —— 這邊沒有這個坑，但日後若加上
`guest` 要記得。

⚠ **自己給 `throttle:30`**：登入頁每送出一次就打一次。主專案的登入 group 掛了
`throttle:10`，端點放進去會變成「開頁 1 次 ＋ 送出 1 次，10 次限額只能試 5 組
密碼」。本專案的登入路由目前**沒有 throttle**，但還是把端點獨立出來並給自己的
限額 —— 哪天登入加上 throttle 就不會被這支吃掉。

### session 過期時為什麼取得到正確的 token

這支 GET 請求本身會開一個新 session，`csrf_token()` 回的就是新 session 的
token —— 正好是接下來那個 POST 需要的那一份。

### 安全性

回 CSRF token 給訪客聽起來危險，但：同網域 GET，而 `config/cors.php` 的
`paths` 是 `['api/*', 'sanctum/csrf-cookie']`，**web 路由不在 CORS 範圍內**，
他站讀不到回應。

⚠ **若日後把 `paths` 放寬到涵蓋 web 路由（例如 `['*']`），這支會變成跨站可讀，
CSRF 防護等於失效**。`allowed_origins` 目前是 `['*']`，改 `paths` 時必須連帶
評估這裡。

## 文案

⚠ 不要寫成「連線逾時」或「系統忙碌」—— 那會讓人以為是網路或系統有問題而一直
重試。他什麼都沒做錯，只是頁面開太久，重打一次就好：

> 登入頁開啟太久，請重新輸入帳號密碼再登入一次

## 驗證

**第三層**：`/login/ajax-token` 回 `200`、`application/json`、40 碼 token。

**第二層**（六種情況）：

| 情況 | 結果 |
|---|---|
| 登入頁 POST ＋ 419 | 導回登入頁 ✓ |
| 登入頁 GET ＋ 419 | 維持原本 ✓ |
| 別的頁面 POST ＋ 419 | 維持原本 ✓ |
| 登入頁 POST ＋ 419 但是 AJAX | 維持原本 ✓ |
| 登入頁 POST 但不是 419 | 維持原本 ✓ |
| token 端點 ＋ 419 | 維持原本 ✓ |

**完整走一次 `render()`**：狀態 302 → `/login`，帶回去的欄位**只有 `account`**
（密碼沒跟著回去），錯誤訊息正確。

⚠ **第一層沒有在瀏覽器實際跑過** —— 要真的把登入頁放著兩小時以上才複製得出
原本的情境。程式邏輯（攔 submit → 取 token → 換掉 → 送出；取不到就照送）
是照主專案已經驗過的那一份寫的。

## 相關位置

| 檔案 | 職責 |
|---|---|
| `public/js/auth/login-token.js` | 第一層 |
| `app/Exceptions/Handler.php`（`render()`／`isLoginTokenMismatch()`） | 第二層 |
| `app/Http/Controllers/Auth/LoginController.php`（`ajaxToken()`） | 第三層 |
| `routes/web.php` | `login.ajax-token`，獨立註冊 ＋ `throttle:30` |
| `resources/views/auth/login.blade.php` | form 的 `id` 與 `data-token-url` |
| `resources/lang/{tw,cn,en}/auth.php` | `page_expired` |
