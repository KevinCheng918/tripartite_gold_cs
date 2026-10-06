# 求助單持續提醒 + 每日提醒統計（設計稿，**尚未實作**）

> **狀態：等需求方確認。** 下面「待釐清」那幾項定了才動工。

## 需求（2026-10-06）

1. **移除**客服對話視窗上的「超時未回覆」紅色橫幅，換成下面這套
2. AI 轉求助單到內部群組後，超過設定時間沒人回答就提醒，**一直提醒到問題解決為止**
3. 升級規則：
   | 第幾次 | tag 誰 |
   |---|---|
   | 第 1 次 | 當班人員 |
   | 第 2 次 | 當班人員 |
   | 第 3 次以後 | 當班人員 **＋ 主管與老闆** |
4. **每次提醒都記次數**
5. **每天把統計報給主管**

## 一、要移除的東西（視窗上的超時橫幅）

現在這條路整個是獨立的，跟求助單無關 —— 它看的是「客人訊息沒人回」：

```
telegram:alert（每分鐘）
  └─ TelegramRepository::getUnrepliedMessages($minutes)   平日 5 分 / 週末 30 分
      └─ event(TelegramAlertTriggered)   broadcast 'telegram-chat' / 'telegram.alert'
          └─ main.js 收到 → T.showAlert() → #tg-alert-bar 紅橫幅，5 秒後消失
```

要刪的檔案與片段：

| 位置 | 動作 |
|---|---|
| `app/Console/Commands/TelegramAlertCommand.php` | 刪除 |
| `app/Events/TelegramAlertTriggered.php` | 刪除 |
| `app/Console/Kernel.php` | 移掉 `telegram:alert` 的每分鐘排程 |
| `public/js/telegram-chat/alert.js` | 刪除 |
| `public/js/telegram-chat/main.js` | 移掉 `channel.bind('telegram.alert', ...)` |
| `public/js/telegram-chat/layout.js` | 移掉 `#tg-alert-bar` 那個 div |
| `resources/views/admin/telegram-chat/index.blade.php` | 移掉 `alert.js` 的 script 標籤 |
| `config/constants.php` | 移掉 `TELEGRAM.ALERT`（WEEKDAY_MINUTES / WEEKEND_MINUTES） |
| `resources/lang/{tw,cn,en}/telegram_chat.php` | 移掉 `alert_unreplied` |
| `app/Repositories/TelegramRepository.php` | `getUnrepliedMessages()` 沒有別的呼叫端 → 一起刪 |

⚠ **這兩套的判斷依據不一樣**，不是單純搬家：
- 舊的：**客人訊息** 5 分鐘沒人回 → 整個群組一則橫幅
- 新的：**求助單** 超時沒人回答 → 內部群組 tag 人

也就是說「客人訊息沒人回但 AI 沒開求助單」的狀況，刪掉之後就**不再有任何告警**。
AI 直接答掉的不需要告警，但**客服自己接手處理中的對話**也不會再被盯。

> 需求方的原話是「換方法」，所以照上面全刪。若希望那層也留著，要另外講。

## 二、持續提醒

### 現況：固定兩階段，第二次之後就不再提醒

```php
// AutoReplySupportService::remindTimeoutTickets()
$sent += $this->remindStage($first,           REMIND.NONE,    REMIND.ON_DUTY);   // tag 當班
$sent += $this->remindStage($first + $second, REMIND.ON_DUTY, REMIND.MANAGER);   // tag 主管老闆
```

`auto_reply_ticket.remind_count` 現在存的是**階段**（0/1/2），不是次數 ——
「第 3 次之後」沒有狀態可以表示。

### 改法：`remind_count` 回歸真正的「次數」，階段由次數推導

```
remind_count  1、2       → tag 當班人員
remind_count  3 以上     → tag 當班人員 ＋ 主管與老闆
```

```
auto-reply:remind（每分鐘）
  └─ 取所有 PENDING 且「該提醒了」的單
      ├─ remind_count = 0 → created_at + 首次間隔 已到
      └─ remind_count ≥ 1 → last_reminded_at + 後續間隔 已到
          └─ 送提醒（文案帶第幾次）→ remind_count++、last_reminded_at = now
```

查詢條件從「`remind_count` 等於某個階段」改成「**上次提醒到現在超過間隔**」，
所以 `ShiftTicketRepository::getTimeoutTickets()` 的簽章要換。

⚠ `REMIND.NONE/ON_DUTY/MANAGER` 這組常數的語意變了（從「階段」變成「門檻」），
留著原名會誤導 —— 改成 `REMIND.ESCALATE_AT = 3`（第幾次開始叫主管）之類。

### 「問題解決」＝ 不再是 PENDING

`status` 離開 `PENDING(1)` 就停止提醒，四種出口都算解決：

| status | 什麼時候 |
|---|---|
| `ANSWERED(2)` | 自己人在內部群組引用回覆作答 |
| `REPLIED(3)` / `SAVED(4)` | 按了回覆客人／加入題庫 |
| `IGNORED(0)` | **客服自己在後台人工回覆客人**（`ignoreOpenTicketsByGroup`） |

所以客服在對話視窗直接回客人，提醒也會自己停，不必再去內部群組處理一次。

## 三、每天報給主管

```
remind:report（每天固定時間）
  └─ 統計「昨天」所有提醒
      ├─ 總計：幾張單被提醒、共幾次、幾張升級到叫主管
      ├─ 逐張：客人群組、問題前 N 字、被提醒幾次、現在狀態
      └─ 私訊給設定的收件人
```

私訊走的是 [[shift-daily-notice]] 剛建好的那條路（`telegram_dm_ready`）。
⚠ 兩邊都要「發 DM 給某個同事、沒綁定就跳過、失敗記 log」，
建議把 `ShiftNoticeService::dm()` 抽成共用的 `StaffDmService`，
不要在第二個 service 裡複製一份。

沒有任何提醒的日子**也要發**（寫「昨天沒有超時的求助單」）—— 理由同班表通知：
安靜不動時分不出「沒事發生」還是「排程壞了」。

## ⚠ 待釐清

### 1. 第 3 次之後，每隔多久提醒一次？

現在設定頁有兩個欄位：`remind_first_minutes`、`remind_second_minutes`。

| 選項 | 說明 |
|---|---|
| **A. 沿用第二個欄位當固定間隔**（建議） | 不動設定頁。第 1 次在首次間隔後，之後每隔 `second` 分鐘一次 |
| B. 設定頁改成「首次 / 之後每隔」兩個欄位 | 語意更清楚，但要改設定頁與語系 |
| C. 遞增（5 → 10 → 20 分…） | 不會洗版，但「越晚越不急」跟需求相反 |

### 2. ⚠ 深夜沒人值班時要照樣一直提醒嗎？

這是最需要先決定的一題。每分鐘跑的排程 + 「不斷提醒」＝
客人半夜丟一題沒人處理，到早上會累積**上百則** tag 主管老闆的訊息。

| 選項 | 說明 |
|---|---|
| **A. 無人值班時段只提醒、不 tag**（建議） | 訊息還是留著，但不會半夜一直響；早上有人上班後恢復 tag |
| B. 提醒次數設上限（例如 30 次） | 簡單，但「一直提醒到解決」就不成立了 |
| C. 照提醒照 tag | 完全照需求字面，但主管半夜會被轟炸 |

另一個相關問題：**同一時間多張單都超時**時，現在是**每張單各發一則**。
五張單同時卡住就是五則 —— 要不要合併成一則？

### 3. 統計報給誰、什麼時候發？

| 問題 | 選項 |
|---|---|
| 收件人 | **A. 沿用班表通知的收件人**（建議，不用再設一次）／ B. 另設一個欄位 |
| 時間 | **A. 跟班表通知同一時間 08:00**（建議，一次看完）／ B. 另設時間 |
| 統計範圍 | **A. 昨天一整天**（建議）／ B. 今天到現在 |

### 4. 統計要「按單」還是「按人」？

需求的原話是「這個被提醒，我要紀錄次數，然後每天報給主管」。
「被提醒的人」＝當班人員，但**當班人員會隨時段換人**，
同一張單提醒五次可能 tag 到三組不同的人。

| 選項 | 說明 |
|---|---|
| **A. 按單統計**（建議） | 「這張單被提醒 5 次」—— 資料現成，`remind_count` 就是 |
| B. 按人統計 | 「小明被提醒 7 次」—— 要新開一張提醒紀錄表（每次提醒一列，記下 tag 到誰） |
| C. 兩者都要 | 同 B，報表多一段 |

⚠ **選 B/C 就需要新表**（`auto_reply_ticket_remind`：ticket_id、第幾次、tag 到的 user_id、送出時間），
工作量差一截。A 的話不用新表，`remind_count` 直接拿來用。

### 5. 第 1 次和第 2 次的提醒內容完全一樣嗎？

兩次都 tag 當班人員，差別只有「第幾次」。
建議文案帶上次數（「⏰ 第 2 次提醒」），不然同仁看不出已經被催第二次了。

## 會動到的檔案（預估）

| 檔案 | 內容 |
|---|---|
| 上面「要移除的東西」整張表 | 刪除舊告警 |
| `app/Services/AutoReplySupportService.php` | `remindTimeoutTickets()` 改成依間隔持續提醒、文案帶次數 |
| `app/Repositories/AutoReplyTicketRepository.php` | `getTimeoutTickets()` 改成依 `last_reminded_at` 判斷 |
| `config/constants.php` | `AUTO_REPLY.REMIND` 語意改寫、報表文案 |
| `app/Services/StaffDmService.php`（新增） | 從 `ShiftNoticeService::dm()` 抽出來共用 |
| `app/Services/RemindReportService.php`（新增） | 每日統計 |
| `app/Console/Commands/RemindReportCommand.php`（新增） | `remind:report` |
| `app/Console/Kernel.php` | 加 `remind:report`、移掉 `telegram:alert` |
| `database/migrations/*`（看第 4 題） | 按人統計才需要新表 |

## 相關

- [[auto-reply]] — 求助單的完整流程（開單、回答、回覆客人、回填題庫）
- [[shift-daily-notice]] — 私訊同事的既有做法（`telegram_dm_ready` 綁定）
- [[scheduling]] — 當班人員怎麼算（`getOnDutyUserIds()`）
- [[telegram-chat]] — 要移除的橫幅所在的畫面
