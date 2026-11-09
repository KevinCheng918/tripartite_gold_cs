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
2. **跑 `php artisan telegram:call-login`** 產生 Telethon session
   （它會告訴你要填什麼；憑證沒設時會把 .env 要加的兩行印出來）
3. **`.env` 設 `TG_CALL_API_ID` / `TG_CALL_API_HASH`**
4. **容器裡 `pip3 install telethon`**
5. **用 userbot 帳號把會被排班的同仁加成聯絡人**（沒設 `@username` 的人要靠這個）
6. **請同仁確認「誰可以打電話給我」** 沒設成僅聯絡人
7. **到通知設定把收件人勾好，再打開開關**

⚠ 前面六步沒做完就打開開關的話：`TelegramCallService::isConfigured()` 會回
false，只會私訊、不會撥電話（設定頁有黃色提示）。那是刻意的降級而不是壞掉。

---

## 收尾稽核（2026-11-09）

1559 行新增逐條核對：`??`／`isset`／`empty`／`!filled` 零處、陣列下標讀取零處、
字串串接零處、`fn(`／`else`／`!== null` 零處。Controller 無 DB、Service 無 HTTP
也無 `Model::where`、新增查詢都有 `select()`、三語 289/289/289。

⚠ `AppSettingService` 整包設定有 `Cache::remember`，所以 `isCallDue()` 在迴圈裡
呼叫 `getInt()` 是讀快取不是查 DB —— **查證過才敢說沒有 N+1**。

### ⚠⚠ 抓到的風險：`withoutOverlapping()` 的鎖預設 24 小時

`withoutOverlapping()` 不帶參數時 `$expiresAt = 1440`（分鐘）。

而 `ticket:escalate` 是所有排程裡**最可能跑超過一分鐘**的一支：開子程序撥號、
一通響 30 秒、逐個當班的人撥。也因此**最可能被 OOM／部署／重開機砍掉** ——
被砍掉的 process 不會釋放鎖，**升級就會整整停擺一天**。

而那是「客人已經等了快兩小時」的最後一道防線。

→ 改成 `withoutOverlapping(10)`。10 分鐘足夠蓋過最壞情況
（3 個當班 × 30 秒 × 幾張單），又讓意外中斷在十分鐘內自己恢復。

⚠ 其他排程都是裸的 `withoutOverlapping()` —— 它們跑得快、被砍的機率低，
所以沒有一起改。要改的話是另一個任務。

## 互動登入做成 artisan 指令（需求方 2026-11-09）

> 「互動登入這個能不能做成一個 seeder，我執行就好，含我要填入的資訊」

**不是 seeder，是 artisan 指令**：

    php artisan telegram:call-login

⚠ **為什麼不是 seeder**：seeder 是灌 DB 資料用的，而這件事**沒有任何資料要
寫進資料庫** —— 它要的是互動輸入（手機號碼、驗證碼）。seeder 跑在非互動
環境時會直接卡住。

指令會做的事：

| 情況 | 它會怎樣 |
|---|---|
| 憑證沒設 | **把要貼進 .env 的兩行直接印出來**，不是只說「請設定環境變數」 |
| session 已存在 | 問要不要覆蓋 |
| 登入前 | 說明接下來會被問的三件事（手機／驗證碼在 App 不是簡訊／兩步驗證） |
| 登入後 | 用 `TelegramCallService::isConfigured()` 確認，再列出剩下的準備步驟 |

⚠ **`setTty(true)` 不能省**：Telethon 的提示要讀 stdin，沒有 TTY 的話使用者
看得到問題卻打不進去，而且會卡住到逾時。抓到 TTY 例外時會提示改用
`docker-compose exec workspace bash` 進互動式 shell。

⚠ `setTimeout(null)`：這是人在輸入，沒有合理的逾時可言。

⚠ 撥號那支（`tg_call.py`）**刻意不做登入** —— 排程不該停下來等驗證碼，
所以它找不到 session 就直接回 `SESSION_NOT_FOUND`。兩支的分工是刻意的。

### 不加新版號

鎖的過期時間是**不可見的穩定性修正**；登入指令是**給部署的人用的**，
而版本紀錄面板是**給客服看的**（既有條目每一條都是客服看得見的事）。
v3.83 已涵蓋這個功能對客服的全部變化。
先例見 [[../bugfix/2026-11-05-validation-message-swallowed]] 的同名小節。

## ⚠⚠ 上線時踩到的：「我跑得起來」不等於「排程跑得起來」

2026-11-09 實際發生，兩段都值得記。

### 一、錯誤訊息怪錯了對象

IT 把 telethon 裝在 venv（`/home/lv_cs_uat/venv-tg/bin/python`，Python 3.12），
系統 Python 是 3.9 沒有 telethon。`TG_CALL_PYTHON` 還沒設時跑登入指令，
畫面只說：

    請先安裝 telethon：pip3 install telethon

**但 telethon 明明裝好好的** —— 真正的問題是「跑到了另一支 Python」。
那句訊息讓人往「是不是沒裝」查，方向完全錯。

→ 登入指令現在會**先印出用的是哪一個 Python、以及它的 telethon 版本**，
失敗時直接說「這個 Python 沒有 telethon」並附上修法。
撥號失敗的 Log 也補上 `python` 欄位 —— `TELETHON_NOT_INSTALLED` 幾乎都不是
真的沒裝，而是跑錯直譯器。

### 二、「沒設 TG_CALL_PYTHON 也能用」是個陷阱

需求方回報：沒加 `.env` 那一行，登入指令**照樣成功**。

原因是他用 `rduser` 從**互動式 shell** 跑，那個使用者的 `PATH` 把 venv 排在
前面，所以裸的 `python3` 解析到了有 telethon 的那一支。

⚠⚠ **但真正撥號的是 cron 與 php-fpm，它們的 PATH 不一樣** —— 很可能解析到
系統的 3.9，然後**在客人已經等了兩小時的那一刻靜悄悄失敗**。

→ 直譯器**不是絕對路徑時，就算探測成功也要警告**（`warnIfNotAbsolute()`）。
這不是潔癖：它擋的是一個**只在最需要這個功能的時候才會現形**的坑。

### 給部署的三條規則

| 規則 | 為什麼 |
|---|---|
| `TG_CALL_PYTHON` 一定要**絕對路徑** | `Symfony\Component\Process` 不經 shell；php-fpm 與 cron 的 PATH 跟登入的 shell 不同 |
| **先改 `.env`、再 `config:cache`** | 反過來的話快取裡是舊值，看起來像沒生效 |
| 用**排程會用的那個身分**跑登入 | session 檔的擁有者要是 cron／apache 讀得到的 —— 用錯身分會「登入成功但排程撥不出去」 |

⚠ 第三點是 IT 主動提醒的，而且他是對的。

---

## 測試撥號（需求方 2026-11-09，v3.84）

> 需求原話：「我想要做一個測試撥電話給某位客服，確認真的可以接通」

### 為什麼需要

逾時升級**平常完全不會動作** —— 它要等到有單催滿 10 次（約 100 分鐘）才第一次
撥號。也就是說：撥號設定壞掉的話，第一個發現的時機，正好是客人已經等了
一百分鐘、而這個功能是最後一道防線的那一刻。

所以要有一個「現在就撥一通」的按鈕。

### 放在哪、為什麼

「通知設定 → 求助單提醒 → 逾時升級」那張卡的**最下面，表單外**。

| 決定 | 理由 |
|---|---|
| 放這張卡 | 設定跟測試要在同一個地方，去另一頁找只會找不到 |
| 放在 `form-escalation` **外面** | 放進去的話，下拉選單會被一起送出，而更新 API 沒有這個欄位。也跟其他九個「測試發送」一致 —— 測試從來不經過存檔 |
| `type="button"` | 否則會觸發表單送出 |

### ⚠⚠ 抓到的真問題：測試名單跟正式撥號路徑條件不一樣

第一版 `UserRepository::getCallCandidates()` 寫成 `level > ADMIN`（排除管理者），
但正式撥號走的 `getMentionableByIds()` 是 `level != ENGINEER`（排除工程師）。

兩份條件不一樣 ⇒ 測試**完全失去意義**：

- 排除 ADMIN ⇒ 真的會被排班、會被撥的管理者，**永遠測不到**
- 沒排除 ENGINEER ⇒ 測得通的工程師，正式流程裡**根本不會被撥**

已改成跟 `getMentionableByIds()` 一字不差（只差一個 `whereIn`），
**兩支都加了「改一邊要改另一邊」的註解**。

> 這是這個功能的第二次同型錯誤 —— 第一次是「撥號與私訊要用兩份不同名單」
> （見上面的三個真 bug）。只要有兩份「誰可以被通知」的查詢，就會走偏。

### 撥號目標的格式化只能有一份

`'@' . ltrim(trim($username), '@')` 原本在 `TicketEscalationService` 裡手寫，
測試撥號若再寫一次，就會變成「測試撥得通、正式撥不通」（或反過來）。

抽成 `TelegramCallService::targetFor($username)`，兩邊共用。
順手修掉一個邊角：username 只有 `'@'` 或只有空白時，舊寫法會撥
`'@'`（`filled('@')` 是 true），現在回 `null` 由 `ring()` 以 `NO_TARGET` 擋下。

### 錯誤訊息要講「可以去做什麼」

sidecar 的錯誤碼一對一對到語系句子，而不是只回「撥號失敗」：

| sidecar 錯誤 | 使用者看到的 |
|---|---|
| `USER_PRIVACY_RESTRICTED` | 他的「誰可以打電話給我」設成僅聯絡人 → 把他加進聯絡人，或請他改設定 |
| `ENTITY_NOT_FOUND` | 找不到這個 username → 確認有沒有填錯 |
| `SESSION_NOT_FOUND` / `SESSION_UNAUTHORIZED` | 機器人還沒登入 → 跑 `php artisan telegram:call-login` |
| `TELETHON_NOT_INSTALLED` | ⚠ 多半不是沒裝，是 `TG_CALL_PYTHON` 沒指到那個 venv |
| 其他 | **把原始錯誤碼帶出來** —— 猜一句友善訊息只會蓋掉線索 |

### 幾個刻意的選擇

- **響 10 秒不是 30 秒**（`TEST_RING_SECONDS`）：測試是一個 HTTP 請求在等，
  響 30 秒代表那個請求要卡 30 秒，還可能撞到 php-fpm 逾時。
  測試要確認的是「撥得出去、會響」，10 秒夠了
- **撥不出去回 422 不是 500**：那些原因（隱私設定、沒登入）都是使用者可以
  去處理的事，前端的 `errorMessage()` 也只在非 2xx 時才去挖 `message`
- **名單空的 or `call_ready` 為 false ⇒ 按鈕直接 disabled**：
  讓人按下去再回「找不到人」，看起來像功能壞了
- **語系提示明講「這是真的撥出去，請先告知對方」**：不講的話，
  被撥的同仁只會收到一通莫名的電話

### ⚠ 自己踩的小坑

- 語系字串第一版寫了 `<options=bold>` —— 那是 **Symfony Console 的格式**，
  但這幾句是**網頁**上的提示，會被 blade 跳脫後原樣顯示。改用這一頁既有的 `**`
- `call_test_failed` 第一版帶了 `:name`／`:error`，但前端把它當「連不上後端」
  的退路用 —— 那時候沒有值可以填，會印出 `:name`。
  拆成兩句：`call_test_error`（帶參數）與 `call_test_failed`（純文字）

### 本次異動檔案

**新增**

- `app/Http/Requests/Notification/TestCallRequest.php`

**修改**

- `app/Repositories/UserRepository.php` — `getCallCandidates()`，條件與 `getMentionableByIds()` 綁在一起
- `app/Services/TelegramCallService.php` — `targetFor()`
- `app/Services/TicketEscalationService.php` — 改用 `targetFor()`
- `app/Services/NotificationSettingService.php` — `testCall()`、`callErrorMessage()`、`escalationSection()` 多回 `call_candidates`／`test_ring_seconds`
- `app/Http/Controllers/Admin/NotificationController.php` — `ajaxTestCall()`
- `routes/web.php` — `ajax-test-call`
- `config/constants.php` — `TICKET_ESCALATION.TEST_RING_SECONDS`
- `config/changelog.php` — v3.84
- `resources/lang/{tw,cn,en}/notification.php` — 12 個 key
- `resources/views/admin/notification/index.blade.php` — 下拉選單 ＋ 按鈕
- `public/js/notification-setting.js` — `renderCallTest()`、`runTest()` 多一個選用的 payload 參數

### 收尾稽核（2026-11-09，v3.84）

逐條對 PROMPTS.md 重掃 v3.84 的 diff（399 行新增），抓到三處要修：

| 違反 | 問題 | 修法 |
|---|---|---|
| **第 2 節 short-circuit** | `escalationSection()` 無條件跑 `getCallCandidates()`，但 `isConfigured()` 是純 config ＋ `file_exists`（不碰 DB），它為 false 時按鈕本來就 disabled、名單一個字都不會用到 —— **每次開設定頁白跑一趟查詢** | 先算 `$callReady`，false 就回 `[]`；順手把 map 抽成 `callCandidates()` |
| **第 1 節 PHPDoc** | `ajaxTestCall` 的 `@param Request` 與簽章 `TestCallRequest` 不一致，而 `Request` 在那個檔已經不 import 了 —— docblock 指著一個解析不到的類別 | 改成 `@param TestCallRequest` |
| 文案與程式不一致 | 提示與版本紀錄都寫「只列出有填 Telegram 帳號的人」，但條件**還排除了 ENGINEER**（level 3）。管理者會疑惑「為什麼 IT 的帳號不在名單裡」 | 三語提示與 v3.84 的版本紀錄一起改成「會被排班、而且填了 Telegram 帳號」並說明工程師不列 |

順手確認過但**刻意不改**的兩項：

- **`Criteria` 封裝**：`app/Criteria/User/` 只有 `UserKeywordSearchCriteria`，而
  `UserRepository` 有 35 處手寫 where —— Criteria 在這個專案是給「複雜且可重用」
  的條件用的。`getCallCandidates()` 是固定條件，照慣例手寫
- **`escalationSection()` 的 `@return array`**：同檔 8 個 section 方法有 7 個是
  `@return array`，只改這一個反而破壞一致性（而且它是既有的，不是本次新增）

> **版本號的決定**：沒有另開 v3.85。v3.84 還沒上線，而其中一處要修的正是
> v3.84 自己那一句不準確的文案 —— 那是**修正未發佈的內容**，不是新一版。

### ✅ 實測結果（2026-11-09）：全體客服都接到測試電話

需求方用設定頁的「測試撥號」**逐一撥給每位客服，全部都接到**。

這一次實測同時證明了四件原本最容易漏、而且**只有真的撥一次才驗得出來**的事：

| 項目 | 為什麼只能靠實撥驗 |
|---|---|
| session 有效且已授權 | 檔案存在不等於還登入著 |
| `TG_CALL_PYTHON` 在**php-fpm 的身分與 PATH 下**解析正確 | 「我從 shell 跑得起來」不等於排程／web 跑得起來（見上面那一節） |
| telethon 真的在那一支直譯器裡 | 錯誤訊息會怪錯對象，說成「沒安裝」 |
| **每位客服的隱私設定／聯絡人狀態都允許撥入** | `USER_PRIVACY_RESTRICTED` 是逐人而異的 —— 一個人通不代表全部人通 |

最後一項是測試撥號這個功能最大的價值：它是**per-user** 的風險，
沒有任何集中設定可以一次確認。

⚠ **測試撥號不檢查「逾時升級的開關」**（刻意的）——
要先測得通才敢打開，所以 `testCall()` 只看 `isConfigured()`。
也就是說：**實測通過 ≠ 功能已啟用**。

⚠ **別密集重測**。連撥是 Telegram 判定 spam 的主要行為樣態（正式流程因此
限 3 通、間隔至少 3 分鐘）。一輪逐人測完就夠了，之後只在換 session、
換直譯器、或有人改了隱私設定時再測。

### 語音：評估過，暫不做（2026-11-09 決定）

需求方問過「接起來有語音做得到嗎」，評估後**先不做，由需求方另尋方法**。

現在只做了 signaling 那一半，所以接起來會斷線 —— 不是「有語音沒放」，
而是**通話根本沒建立**（缺 DH 交換 ＋ libtgvoip／tgcalls 媒體層）。

| 方案 | 結論 |
|---|---|
| 維持只響不說話 | 現狀 |
| py-tgcalls／NTgCalls 放錄音 | ⚠ **要 spike 才敢說**：NTgCalls 是 C++ 原生依賴（prebuilt wheel ＋ ffmpeg ＋ 較新 Python），而 pytgcalls 傳統上做的是**群組語音**，一對一 P2P 是 NTgCalls 後來才宣稱支援的路徑 |
| 改用群組語音聊天 | 技術上最成熟，**但不會像來電那樣讓手機響** ⇒ 整個功能的意義消失 |
| 走電信商（Twilio／SIP） | 最可靠、真的能 TTS 唸內容、Telegram 被登出也照樣響。代價：按通計費 ＋ 要收集手機號碼（個資） |

> PHP 端早就斷了：MadelineProto 已移除 VoIP 且需 PHP 8.2+（本專案 7.4）。
> 語音只可能在 Python sidecar 裡做。

**不做的理由**：電話的價值是穿過靜音與勿擾，而那只需要「響」。語音唯一有
價值的時間窗是「他接了」到「他讀到私訊」之間 —— 但他接了就代表已經醒了，
而私訊早就在等著。為這一小段窗口在容器裡塞一個原生依賴不划算。

⚠ **但有一個真問題還沒解**：同仁接起來聽到斷線，可能以為是詐騙或撥錯，
久了就不接。這不需要語音修 —— 把它變成**約定好的訊號**就好：
「系統打來的電話只會響、接起來一定會斷，那就是叫你去看私訊。」
（尚未寫進版本紀錄與私訊開頭 —— 需求方說先這樣，所以沒動）
