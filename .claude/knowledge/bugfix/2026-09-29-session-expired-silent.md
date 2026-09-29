# session 過期時沒有任何人看得懂的提示

## 現象

客服回報：打卡按了確認、也看到成功訊息，但紀錄沒進去，而且「沒有重新登入」。

## 起因

兩個缺陷疊在一起。

### 一、8 份 apiFetch 沒有一份處理 401／419

全站有 8 支各自實作的 `apiFetch`（attendance、attendance-detail、shifts、
broadcast、reply-template-admin、station、setting-admin、quick-reply-admin），
每一支都只是把後端的 `message` 原樣顯示：

```js
.catch(function (error) { showMessage(getErrorMessage(error)); });
```

session 一過期，使用者看到的就是這個 —— 實測 `POST` 不帶有效 CSRF token：

```
HTTP 419
{"message": "CSRF token mismatch.", ...}
```

看不懂、不知道要重新登入、也不會被導到登入頁。
未登入打 API 則是 `401 {"message":"Unauthenticated."}`（`Authenticate::redirectTo()`
在 `expectsJson()` 時回 null，所以是 401 而不是 302）。

### 二、SESSION_LIFETIME 只有 120 分鐘

客服上班把頁面掛著一整天是常態。早上打完上班卡之後不再操作，
**下班要打卡時 session 早就過期了** —— 這正是最容易踩到的場景。

## 做法

### AuthGuard（`public/js/common.js`）

任何 AJAX 收到 401／419 就跳「登入已過期，請重新登入」並導向登入頁。
8 支 apiFetch 各加三行接上：

```js
var intercepted = window.AuthGuard && window.AuthGuard.intercept(response);

if (intercepted) { return intercepted; }
```

> ⚠️ **`intercept()` 接手時回的是「永遠不 settle 的 Promise」**，這是故意的。
> 讓呼叫端的 `then`／`catch` 都不會跑，畫面上就只有這個提示視窗，
> 不會再疊一個看不懂的「Unauthenticated.」。反正下一步就是離開頁面。

> ⚠️ **攔截必須在 `response.json()` 之前**，只看 status。
> 有些情況（例如被導到登入頁的 HTML）body 根本不是 JSON，
> 先 parse 會先炸在 SyntaxError 上，攔不到真正的原因。

> ⚠️ **jQuery 的 `$.ajax` 要另外攔。**（2026-09-29 補）
> 任務看板、帳號、站台、虛擬機等**十幾個頁面走的是 jQuery 而不是 apiFetch**，
> 只改 apiFetch 的話那些頁面 session 過期時依然靜悄悄。
> `common.js` 加一個全域 `$(document).ajaxError`，一次涵蓋所有 `$.ajax`，
> 不必去改每一支呼叫。jqXHR 有 `status` 屬性，`isExpired()` 只看 status，
> 直接吃得下。
>
> jQuery 在 layout 的第 500 行、`common.js` 在 509 行 —— 順序是對的。

其他細節：

- 提示視窗**動態建立**而不是寫在 layout —— 各頁面的訊息 Modal id 都不一樣
- 顯示前先關掉畫面上其他 `.modal.show`，否則會疊兩層 backdrop，
  而且那些視窗上的按鈕按了也沒用；等 300ms 讓關閉動畫跑完再開，避免 backdrop 殘留
- `expiredNotified` 旗標只提示一次 —— 一個畫面同時發好幾支 AJAX 是常態
- 文字走語系檔，由 `layouts/app.blade.php` 的 `<body data-auth-expired-*>` 傳進來
  （`common.js` 讀不到 PHP 語系檔，同 `TimeSelect` 的 `data-*-label` 作法）

### Session 心跳

`GET /admin/ajax-ping`（`DashboardController::ajaxPing()`），每 15 分鐘一次。

**這支刻意什麼都不做** —— 能走到 controller 就代表 `auth` middleware 通過，
而 session driver 會在請求結束時把 last activity 往後推。
續期的效果來自「有發出這次請求」本身，不是回傳的內容。

不綁權限 keyword：任何登入中的帳號都要能續期。

> ⚠️ **`visibilitychange` 時要補一次 ping。**
> 分頁切到背景時瀏覽器會把 `setInterval` 壓到最慢一分鐘一次，電腦休眠更是完全停住。
> 回到頁面時先補一次，使用者才不會在「看起來正常」的畫面上按下去才發現已經過期。

只在 `/admin` 開頭的頁面跑心跳，登入頁不需要。

### 打卡不再「假成功」

以前是 `.then(function () { 顯示成功 })` —— 連回傳內容都沒接，只要 HTTP 2xx 就報成功。
改成檢查後端回的 `AttendanceResource` 有沒有 `id`：

```js
function isSaved(body) {
    var record = body && (body.data || body);

    return !!(record && record.id);
}
```

## 沒有查清楚的部分

**「顯示打卡成功卻沒有紀錄」這段的確切機制沒有重現。**

依現有程式碼，`.then()` 只在 HTTP 2xx 且 body 是合法 JSON 時才跑，
所以要顯示成功，後端必須真的回了 2xx。本機 log 停在 09-23，查不到當時那一次。

上面三項修完之後，同樣情況再發生時會是明確的「登入已過期」或「打卡失敗」，
而不是無聲的成功。若仍有「顯示成功但沒紀錄」，那就是後端問題，
要從正式站的 `storage/logs` 與 `attendance_record` 表回頭查。

## 異動檔案

| 檔案 | 改什麼 |
|---|---|
| `public/js/common.js` | `AuthGuard`、心跳 |
| `public/js/{attendance,attendance-detail,shifts,broadcast,reply-template-admin,station,setting-admin,quick-reply-admin}.js` | apiFetch 接上攔截（各三行） |
| `public/js/attendance.js` | `isSaved()`，打卡不再假成功 |
| `app/Http/Controllers/Admin/DashboardController.php` | `ajaxPing()` |
| `routes/web.php` | `admin/ajax-ping` |
| `resources/views/layouts/app.blade.php` | `<body data-auth-expired-*>` |
| `resources/lang/{tw,cn,en}/auth.php` | `expired_title` / `expired_hint` / `expired_action` |

## 相關

- [[attendance]] — 打卡與補打卡
