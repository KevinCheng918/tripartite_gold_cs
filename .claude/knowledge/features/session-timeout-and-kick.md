# 自選閒置登出時間 ＋ 後台踢人下線（規劃中，待確認）

> 需求方 2026-11-22：「我個人設定這邊想要有一個自選登出的間隔時間，現在好像
> 都是用 laravel session time，也可以選完全不登出，然後我後台的帳號管理要有
> 一個按鈕功能是踢出登入。」
>
> 同日確認：選項 = **30 分／1 小時／4 小時／8 小時／不登出**；
> **每個人都能選「不登出」**（管理者隨時可以踢）。

## ⚠⚠ 先講一件事：現在的 120 分鐘其實沒在作用

`SESSION_LIFETIME=120`，但 `common.js` **每 15 分鐘無條件 ping 一次**
（`/admin/ajax-ping`，而且分頁從背景切回來還會補一次）——
只要瀏覽器分頁開著，session 就一直被往後推。

所以現況實際上是：

| 情況 | 現在會怎樣 |
|---|---|
| 分頁開著、人去吃飯三小時 | **不會登出**（心跳一直在續期） |
| 關掉分頁 | 120 分鐘後過期 |

⚠ 換句話說，**「完全不登出」就是現在的行為** —— 這個需求真正要新增的是
「**我要它在閒置 N 分鐘後登出**」那幾個選項。

⚠ 對話頁還有每 30 秒的輪詢，那也會續期 —— 所以「閒置」**不能用
「最後一個請求的時間」來判斷**，那永遠不會過期。

## 怎麼判斷「閒置」

只能看**真人有沒有動**：`pointerdown` / `keydown` / `touchstart`。

```
真人操作 → 記下時間
每分鐘檢查一次：現在 − 最後操作 > 使用者設定？
   ├─ 是 → 停掉心跳、打登出、導回登入頁（帶一個「閒置登出」的提示）
   └─ 否 → 照常
```

⚠ **心跳與輪詢不算操作** —— 它們是程式發的，不是人。這是整件事的重點。

⚠ **選「不登出」就完全不倒數**（等於現狀），心跳照跑。

### ⚠ 這是「便利」不是「安全」

倒數在前端，懂的人可以繞過。真正的安全界線仍然是後端的
`SESSION_LIFETIME`（關掉分頁後 120 分鐘）與下面的「踢出登入」。

這點要寫在設定旁邊，不要讓人以為勾了 30 分鐘就等於強制登出。

## 踢出登入

### ⚠ file driver 查不到「誰的 session」

`SESSION_DRIVER=file`，session 檔名是 session id —— **沒辦法從 user_id
找到對應的檔案**。要能精準刪，得改成 `database` driver（多一張表、
每個請求多一次 DB 讀寫）。

為了一個按鈕改掉整個 session 基礎設施不划算，所以走另一條：

### 用「踢出時間」比對

| 步驟 | 做什麼 |
|---|---|
| 登入時 | `session(['login_at' => now()])` |
| 管理者按「踢出登入」 | `user.force_logout_at = now()` |
| 每個請求的 middleware | `force_logout_at > session('login_at')` ⇒ `Auth::logout()` |

⚠ **下一個請求就生效**。而對話頁有輪詢、每頁有心跳，所以實際上
幾十秒內那個人就會被踢回登入頁，不必等他自己點什麼。

⚠ **被踢的人要看得到原因**：導回登入頁時帶一句「您已被管理者登出」，
不然他只會以為系統壞了，然後再登入一次。

⚠ 踢完之後 `force_logout_at` **不清掉** —— 它記的是「這個時間點之前的
登入都無效」。那個人重新登入時 `login_at` 會比它新，自然就過得去。

## 權限

**新開一個 keyword `account.kick`**，不要併進 `account.update`。

理由：改別人的資料與**把正在工作的人踢下線**是兩件事 ——
後者會中斷他手上正在回的客人訊息。該由管理者單獨指派。

## 檔案清單

| 檔案 | 動作 |
|---|---|
| `database/migrations/…_add_session_fields_to_user_table.php` | `session_timeout`（分鐘，0 = 不登出）、`force_logout_at` |
| `app/Models/User.php` | 兩個欄位的 cast 與 PHPDoc |
| `config/constants.php` | `USER.SESSION_TIMEOUT`（選項清單與預設） |
| `app/Http/Middleware/CheckForceLogout.php` | 新增；掛在 `web` ＋ `auth` 之後 |
| `app/Http/Kernel.php` | 註冊 middleware |
| `app/Http/Controllers/Admin/AccountController.php` | `ajaxKick()`；`ajaxUpdateProfile()` 收 `session_timeout` |
| `app/Http/Requests/Account/KickRequest.php` ＋ `config/rules.php` | 驗證 |
| `app/Services/AccountService.php` | `kick()`、個人設定存逾時 |
| `routes/web.php` | `accounts/ajax-kick`（`can:account.kick`） |
| `config/permissionMap.php` | 加 `account.kick` keyword |
| `resources/views/layouts/app.blade.php` | 個人設定 modal 加「閒置多久自動登出」下拉 |
| `resources/views/admin/accounts/index.blade.php`（或 partials） | 每一列加「踢出登入」按鈕 |
| `public/js/common.js` | 閒置偵測與倒數（心跳那一段隔壁） |
| `public/js/admin-roles.js` 或 accounts 的 JS | 踢出按鈕 |
| `resources/lang/{tw,cn,en}/profile.php`、`account.php`、`permission.php` | 文案（三份同步） |
| `.claude/knowledge/features/session-timeout-and-kick.md`、`INDEX.md` | 本檔與索引 |
| `config/changelog.php` | v3.98 |

## 待釐清

1. **踢出要不要留紀錄？** 目前打算寫一行 log（誰踢了誰）。
   要能在後台查的話就得開一張表，這一版不做。
2. **要不要讓使用者看到自己「還有多久登出」？** 目前不做 ——
   畫面上多一個倒數只會讓人焦慮，而且它每分鐘才檢查一次，
   秒數對不起來反而更怪。
3. **被踢的人正在打字怎麼辦？** 沒有辦法救 —— 那是踢人這個動作的本質。
   所以按鈕要有二次確認，並在確認框寫明「他正在打的訊息會不見」。
