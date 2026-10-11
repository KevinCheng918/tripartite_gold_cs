# 客人說「稍等」的待追蹤事項

> **狀態：設計稿，等需求方確認。** 待釐清問題在最後一節，確認後才動工。

## 需求（2026-10-11）

Telegram 對話目前 AI 只做兩件事：回答、轉求助單。需求方要補上第三件：

1. 客人訊息裡出現**等待詞**（稍等、稍晚提供、等一下給你、我去問一下…）→
   記成一筆**還未完成事項**
2. 隔一段時間後，去**詢問當班人員**要不要繼續追蹤
   - **要** → 系統發訊息問客人後續進度
   - **不要** → 這個話題就結束
3. 當班人員**沒回應**時，**比照求助單的做法**：記次數、tag 人員、次數到了升級

## 一、這是跟求助單反方向的一條線

| | 求助單（已實作） | 待追蹤事項（本案） |
|---|---|---|
| 誰欠誰 | **我們欠客人**答案 | **客人欠我們**資料 |
| 怎麼產生 | AI 答不出來 → 開單 | 客人說「稍等」→ 開單 |
| 內部群組的訊息 | 「這題怎麼回？」等同仁作答 | 「要不要追？」等當班人員按鈕 |
| 怎麼結束 | 回覆了客人 / 入題庫 / 客服自行處理 | 客人回來了 / 當班人員按「不追了」 |

⚠ **所以不能塞進 `auto_reply_ticket`。** 那張表的狀態機（PENDING → ANSWERED →
REPLIED / SAVED）講的是「這題答出來了沒有」，而追蹤單要表達的是「客人回來了沒有」——
硬共用會讓求助單統計的每一個數字都要先問「這筆是哪一種」。

⚠ **但提醒機制要共用。** [[remind-escalation]] 已經有一整套「計次 → 升級 → 上限 →
收尾 → 每日統計」，需求方說的「比照」就是它。共用的方式見第五節。

## 二、怎麼認出等待詞

### 建議做法：詞表先篩，命中才叫 AI 確認

```
客人訊息存進 telegram_message
  └─ 等待詞偵測（開關關掉就整個跳過）
      ├─ 方向必須是 inbound（客服自己說「稍等」不算，見下）
      ├─ 詞表比對（`str_contains`，命中 0 個就結束 —— 絕大多數訊息走到這裡就停）
      └─ 命中 → 叫一次模型確認「客人是不是在說他稍後會提供東西」
          ├─ 是 → 開/更新追蹤單
          └─ 不是 → 什麼都不做（只寫 log）
```

⚠ **為什麼不能只靠 AI**：客人訊息**不保證會經過 AI**。`handleIncomingMessage()`
裡那三道判斷（`isAutoReplyOn` / `isStaff` / `isIgnored`，見 [[ignore-member]]、
[[ignore-staff]]）任一道不過就不會 dispatch `AutoReplyJob` —— 關掉自動回覆的對話
（客服正在親自處理的那些）反而最需要這個功能。

⚠ **為什麼不能只靠詞表**：「等等的費率問題」「我稍後再看一下你們的文件」都含「等」
「稍後」但不是承諾。誤判的代價是多一張追蹤單（當班人員按「不追了」就結案），
不算嚴重，但每天多幾則群組訊息就會開始被當雜訊忽略 —— 那整個功能就失效了。

⚠ **模型只做是非題**，不產生文案、不挑題目，所以可以用最小的 schema
（`{is_waiting: bool}`），跟 [[auto-reply-natural]] 的 intent 判斷分開。
**不走備援 API**（比照 `EncouragementWriter`）：這是輔助功能，額度用完時
退回「只靠詞表」比去花 API 的錢合理。

### 詞表放哪裡

設定頁一個 textarea，一行一詞，存成 `app_setting`。**不做獨立維護頁面**：
量只有十幾個詞，為它開一支 CRUD 的維護成本高於價值。預設值放
`constants.FOLLOWUP.KEYWORDS`，設定沒填就用預設。

預設詞：`稍等`、`等等`、`等一下`、`等下`、`晚點`、`稍晚`、`稍後`、`之後提供`、
`再提供`、`再給你`、`再傳`、`我去問`、`我問一下`、`確認後`、`查一下`、`馬上`、`等我`

## 三、一張追蹤單的生命週期

```
開單（客人說稍等）
  └─ status = WAITING（等客人自己回來）
      │
      ├─ 客人又傳訊息 ────────────► 等待起點往後推（重新計時），單子不動
      │
      └─ 安靜滿 N 分鐘 → 問當班人員（內部群組，附兩顆按鈕）
            ├─ 按「要追蹤」 → 發訊息問客人 → status = ASKED，進下一輪等待
            ├─ 按「不追了」 → status = CLOSED，結束
            └─ 沒人按 → 每隔 M 分鐘再問一次（計次、第 3 次起加 tag 主管）
                  └─ 到上限 → 發收尾那則 → status = GIVEN_UP
```

### 「安靜滿 N 分鐘」而不是「開單滿 N 分鐘」

⚠ 客人說完「稍等我查一下」常常三分鐘後就接著講。固定從開單起算的話，
**對話正熱的時候系統會插進來問當班人員「要不要追蹤」**，而客人其實就在線上。

所以每一則新的客人訊息都把 `wait_at` 推到最新，等於「這個對話安靜 N 分鐘了，
而且最後是客人說要給我們東西」才去問。

⚠ **客服回覆客人不推後計時**：我們催過了還是在等客人，計時不該因為我們自己
講話而重來。

### 同一個對話只有一張未結案的單

客人每說一次「稍等」就開一張的話，一個對話能堆出五張內容幾乎一樣的單。
命中時先找該對話未結案的單：有就更新 `wait_at` 與原句、**提醒次數歸零**（當班人員
還沒被問過的那輪不算浪費），沒有才開新的。

### 追問客人幾輪

按「要追蹤」→ 發訊息給客人 → 客人還是不回 → 再問當班人員一次。
**輪數有上限**（預設 2 輪），到了就自動結案並在群組留一則說明 ——
無限追問客人會變成騷擾（參考 [[remind-escalation]] 收尾那則的理由：
安靜停掉的話那張單會從所有人視線裡消失）。

### 什麼時候算客人回來了

**只有「客人傳了新訊息」這一件事**，而且**只在 `ASKED` 狀態下才算結案**：

- `WAITING` 狀態下客人說話 → 推後計時（他可能只是說「還在找」）
- `ASKED` 狀態下客人說話 → 他回應了我們的追問 → `status = ANSWERED`，結案

⚠ **客服在後台回覆客人不結案**，這跟求助單相反（[[remind-escalation]] 那邊
客服一回就 `ignoreOpenByGroup`）。理由：我們回了不代表客人把東西給了。

⚠ **放太久要收**：`WAITING` / `ASKED` 超過 N 天（預設 7）自動 `EXPIRED`，
比照 `ticket:close-stale`，**不通知客人**。

## 四、資料

### `conversation_followup`（新表）

| 欄位 | 說明 |
|---|---|
| `telegram_group_id` | 哪個對話 |
| `telegram_message_id` | 客人說稍等的那一則（nullable，`nullOnDelete`） |
| `sender_name` | 客人暱稱（訊息被清掉後統計還要看得出是誰） |
| `wait_text` | 客人那句原話（截 `WAIT_CHARS` 字） |
| `matched_keyword` | 命中哪個詞 —— 詞表要調整時靠這個欄位知道哪些詞在誤判 |
| `wait_at` | 等待起點，客人每次說話往後推 |
| `status` | 1=WAITING / 2=ASKED / 3=ANSWERED / 4=CLOSED / 5=GIVEN_UP / 6=EXPIRED |
| `ask_round` | 已經追問客人幾輪 |
| `ask_message_id` | 群組那則詢問訊息的 id（按鈕要改寫它） |
| `remind_count` / `last_reminded_at` | 問了當班人員幾次、最後一次什麼時候 |
| `decided_by` / `decided_at` | 誰按的按鈕、什麼時候（「當班人員沒反應」要有憑據） |

索引：`(status, wait_at)`（每分鐘的排程靠它撈單）、`(telegram_group_id, status)`（去重）。

### `conversation_followup_remind`（新表）

結構照 `auto_reply_ticket_remind` 抄：一次提醒 × 一個被 tag 的人 = 一列，
`user_id` nullable（tag 不到人也要記一列，理由見那張表的 migration 註解）。

⚠ **不共用 `auto_reply_ticket_remind`**：它的 `auto_reply_ticket_id` 是
`constrained()` 的 FK，塞別種單的 id 進去會直接違反外鍵；改成可為 null 再加
一個 type 欄位的話，既有統計的每一句 SQL 都要跟著加條件。

## 五、提醒與統計怎麼共用

`AutoReplySupportService::remindTimeoutTickets()` 那一圈的邏輯（取當班人員整輪
只查一次、`remindOne()` 決定 stage、送不出去也要推進次數）**原樣適用**，
但它跟求助單的文案與 repository 綁在一起。

建議抽一支 `app/Services/Notify/EscalatingReminder.php`：

```php
// 吃「要提醒的對象」與「每一次提醒要送什麼」，回報送出幾次
public function run(iterable $items, array $targets, array $options, callable $send)
```

兩邊各自提供自己的文案與落地方式，升級規則（`ESCALATE_AT`、`MAX_COUNT`、
stage 判斷、id 去重）只有一份。

⚠ **這會動到已上線的求助單提醒。** 若不想碰，退路是在
`CustomerFollowupService` 裡複製那段邏輯 —— 代價是升級規則從此有兩份，
改一邊忘另一邊。**建議抽共用，並在同一次改動裡確認求助單那條路沒壞。**

### 統計

併進 08:30 那則既有統計（[[remind-escalation]]），多一段「待追蹤事項」：
幾筆、追問了幾次、幾筆當班人員沒反應。

⚠ **不另發一則**：早上 08:00 班表、08:30 統計、09:00 匯率已經三則，
再多一則會開始沒人細看。

## 六、設定（通訊管理 → 通知設定 → 內部支援群組）

| 欄位 | 預設 |
|---|---|
| 待追蹤事項開關 | 關（新功能預設不開，上線後再打開） |
| 等待詞（一行一個） | 見第二節 |
| 安靜多久後問當班人員 | 30 分 |
| 當班人員沒反應，之後每隔 | 30 分 |
| 最多問幾次 | 5 次 |
| 最多追問客人幾輪 | 2 輪 |
| 幾天沒結果就自動收掉 | 7 天 |

⚠ **不沿用求助單那三個欄位**（首次／之後每隔／上限）：求助單是「客人在等我們」，
壓力完全不同，間隔被綁在一起調整時一定會互相將就。

⚠ 發到哪個話題沿用 [[support-group-topics]] 的話題設定，新增一個通知種類的 key。

## 七、問客人的那則訊息

AI 現寫，公版退路 —— 比照 `WaitWriter`／`EncouragementWriter`：

```
不好意思打擾了，想跟您確認一下，先前提到會提供的資料是否方便一併給我們？
我們這邊收到後會立刻幫您處理 🙏
```

⚠ **要引用回覆客人當初那則**（`wait_text` 那一則）。隔了半天之後只發一句
「想確認一下進度」，客人不知道在講哪件事 —— 這是 [[remind-escalation]]
「提醒訊息必須自己站得住」踩過的同一個坑。引用掛不上時（訊息被刪）
退成在文案裡帶上原句摘要。

⚠ 語氣**不能催**。客人沒義務準時給資料，那句話的目的是把球遞回去，不是討債。

## 八、預計檔案

| 檔案 | 內容 |
|---|---|
| `database/migrations/*_create_conversation_followup_table.php` | 新表 |
| `database/migrations/*_create_conversation_followup_remind_table.php` | 提醒紀錄 |
| `app/Models/ConversationFollowup.php`、`ConversationFollowupRemind.php` | Model |
| `app/Repositories/ConversationFollowupRepository.php` | 撈待處理、去重、統計 |
| `app/Repositories/ConversationFollowupRemindRepository.php` | 提醒紀錄與按人統計 |
| `app/Services/CustomerFollowupService.php` | 偵測、開單、問當班、按鈕處理、追問客人 |
| `app/Services/AutoReply/WaitDetector.php` | 詞表比對 + 模型確認 |
| `app/Services/AutoReply/FollowupAskWriter.php` | 問客人那句的文案 |
| `app/Services/Notify/EscalatingReminder.php` | 從求助單提醒抽出的共用升級邏輯 |
| `app/Services/TelegramChatService.php` | 入站訊息掛偵測、`ASKED` 狀態的結案判斷 |
| `app/Services/AutoReplySupportService.php` | 改用共用的 `EscalatingReminder` |
| `app/Services/AutoReplySupportService.php`（`handleCallback`） | 多一個 callback prefix |
| `app/Services/RemindReportService.php` | 統計多一段 |
| `app/Services/AppSettingService.php`、`SettingService.php` | 七個新設定 |
| `app/Http/Requests/Setting/UpdateSupportRequest.php`、`config/rules.php` | 驗證 |
| `app/Console/Commands/FollowupAskCommand.php` | `followup:ask`（每分鐘） |
| `app/Console/Commands/CloseStaleFollowupCommand.php` | `followup:close-stale` |
| `app/Console/Kernel.php` | 排程 |
| `config/constants.php` | `FOLLOWUP.*`（詞表、狀態、文案、上限） |
| `resources/views/admin/setting/index.blade.php`、`public/js/setting-admin.js` | 設定 UI |
| `resources/lang/{tw,cn,en}/setting.php` | 語系 |

後台列表頁（若要做，見待釐清 5）：`ReportController` 之外另開
`FollowupController` + blade + js + `config/permissionMap.php` keyword + 語系。

## 待釐清（需求方確認後才動工）

1. **偵測方式**：詞表先篩、命中才叫 AI 確認（第二節）可以嗎？
   或是你希望**純詞表**（完全不花模型額度、但誤判較多）？
2. **問當班人員發哪裡**：內部支援群組 tag 人 + 按鈕（建議，比照求助單，
   大家都看得到）還是**私訊**當班的人？
3. **客人在對話中持續說話時延後計時**（第三節）符合你的預期嗎？
   還是不管客人後來說什麼，開單 N 分鐘後就一定去問當班人員？
4. **追問客人幾輪**：建議上限 2 輪後自動結案。要不要只追一輪？
5. **後台要不要「待追蹤事項」列表頁**（可手動結案／手動追蹤、看歷史）？
   或是 v1 只在對話視窗標一個「等客人回覆」的小標記就夠？
6. **統計**：併進 08:30 既有那則（建議）還是獨立一則？
7. **我方客服在客戶群用個人帳號說「稍等，我確認一下」算不算**？
   目前設計是**排除後台帳號**（`isStaff`）—— 你說的是客人說稍等。
   但那句話同樣代表「有事情沒完成」，要不要也記成待追蹤（反向：我們欠客人）？

## 相關

- [[remind-escalation]] — 要共用的計次／升級／上限／統計機制
- [[auto-reply]] — 求助單的完整流程
- [[auto-reply-ask-info]] — 「AI 追問客人補資料」，客人不回也會一直掛著，
  本案的機制可以一併接上去（那是 remind-escalation 列的三個告警缺口之一）
- [[ignore-member]]、[[ignore-staff]] — 為什麼不能只靠 AI 偵測
- [[support-group-topics]] — 發到哪個話題
- [[scheduling]] — 當班人員怎麼算（`getOnDutyUserIds()`）
