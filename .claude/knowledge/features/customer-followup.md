# 待追蹤事項（有人說「稍等」就記一筆）

> **狀態：設計定稿（2026-10-11），需求方七題全部確認，可以動工。**

## 需求（2026-10-11）

Telegram 對話目前 AI 只做兩件事：回答、轉求助單。需求方要補上第三件：

1. 訊息裡出現**等待詞**（稍等、稍晚提供、等一下給你、我去問一下…）→
   記成一筆**還未完成事項**
2. 隔一段時間後，去**詢問當班人員**要不要繼續追蹤
   - **要** → 系統發訊息問客人後續進度
   - **不要** → 這個話題就結束
3. 當班人員**沒回應**時，**比照求助單的做法**：記次數、tag 人員、次數到了升級

## 需求方確認的七件事（2026-10-11）

| # | 題目 | 決定 |
|---|---|---|
| 1 | 等待詞怎麼判定 | **詞表先篩，命中才叫 AI 確認** |
| 2 | 去問當班人員時發哪裡 | **內部支援群組 tag ＋ 按鈕** |
| 3 | 客人說完又繼續傳訊息 | **每則新訊息都把計時往後推** |
| 4 | 我方客服說「稍等」算不算 | **也要記，但分開標示** |
| 5 | 我方承諾按「要追蹤」做什麼 | **只 tag 當班人員，不指名** |
| 6 | 客人追問後還是不回 | **不自動結案**，由人決定何時收 |
| 7 | 後台列表頁／統計 | **要完整列表頁**；統計**獨立一則** |

## 一、這是跟求助單反方向的一條線

| | 求助單（已實作） | 待追蹤事項（本案） |
|---|---|---|
| 誰欠誰 | **我們欠客人**答案 | **客人欠我們**資料（或我方自己欠著沒做） |
| 怎麼產生 | AI 答不出來 → 開單 | 有人說「稍等」→ 開單 |
| 內部群組的訊息 | 「這題怎麼回？」等同仁作答 | 「要不要追？」等當班人員按鈕 |
| 怎麼結束 | 回覆了客人 / 入題庫 / 客服自行處理 | 客人回來了 / 當班人員按「不追了」 |

⚠ **所以不能塞進 `auto_reply_ticket`。** 那張表的狀態機（PENDING → ANSWERED →
REPLIED / SAVED）講的是「這題答出來了沒有」，而追蹤單要表達的是「對方回來了沒有」——
硬共用會讓求助單統計的每一個數字都要先問「這筆是哪一種」。

⚠ **但提醒機制要共用。** [[remind-escalation]] 已經有一整套「計次 → 升級 →
上限 → 收尾」，需求方說的「比照」就是它。共用方式見第六節。

## 二、兩種來源（決策 4）

需求方要「我方客服說稍等也記一筆，但分開標示」。`telegram_message` 現成的
兩個欄位剛好分得乾淨：

| 訊息 | `direction` | 判定 | `source` |
|---|---|---|---|
| 收到的，發話者不是後台帳號 | INBOUND | 客人承諾 | `CUSTOMER` |
| 收到的，發話者是後台帳號（客服用個人帳號在客戶群講話） | INBOUND | 我方承諾 | `STAFF` |
| 後台送出的，`is_auto = false` | OUTBOUND | 我方承諾（客服手打） | `STAFF` |
| 後台送出的，`is_auto = true` | OUTBOUND | **不開單** | — |

⚠ **AI 自動回覆那句一定要排除。** 轉人工的稍等句（`WaitWriter` 寫的）必然含
「稍等」，而那條路**已經開了求助單、已經在催了** —— 不排除的話每次轉人工都會
多一張追蹤單，兩套提醒追同一件事。`is_auto` 就是那道閘門。

⚠ 判斷「發話者是不是後台帳號」用現成的 `StaffIgnoreService::isStaff()`
（[[ignore-staff]] 那份名單，全站共用快取），不要自己比對暱稱。

### 兩種單只有一個地方不一樣：按下「要追蹤」之後

| | `CUSTOMER` | `STAFF` |
|---|---|---|
| 群組訊息標示 | 🙋 客人承諾 | 🧑‍💻 我方承諾 |
| 按「要追蹤」 | **發訊息問客人**後續（AI 寫，引用原句） | **只 tag 當班人員**問處理了沒，**完全不碰客人** |
| 按「不追了」 | 結案 | 結案 |
| 沒人按 | 計次、升級、到上限收尾 | 同左 |

⚠ **`STAFF` 單不指名說那句話的人**（決策 5）：他今天不一定上班，交辦給他
只會沒人做 —— 同一個理由在 [[remind-escalation]] 的個人版那段已經踩過。
當班的人負責接起來，`sender_name` 留在單子上給他知道是誰講的。

⚠ **`STAFF` 單絕對不發訊息給客人。** 那是我們自己欠的事，回頭問客人
「進度如何」語意完全反過來。

## 三、怎麼認出等待詞（決策 1）

```
訊息存進 telegram_message
  └─ 等待詞偵測（開關關掉就整個跳過）
      ├─ 判來源（第二節）；AI 自動回覆直接結束
      ├─ 詞表比對（命中 0 個就結束 —— 絕大多數訊息停在這裡，零成本）
      └─ 命中 → 叫一次模型確認「這句是不是在說稍後會提供／會處理」
          ├─ 是 → 開／更新追蹤單
          └─ 不是 → 什麼都不做（只寫 log）
```

⚠ **為什麼不能只靠 AI**：客人訊息**不保證會經過 AI**。`handleIncomingMessage()`
裡那三道判斷（`isAutoReplyOn` / `isStaff` / `isIgnored`，見 [[ignore-member]]、
[[ignore-staff]]）任一道不過就不會 dispatch `AutoReplyJob` —— 關掉自動回覆的
對話（客服正在親自處理的那些）反而最需要這個功能。而 `STAFF` 來源的訊息
本來就永遠不會進 AI。

⚠ **為什麼不能只靠詞表**：「等等的費率問題」「我稍後再看一下你們的文件」都含
「等」「稍後」但不是承諾。誤判的代價是多一則群組訊息，而**雜訊會讓整個功能
被忽略** —— 這比漏記一筆嚴重。

⚠ 模型只做是非題（schema 只有 `is_waiting`），跟 [[auto-reply-natural]] 的
intent 判斷分開。**不走備援 API**（比照 `EncouragementWriter`）：這是輔助功能，
額度用完時退回「只靠詞表」比去花 API 的錢合理。

### 詞表放哪裡

設定頁一個 textarea，一行一詞，存 `app_setting`。**不做獨立維護頁面**：
量只有十幾個詞，為它開一支 CRUD 的維護成本高於價值。預設值在
`constants.FOLLOWUP.KEYWORDS`，設定沒填就用預設。

預設詞：`稍等`、`等等`、`等一下`、`等下`、`晚點`、`稍晚`、`稍後`、`之後提供`、
`再提供`、`再給你`、`再傳`、`我去問`、`我問一下`、`確認後`、`查一下`、`等我`

⚠ 每張單記下 `matched_keyword` —— 詞表要調整時，靠這個欄位才知道哪幾個詞
一直在誤判。

## 四、一張追蹤單的生命週期

```
開單
  └─ status = WAITING（等對方自己回來）
      │
      ├─ 客人又傳訊息 ────────────► 計時往後推，單子不動（決策 3）
      │
      └─ 安靜滿 N 分鐘 → 問當班人員（內部群組，附兩顆按鈕）
            ├─ 按「要追蹤」 → CUSTOMER：發訊息問客人／STAFF：tag 當班人員
            │                   → status = ASKED，重新開始計時
            │                   ├─ 客人回話、不是等待詞 → ANSWERED 結案
            │                   └─ 客人又說「稍等」 → 退回 WAITING、wait_count++
            │                         （⚠ 這裡不能結案，見下）
            ├─ 按「不追了」 → status = CLOSED，結束
            └─ 沒人按 → 每隔 M 分鐘再問一次（計次，第 3 次起加 tag 主管老闆）
                  └─ 到上限 → 發收尾那則 → status = GIVEN_UP
```

### 「安靜滿 N 分鐘」而不是「開單滿 N 分鐘」（決策 3）

⚠ 客人說完「稍等我查一下」常常三分鐘後就接著講。固定從開單起算的話，
**對話正熱的時候系統會插進來問當班人員「要不要追蹤」**，而客人其實就在線上。

所以每一則新的客人訊息都把 `wait_at` 推到最新，等於「這個對話安靜 N 分鐘了，
而且最後是有人說要給我們東西」才去問。

⚠ **客服回覆客人不推後計時**：我們催過了還是在等客人，計時不該因為我們自己
講話而重來。（`STAFF` 單相反 —— 那是我們自己的事，見下。）

### 不自動結案（決策 6）

需求方選的是「不自動結案」：追問客人之後還是沒回，就**回到 WAITING 重新計時**，
下一輪再問當班人員要不要再追。收不收由人決定。

⚠ **所以「追問輪數上限」整個不存在**，`ask_round` 只拿來顯示「已追問 N 次」——
那個數字是當班人員決定要不要再追的依據。

⚠ **但「沒人按按鈕」仍然有次數上限。** 這兩件事不一樣：
「追問輪數」是有人做了決定；「提醒次數」是**沒有人做決定**，深夜無人值班時
不設上限就是整晚幾百則群組訊息（[[remind-escalation]] 踩過）。到上限發一則
收尾（tag 主管老闆）說明「已提醒 N 次沒人處理」，然後停。

⚠ **自動收舊單預設停用**（`FOLLOWUP.STALE_DAYS = 0`）。既然需求方要「不自動
結案」，就不該有一條悄悄替他收單的路；但列表無限長也不行，所以指令寫好、
排程不掛，要用的時候手動跑或自己設天數 —— 比照 `ticket:close-stale`
（那支也是這個理由預設停用）。

### 什麼時候算對方回來了

| `source` | 算回來了 | 不算 |
|---|---|---|
| `CUSTOMER` | `ASKED` 狀態下客人傳新訊息，**而且那句不是又一次「稍等」** → `ANSWERED` 結案 | `WAITING` 狀態下客人說話（他可能只是說「還在找」）→ 只推後計時 |
| `STAFF` | 只有**人按「不追了」** → `CLOSED` | 客人說話不影響 —— 我們自己欠的事，客人回什麼都不代表做完了 |

⚠ **客服在後台回覆客人不結案 `CUSTOMER` 單**，這跟求助單相反
（[[remind-escalation]] 那邊客服一回就 `ignoreOpenTicketsByGroup`）。
理由：我們回了不代表客人把東西給了。

### 🔴 「說稍等、又說稍等」不能結案（需求方 2026-10-11 問出來的漏洞）

需求方問：「客人說稍等，然後又說稍等，他會自動結案嗎？」—— 照本文件原本的
寫法，**分兩種情況，而第二種會錯**：

| 情況 | 原本的行為 | 對不對 |
|---|---|---|
| `WAITING` 時又說一次稍等 | 推後計時、更新原句，不結案 | ✅ 對 |
| **`ASKED` 時（我們追問過了）回一句「稍等」** | 「客人傳新訊息」→ `ANSWERED` 結案 | ❌ **錯得最嚴重的一種** |

第二種正是最該追的情況：我們追問了，客人又拖一次 —— 結果系統判定他回來了、
把單收掉，這件事從此沒有人再看。**比完全沒做這個功能更糟**，因為大家會以為
系統在看著。

**修法**：`ASKED` 狀態下客人回話時，**先跑一次等待詞偵測再決定**：

```
ASKED 狀態，客人傳新訊息
  └─ 跑等待詞偵測（詞表 ＋ AI 是非題，跟開單用的是同一支）
      ├─ 又是等待詞 → 不結案：退回 WAITING、計時從這一則重新算、
      │                 wait_count++（他拖的次數）
      └─ 不是等待詞 → ANSWERED 結案（他真的回應了）
```

⚠ **偵測本來就會對每一則客人訊息跑**，所以這不是多一道成本 ——
只是把「要不要結案」的判斷排在偵測**之後**，順序對了就自然正確。

⚠ **判斷不到的時候一律不結案**（模型逾時、額度用完、只有詞表命中）：
漏結案的代價是當班人員多按一次「不追了」，誤結案的代價是事情消失。

⚠ 客人同時給了部分資料又說「另一筆稍等」→ `is_waiting` 是 true → **不結案**。
還有東西沒給，單子就該留著。

### 「他已經說第幾次稍等了」要看得見

為此多一個 `wait_count` 欄位（預設 1，每次又命中就 +1），群組那則詢問訊息與
後台列表都要顯示：

```
🙋 客人承諾　已拖 3 次　我們追問過 2 次
```

⚠ 這個數字是**當班人員按按鈕的依據**。「第一次說稍等」跟「說了第三次稍等、
我們也追問過兩次」要做的決定完全不同 —— 後者通常該改成直接打電話或
升級處理，而不是再發一次「想跟您確認進度」。

⚠ 它也是**唯一能看出「這個客人習慣性拖延」的訊號**，統計那則要列出
「拖最多次」的前幾筆。

### 同一個對話、同一種來源，只有一張未結案的單

每說一次「稍等」就開一張的話，一個對話能堆出五張幾乎一樣的單。命中時先找
該對話**同來源**未結案的單：有就更新 `wait_at` 與原句、`wait_count++`、
**提醒次數歸零**（當班人員還沒被問過的那一輪不算浪費），沒有才開新的。

⚠ 去重要帶 `source`：客人承諾與我方承諾是兩件事，不能互相蓋掉。

⚠ **提醒次數歸零、但 `wait_count` 與 `ask_round` 不歸零**：前者是「這一輪
還沒問到人」，後者兩個是「這件事拖了多久、我們催了幾次」—— 跟著歸零的話，
拖第五次的單看起來會跟剛開的一樣新。

## 五、資料

### `conversation_followup`（新表）

| 欄位 | 說明 |
|---|---|
| `telegram_group_id` | 哪個對話 |
| `telegram_message_id` | 說稍等的那一則（nullable，`nullOnDelete`） |
| `source` | 1=CUSTOMER（客人承諾）／2=STAFF（我方承諾） |
| `sender_name` | 誰說的（訊息被清掉後還看得出來；`STAFF` 單靠它知道是哪位同事） |
| `sender_user_id` | 我方承諾時對應的後台帳號（nullable，個人帳號沒綁定就是 null） |
| `wait_text` | 那句原話（截 `WAIT_CHARS` 字） |
| `matched_keyword` | 命中哪個詞 |
| `wait_at` | 計時起點，每則新訊息往後推 |
| `status` | 1=WAITING／2=ASKED／3=ANSWERED／4=CLOSED／5=GIVEN_UP／6=EXPIRED |
| `wait_count` | 對方說了第幾次「稍等」（預設 1，`ASKED` 時又說一次也算）|
| `ask_round` | 已經追問幾輪（只顯示，不設上限） |
| `ask_message_id` | 群組那則詢問訊息的 id（按下按鈕後要改寫它） |
| `remind_count` / `last_reminded_at` | 問了當班人員幾次、最後一次什麼時候 |
| `decided_by` / `decided_at` | 誰按的按鈕、什麼時候（「當班人員沒反應」要有憑據） |

索引：`(status, wait_at)`（每分鐘的排程靠它撈單）、
`(telegram_group_id, source, status)`（去重）、`created_at`（統計）。

### `conversation_followup_remind`（新表）

結構照 `auto_reply_ticket_remind` 抄：一次提醒 × 一個被 tag 的人 = 一列，
`user_id` nullable（tag 不到人也要記一列，理由見那張表的 migration 註解）。

⚠ **不共用 `auto_reply_ticket_remind`**：它的 `auto_reply_ticket_id` 是
`constrained()` 的 FK，塞別種單的 id 進去會直接違反外鍵；改成可為 null 再加
一個 type 欄位的話，既有統計的每一句 SQL 都要跟著加條件。

## 六、提醒與升級怎麼共用

`AutoReplySupportService::remindTimeoutTickets()` 那一圈的邏輯（當班人員整輪
只查一次、`remindOne()` 決定 stage、送不出去也要推進次數）**原樣適用**，
但它跟求助單的文案與 repository 綁在一起。

抽一支 `app/Services/Notify/EscalatingReminder.php`：

```php
// 吃「要提醒的對象」與「每一次提醒要送什麼」，回報送出幾次
public function run(iterable $items, array $targets, array $options, callable $send)
```

兩邊各自提供自己的文案與落地方式，升級規則（`ESCALATE_AT`、`MAX_COUNT`、
stage 判斷、id 去重）只有一份。

⚠ **這會動到已上線的求助單提醒**，所以同一次改動裡要確認那條路沒壞
（`auto-reply:remind` 的行為必須完全一樣）。不想碰的退路是複製那段邏輯 ——
代價是升級規則從此有兩份，改一邊忘另一邊。

## 七、後台「待追蹤事項」列表頁（決策 7）

| 欄位 | 內容 |
|---|---|
| 對話 | 群組標題，點進去開對話視窗 |
| 來源 | 🙋 客人承諾／🧑‍💻 我方承諾 |
| 說的人 | `sender_name` |
| 原句 | `wait_text`（截斷，hover 看全文） |
| 等多久 | 從 `wait_at` 算 |
| 狀態 | 圖示 ＋ 文字（比照統計那套 🔴🟡🟢⚪⚫） |
| 已拖 | `wait_count` 次（又說一次「稍等」就 +1） |
| 已追問 | `ask_round` 次 |
| 操作 | 手動追蹤／結案 |

- 預設只看未結案（WAITING / ASKED），可切「全部」
- 篩選：來源、狀態、對話
- 權限 keyword：`followup.index`（檢視）、`followup.manage`（手動追蹤／結案）
- 列表頁放 sidebar「客服管理」底下（跟對話視窗同一組）

⚠ **手動結案要記 `decided_by`**，跟按鈕走同一條路 —— 統計要分得出
「有人決定」與「沒人理」。

## 八、統計（決策 7）

獨立一則，**每天 10:30**（排程空檔：07:30 待接手、08:00 班表、08:30 超時統計、
09:00 匯率、09:30 VM 繳款、10:00 餘點同步、11:00 打卡報表）。

```
followup:report（每天 10:30）
  ├─ 昨天新開幾筆（客人承諾／我方承諾各幾筆）
  ├─ 結案幾筆、現在還掛著幾筆（含最久的那筆等了多久）
  ├─ 當班人員沒反應幾次（這是最該被看見的數字）
  ├─ **拖最多次的前幾筆**（`wait_count` 排序）—— 習慣性拖延只有這裡看得出來
  └─ 私訊給設定的收件人（可多位，比照既有）
```

⚠ 收件人沿用 `getIntList()` 那套逗號串接的做法，**獨立一個設定鍵**。
⚠ **沒事那天也要發**（比照 [[remind-escalation]]）：安靜不動時分不出
「昨天沒事」還是「排程壞了」。

## 九、問客人的那則訊息（只有 CUSTOMER 單會發）

AI 現寫，公版退路 —— 比照 `WaitWriter`／`EncouragementWriter`：

```
不好意思打擾了，想跟您確認一下，先前提到會提供的資料是否方便一併給我們？
我們這邊收到後會立刻幫您處理 🙏
```

⚠ **要引用回覆客人當初那則**（`wait_text` 那一則）。隔了半天之後只發一句
「想確認一下進度」，客人不知道在講哪件事 —— 這是 [[remind-escalation]]
「提醒訊息必須自己站得住」踩過的同一個坑。引用掛不上時（訊息被刪）退成在
文案裡帶上原句摘要。

⚠ 語氣**不能催**。客人沒義務準時給資料，那句話的目的是把球遞回去，不是討債。

⚠ 第二輪之後的文案不要重複同一句（帶 `ask_round` 給模型），
但也不要越問越急 —— 公版只有一句，重複追問時改由模型換說法。

## 十、設定（通訊管理 → 通知設定 → 內部支援群組）

| 欄位 | 預設 |
|---|---|
| 待追蹤事項開關 | 關（新功能預設不開，上線後再打開） |
| 等待詞（一行一個） | 見第三節 |
| 安靜多久後問當班人員 | 30 分 |
| 當班人員沒反應，之後每隔 | 30 分 |
| 最多問幾次（沒人按按鈕才算） | 5 次 |
| 每日統計私訊給 | 空（可多位＋全選） |
| 幾天沒結果就自動收掉 | **0 = 停用** |

⚠ **不沿用求助單那三個欄位**（首次／之後每隔／上限）：求助單是「客人在等
我們回覆」，壓力完全不同，間隔綁在一起調整時一定會互相將就。

⚠ 發到哪個話題沿用 [[support-group-topics]] 的話題設定，新增一個通知種類的 key。

## 十一、預計檔案

| 檔案 | 內容 |
|---|---|
| `database/migrations/*_create_conversation_followup_table.php` | 新表 |
| `database/migrations/*_create_conversation_followup_remind_table.php` | 提醒紀錄 |
| `app/Models/ConversationFollowup.php`、`ConversationFollowupRemind.php` | Model（狀態判斷、來源判斷） |
| `app/Repositories/ConversationFollowupRepository.php` | 撈待處理、去重、列表分頁、統計 |
| `app/Repositories/ConversationFollowupRemindRepository.php` | 提醒紀錄與按人統計 |
| `app/Criteria/Followup/*.php` | 列表篩選條件（來源／狀態／對話） |
| `app/Services/CustomerFollowupService.php` | 開單、問當班、按鈕處理、追問、結案 |
| `app/Services/AutoReply/WaitDetector.php` | 來源判定 ＋ 詞表比對 ＋ 模型確認 |
| `app/Services/AutoReply/FollowupAskWriter.php` | 問客人那句的文案 |
| `app/Services/FollowupReportService.php` | 每日統計 |
| `app/Services/Notify/EscalatingReminder.php` | 從求助單提醒抽出的共用升級邏輯 |
| `app/Services/TelegramChatService.php` | 入站／送出都掛偵測；`ASKED` 的結案判斷 |
| `app/Services/AutoReplySupportService.php` | 改用共用的 `EscalatingReminder`；`handleCallback` 多一個 prefix |
| `app/Http/Controllers/Admin/FollowupController.php` | 列表頁 ＋ 兩支 ajax |
| `app/Http/Requests/Followup/*.php`、`config/rules.php` | 驗證 |
| `app/Http/Resources/FollowupResource.php` | 列表回傳 |
| `app/Console/Commands/FollowupAskCommand.php` | `followup:ask`（每分鐘） |
| `app/Console/Commands/FollowupReportCommand.php` | `followup:report`（10:30） |
| `app/Console/Commands/CloseStaleFollowupCommand.php` | `followup:close-stale`（不排程） |
| `app/Console/Kernel.php` | 排程 |
| `config/constants.php` | `FOLLOWUP.*`（詞表、來源、狀態、文案、上限） |
| `config/permissionMap.php` | `followup.index` / `followup.manage` ＋ route |
| `routes/web.php` | 列表頁 ＋ `ajax-` 兩條 |
| `app/Services/AppSettingService.php`、`SettingService.php` | 七個新設定 |
| `resources/views/admin/followup/index.blade.php`、`public/js/followup.js` | 列表頁 |
| `resources/views/admin/setting/index.blade.php`、`public/js/setting-admin.js` | 設定 UI |
| `resources/views/layouts/app.blade.php` | sidebar 入口 |
| `resources/lang/{tw,cn,en}/followup.php`、`setting.php`、`permission.php` | 語系（tw → cn → en） |
| `config/changelog.php` | 新版號 |

⚠ 動了 `routes/`、`config/permissionMap.php`、`config/constants.php`，
收尾要跑 `php artisan optimize`（PROMPTS 第 7 節）。

## 上線前置作業

1. `php artisan migrate`（兩張新表）
2. `php artisan optimize`
3. 到「通訊管理 → 通知設定 → 內部支援群組」**打開開關**並確認間隔與收件人
4. 到「帳號管理 → 權限」勾 `followup.index` / `followup.manage`
5. `php artisan followup:report --dry-run` 看一眼排版

## 相關

- [[remind-escalation]] — 要共用的計次／升級／上限機制
- [[auto-reply]] — 求助單的完整流程
- [[auto-reply-ask-info]] — 「AI 追問客人補資料」，客人不回也會一直掛著，
  本案的機制可以一併接上去（那是 remind-escalation 列的三個告警缺口之一）
- [[ignore-staff]] — `isStaff()` 那份名單，來源判定靠它
- [[ignore-member]] — 為什麼不能只靠 AI 偵測
- [[support-group-topics]] — 發到哪個話題
- [[scheduling]] — 當班人員怎麼算（`getOnDutyUserIds()`）
- [[telegram-chat]] — 偵測掛在哪條路上
