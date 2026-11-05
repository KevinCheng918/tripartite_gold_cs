# 登入私人通知

**需求方 2026-11-05**：

> 「我現在登入後台要發送私人通知，幾點幾分 IP 多少登入、作業系統、瀏覽器、裝置。」

**狀態：規劃中，等裁示。**

## 好消息：資料已經在記了，不用改登入流程

`LoginController::login()` 現在就已經把每次登入（成功與失敗）寫進 `login_log`：

| 欄位 | 內容 |
|---|---|
| `created_at` | 登入時間 ← 「幾點幾分」 |
| `ip` | 登入 IP ← 「IP 多少」 |
| `device` | **原始 User-Agent 整串** ← 作業系統／瀏覽器／裝置都藏在這裡面 |
| `is_success` / `fail_reason` | 成功失敗與原因 |

所以這個需求**不是「開始記錄」，而是「把已經記的東西解析出來、私訊出去」**。

## ⚠⚠ 最大的坑：UA 解析已經有一份，寫在 Blade 的 JS 裡

`resources/views/admin/accounts/index.blade.php` 第 827 行附近有一個
`parseUA()`，用正規表達式從 UA 撈出 OS 與瀏覽器，**只跑在瀏覽器端**。
通知要在伺服器端發，所以伺服器端也需要一份。

**兩份各自維護的下場今天才剛修過**：`StaffResource` 與 `AccountResource`
各寫一份 `calcTenure()`，兩份都有同一個 bug
（見 [[../bugfix/2026-11-05-tenure-diff-skips-a-day]]）。

> **所以這次要反過來做**：解析寫成 `UserAgentPresenter`（PHP），
> 塞進 `LoginLogResource` 一起回前端，**然後把 Blade 裡那段 JS 刪掉**。
> 畫面與通知從此看到同一份解析結果。

⚠ 沒有裝任何 UA 解析套件（`composer.json` 裡沒有 `jenssegers/agent`
之類）。PHP 版就照現有 JS 那組正規表達式搬過去，不為這個需求裝新套件。

## ⚠⚠ 第二個坑：不能在登入當下同步打 Telegram

`TelegramBotService` 的 HTTP client 是 `['timeout' => 10]`，而
`QUEUE_CONNECTION=sync` ——  **`dispatch()` 在這個專案是當場跑完，不是丟背景**
（既有的 `AutoReplyJob` 等等也都是同步跑）。

在登入流程裡直接發私訊的話：Telegram 慢或掛掉時，**使用者按下登入會卡最多
10 秒才進得去後台**。登入是所有人每天第一個動作，不能掛這種風險。

**建議照抄 `task:notify-changes` 的做法**（這個專案已經有這個模式）：
`login_log` 加一個 `notified_at`，新開 `login:notify` 每分鐘撈「成功登入但
還沒通知」的紀錄發出去。登入流程完全不動、零延遲，代價是通知最多慢 1 分鐘。

⚠ 要 `withoutOverlapping`，否則同一批會被撈兩次（`notified_at` 是發完才標）。

## ⚠ 第三個坑：沒綁機器人的人收不到，而且不會報錯

`StaffDmService::canDm()` 要 `telegram_user_id` **且** `telegram_dm_ready`
兩個都成立 —— Telegram 不讓 bot 主動私訊沒對話過的人。

沒私訊過 bot 的帳號，這個通知會**安靜地不送出**。對一般通知沒差，
但這是**安全性通知**：以為自己被保護著、其實沒有，比沒有這個功能更糟。

> 建議：後台人員管理列表要能看出「誰收不到登入通知」，
> 或至少在個人設定頁標一行提示。

## 通知長什麼樣（草稿）

```
🔐 您的帳號已登入

時間：2026-11-05 14:32
IP：203.0.113.45
作業系統：Windows 10
瀏覽器：Chrome 131
裝置：桌機

若這不是您本人的操作，請立即聯繫管理者更換密碼。
```

⚠ 「裝置」這一欄要討論 —— 見待釐清 4。

## 待釐清（等裁示）

### 1. 即時發，還是最多慢 1 分鐘？

| 做法 | 延遲 | 代價 |
|---|---|---|
| **每分鐘排程**（推薦）| 最多 60 秒 | 登入流程零風險；要加 `notified_at` 欄位 |
| 登入當下同步發 | 即時 | **Telegram 慢的時候登入會卡最多 10 秒** |

### 2. 每次登入都發，還是只有「新 IP／新裝置」才發？

每天開關電腦好幾次的人，每次都發等於每天好幾則私訊 —— 通知一吵就會被忽略，
而被忽略的安全性通知等於沒有。

| 做法 | 說明 |
|---|---|
| 每次都發 | 最符合字面需求，但會吵 |
| **只有沒見過的 IP 或裝置才發**（推薦）| 真正要被看見的就是這種 |
| 每次都發，但同一組 IP＋裝置 N 小時內只發一次 | 折衷 |

### 3. 登入失敗要不要也發？

⚠ **安全性上這個其實比成功登入更該通知** ——
「有人在試你的帳號但沒進去」是唯一還來得及反應的時機。
`login_log` 已經在記失敗了，發不發只是一個判斷。

建議：失敗**不要每筆都發**（有人打錯密碼三次會收到三則），
改成「同一帳號 N 分鐘內失敗滿 M 次才發一則」。N／M 請裁示。

### 4. 「裝置」要顯示什麼？

⚠ 現有那份 JS 把「裝置」對應到**排版引擎**（WebKit／Gecko／Trident）——
對使用者毫無意義，看到「WebKit」沒人知道那是什麼。

建議改成**桌機／手機／平板**（從 UA 的 Mobile／iPad／Android 判斷），
並且把既有畫面那一欄一起改掉。要不要保留引擎當附註請裁示。

### 5. 這個通知可以被關掉嗎？

`NOTICE.PERSONAL` 有一套個人靜音設定（班表、任務卡、催單、出勤四種），
技術上加一個 `login` 進去就能關。

⚠ **但安全性通知可以關掉是有疑慮的**：帳號一旦被入侵，
入侵者第一件事就能進後台把這個通知關掉，之後登入就完全無聲。

建議：**不納入可靜音清單**（`send()` 不傳 `noticeKey`）。請裁示。

### 6. 要不要附 IP 歸屬地（國家／城市）？

「IP 203.0.113.45」一般人看不出有沒有問題，「從越南登入」才看得出。
但這需要打外部 IP 查詢 API 或帶一份 GeoIP 資料庫 —— 是另一個工程量，
建議第一版不做，先確認要不要排後面。

### 7. 只通知本人，還是管理者也收一份？

目前假設**只私訊登入者本人**。要不要讓管理者收到「所有人的登入」
或「只有異常登入」請裁示。

## 預計動到的檔案（待裁示後才動工）

**新增**

```
database/migrations/..._add_notified_at_to_login_log_table.php   排程用的標記
app/Presenters/UserAgentPresenter.php                            UA → OS／瀏覽器／裝置
app/Services/LoginNoticeService.php                              組字 ＋ 發送
app/Console/Commands/LoginNotifyCommand.php                      login:notify
.claude/knowledge/features/login-notice.md（本檔）
```

**修改**

```
app/Repositories/LoginLogRepository.php   撈未通知的、標記已通知
app/Http/Resources/LoginLogResource.php   帶上解析後的 OS／瀏覽器／裝置
resources/views/admin/accounts/index.blade.php  ⚠ 刪掉那份 JS parseUA
app/Console/Kernel.php                    每分鐘排程
config/constants.php                      通知文案
resources/lang/{tw,cn,en}/login_log.php   欄位名
```

⚠ `LoginLogService::record()` 現在用了 `$params['user_id'] ?? null`
這種寫法，違反專案的 helper 規則（要用 `Arr::get`）—— 動到這支時一併修掉。

## 相關

- [[staff-manage]] — 人員管理（登入紀錄彈窗在這）
- [[telegram-chat]] — 私訊同仁的 `StaffDmService`
- [[../bugfix/2026-11-05-tenure-diff-skips-a-day]] — 兩份解析各自維護的教訓
