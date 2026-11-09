# 求助單逾時升級：催到第 10 次就打電話把人叫起來

> **狀態：已實作（2026-11-09，v3.83）。上線前要做 7 件事，見文末。**
>
> userbot 響鈴已**實測成功** —— 那是整個功能的前提，驗證過程見「打電話」一節。

## 需求（需求方 2026-11-08）

> 「我想要用機器人打電話，如果當求助單提醒超過 10 次，就代表已經逾時一個
> 多小時了，我要機器人打電話給當班人，提醒要上線處理」
>
> 打不通：「重撥幾次，還是不接的話，就傳私人訊息給勾選清單」

## 「10 次 ≈ 一個多小時」的相依 ⚠⚠

現行預設：首次 10 分鐘、之後每 10 分鐘 → 第 10 次約在開單後 **100 分鐘**。

> **次數上限（`KEY_REMIND_MAX_COUNT`，預設 30）如果被設成小於升級門檻，
> 提醒會在升級之前就停止 —— 電話永遠不會響，而且不會有任何錯誤。**

`getTicketsDueForRemind()` 用 `remind_count <= $max` 收單，上限設 8 的話
第 10 次根本不存在。→ **設定頁要擋下來**：次數上限必須大於升級門檻。

## 升級鏈（需求方 2026-11-09 確認）

```
第 1～9 次   群組 tag（現行行為，一行不動）

第 10 次     階段 A：電話 #1 ＋ 私訊當班人
  +5 分       電話 #2  （單還沒處理的話）
  +10 分      電話 #3
  +11 分      階段 B：私訊勾選清單   ← 不等，排程下一輪就發
  之後        停止升級（群組提醒仍照常催到上限 30 次）
```

| 項目 | 決定 |
|---|---|
| 升級門檻 | 第 **10** 次 |
| 響鈴長度 | **30 秒** |
| 撥號次數 | 最多 **3 次**，間隔 **5 分鐘** |
| 階段 B 何時啟動 | 三通撥完後**不等**，單還沒處理就發 |
| 總開關 | **預設關** |
| 階段 C | **沒有** |

### ⚠⚠ 「都沒接」實作成「單還沒被處理」，不是「電話沒被接起」

**系統分不出「沒接」與「接了但斷線」。** 那通電話接起來一定會斷
（我們不串音訊），所以通話狀態不可靠。

唯一可信的訊號是**那張單的狀態**（離開 PENDING／ANSWERED）。
同理也不能用「已讀私訊」—— bot 讀不到已讀。

⚠ 這其實比「接通就停」安全：**人被叫起來不等於事情被處理**。

### ⚠ 階段 A 要私訊而不只是打電話

電話把人叫起來，但**電話裡沒有內容**（只響不串音訊）—— 他醒了要知道是哪一張
單，那得靠私訊。而且群組 tag 他已經無視九次了，私訊是真的會單獨跳出來的一層。

## 打電話：userbot 響鈴（2026-11-09 實測成功）

### 為什麼是「只響不說話」

- **Bot API 完全沒有語音通話能力** —— 不是權限問題，bot 不能打也不能接
- **userbot 串音訊那一層沒人維護**：PHP 的 MadelineProto 已移除 VoIP 且需
  PHP 8.2+（本專案 7.4）；Python 的 `pytgcalls` 做的是群組語音聊天，
  一對一的 `pytgvoip` 多年未更新
- **但「響」不需要音訊**：`phone.requestCall` → 對方手機響 →
  `phone.discardCall` → 未接來電。**完全不碰音訊層**

> 電話之所以有用，正是因為它**穿得過靜音與勿擾模式**。而要做到那件事，
> 只需要「響」。該做什麼在 Telegram 訊息裡已經寫了十次。

### 實測結論（2026-11-09）

| 檢查 | 結果 |
|---|---|
| Telegram 接受撥號請求 | ✅ |
| **對方手機真的響** | ✅ ← 這是分水嶺，API 接受不等於對方被通知 |
| 接得起來 | ✅（接起來會斷線，體感可接受） |

⚠ spike 腳本原本的結論寫「響鈴可行」只憑 API 回傳就下判斷，**那是錯的措辭**
—— 已改成要求實際去看手機。

### 架構：Python sidecar

PHP 不可能直接做 MTProto 語音，所以外接一支 Python 腳本，用
`Symfony\Component\Process` 呼叫 —— 這個專案已經有兩個同樣的先例
（Claude Code CLI、headless Chrome 截圖）。

```
TicketEscalationService (PHP)
  → TelegramCallService (PHP, 包 Process)
    → tools/tg_call.py (Python, Telethon)
      → phone.requestCall → 等 N 秒 → phone.discardCall
    ← stdout JSON: {ok, answered, call_id} 或 {ok:false, error}
```

### ⚠ 上線前的準備（這些比程式更容易漏）

1. **在伺服器上跑一次互動登入**產生 session 檔（要收驗證碼，必須人工做一次）
2. **用 userbot 帳號把所有會被排班的同仁加成聯絡人** —— 沒設 `@username`
   的人要靠這個才撥得到
3. **確認同仁的「誰可以打電話給我」** 沒設成僅聯絡人（或確認 userbot 已在
   他們的聯絡人裡），否則會 `USER_PRIVACY_RESTRICTED` 而且不會響

### ⚠ 封號風險是真的

Telethon 登入時自己會跳：`remember to not break the ToS or you will risk an
account ban`。自動化撥號是典型 spam 訊號，所以：

- 撥號次數**寫死上限**（3 次），不開放設定頁調到更高
- 那個帳號**不要跟任何人的主帳號共用**
- `api_id` 的申請是**擋 IP 不是擋帳號**（2026-11-09 實測：同網路換帳號仍被擋、
  改用手機 4G 熱點就過了）

## 設定項

| 項目 | 預設 | 存在哪 |
|---|---|---|
| 總開關 | **關** | `app_setting` |
| 升級門檻（第幾次） | 10 | `app_setting` |
| 響鈴秒數 | 30 | `app_setting` |
| 撥號間隔（分鐘） | 5 | `app_setting` |
| 勾選清單（階段 B 私訊給誰） | 空 | `app_setting`，沿用既有 `KEY_*_MANAGER` 那套 |

⚠ **撥號次數上限（3）寫在 `constants` 不開放設定頁** —— 它是封號風險的煞車，
不該讓人在介面上調大。

⚠ **預設關**：這個功能會私訊人**還會撥號**，而勾選清單一開始是空的。
上線時把清單設好、聯絡人加好、session 建好，再打開。

## 紀錄表 `auto_reply_ticket_escalation`

```
id
auto_reply_ticket_id  FK auto_reply_ticket, cascadeOnDelete
stage                 tinyint  1=打電話, 2=私訊當班人, 3=私訊勾選清單
seq                   int      觸發時那張單已經催到第幾次
attempt               int      同一階段的第幾次（撥號用，1-3；私訊固定 1）
user_id               FK user  nullable + nullOnDelete；通知／撥打的對象
user_name             string   名稱快照
result                tinyint  1=成功, 2=送不出去（沒綁定／沒 username）, 3=被拒絕（隱私設定等）
detail                string   nullable；錯誤碼或通話結果
created_at / updated_at
index (auto_reply_ticket_id, stage)
```

⚠ **一次升級撥給／私訊給幾個人就幾列**（比照 `auto_reply_ticket_remind`）
—— 之後要回答「誰最常被叫起來」「誰最常收不到」才有資料。

⚠ **不複用 `auto_reply_ticket_remind`**：那張表記的是「**群組**提醒 tag 到誰」。
升級是另一種管道，混進去之後「這張單被催了幾次」會同時包含群組與私訊／電話，
超時統計的每個數字都要先問「這筆是哪一種」。同
`conversation_followup` 當初不跟 `auto_reply_ticket` 共用的理由。

## ⚠ 私訊送不到的人

`StaffDmService` 要對方**先私訊過 bot**（`telegram_dm_ready`）。沒綁定的人：

- 私訊不出去 → **在支援群組補一則**說明「X 沒綁定，收不到私訊」，
  不要靜靜失敗（比照班表通知的處理）
- 記進 `result` = 2

## 預計異動檔案（實作前的估算）

> ⚠ **以文末「實作」那一節為準。** 這份估算有一處沒成真：
> `AutoReplySupportService::remindOne()` **一行都沒動** —— 改成獨立排程，
> 理由見文末。

### 新增

| 檔案 | 內容 |
|---|---|
| `database/migrations/*_create_auto_reply_ticket_escalation_table.php` | 紀錄表 |
| `app/Models/AutoReplyTicketEscalation.php` | Model |
| `app/Repositories/AutoReplyTicketEscalationRepository.php` | 寫入與查詢 |
| `app/Services/TicketEscalationService.php` | 判斷該不該升級、送出、記錄 |
| `app/Services/TelegramCallService.php` | 包 Process 呼叫 sidecar |
| `tools/tg_call.py` | Python sidecar（Telethon） |
| `app/Http/Requests/Notification/UpdateTicketEscalationRequest.php` | 設定頁驗證 |

### 修改

| 檔案 | 改什麼 |
|---|---|
| `app/Services/AutoReplySupportService.php` | `remindOne()` 送出成功後呼叫升級判斷 |
| `app/Console/Kernel.php` | 撥號重試要每分鐘檢查（接在既有的 `auto-reply:remind` 之後或另一支） |
| `app/Services/AppSettingService.php` | 五個 KEY |
| `app/Services/NotificationSettingService.php` ＋ 設定頁 Request/Blade/JS | 設定區 ＋ **次數上限必須大於升級門檻**的驗證 |
| `config/constants.php` | `TICKET_ESCALATION`（階段、預設、文案模板） |
| `config/telegram_call.php`（新） | sidecar 路徑、session 路徑、api 憑證走 env |
| `resources/lang/{tw,cn,en}/notification.php` | 設定欄位與訊息 |

⚠ 新增 `config/constants.php` 頂層 key 之前先
`grep -n "^    'TICKET_ESCALATION'" config/constants.php` —— 2026-11-08 踩過
`SHIFT_HANDOVER` 撞名、**PHP 重複陣列 key 無聲覆蓋**把別的功能弄壞的坑。

## 相關

- [[auto-reply]] — 求助單流程與狀態機
- [[remind-escalation]] — 現行的群組提醒升級（第 3 次起 tag 主管）
- [[shift-handover-topic]] — 另一個「叫人起來」的功能

---

## 實作（2026-11-09，v3.83）

### 架構上的一個改變：不掛在 `remindOne()`

原規劃是掛在 `AutoReplySupportService::remindOne()`。實作時發現**對不上** ——
撥號重試是 5 分鐘一次，而提醒是 10 分鐘一次。

→ 改成**獨立的每分鐘排程 `ticket:escalate` 自己查**
（`getEscalationCandidates()`：open 狀態 ＋ `remind_count >= 門檻`），
**既有的提醒流程一行都沒動**。

這也讓整條鏈的狀態機只有一個地方在推進，看升級紀錄就知道下一步：

```
沒有紀錄            → 電話 #1 ＋ 私訊當班人
撥 < 3 且距上通 ≥5分 → 下一通
撥滿 3 且沒私訊清單  → 私訊勾選清單
都做完              → 停
```

### ⚠⚠ 實作時抓到的三個真 bug

**1. `filled($this->staffDm->send(...))`** —— `send()` 回 **bool**，
而 **`filled(false)` 在 Laravel 是 true** ⇒ 每一次私訊失敗都會被記成成功，
**而且不報錯**。這個專案的 `NotificationSettingService` 註解警告過同一件事。

**2. 撥號與私訊要用兩份不同的名單。**
`getMentionableByIds()` 有 `telegram_username`（撥號要）但**沒有**
`telegram_user_id` / `telegram_dm_ready`（私訊要）；`getForDmByIds()` 反之。
拿錯一份會**靜默失敗** —— `UserRepository` 的註解寫明少了 `telegram_dm_ready`
會讓所有人被判定成沒綁定。

**3. `UserRepository::getByIds()` 根本不存在**（我憑印象寫的）。

### ⚠ 兩邊都要擋的互鎖

升級門檻必須小於提醒次數上限，否則提醒會在升級之前就停止、**電話永遠不會響
而且不報錯**。

**兩個分頁各自存檔**，所以 `UpdateTicketEscalationRequest` 與
`UpdateRemindRequest` 都要擋 —— 使用者可能先設好門檻 10，之後才去另一頁把
上限調成 8。

⚠ 升級功能**關著時不擋**：那時門檻沒有意義，擋下來只會讓人莫名其妙改不了一個
跟他無關的設定。

### 封號風險的煞車

| 做法 | 為什麼 |
|---|---|
| `MAX_CALLS = 3` 寫死在 constants | **不開放設定頁調整** —— 它是封號風險的煞車 |
| 撥號間隔 `min:3` 分鐘 | 連撥是 Telegram 判定 spam 的主要行為樣態 |
| 排程 `withoutOverlapping` | 一通響 30 秒、逐人撥，整輪很容易超過一分鐘；重疊會讓同一張單被撥兩次 |

### sidecar 的設計

`tools/tg_call.py` **一律印一行 JSON**（失敗路徑也印），PHP 端只讀那一行。
實測過兩種失敗路徑（缺憑證、缺 session）都正確回 JSON 並 exit 1。

⚠ 錯誤分成 `retryable` 與否：隱私設定、被封鎖、找不到對象這類**重撥也不會好**，
記成「被拒絕」；其餘記成「送不出去」。兩種要分得出來 ——
前者要去調對方的隱私設定，後者是我們這邊的問題。

### 實測

| 檢查 | 結果 |
|---|---|
| 互鎖：門檻 10／30／50 vs 上限 30 | ✅ 只有 10 通過 |
| 互鎖：上限 8 ＋ 升級**關** | ✅ 通過（不該擋） |
| 互鎖：上限 8 ＋ 升級**開** | ✅ 擋下 |
| 響 120 秒 / 間隔 1 分 | ✅ 都擋下 |
| 開關預設 | ✅ `false` |
| `run()` 關閉時 | ✅ 回 0，不查 DB |
| sidecar 失敗路徑 | ✅ 都回一行 JSON |
| DI、路由、指令註冊 | ✅ |
| 三語 key | ✅ 289/289/289 |

⚠ 驗證腳本**沒有寫 DB**（改設定會動到 `app_setting`）—— 用容器替身換掉
`AppSettingService` 來跑跨欄位驗證。

### 上線要做的事

1. **跑 migration**（建 `auto_reply_ticket_escalation` 表）
2. **在伺服器上跑一次互動登入**產生 Telethon session：
   `TG_API_ID=… TG_API_HASH=… TG_SESSION=storage/app/telegram/call_session python3 tools/tg_call.py @someone`
   （第一次會問手機與驗證碼）
3. **`.env` 設 `TG_CALL_API_ID` / `TG_CALL_API_HASH`**
4. **容器裡 `pip3 install telethon`**
5. **用 userbot 帳號把會被排班的同仁加成聯絡人**（沒設 `@username` 的人要靠這個）
6. **請同仁確認「誰可以打電話給我」** 沒設成僅聯絡人
7. **到通知設定把收件人勾好，再打開開關**

⚠ 前面六步沒做完就打開開關的話：`TelegramCallService::isConfigured()` 會回
false，只會私訊、不會撥電話（設定頁有黃色提示）。那是刻意的降級而不是壞掉。
