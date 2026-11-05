# 登入私人通知

**需求方 2026-11-05**：

> 「我現在登入後台要發送私人通知，幾點幾分 IP 多少登入、作業系統、瀏覽器、裝置。」

**狀態：已實作（v3.77，2026-11-05）。**
**⚠ 上線前置：跑 migration `2026_11_06_000001`（加 `notified_at`）；正式機 `php artisan optimize`。沒跑 migration 的話 `login:notify` 每分鐘報錯。**

## 好消息：資料已經在記了，不用改登入流程

`LoginController::login()` 現在就已經把每次登入（成功與失敗）寫進 `login_log`：

| 欄位 | 內容 |
|---|---|
| `created_at` | 登入時間 ← 「幾點幾分」 |
| `ip` | 登入 IP ← 「IP 多少」 |
| `device` | **原始 User-Agent 整串** ← 作業系統／瀏覽器／裝置都藏在這裡面 |
| `is_success` / `fail_reason` | 成功失敗與原因 |

所以這個需求**不是「開始記錄」，而是「把已經記的東西解析出來、私訊出去」**。

## 架構：登入只寫紀錄，排程負責發

```
LoginController::login()          登入當下只寫 login_log（本來就會寫）
        ↓
login:notify（每分鐘，withoutOverlapping）
        ↓
LoginNoticeService::run()         撈 notified_at 為 null 的 → 判斷 → 組字
        ↓
UserAgentPresenter::parse()       UA → 作業系統／瀏覽器／裝置
StaffDmService::send()            私訊本人（不傳 noticeKey ＝ 不可靜音）
        ↓
LoginLogRepository::markNotified()  處理完就標記（含決定不發的）
```

⚠ **為什麼不在登入當下發**：`TelegramBotService` 的 HTTP client 是
`['timeout' => 10]`，而 `QUEUE_CONNECTION=sync` —— **`dispatch()` 在這個專案
是當場跑完、不是丟背景**（既有的 `AutoReplyJob` 等等也都是同步跑）。
在登入流程裡發私訊的話，Telegram 慢或掛掉時使用者按下登入會卡最多 10 秒
才進得去後台。登入是所有人每天第一個動作。

⚠ 要 `withoutOverlapping`，否則同一批會被撈兩次（`notified_at` 是處理完才標）。

⚠ 沒有裝任何 UA 解析套件（`composer.json` 裡沒有 `jenssegers/agent` 之類），
也刻意不為這個需求裝 —— 規則就這幾條，搬一份正規表達式比多一個依賴便宜。

## 通知長什麼樣

成功登入（已知來源）：

```
🔐 您的帳號已登入

時間：2026-11-06 14:32
IP：203.0.113.45
作業系統：Windows 10
瀏覽器：Chrome 131
裝置：桌機

若這不是您本人的操作，請立即更換密碼並聯繫管理者。
```

沒見過的來源**不另外發一則**，而是在同一則後面加一行
「⚠️ 這是我們沒有見過的登入來源。」—— 成功登入已經每次都發了，
再多一則會變成同一件事兩則訊息。

失敗另有兩種版本（陌生來源／連續失敗），四種文案都在
`constants.LOGIN_NOTICE`。

## 裁示結果（需求方 2026-11-05／06）

| 題目 | 裁示 |
|---|---|
| 發送時機 | **每分鐘排程**，不在登入當下同步發 |
| 成功登入 | **每次都發** |
| 失敗登入 | **沒見過的 IP 或裝置才發** |
| 裝置欄位 | **桌機／手機／平板** |
| 可否關閉 | **不可** |
| IP 歸屬地 | 第一版不做 |
| 收件人 | 只私訊登入者本人 |

⚠ 需求方一開始同時選了「每次成功登入都發」與「只有沒見過的 IP 或裝置才發」，
確認後才釐清：**前者是成功的規則、後者是失敗的規則**，不是互斥的二選一。

### 失敗登入的兩條路（實作時的補充設計）

單純「沒見過才發」會漏掉一種情況：已知裝置上有人慢慢試密碼。所以分兩條：

| 情況 | 行為 |
|---|---|
| 沒見過的 IP 或裝置 | **第 1 次就發**，不等累積 —— 那才是可疑的 |
| 已知 IP 與裝置 | 5 分鐘內失敗滿 3 次才發一則 |

⚠ 後者的門檻是為了「自己打錯密碼不該每次都被私訊」。
窗口與門檻在 `constants.LOGIN_NOTICE`。

## 實作時踩到／刻意處理的

### ⚠⚠ 又多一份：UA 解析原本有**兩份** JS，不是一份

規劃時只找到 `admin/accounts/index.blade.php` 的 `parseUA()`。
實作時才發現 `layouts/app.blade.php` 還有一份 `myLoginLogParseUA()`
（「我的登入紀錄」彈窗用的），兩份規則一字不差地重複。

加上伺服器端需要的那一份，本來會變成**三套各自維護**。

兩份 JS 都已刪除，改由 `LoginLogResource` 回 `os`／`browser`／`device_type`，
三個出口（兩個彈窗 ＋ Telegram 通知）看同一份 `UserAgentPresenter`。

### ⚠⚠ 裝置指紋不能用 User-Agent 原字串

Chrome 每次小版本更新 UA 就變（`131.0.6778.86` → `131.0.6778.140`）。
拿原字串比「見過沒有」的話，**每個人每隔幾天就收到一則「新裝置登入」**——
通知一吵就被忽略，而被忽略的安全通知等於沒有。

`UserAgentPresenter::signature()` 只取名稱不含版本號：`Windows|Chrome|桌機`。
實測 131.0.6778.86 / .140 / 132 三者指紋相同、換 macOS 才不同。

### ⚠⚠ 「見過」只採計成功登入

把失敗也算進「見過」的話，攻擊者第一次失敗之後他的 IP 就變成「見過的 IP」，
後續連續嘗試全部降級成要等累積門檻 —— **等於自己把警報關小聲**。
`knownIps()`／`knownDevices()` 都有 `where('is_success', 1)`。

### ⚠⚠ migration 一定要回填既有紀錄

`notified_at` 留 null 的話，`login:notify` 第一次跑會把**整個歷史**判成未通知。
正式機累積上千筆 —— 每個人會在同一分鐘內被轟幾十則幾個月前的登入，
而 Telegram 訊息收回不來。回填成 `created_at`（不是 `now()`）：
語意是「這筆在它發生的當下就算處理完了」。

### ⚠ 回看窗口防「排程停掉後的補發雪崩」

排程停一天再恢復，沒有下限會把一整天的登入全補發。超過
`LOOKBACK_MINUTES`（30）的由 `staleNoticeIds()` 標記掉、不發。

⚠ 那些**一定要標記**，不是放著 —— 留 null 會永遠被 `whereNull` 掃到。

### ⚠ 新人第一次登入不報「新來源」

完全沒有成功登入歷史時 `isNewSource()` 回 false。
不然新人會在自己剛設定好帳號的那一刻收到「這是我們沒有見過的登入來源」——
技術上正確，對他毫無意義。

### ⚠ 刻意不納入可靜音清單

`StaffDmService::send()` 不傳 `$noticeKey`（需求方裁示）。
帳號被入侵時，入侵者第一件事就能把這個通知關掉。

### 已知限制：沒綁機器人的人收不到，而且不報錯

`canDm()` 要 `telegram_user_id` 與 `telegram_dm_ready` 都成立。
這是**安全通知** —— 以為自己被保護著、其實沒有，比沒有這個功能更糟。
每輪會把人數記一筆 `Log::warning`（記數量不記逐人，免得洗掉日誌）。
要查是誰看帳號管理列表「有沒有私訊過機器人」那一欄。

### ⚠ 順手發現：`admin.login-log.index` 的 view 不存在

`routes/web.php` 有 `login-log.index` 指向 `view('admin.login-log.index')`，
但 `resources/views/admin/login-log/` **整個目錄都沒有** —— 打開那個網址會
`View not found`。側邊欄的「登入紀錄」走的是 `layouts/app.blade.php` 的彈窗
（`menu-go-login-log` → `modal-my-login-log`），所以 UI 上沒人踩到。

**這次沒修**（不在需求範圍），但那條路由是壞的，要嘛補 view 要嘛拿掉。

## 實測

**UA 解析（13 個案例）**

| 檢查 | 結果 |
|---|---|
| iPad 不被判成 macOS（UA 含 `Mac OS X`）| iPadOS 17.1／平板 ✅ |
| Android 手機 vs 平板（差在有無 `Mobile`）| 手機／平板 ✅ |
| Edge／Opera 不被判成 Chrome（都含 `Chrome/`）| Edge 131／Opera 115 ✅ |
| iOS 版 Chrome（`CriOS`）| Chrome 131／手機 ✅ |
| Android 不被判成 Linux | Android 14 ✅ |
| Windows NT 6.1 → 7 | Windows 7 ✅ |
| 空字串／`curl/8.4.0` | 不爆，回 `-` ✅ |
| 瀏覽器只留主版號 | `Chrome 131`（原字串仍在 `device`）✅ |

**「沒見過的來源」判斷（7 項）全數通過**，含：同 IP ＋ Chrome 升版判已知、
陌生 IP 判新、同 IP 換 iPhone 判新、新人無歷史判已知。

**累積門檻**：1／2 次不發、3／5 次發。

**四種通知文案**都實際渲染出來看過。

**Command 與排程**：`login:notify` 已註冊、`schedule:list` 顯示 `* * * * *`。
語系三語同步（`login_log`／`account` 各缺 0）。

⚠ **端到端還沒跑過**：`notified_at` 欄位要等需求方跑 migration。
跑完後建議先 `php artisan login:notify --dry-run` 看一輪會發什麼再開排程。

## 動到的檔案

**新增**

```
database/migrations/2026_11_06_000001_add_notified_at_to_login_log_table.php
app/Presenters/UserAgentPresenter.php        UA → OS／瀏覽器／裝置 ＋ 指紋
app/Services/LoginNoticeService.php          判斷 ＋ 組字 ＋ 發送
app/Console/Commands/NotifyLoginCommand.php  login:notify（有 --dry-run）
.claude/knowledge/features/login-notice.md（本檔）
```

**修改**

```
app/Repositories/LoginLogRepository.php   pendingNotices／staleNoticeIds／markNotified／
                                          knownIps／knownDevices／countFailuresSince
                                          ＋ 順手修掉 paginate() 的 6 處 `??`
app/Http/Resources/LoginLogResource.php   帶上 os／browser／device_type
app/Models/LoginLog.php                   notified_at 的 cast 與 @property
resources/views/admin/accounts/index.blade.php  ⚠ 刪掉 parseUA()
resources/views/layouts/app.blade.php           ⚠ 刪掉 myLoginLogParseUA()
app/Console/Kernel.php                    每分鐘排程
config/constants.php                      LOGIN_NOTICE：門檻與四種文案
resources/lang/{tw,cn,en}/login_log.php   field_os／field_browser／device_*
```

## 相關

- [[staff-manage]] — 人員管理（登入紀錄彈窗在這）
- [[telegram-chat]] — 私訊同仁的 `StaffDmService`
- [[../bugfix/2026-11-05-tenure-diff-skips-a-day]] — 兩份解析各自維護的教訓
