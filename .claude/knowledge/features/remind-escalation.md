# 求助單持續提醒 + 每日提醒統計

> **狀態：已實作（2026-10-06）。** 上線前置作業見最後一節。

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

後續追加（同日）：

6. 統計收件人**可以勾多位**（含全選）
7. 統計分兩種：**勾選的人收全部人的**、**被提醒到的人各收自己那份**
8. 「還沒人處理的」**早上 7:00 發到內部群組並 tag 當天早班** ——
   因為**大夜班目前沒有人排班**，深夜的提醒 tag 不到任何人

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

### ⚠ 刪掉之後留下的缺口（重要）

**這兩套的判斷依據不一樣**，不是單純搬家：

| | 舊的（已移除） | 新的 |
|---|---|---|
| 看什麼 | **客人訊息** 沒人回 | **求助單** 沒人回答 |
| 門檻 | 平日 5 分 / 週末 30 分（寫死） | 設定頁可調 |
| 怎麼告警 | 對話視窗紅橫幅，5 秒後消失 | 內部群組 tag 人，一路催到處理完 |
| 涵蓋範圍 | 所有沒回的客人訊息 | **只有 AI 開了求助單的** |

所以下面這幾條路**現在沒有任何自動告警**：

1. **AI 判成寒暄／不需回應（SILENT）** —— 不開單，所以提醒撈不到。
   判錯的時候只能靠客服看對話列表發現（見 [[auto-reply-natural]]）
2. **AI 追問客人補資料** —— 客人不回就一直掛著（見 [[auto-reply-ask-info]]）
3. **客服自己接手處理中的對話** —— AI 沒介入，也就沒有單

需求方的原話是「換方法」，所以照上面全刪。**上面這三條如果也要盯，要另外做**
（最直接的作法是讓這幾條路也開一張單，提醒就自動接上）。

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

## 三、每天 08:30 的統計（兩種收件人）

```
remind:report（每天 08:30）
  ├─ 完整版 → 設定頁勾選的人（可多位，建議主管以上）
  │    ├─ 總計：幾題被提醒、累計幾次、幾題升級到叫主管
  │    ├─ 依題目：問題前 40 字、客人群組、幾次、現在狀態
  │    └─ 依人員：誰被催幾次（含「沒 tag 到任何人」幾次）
  └─ 個人版 → **所有在職同仁**（不含管理者、不含已勾完整版的人），各自一則
       ├─ 昨天被提醒到 → 只有自己被催的那幾題
       └─ 昨天沒被提醒到 → 一句肯定他的話（AI 當天寫）
```

私訊走 `StaffDmService`（從 `ShiftNoticeService::dm()` 抽出來共用）。

⚠ **兩種沒事時都要發**（需求方 2026-10-06 調整，原本個人版沒事不發）。
安靜不動時分不出「昨天沒事」還是「排程壞了」。

⚠ **勾了完整版的人不再收個人版**（2026-10-08）——
完整版的「依人員」那段已經包含他自己，兩則同時間到看起來像重複發送。

⚠ **統計裡不提「主管」「老闆」**（2026-10-08）。總計那行原本寫
「其中 N 題升級到通知主管」，改成「其中 N 題催了 {ESCALATE_AT} 次以上」——
數字是同一個，但不會讓這則看起來像在告狀。門檻用 config 不寫死，改設定時那句話要跟著對。

### ⚠ 沒事那天的句子由 AI 當天寫，不用公版

需求方指定：**公版寫久了大家會自動略過，那就失去意義了。**

- 個人版：帶名字，逐人各叫一次模型（才有「針對他」的感覺）
- 完整版：一句肯定整個團隊的話

實作在 `app/Services/Notify/EncouragementWriter.php`，走
`ClaudeCodeMatcher::generateText()`（新增的通用生成入口，schema 只有一個
`text` 欄位，**不走備援 API** —— 訂閱額度用完時還去花 API 的錢寫鼓勵的話並不合理）。

⚠ **一定要有公版退路**（`ENCOURAGE.*_FALLBACK`）。這掛在每天 08:30 的排程上，
模型沒設定、額度用完、CLI 逾時都是會發生的事 ——
**「沒有鼓勵的話」比「整則統計不發」好得多。**

⚠ **寫太長就退公版**（`MAX_LENGTH`）。模型偶爾會寫成一整段，那則訊息是接在
統計後面的一句話，長出去會讓整則看起來像廢文。

⚠ **`--dry-run` 不叫模型**，直接用公版 —— 空跑只是要看排版，
不該為了預覽燒額度，也不該為了十幾次 CLI 呼叫跑好幾分鐘。

⚠ **個人版只報統計，不交辦事情**（需求方指定）。「仍未處理的麻煩今天優先看」
那種交辦話改由下面的待接手清單負責 —— 個人版的收件人是「昨天被催到的人」，
他**今天不一定上班**，交辦給他只會沒人做。

## 四、早上 07:30 的待接手清單

```
ticket:handover（每天 07:30）
  ├─ 所有 status = PENDING 的求助單（不看時間，連超過提醒上限的也列）
  ├─ tag 當天早班
  └─ 發到內部支援群組
```

⚠ **存在的理由是大夜班目前沒有人排班**：深夜的提醒 tag 不到任何人，
那些問題整晚沒有人接手。這則在早班上班前把它們正式交接出去。

⚠ **發群組不是私訊**（需求方指定）：交接要留在大家看得到的地方，
而且 tag 得到人就不必擔心對方有沒有私訊過 bot。

⚠ **「早班」＝啟用中的班別裡 `start_time` 最早的那一個**，不是比對班別名稱 ——
名稱改掉（「早班」→「A 班」）就會悄悄 tag 不到人而且不報錯。
代價是：大夜班若被改成從 00:00 開始，它會變成「最早」，改班別時間時要一起看。

⚠ **tag 不到人時那句說明比清單本身重要**：沒人被 tag 代表這份清單沒有人負責，
靜靜發出去只會沒人認領，所以換成「今天早班沒有可以 tag 的人員，麻煩主管協助指派」。

清單裡每題都寫「已等多久」—— 判斷先處理哪一題靠的是這個，不是順序。

## 已確認的決策（2026-10-06）

| 題目 | 決定 |
|---|---|
| 深夜無人值班 | **提醒次數設上限**（預設 30 次）。到上限就停，並發一則「已提醒 N 次仍未處理」收尾 |
| 第 3 次之後的間隔 | **設定頁改成「首次 / 之後每隔」** 兩個欄位 |
| 統計維度 | **按單 ＋ 按人都要** → 需要新表記錄每一次提醒 tag 到誰 |
| 統計收件人 | **另設一個欄位**，不跟班表通知共用 |

### 上限到了之後

停止提醒，但**發最後一則**（tag 主管與老闆）說明「這張單已提醒 N 次仍未處理」——
直接安靜停掉的話，那張單就從所有人的視線裡消失了。
隔天的統計也會把「撞到上限」的單單獨列出來。

### 設定放哪裡

全部放**通知設定頁的「內部支援群組」分頁**（首次 / 之後每隔 / 上限次數 / 統計收件人）。
提醒間隔本來就在那裡，拆到兩頁反而更難找。

### 統計發送時間

08:30 —— 班表通知（08:00）之後、匯率報價（09:00）之前，三則錯開。

## 實作結果

### 資料

`auto_reply_ticket.remind_count` 的語意從「階段」（0/1/2）改成**真正的累計次數**，
階段由次數推導。撞到上限時推成 `max + 1`，查詢用 `remind_count <= $max` 收單。

新表 `auto_reply_ticket_remind`：**每送出一次提醒、每 tag 到一個人就是一列**。
`user_id` 可為 null —— tag 不到人時也要記一列，那代表「催了但沒人被叫到」，
是最該被看見的狀況，不記的話統計上會看起來像那次提醒沒發生。

### 一輪的流程

```
auto-reply:remind（每分鐘）
  ├─ getTicketsDueForRemind(首次, 間隔, 上限)
  │    remind_count = 0 → created_at + 首次 已到
  │    remind_count ≥ 1 → last_reminded_at + 間隔 已到
  ├─ 當班人員**整輪只查一次**（十張單卡住不該查十次排班）
  └─ 逐張 remindOne()
       ├─ seq = remind_count + 1，決定 stage
       ├─ 送出（失敗只記 log，不中斷整輪）
       └─ DB::transaction：寫提醒紀錄 + 推進 remind_count
```

⚠ **不論送不送得出去，`remind_count` 都要往前推進**：tag 不到人就不更新的話，
這張單每分鐘都會被重試，整晚下來是幾百次查詢與送信。

⚠ **第 3 次之後是「當班人員 ＋ 主管與老闆」，不是「換成主管」**——
改之前是後者，當班的人會以為事情已經不關他了。id 去重，主管自己在值班時不會被 tag 兩次。

### 設定頁（通訊管理 → 通知設定 → 內部支援群組）

| 欄位 | 說明 |
|---|---|
| 第一次提醒（分鐘） | 開單後多久送第一次 |
| 之後每隔（分鐘） | 第一次之後的固定間隔 |
| 最多提醒幾次 | 到這個次數就停並發收尾那則 |
| 完整統計私訊給 | **可勾多位 + 全選**，沒綁定的標「（未綁定）」但不隱藏 |

⚠ 收件人存的是**逗號串接的 id**（`3,7,12`），用 `AppSettingService::getIntList()` 讀、
`idListValue()` 寫。不用 JSON —— 內容就只是一串整數，JSON 只會多一層轉義。
儲存鍵沿用單數的 `shift_notice.manager_user_id` / `auto_reply.remind_report_user_id`：
換掉字串會讓既有設定值歸零。

⚠ `KEY_REMIND_INTERVAL_MINUTES` 存的字串**刻意沿用舊的 `remind_second_minutes`**：
語意變了（「第二次要再等多久」→「之後每隔」），但換掉字串會讓既有設定值歸零、
悄悄退回預設。常數名表達新語意，儲存鍵維持穩定。

### 每日統計

```
remind:report（每天 08:30）
  ├─ 按單：getTicketsForDate() —— 問題前 40 字、群組、幾次、現在狀態
  ├─ 按人：countByUserForDate() —— 誰被催幾次、涉及幾題
  │         user_id 為 null 的那列單獨顯示「沒 tag 到任何人」
  └─ StaffDmService 私訊給設定的收件人
```

- 設定頁有「測試發送」：真的把昨天的完整統計發給勾選的人（開頭掛 `TEST_PREFIX`）。
  **只發完整版**，不發個人版 —— 測試不該去打擾昨天被提醒到的同仁。
  刻意不是 dry-run：沒綁定、被封鎖這些真正會出事的狀況，只組字串測不到
- 兩段各最多 15 行，超出只報「另外還有 N 筆」—— Telegram 單則上限 4096 字
- 暱稱**另外查**，不在統計查詢裡 join：帳號被刪時 `user_id` 變 null，
  join 進來那幾列會整個從統計消失
- 日期條件用 `whereBetween` 而不是 `whereDate`：後者等於 `DATE(created_at) = ?`，
  欄位被函式包住就吃不到索引

### StaffDmService

`ShiftNoticeService::dm()` 抽出來共用（班表通知 + 每日統計）。
綁定檢查（`telegram_user_id` **且** `telegram_dm_ready`）、送出、失敗處理都在這支。

## 檔案

| 檔案 | 內容 |
|---|---|
| 上面「要移除的東西」整張表 | 刪除舊告警 |
| `app/Services/AutoReplySupportService.php` | `remindTimeoutTickets()` 改成依間隔持續提醒、文案帶次數 |
| `app/Repositories/AutoReplyTicketRepository.php` | `getTimeoutTickets()` → `getTicketsDueForRemind()`，依 `last_reminded_at` 判斷 |
| `app/Repositories/AutoReplyTicketRemindRepository.php`（新增） | 寫入提醒紀錄、兩個維度的統計 |
| `app/Models/AutoReplyTicketRemind.php`（新增） | 提醒紀錄 Model |
| `database/migrations/2026_10_06_000002_create_auto_reply_ticket_remind_table.php`（新增） | 提醒紀錄表 |
| `app/Services/AppSettingService.php` | `KEY_REMIND_INTERVAL_MINUTES`／`KEY_REMIND_MAX_COUNT`／`KEY_REMIND_REPORT_MANAGER` |
| `app/Services/SettingService.php` | 設定頁多四個欄位 + 收件人候選 |
| `app/Http/Requests/Setting/UpdateSupportRequest.php` | 新欄位驗證 |
| `resources/views/admin/setting/index.blade.php`、`public/js/setting-admin.js` | 設定頁 UI |
| `resources/lang/{tw,cn,en}/setting.php` | 新欄位語系 |
| `app/Console/Commands/AutoReplyRemindCommand.php` | 說明改寫 |
| `config/constants.php` | `AUTO_REPLY.REMIND` 語意改寫、報表文案 |
| `app/Services/StaffDmService.php`（新增） | 從 `ShiftNoticeService::dm()` 抽出來共用 |
| `app/Services/RemindReportService.php`（新增） | 每日統計 |
| `app/Console/Commands/RemindReportCommand.php`（新增） | `remind:report` |
| `app/Services/TicketHandoverService.php`（新增） | 早上 7:00 的待接手清單 |
| `app/Console/Commands/TicketHandoverCommand.php`（新增） | `ticket:handover` |
| `app/Console/Kernel.php` | 加 `ticket:handover`（07:00）、`remind:report`（08:30）、移掉 `telegram:alert` |
| `app/Services/AppSettingService.php` | `getIntList()` / `idListValue()`：收件人改成可多位（逗號串接） |


## 上線要做的事

1. `php artisan migrate`（`auto_reply_ticket_remind` 新表；`telegram_dm_ready` 若還沒跑也要）
2. `php artisan optimize`（動過 `config/`）
3. 到「通訊管理 → 通知設定 → 內部支援群組」設定：之後每隔幾分鐘、最多幾次、完整統計私訊給誰（可勾多位／全選）
4. **請統計收件人先私訊機器人一次**，否則下拉顯示「（未綁定）」、統計發不出去
5. `php artisan remind:report --dry-run` 與 `php artisan ticket:handover --dry-run` 各看一眼排版

⚠ **沒跑 migration 的話通知設定頁會 500**（`getDmCandidates()` 讀
`telegram_dm_ready`）—— 這是既有頁面，不能只部署程式不跑 migration。

## ⚠ 提醒訊息必須自己站得住

原本文案是「**上面這題**已經等 20053 分鐘了」—— 完全依賴引用還掛得上，
而且分鐘數沒人換算得出來。2026-10-06 群組升級成 supergroup 之後，
舊的 `ask_message_id` 在新群組裡不存在，群組裡就只剩一排孤兒訊息：
看得到「等了 20053 分鐘」，但沒人知道在講哪一題。

現在每則提醒都自己帶**客人群組 + 問題前 60 字 + 天/小時**：

```
@當班 @主管
⏰ 第 5 次提醒，這題已經等 13 天 22 小時
　某某商戶客服群
　代收訂單一直是處理中，這是什麼原因呢？麻煩協助確認一下，我們客人在等
麻煩協助看一下 🙏
```

也拿掉了「客人還在線上等回覆」—— 等了 13 天之後那句是假的。

## ⚠ 引用掛不上時要重發，否則那張單永遠回不了

客服作答唯一的管道是「引用回覆求助訊息」。原訊息不在了（被刪、或群組換過 id）
就**永遠回不了** —— 那張單會一路催到上限然後消失，而客人的問題從頭到尾沒人處理。

怎麼知道引用沒掛上：送出時帶了 `allow_sending_without_reply`，所以引用失敗時
Telegram **不會報錯**，而是靜默退化成一般訊息 —— 回應裡就少了
`result.reply_to_message`。**那個欄位在不在，是唯一分得出來的訊號。**

偵測到就重發一張完整的求助訊息（`REMIND.REISSUE`）並把 `ask_message_id`
換成新的，迴路就接回來了。

## ⚠ 待接手清單積了舊資料怎麼辦

```bash
# 先看會收掉哪些（不改任何東西）
php artisan ticket:close-stale --days=7 --dry-run

# 確認沒問題再真的收
php artisan ticket:close-stale --days=7
```

⚠ **這支預設不排程**（`constants.AUTO_REPLY.STALE_DAYS` 預設 0，等於停用）——
自動關掉客人的問題是不可逆的，不該預設開啟。要自動化就把那個值改成天數、
並在 `Console\Kernel` 加排程。

⚠ **沒給天數時什麼都不做**，不會自己挑一個預設值：猜錯天數的代價是把還該
處理的單一起收掉。

### 為什麼新增 `EXPIRED` 狀態而不是沿用 `IGNORED`

`IGNORED` 的語意是「客服自己處理掉了」，統計上顯示「客服已自行處理」——
把逾期的單標成那樣，**報表就在說謊**。`EXPIRED(5)` 誠實地說
「沒人處理，時間到了被系統收掉」，統計顯示「⚠️ 放太久已自動收掉」。

`AutoReplyTicket::isClosed()` 要包含它，不然同仁回覆那張單還會跑一次作答流程。

### ⚠ 不通知客人

那些客人早就不在線上等了（所以才會放到過期）。現在補一句「您的問題我們不處理了」
只會把已經冷掉的事情重新點燃。

## ⚠ 排版的幾個規則（2026-10-08 重做）

需求方回報「很難看清楚資訊」。改動與理由：

| 原本 | 現在 | 為什麼 |
|---|---|---|
| `（10/7（週三））` | 標題與日期分兩行 | `NoticeText::date()` 已經含括號，外面又包一層。**三則通知都犯這個毛病**，一起修 |
| 一題擠一行，用「｜」分隔 | 拆三行：狀態＋題目／群組／次數 | 中英數與全形符號混在同一行很難讀 |
| 狀態寫在行尾 | **顏色圖示放行首** | 一排紅燈綠燈掃一眼就知道哪幾題還沒好 |
| 總計擠成一句 | 一行一件事、數字加粗 | 這則是早上掃一眼的東西，不是拿來細讀的 |

狀態圖示：🔴 未處理／🟡 處理中／🟢 結案／⚪ 客服自行處理／⚫ 放太久收掉。
圖示與文字分開放（`STATUS_ICON` / `STATUS_LABEL`）—— 圖示給人掃，文字說清楚。

⚠ **全形「）」後面不要加半形空格**：`昨天 10/7（週三） 有 2 題` 看起來像斷開，
寫成 `昨天 10/7（週三）有 2 題`。

## 相關

- [[auto-reply]] — 求助單的完整流程（開單、回答、回覆客人、回填題庫）
- [[shift-daily-notice]] — 私訊同事的既有做法（`telegram_dm_ready` 綁定）
- [[scheduling]] — 當班人員怎麼算（`getOnDutyUserIds()`）
- [[telegram-chat]] — 要移除的橫幅所在的畫面
