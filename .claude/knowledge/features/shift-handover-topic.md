# 交接話題：每班交接，沒交接就在話題 tag 兩邊

> **狀態：已實作（2026-11-08，v3.81）。上線前要做三件事，見文末。**
>
> 下面到「需求方 2026-11-08 確認」為止是實作前的設計稿；
> 實際成果與踩到的坑在文末的「實際實作」。

## 需求（需求方 2026-11-08）

> 「我想要新增一個話題，是用來做交接的，每個班都需要交接，如果沒交接就在
> 話題＠那一班的人，時間是回訊時間前5分鐘開始提醒，然後因為現在大夜班都
> 沒有人，所以還是會需要『早班待接手清單』，這個就不用動了，所以早班的人，
> 除了要看昨天晚班的，還要看系統產生的早班待接手清單」

追問後補充：

> 「要＠上一個班的人要打交接事項，也要＠下一個班的人說你要上線了，我要跟你
> 交接了，然後時間部分有故意設計中間重疊一小時交接，所以感覺好像是這個
> 重疊時間要交接」

## 關鍵發現：回訊時間是刻意重疊的

dev 環境的實際設定（`shifts` 表）：

| 班別 | 上下班 | **回訊** |
|---|---|---|
| 早班 | 08:00–20:00 | **08:00–14:00** |
| 午班 | 10:00–22:00 | **13:00–19:00** |
| 晚班 | 12:00–00:00 | **18:00–00:00** |
| 大夜班 | 22:00–10:00 | **23:00–09:00** |

⚠ **回訊時間不是首尾相接，是每兩班重疊一小時** —— 那是刻意設計的交接窗口
（需求方確認）。上下班時間則大量重疊（都 12 小時），所以**判斷「誰該交接」
一律看回訊時間**，不看上下班時間。既有的 `isTimeInShiftRange()` 已經是
這個原則。

### 四個交接窗口

| 窗口 | 上一班（要寫交接） | 下一班（要接手） | 重疊時間 | 提醒起點 |
|---|---|---|---|---|
| 1 | 早班 | 午班 | 13:00–14:00 | **12:55** |
| 2 | 午班 | 晚班 | 18:00–19:00 | **17:55** |
| 3 | 晚班 | 大夜班 | 23:00–00:00 | **22:55** |
| 4 | 大夜班 | 早班 | 08:00–09:00 | **07:55** |

「回訊時間前 5 分鐘」＝ **下一班回訊開始前 5 分鐘**＝重疊窗口開始前 5 分鐘。

## 提醒的內容：一則訊息 tag 兩邊

需求方要兩邊都 tag，而且兩邊的話不一樣：

```
🔄 交接時間到了（早班 → 午班）

@mary @john  ← 早班
請把這一班的狀況打在這個話題：還沒解決的、客人在等的、要注意的。

@amy  ← 午班
你 13:00 要開始回訊了，請先看上面的交接。

⏰ 交接窗口 13:00–14:00
```

⚠ **一則訊息、不要分兩則。** 兩則的話交接內容會被夾在中間，而且上下班兩邊
看到的是不同訊息、對不起來。

⚠ **早班那一窗（窗口 4）要多一句**：需求方指定早班除了看大夜班的交接，
還要看系統產生的「早班待接手清單」（07:30 那則，`ticket:handover`）。

## 怎麼算「已經交接」

**那一班（上一班）的人在交接話題發訊息就算**（需求方選）。

- Telegram username → 後台帳號（`user.telegram_username`，比照
  `StaffIgnoreService` 的對法，走 `TelegramUsernamePresenter::normalize()`）
- 再比對他今天是不是排那一班
- 一命中就記 `handed_at` / `handed_by`，提醒停止

⚠ **他沒填 `telegram_username` 就對不到人** —— 跟「tag 不到他」是同一個根源。
那時他發了交接也不算，會被一直催。設定頁的「認不出 Telegram 身分的帳號」
提示（`ignore-member` 那頁已有）就是給這件事用的。

⚠ **支援群組的訊息沒有存進 `telegram_message`**（webhook 在
`isFromSupportChat()` 就攔掉了，只在記憶體處理），所以**不能靠查訊息表反推
交接了沒**，必須自己記一張狀態表。

## 催到什麼時候停

需求方 2026-11-08 明確指示：

> 「催到停這個就讓他一直催，時間可以拉長，但是就是催到他完成交接事項」
>
> 「因為他沒有就會打沒有了」

**所以沒有窗口結束的停點，也沒有次數上限。** 停止條件只有兩個：

1. **那一班的人完成交接**（在交接話題發訊息）
2. **那一班今天沒有人排班** → 不催，改發一則給下一班（見下節）

⚠⚠ **這一條是刻意違反「不要無上限催促」的通則**，理由是需求方那句
「他沒有就會打沒有了」：**交接沒有正當的不做理由**。沒事情要交接的人打兩個字
「沒有」就結束了，所以被催到做完是合理的 —— 這跟求助單不一樣（求助單可能
真的沒有人答得出來，所以那邊有次數上限）。

⚠ **代價要講明**：有人徹底忘記的話會被催一整晚，而且會跨進下一班的時間。
需求方選擇用**拉長間隔**（預設 30 分鐘）而不是設上限來控制 ——
把「會不會被吵」交給間隔，而「會不會漏掉」交給沒有上限。

⚠ 第 3 次起連主管一起 tag（沿用求助單那份主管名單，需求方指定），
之後每次都 tag —— 沒有「升級完就放手」這回事，因為沒有收尾那則。

### ⚠⚠ 上一班沒有人排班 → 不催，但要跟下一班講一聲

需求方 2026-11-08 補充：

> 「催到有人交接這個要加判斷，如果那一個班沒人，就不要催了，因為大夜班
> 沒人，如果早班要上班就請他去看早班待接手和昨天晚班的交接事項」

所以**不是整個窗口靜悄悄跳過**，而是：

| 行為 | 做法 |
|---|---|
| 催上一班交接 | **不催**（沒有人可以催） |
| 跟下一班講一聲 | **發一則，只發一次**，不重複催 |
| 那一則要寫什麼 | 「大夜班今天沒有人值班，沒有交接事項。請看 ⟨07:30 的早班待接手清單⟩ 與 ⟨上一份交接⟩」 |

⚠ **「上一份交接」用引用（`reply_to`）而不是寫文字描述。**
`SupportGroupService::send($text, $keyboard, $replyTo, $type)` 本來就吃
`$replyTo` —— 直接引用那則交接訊息，同仁點一下就跳到原文。
所以狀態表要存 **`handed_message_id`**（交接那則的 Telegram message_id）。

⚠ **判準是「最近一筆已交接的紀錄」，不要寫死「昨天晚班」。**
需求方說的是大夜班沒人時的現況，但規則本身不該綁死班別名稱 ——
大夜班哪天補了人，或哪天午班沒人，同一套邏輯要照樣對。
找不到任何已交接的紀錄時就不引用，只發文字。

⚠ 不這樣做的話有兩種壞結局：整個窗口靜悄悄跳過 → 早班不知道該去看什麼；
或照常催 → 早班每天 07:55 起被一個不存在的班催一小時。

| 項目 | 建議值 | 存在哪 |
|---|---|---|
| 功能開關 | **預設開**（需求方指定） | `app_setting` |
| 提前幾分鐘開始提醒 | 5 | `app_setting`（需求方指定的 5 分鐘） |
| 再催間隔 | **30 分鐘**（拉長，因為沒有上限） | `app_setting` |
| 升級 tag 主管 | 第 3 次起 | `constants`（比照求助單的 `ESCALATE_AT`） |
| 次數上限 | **沒有** | — |

⚠ **功能開關預設開，跟專案慣例相反**（`followup.enabled`、
`wallet_notice.enabled`、`auto_reply_resume.enabled` 都預設關）。
需求方 2026-11-08 指定預設開 —— 這是**每班都要做的事**，預設關等於上線後
沒有人知道要去打開它。代價是部署完就會開始 tag 人，所以上線前要先把
交接話題的 thread id 設好（見最後一節）。

## 資料表 `shift_handover`

```
id
date                 date      交接日（窗口開始那一天）
from_shift_id        FK shifts 要寫交接的那一班
to_shift_id          FK shifts 要接手的那一班
window_start         dateTime  重疊開始（＝下一班回訊開始）
window_end           dateTime  重疊結束（＝上一班回訊結束）
handed_at            dateTime  nullable
handed_by_user_id    FK user   nullable + nullOnDelete
handed_by_name       string    名稱快照（帳號刪掉後仍要看得出是誰交接的）
handed_message_id    bigint    nullable；交接那則的 Telegram message_id，下一班的通知要引用它
remind_count         int       default 0
last_reminded_at     dateTime  nullable
notified_next_at     dateTime  nullable；「上一班沒人」那則已經跟下一班講過了（只發一次的依據）
status               tinyint   1=待交接（催到完成為止）, 2=已交接, 3=上一班沒人（不催）
timestamps
unique (date, from_shift_id, to_shift_id)
index (status, window_start)
```

⚠ **`window_start`／`window_end` 存下來而不是每次重算**：班別的回訊時間在
後台可以改。改了之後，昨天那筆交接紀錄應該留著當時的窗口，不該跟著變
（同 `leave_request.minutes` 存當下換算結果的理由）。

⚠ **unique 是三欄**：同一天同一組班別只會有一筆。排程每分鐘 `firstOrCreate`，
重複跑不會長出第二筆。

## 誰建這些列

**每分鐘的排程自己算、自己 `firstOrCreate`**，不另外做一支「每天先建當天四筆」
的 job。

理由：班別的回訊時間後台可改，而且班別可以新增／停用。預先建立的話，
改了時間就要回頭修當天已建的列 —— 而那種「兩邊各算一次」正是這個專案
踩過好幾次的坑。

## 預計異動檔案

### 新增

| 檔案 | 內容 |
|---|---|
| `database/migrations/*_create_shift_handover_table.php` | 狀態表 |
| `app/Models/ShiftHandover.php` | Model |
| `app/Repositories/ShiftHandoverRepository.php` | 待提醒／逐窗口查詢 |
| `app/Services/ShiftHandoverService.php` | 算窗口、建列、組訊息、記交接 |
| `app/Console/Commands/ShiftHandoverRemindCommand.php` | `shift:handover-remind`（每分鐘） |

### 修改

| 檔案 | 改什麼 |
|---|---|
| `app/Http/Controllers/Webhook/TelegramWebhookController.php` | 支援群組訊息多一道：交接話題有人發言就記一筆（**不消耗訊息**，後面的判斷照走） |
| `app/Console/Kernel.php` | 掛 `shift:handover-remind` 每分鐘 ＋ `withoutOverlapping` |
| `app/Services/AppSettingService.php` | 開關、提前分鐘、再催間隔 |
| `app/Services/NotificationSettingService.php` ＋ 通知設定頁 Request/Blade/JS | 那三個設定欄位 |
| `config/constants.php` | `SHIFT_HANDOVER`（文案模板、狀態、升級次數）；`SUPPORT_TOPIC.TYPES` 加 `shift_handover` |
| `resources/lang/{tw,cn,en}/notification.php` | 設定欄位、`notice_type.shift_handover` |

⚠ **`ticket:handover`（早班待接手清單）一行都不動** —— 需求方指定。
新功能只在窗口 4 的訊息裡多一句「也要看 07:30 那份清單」。

⚠ 新通知類型 `shift_handover` 的 `needs_reply` 是 **false**：它可以勾在多個
話題（偵測時用 `topicIdsFor()` 回的陣列比對 `message_thread_id`，不會有
「只能記一個 message_id」的問題）。

## 需求方 2026-11-08 確認

| 題目 | 決定 |
|---|---|
| 提醒時機 | **下一班回訊開始前 5 分鐘**（＝重疊窗口開始前 5 分鐘） |
| tag 誰 | **兩邊都 tag**，一則訊息：上一班「請打交接事項」、下一班「你要上線了」 |
| 完成判定 | **那一班的人在交接話題發訊息**就算 |
| 催到什麼時候 | **一直催到完成**，沒有上限、沒有窗口結束停點；間隔拉長到 30 分鐘 |
| 那一班沒人 | **不催**，改發一則給下一班（只發一次），引用上一份交接 |
| 功能開關 | **預設開** |
| 升級主管名單 | **沿用**求助單那份（`EscalatingReminder` / `getManagersForMention()`） |
| 跨午夜排班日期 | 本功能自己做對；**既有的 `getOnDutyAssignments()` 不動** |
| `ticket:handover` | **一行都不動** |

## 待釐清

1. ~~功能開關預設開還是關？~~ **已確認：預設開。**
2. ~~「上一班沒人」要不要留個痕跡？~~ **已確認**：建列並記成「上一班沒人」，
   而且要發一則給下一班（見上）。之後要回答「大夜班到底多久沒人」也有資料可查。
3. **跨午夜的排班日期**：大夜班回訊 23:00–09:00，所以窗口 4（08:00–09:00）的
   「上一班」是**昨天**那筆 `shift_assignments`。既有的 `getOnDutyAssignments()`
   只查今天（`getByDateRange($today, $today)`）—— 那支在大夜班有人時也會
   撈錯人，但因為大夜班一直沒人所以沒浮現。本功能**會自己處理昨天那筆**，
   但那表示跟既有那支的判斷不一致，要不要順手把既有那支一起修？
   建議：本功能自己做對，既有那支**不動**（它影響求助單 tag 與自動指派，
   改它的風險超出這個需求），但把這件事記在這裡。
4. **主管是誰**？升級時要 tag 誰 —— 沿用求助單升級那份名單，還是另外設？
   建議沿用（`EscalatingReminder` 已有），不要再開一份名單。

---

## 實際實作（2026-11-08，v3.81）

> **狀態：已實作。上線前要做三件事，見最後一節。**

### 新增

| 檔案 | 內容 |
|---|---|
| `database/migrations/2026_11_08_000001_create_shift_handover_table.php` | `shift_handover` 表 |
| `app/Models/ShiftHandover.php` | Model |
| `app/Repositories/ShiftHandoverRepository.php` | 建窗口／待催／沒人通知／最近一筆已交接 |
| `app/Services/ShiftHandoverService.php` | 算窗口、建列、組訊息、偵測交接 |
| `app/Console/Commands/ShiftHandoverRemindCommand.php` | `shift:handover-remind`（每分鐘） |
| `app/Http/Requests/Notification/UpdateShiftHandoverRequest.php` | 設定頁驗證 |

### 修改

`Console\Kernel`（掛排程）、`TelegramWebhookController`（偵測掛勾）、
`AppSettingService`（三個 KEY）、`NotificationSettingService`（section + update）、
`NotificationController`、`routes/web.php`、`config/constants.php`、
`config/changelog.php`、`ShiftAssignmentRepository`（兩個新查詢）、
`StaffIgnoreService`（`matchUserId()` 改 public）、
三語 `notification.php`、`notification/index.blade.php`、`notification-setting.js`

### ⚠⚠ 實作時踩到的最大一個坑：constants key 撞名

第一版把常數區塊取名 `SHIFT_HANDOVER` —— **那個名字已經被「早班待接手清單」
用掉了**（`config/constants.php:782`，`TicketHandoverService:174` 在讀）。

PHP 的重複陣列 key 會讓**後者無聲覆蓋前者**，所以那一版等於把需求方明確
交代「一行都不要動」的那支功能整個弄壞，而且不會有任何錯誤訊息。

→ 改名 `SHIFT_HANDOVER_TOPIC`。**既有那個一個字都沒動。**

⚠ 教訓：新增 `config/constants.php` 的頂層 key 之前，先
`grep -n "^    'YOUR_KEY'" config/constants.php` 看撞不撞 ——
這個檔案四千多行，肉眼掃不到。

⚠ 連帶的第二個坑：訊息裡要印「早班待接手清單的發送時間」，那要讀**既有**
那個 `SHIFT_HANDOVER.SEND_AT`，不是本功能的 `SHIFT_HANDOVER_TOPIC` ——
兩個名字很像，指錯會印出空字串而且不報錯。

### ⚠ 沒勾交接話題時，整個功能不動作（刻意與其他通知相反）

其他通知類型「沒勾話題就發主區」。這一項**不行**，因為它要雙向運作：

- 發送端沒勾會退到主區（`threadIdsFor()` 回 `[null]`）
- 偵測端沒勾會認不出任何訊息是交接（`topicIdsFor()` 回空陣列）

兩個加起來 ＝ **在主區無上限地催，而且永遠無法被滿足**。這個功能沒有
次數上限，所以那不是「少一個通知」，是整天轟炸。

→ `run()` 與 `detect()` 都先檢查 `topicIds()`，空的就短路並記一筆 warning；
設定頁也會跳一條黃色提示。

### 偵測交接：重用 `StaffIgnoreService::matchUserId()`

`matchUserId()` 從 private 改成 public。它是全專案最可靠的
「Telegram 身分 → 後台帳號」對法：**telegram_user_id 優先、username 退路、
命中後回填 ID**，而且名單有快取。

⚠ 另外寫一份 `findByTelegramUsername()` 版本會是**只有 username、沒有 ID
比對、沒有回填**的弱化版 —— 那正是這份程式碼一再警告的「兩邊各寫一次而漂移」。

### 偵測掛在 webhook，而且**不消耗訊息**

`TelegramWebhookController` 在 `isFromSupportChat()` 之後第一個呼叫
`handoverService->detect($payload)`，**不 return** —— 它只是旁觀記錄。
同一則訊息可能同時是交接事項、又引用了某張求助單，兩件事都該發生。

### 實測

| 檢查 | 結果 |
|---|---|
| 四個窗口（拿實際班別設定算） | ✅ 13–14、18–19、23–00、08–09 |
| 提醒起點 | ✅ 12:55／17:55／22:55／07:55 |
| **跨午夜那一窗的日期** | ✅ 大夜班 D=11/08，窗口落在 **11/09** 08:00–09:00 |
| 功能開關預設值 | ✅ `true`（跟其他功能相反，需求方指定） |
| 提前分鐘／再催間隔預設 | ✅ 5／30 |
| 沒設交接話題時 `run()` | ✅ 安靜回 0，不發任何訊息 |
| 路由／指令／DI 容器解析 | ✅ 四個類別都解得開，無循環依賴 |
| 三語 key | ✅ notification 268/268/268 |
| PHP／JS 語法 | ✅ |

### 待釐清（仍未決）

1. **跨午夜的既有不一致**：`TelegramChatService::getOnDutyAssignments()` 只查
   今天，大夜班有人時會撈錯人。需求方 2026-11-08 同意**那支不動**
   （它影響求助單 tag 與自動指派，改它的風險超出這個需求），
   但大夜班真的補人之後要回來處理。
2. **一直催沒有上限**的實際體感還沒驗證過 —— 忘記交接的人會被催到完成，
   跨班、跨夜都會。需求方選擇用拉長間隔（30 分）控制，上線後要看實況。

## 上線要做的三件事

1. **跑 migration**（建 `shift_handover` 表）：
   ```bash
   cd ../laradock && docker-compose exec workspace bash -c \
     "cd /var/www/tripartite_gold_cs && php artisan migrate"
   ```
2. **在 Telegram 的支援群組新開一個「交接」話題**，用 `/topicid` 取得它的 id，
   到「通知設定 → 支援群組與話題」新增那個話題並**把「班別交接」勾起來**。
   ⚠ 在這之前功能不會動作（設定頁會跳黃色提示）。
3. **確認排班有排人、而且每個人都填了 Telegram 帳號** —— 沒填的人 tag 不到，
   而且他發的交接也認不出來（兩件事同一個根源）。

⚠ 功能開關**預設開**，所以 migration 跑完 ＋ 話題勾好的那一刻就會開始運作。

## 相關

- [[../bugfix/2026-11-07-ticket-blocked-by-group-ignore]] — 求助單的狀態機
- [[scheduling]] — 班別與回訊時間
- [[support-group-topics]] — 話題分流（本功能是第一個「雙向」的類型）
- [[remind-escalation]] — 主管升級名單（本功能沿用）

---

## 收尾稽核：按 PROMPTS.md 逐條核對（2026-11-08）

需求方要求「嚴格嚴謹按照 PROMPTS.md」。核對上一個 commit 的 1663 行新增，
**找到 4 項，都修了。**

### 1. ⚠ N+1：`mentionableFor()` 在三個迴圈裡各自查兩次 DB

這是本次最實質的一項，而且**專案自己的註解早就警告過**：

> `CustomerFollowupService::askOnDuty()`
> ⚠ **當班人員整輪只查一次**。這支每分鐘跑，同時卡住十筆是常態 ——
> 逐筆去查排班與主管名單就是十倍查詢，而一分鐘內名單都不會變。

而我寫的 `mentionableFor()` 自己查「某天某班有誰」＋「這些人能不能被 tag」，
**每筆催促呼叫兩次**（上一班、下一班），`noticeNoStaff()` 再一次，
`syncWindows()` 又逐窗口查一次。

→ 新增 `ShiftAssignmentRepository::getShiftUserMapByDateRange()`，
在 `run()` 開頭用 `buildRoster()` **整輪撈一次**（排班 1 次 ＋ 可 tag 名單 1 次），
`mentionableFor()` 改成純陣列查找、完全不碰 DB。

| 檢查 | 修前 | 修後 |
|---|---|---|
| `buildRoster()` | — | 1～2 次查詢（沒排班時 1 次，`getMentionableByIds([])` 自己短路） |
| `mentionableFor()` 呼叫 4 次 | 8 次查詢 | **0 次** |
| `run()` 在沒設話題時 | — | **0 次查詢**（短路在 `buildRoster()` 之前） |

### 2. 死碼：`ShiftHandover::isHanded()` / `isPending()`

兩支都沒有任何呼叫端（狀態判斷全在 Repository 的 where 裡）。刪掉。

### 3. 死碼：`getUserIdsByShiftAndDate()`

改用整輪撈的 roster 之後就沒人用了。刪掉，順手修掉另一段註解裡對它的引用。

### 4. 沒用到的參數：`mentionableFor(ShiftHandover $handover, ...)`

`$handover` 整個方法裡沒用到。重構時一併移除。

### ⚠⚠ 驗證時另外抓到一個行為問題：`CREATE_WITHIN_HOURS` 原本是 24

實測 `buildWindows()` 時發現它會把**昨天**的三個窗口也算出來 —— 那表示
**第一次上線會立刻為過去一整天的窗口建紀錄並同時開始催**，而那些班次
根本還沒有交接流程，催了也沒有人知道在催什麼。

→ 調成 **2 小時**。要講清楚的是它**不影響「催到完成」**：這個值只管
「要不要為一個已經過去的窗口補建一筆」，一旦建出來就照常催到完成。
交接窗口本身只有一小時，2 小時足夠涵蓋「排程掛掉一陣子才回來」。

⚠ 代價：排程中斷超過 2 小時的話，那段期間的窗口不會被補建也不會被催。
刻意的取捨 —— 漏催一次比上線當天亂催一輪好。

實測八個時間點（`Carbon::setTestNow`）：

| 模擬時間 | 情境 | 建出 |
|---|---|---|
| 12:50 | 還沒到 12:55 提醒起點 | 0 ✅ |
| 12:55 | 早班→午班 提醒起點 | 1 ✅ |
| 13:30 | 窗口進行中 | 1 ✅ |
| 14:50 | 窗口結束但仍在 2 小時內 | 1 ✅ |
| 15:30 | 超過 2 小時 | 0 ✅ |
| 17:55 / 22:55 | 午→晚 / 晚→大夜 提醒起點 | 1 ✅ |
| 隔天 07:55 | 大夜→早班（跨午夜） | 1 ✅ `date=前一天` |

### 逐條核對結果

| 檢核項 | 結果 |
|---|---|
| `??`／`isset`／`empty`／`!filled` | ✅ 1663 行新增內零處 |
| 陣列下標讀取 | ✅ 零處（只有 `$windows[] =` 這種 append） |
| 字串 `.` 串接變數 | ✅ 零處 |
| `fn(`／`else`／`!== null` | ✅ 零處 |
| Controller 無商業邏輯／無 DB | ✅ `NotificationController` 只 `$params` → Service；webhook 只呼叫一支 |
| Service 無 HTTP 處理 | ✅ grep `request()`／`Auth::` 皆無 |
| Service 無 `Model::where` | ✅ 全走 Repository |
| Model 無商業邏輯 | ✅ 刪掉兩支述詞後只剩關聯與 casts |
| PHPDoc | ✅ 複雜回傳都標了（`array{assignments:…, users:…}`、`[Y-m-d][shift_id] => [user_id]`） |
| SELECT 指定欄位 | ✅ 新增的查詢都有 `select()` |
| **N+1** | ✅ 本次修掉（見上） |
| Config/Cache 判斷先於 DB | ✅ `isEnabled()`／`isConfigured()`／`topicIds()` 三道都在 `buildRoster()` 之前 |
| Early Return | ✅ 無 `else` |
| Ajax route 含 `ajax-` 前綴 | ✅ `ajax-update-shift-handover` |
| Controller params 用 `$params` | ✅ |
| 多表寫入用 Transaction | ✅ 不適用 —— 每次只寫 `shift_handover` 一張表（`firstOrCreateWindow` / `update`），沒有跨表寫入 |
| 外部輸入／異常有 Log | ✅ 送出、交接完成、認不出發言者、沒勾話題各有一筆 |
| 語系 tw → cn → en | ✅ 268/268/268 |
| 改 `config/` → `optimize` | ✅ 容器內已執行 |

### 不加新版號

v3.81 已在功能那個 commit，而本次是 **N+1 重構（零行為變更）＋ 刪死碼 ＋
一個常數調整**。常數那一項唯一的使用者可見差別是「第一次上線不會亂催
昨天的班」—— 那是讓 v3.81 的描述成立，不是新增的行為，所以不另編版號。
先例見 [[../bugfix/2026-11-05-validation-message-swallowed]] 的同名小節。
