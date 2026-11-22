# 自選閒置登出時間 ＋ 後台踢人下線（v3.98 已實作，**migration 待跑**）

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

## 實作後的補充（v3.98）

### 檔案

| 檔案 | 內容 |
|---|---|
| `database/migrations/…_add_session_fields_to_user_table.php` | `session_timeout`（分鐘，**預設 0 = 不登出**）、`force_logout_at` |
| `app/Http/Middleware/CheckForceLogout.php` | 比 `force_logout_at` 與 session 的 `login_at` |
| `app/Http/Kernel.php` | 掛在 `web` 群組**最後**（要讀 session） |
| `app/Http/Controllers/Auth/LoginController.php` | 登入時寫 `login_at`；登出帶 `?reason=idle` 時給提示 |
| `app/Services/AccountService.php` | `kick()`；`update()` 收 `session_timeout` |
| `app/Http/Requests/Account/KickRequest.php` | 擋踢自己 |
| `app/Http/Requests/Account/UpdateProfileRequest.php` | `session_timeout` 用 `in:` 限定在選項裡 |
| `resources/views/layouts/app.blade.php` | 兩個 meta ＋ 個人設定的下拉 |
| `public/js/common.js` | 閒置偵測與倒數 |
| `resources/views/admin/accounts/partials/row-actions.blade.php`、`index.blade.php` | 踢出按鈕與確認視窗 |

### ⚠ 幾個容易寫錯的地方

1. **`login_at` 要寫在 `session()->regenerate()` 之後** —— regenerate 會換掉
   session id，寫在前面的話那個值會跟著舊 session 被丟掉，結果是**每個人
   都看起來「沒有登入時間」而被誤踢**。
2. **舊 session 沒有 `login_at` 時要判定為「該踢」**。反過來設計的話，
   管理者按了踢出卻踢不掉這個功能上線前就登入著的人 —— 而那正是最可能
   需要踢的對象。
3. **`session_timeout` 用 `array_key_exists` 而不是 `filled`**：
   **0 是合法值**（不登出），用 filled 判斷的話永遠存不進去 ——
   想從「30 分鐘」改回「不登出」的人會發現按了沒反應。
4. **驗證用 `in:` 限定在選項裡**，不要只驗 integer：這一欄會被前端拿去
   倒數，塞一個 1 分鐘進來等於讓那個人一直被登出。
5. **捲動不算「有在動」**：只聽 `pointerdown` / `keydown` / `touchstart`。
   手機上輕碰、甚至頁面自己載入內容都會觸發 scroll，那會讓倒數永遠重來。
6. **閒置到點要用 POST 打真正的登出**，不能只把頁面導走 —— session 還在的話，
   下一個用這台電腦的人按上一頁就進得來，那正是這個設定要防的事。

### ⚠ migration 還沒跑也不會把後台弄壞

`layouts/app.blade.php` 每一頁都會讀 `Auth::user()->session_timeout`。
欄位還不存在時 Eloquent 回 `null` ⇒ `(int) null` 是 `0` ⇒ 不登出（＝現況）。
**本機實測確認過**，不是推論。

⚠ 但**個人設定存不進去**（Laravel 的 `isGuarded()` 會把未知欄位靜靜丟掉），
所以上線時 migration 還是要先跑。

## 驗證到哪

- 後端全檔 `php -l`、`common.js` `node --check` 都過
- 「欄位不存在時 `(int) null = 0`」本機實測過
- ⚠ **還沒端到端跑過**：要等 migration 跑完才能驗
  「設定存得進去」「閒置真的會登出」「踢人真的會把對方踢掉」

### ⚠⚠ 這個專案是 Laravel 8，不能用 `@selected()`／`@checked()`

第一版下拉用了 `@selected(...)` —— 那是 **Laravel 9** 才有的 Blade 指令。
8.83 不認得它，於是**整串原樣印進選單裡**：

```
session_timeout === (int) $minutes)> 不登出
```

改回傳統寫法：

```blade
<option value="{{ $minutes }}"
        {{ (int) Auth::user()->session_timeout === (int) $minutes ? 'selected' : '' }}>
```

⚠ 版本就寫在 `CLAUDE.md` 與 `PROMPTS.md` 的第一張表（Laravel v8.83.29），
**動手前該先看**。同一類的還有 `@checked()`、`@disabled()`、`@required()`、
`@readonly()` —— 全都是 Laravel 9 才有的，這個專案一個都不能用。
（已 grep 過，其他地方沒有誤用。）

## 按 PROMPTS.md 稽核（2026-11-23）

架構／效能／安全三節無違反：Controller 只接參數 → Service、用 `$params`
包裝；Service 不碰 HTTP；DB 只在 Repository；`select` 指定欄位；
`CheckForceLogout` 第一行就短路（沒登入、或沒被踢過的帳號完全不做事）；
單表寫入不需要交易；踢人與強制登出都有 Log；
權限 keyword ＋ route ＋ 語系三份都補齊。

修了三處：

1. ⚠ **被踢的人會看到錯誤的原因。** Ajax 路徑回 401，而 `AuthGuard.notify()`
   的提示視窗標題是**寫死的**「登入已過期」—— 被管理者踢下線的人會以為
   只是逾時，而那是兩件事（一個是時間到，一個是有人刻意請他下線）。
   `notify()` 改成可帶 `reason`，`ajaxError` 把後端的 message 傳進去。
2. ⚠ **改完設定要重整才生效。** 倒數原本在啟動時讀一次 meta 就記住 ——
   使用者在個人設定改完之後那一頁不會重載，而他不會知道要重整。
   改成**每次檢查都重讀 meta**，並在儲存成功時把新值寫回 meta。
3. ⚠ **踢人成功的 fallback 文案會變成「已把「」踢出登入」**（`:name` 是空的）。
   改用不帶名字的 `msg.kick_done`（三份語系）。

⚠ 第 1、2 項是同一類：**功能本身會動，但使用者得到的資訊是錯的或過時的**
—— 不會報錯，所以不稽核就看不到。
