# 自動回覆忘記勾回來 — 靜置後自動恢復

> **狀態：已實作（2026-11-07，v3.78）。上線前要做兩件事，見最後一節。**

## 問題

客服遇到難處理的對話，會取消勾選自動回覆改人工回覆 —— 這是
[auto-reply.md](auto-reply.md) 原本就設計的用法。但**處理完就忘記勾回來**，
那個群組從此一直是人工，自動回覆等於白設。

直覺的做法是「每天把關著的群組全部開起來」，但**不能這樣做**：有幾個群組是
刻意永遠不開自動回覆的（`auto_reply` 預設就是 0，從來沒被打開過），
全部掃開會把自動回覆送進那些群組 —— 而且客人收到的訊息收不回來。

## 核心設計：記「關閉事件」，不掃「關閉狀態」

排程**不去查** `telegram_group WHERE auto_reply = 0` —— 那張結果裡混了兩種
完全不同的群組，分不出來：

| 群組 | `auto_reply` | 該不該恢復 |
|---|---|---|
| 平常開著，客服剛剛關掉去人工處理 | 0 | **要** |
| 從來沒開過，刻意一直人工 | 0 | **絕對不要** |

改成**關閉的那一刻記一筆「待恢復」紀錄**，排程只看這張表。

> ⚠ **這是這個功能的全部重點**：從沒開過自動回覆的群組，永遠不會有這筆紀錄，
> 所以**自動被排除**，不需要任何人去維護「排除名單」、新群組加進來也不用有人
> 記得去勾。需求方確認採此做法（2026-11-07）。

## 關閉時問意圖

光看「關閉」還少一半資訊：原本設計裡「難搞的客戶單獨關掉轉人工」是要**一直
關著**的，那種關閉不該被恢復。所以取消勾選時跳 modal 問一句：

| 選項 | 行為 |
|---|---|
| **暫時轉人工 N 分鐘**（預設） | 建立待恢復紀錄，靜置 N 分鐘後自動勾回來 |
| 永久關閉 | 一樣記一筆、但標記為永久，排程不會碰它 |

> ⚠ 預設停在「暫時」，因為那才是會出問題的那種 —— 預設選「永久」等於這個
> 功能不存在。
>
> ⚠ **永久也要記一筆**（而不是不寫），理由是稽核：之後要回答「這個群組是什麼
> 時候、誰決定不自動回覆的」只能靠這張表。狀態欄位區分，不是另開一張表。

同一個群組之後又被手動勾開、再關掉時，modal 一律回到預設的「暫時」——
那是最常見的情況，不因為上次選了永久就記住。

### 分鐘數由關閉的人當場決定（需求方 2026-11-07）

**不是全域一個值**，而是 modal 上讓關閉的人自己填 —— 他才知道這一通要處理多久。

| 項目 | 決定 |
|---|---|
| 存在哪 | **每一筆紀錄上**（`auto_reply_resume.quiet_minutes`），不是 `app_setting` |
| 全域設定 | 仍留一個 **modal 預填值**（預設 30），避免每次都要打字 |
| 介面 | 快捷鈕 15／30／60／120 分 ＋ 自訂輸入 |
| 允許範圍 | **5 ～ 1440 分鐘**（上限一天），寫進 `config/rules.php` |

⚠ **範圍的下限不是防呆而已**：客服思考、打字、等客人看訊息，對話安靜 3～5 分鐘
很常見。填 1 分鐘會讓 AI 在人工處理的中途插話 —— 靜置基準擋不住這麼短的值。

⚠ **上限的理由相反**：填一週等於「忘記」，這個功能對那一筆就失效了。

⚠ **modal 文案一定要寫成「對話靜置 N 分鐘後恢復」**，不能寫成「N 分鐘後恢復」。
兩者在客人一直在講話時的行為完全不同（見下節），而填數字的人預設會理解成
固定延遲。文案要補一句「客人還在聊就會繼續等，不會中途把 AI 放回來」。

## 恢復條件

排程每分鐘跑一次，一筆待恢復紀錄要**全部**滿足才恢復：

| 條件 | 為什麼 |
|---|---|
| 靜置滿**這筆紀錄自己的** `quiet_minutes` | 對話還在進行中就把 AI 放回來，客服正在跟客人來回，AI 會搶著回下一句 |
| 群組現在仍然 `auto_reply = 0` | 客服自己勾回來了 → 把紀錄結案為「已手動恢復」，不要再動 |
| 群組 `status` 仍啟用（未封存） | 封存的對話恢復了也沒有意義 → 作廢，不通知 |
| Claude 憑證仍可用（`AutoReplyService::isAvailable()`） | 沒憑證時開起來只會讓客人一直收到「稍等」。⚠ **這種情況不作廢、留著等設定好**，不是跳過一次就丟掉 |

### ⚠ 靜置的基準不是 `last_message_at`

`telegram_group.last_message_at` 可能**比關閉時間還早**（客服是在客人安靜一陣子
之後才關掉的）。直接拿它算，一關掉就立刻達標、下一分鐘就被恢復 ——
人工處理還沒開始就結束了。

基準要取 **`max(關閉時間, last_message_at)`**。

### 已知邊界：客人一直講不停就不會恢復

靜置基準是「最後一則訊息」，所以客人整天在講的群組會一直停在人工。

**這是對的，不做上限**：客人還在講，代表這通對話正在進行、客服正在處理，
那不是「忘記勾回來」。真正的風險是「客服下班了客人還在講」—— 但那個情境
既有的**求助單超時提醒**已經會響（`auto-reply:remind`），不該由這個功能
再補一套、更不該為此在無人值班的深夜把 AI 放回去對客人講話。

## 恢復後的通知

需求方確認（2026-11-07）：**只發內部支援群組，不私訊關閉的本人。**

- 走既有的 `SupportGroupService::send()`，新增通知類型 `auto_reply_resume`
  （`needs_reply => false`，不必引用回覆，所以可以勾在多個話題）——
  設定方式見 [support-group-topics.md](support-group-topics.md)
- 內容講清楚：群組名、誰在幾點關的、靜置多久、已恢復
- ⚠ **同一輪恢復多個群組要併成一則**，不要一個群組一則 —— 每分鐘跑的排程
  一次恢復五個群組就是五則，沒有人會看
- ⚠ **沒恢復的不通知**（還在靜置中、憑證沒設好）：每分鐘講一次等於洗版

### 要 tag 關閉的那個人（需求方 2026-11-07）

不是只寫名字，**直接 tag**。需求方要的是當事人會看到。

走既有做法：`user.telegram_username`（人工填的欄位）組成 `@name`，
正規化規則見 `TelegramUsernamePresenter::mentionLine()`。

⚠ **tag 不到人要有退路**：`telegram_username` 是人工填的，沒填就 tag 不到。
那時退回顯示 `nickname`（純文字），**不是整則不發** —— 既有的
`TicketHandoverService.php:215` 就是這樣處理的，照同一個模式。

⚠ 一輪恢復多個群組、而且是不同人關的 → 一則訊息裡會有多個 @，
逐列 tag 各自的關閉者，不要全部堆在開頭。

### 發在哪一個話題（2026-11-07 需求方提問）

**不寫死，由需求方在「通知設定 → 話題分流」頁勾。** 新增的 `auto_reply_resume`
會出現在每個話題的勾選清單裡，勾哪個就發到哪個。

| 行為 | 說明 |
|---|---|
| 可以勾幾個話題 | **幾個都行**（`needs_reply => false`，不像 `auto_reply_ticket`／`daily_rate` 只能勾一個） |
| 一個都沒勾 | **發到群組主區（General）**，`threadIdsFor()` 回 `[null]` —— 不會安靜消失 |

**建議勾在放 `ticket_handover`（早班待接手清單）的那個排程通知話題。**

⚠ **不要勾在「AI 轉來的問題」那個話題**：那裡是客服要引用回覆作答的作業區，
每一則都在等人動作。混進「某個群組的自動回覆已恢復」這種純告知，會讓那一區的
「有新訊息」失去意義。自動恢復本質上是系統自己動了手的事後告知，
跟待接手清單、班表同一類。

## 聊天視窗要看得到

群組清單與對話標頭顯示「人工中 · 約 N 分後恢復」的狀態字。

這不是附加的裝飾 —— 不顯示的話，客服會遇到「我明明關了它怎麼自己開了」，
而且看不出自己現在處於哪一種狀態。`TelegramChatService::getGroups()` 已經在回
`auto_reply` / `auto_reply_running`，多回一個預計恢復時間即可。

## 預計異動檔案（實作前的估算）

> ⚠ **以文末「實際實作」那一節為準。** 這份估算有兩處沒成真：
> `app/Criteria/AutoReplyResume/` 沒有建（查詢只有三支、條件固定），
> 而 `quiet_minutes` 最後不是「僅 mode=暫時時必填」而是 `nullable`——
> 理由都寫在那一節。

### 新增

| 檔案 | 內容 |
|---|---|
| `database/migrations/*_create_auto_reply_resume_table.php` | 待恢復紀錄 |
| `app/Models/AutoReplyResume.php` | Model |
| `app/Repositories/AutoReplyResumeRepository.php` | DB 操作 |
| `app/Criteria/AutoReplyResume/PendingResumeCriteria.php` | 待恢復查詢條件 |
| `app/Services/AutoReplyResumeService.php` | 建紀錄、掃描恢復、組通知 |
| `app/Console/Commands/AutoReplyResumeCommand.php` | `auto-reply:resume`（每分鐘排程） |
| `app/Console/Commands/AutoReplyResumeAuditCommand.php` | `auto-reply:resume-audit` —— 上線用的一次性盤點（見最後一節） |

`auto_reply_resume` 表欄位：

```
id
telegram_group_id   FK → telegram_group, cascadeOnDelete
user_id             FK → user, nullable + nullOnDelete（帳號停用後仍要看得出是誰關的 → 另存 operator_name 快照）
operator_name       關閉者名稱快照
off_at              關閉時間（靜置基準之一）
quiet_minutes       這一筆要靜置幾分鐘（關閉者在 modal 當場填，5～1440）；永久關閉時為 null
mode                1=暫時, 2=永久
status              1=待恢復, 2=已自動恢復, 3=已手動恢復, 4=永久關閉, 5=作廢
resumed_at          nullable
index (status, telegram_group_id)
```

> ⚠ `user_id` 是 `nullable` + `nullOnDelete` —— nullOnDelete 的外鍵欄位必須
> nullable，否則帳號被刪時寫 null 會炸。

### 修改

| 檔案 | 改什麼 |
|---|---|
| `app/Services/AutoReplyService.php` | `toggle()` 加 `$mode`；關閉時建紀錄、開啟時把待恢復紀錄結案為「已手動恢復」。⚠ 兩表寫入要包 `DB::transaction` |
| `app/Http/Requests/TelegramChat/ToggleAutoReplyRequest.php` + `config/rules.php` | 加 `mode`、`quiet_minutes`（5～1440、僅 `mode=暫時` 時必填）驗證 |
| `app/Http/Controllers/Admin/TelegramChatController.php` | `$params` 帶 `mode`、`quiet_minutes` |
| `app/Services/TelegramChatService.php` | 群組清單多回預計恢復時間 |
| `app/Console/Kernel.php` | 掛 `auto-reply:resume` 每分鐘 + `withoutOverlapping` |
| `app/Services/AppSettingService.php` | `KEY_AUTO_REPLY_RESUME_ENABLED`、`KEY_AUTO_REPLY_RESUME_DEFAULT_MINUTES`（modal 預填值） |
| `app/Services/NotificationSettingService.php` + 通知設定頁 Request/Blade/JS | 兩個新設定欄位 |
| `config/constants.php` | `AUTO_REPLY_RESUME`（狀態、預設分鐘）；`SUPPORT_TOPIC.TYPES` 加 `auto_reply_resume` |
| `resources/lang/{tw,cn,en}/telegram_chat.php` | modal 文案、狀態字 |
| `resources/lang/{tw,cn,en}/notification.php` | 設定欄位、`notice_type.auto_reply_resume` |
| `public/js/telegram-chat/input.js` | 取消勾選改成跳 modal 問意圖 |
| `public/js/telegram-chat/layout.js` | 清單／標頭顯示恢復倒數 |

> 不新增權限 keyword：開關本來就是 `telegram_chat.reply`，這個功能沒有新介面。
> （→ 待釐清 2）
>
> 改了 `config/` → 收尾要跑 `php artisan optimize`。

## 需求方 2026-11-07 確認

| 題目 | 決定 |
|---|---|
| 排除「一直不開」的群組 | **記關閉事件、不掃關閉狀態**（自動推導，不需人工名單） |
| 關閉時問意圖 | **要**，暫時／永久二選一，預設暫時 |
| 靜置分鐘數 | **關閉時由人員在 modal 自己設定**（不是全域一個值） |
| 功能開關 | **預設關**，上線後到設定頁打開 |
| 恢復通知 | 只發**內部支援群組**，不私訊本人 |
| 發到哪個話題 | 設定頁勾，建議跟 `ticket_handover` 同一個排程通知話題 |
| 通知裡的關閉者 | **要 tag**（`@username`，tag 不到時退回顯示 nickname） |
| 上線盤點 | **方案 A**：不自動改狀態，跑一次性盤點指令列清單 |

## 仍未確認（已採建議值實作，發現不對再調）

1. **「永久關閉」沒有獨立權限**，沿用 `telegram_chat.reply`。它會讓一個群組
   從此不自動回覆，影響比「回一則訊息」大（這正是 `telegram_chat.ignore_manage`
   當初被拆出來的理由），但關掉自動回覆本來就是 `reply` 在做的事。先不拆、
   改成記 Log，要拆再補 keyword。
2. **群組被封存時**待恢復紀錄作廢且**不通知**（恢復一個封存的對話沒有意義，
   為它發一則通知只是雜訊）。
3. **不做「誰最常忘記勾回來」的報表**。表已經存得下來（`user_id` + `off_at` +
   `status`），要做隨時能補，但現在沒有人要看。

## 上線時的既有資料（2026-11-07 需求方提問）

> 「這樣我更新上去正式的話，我是不是每個都要重新去勾選」

**不用。** 上線不改任何 `auto_reply` 欄位，開著的照樣開著；機制也不需要逐群組
設定（沒有白名單要勾），從上線那一刻起誰關掉就記一筆。

**但有一批例外**：上線前**就已經處於「忘記勾回來」狀態**的群組。那一刻系統手上
沒有關閉紀錄，分不出它是「忘了關的」還是「本來就不開的」—— 這些不會被自動
救回來。這是「記事件而不記狀態」這個設計唯一的代價，而且只在上線這一次發生。

### 這批可以自動認出來

`auto_reply_at`（最後一次自動回覆時間）只會經由
`TelegramRepository::updateAutoReplyState()` 寫入，而該方法只在
`isAutoReplyOn()` 閘門之後才到得了（`AutoReplyService.php:102`、
`TelegramChatService.php:595`）。所以它是可靠的「曾經真的開著回過客人」訊號：

| 現在的狀態 | 判讀 |
|---|---|
| `auto_reply = 0` 且 `auto_reply_at` 有值 | 曾開著回過客人、現在關著 → **很可能是忘記勾回來的** |
| `auto_reply = 0` 且 `auto_reply_at` 為 `NULL` | 從來沒自動回覆過 → **就是刻意一直不開的那幾個** |

⚠ 需求方說的是「一直都不打開」，那些群組的 `auto_reply_at` 必定是 `NULL`，
**不會落進盤點清單** —— 這道判斷對它們是安全的。

### 決定：方案 A —— 一次性盤點指令（需求方 2026-11-07）

**上線時不自動改任何群組狀態**，只跑一支指令把清單列出來，由需求方自己決定
要勾開哪些。

```bash
php artisan auto-reply:resume-audit            # 印出表格
php artisan auto-reply:resume-audit --notify   # 同時發一則到內部支援群組
```

列出 `auto_reply = 0` 且 `auto_reply_at IS NOT NULL` 的群組，每列給
群組名、最後一次自動回覆時間、最後一則訊息時間 —— 後兩個欄位是判斷
「這是忘了勾，還是刻意關的」的依據。

⚠ **這支指令一行都不寫入**（連待恢復紀錄都不建）。整個功能唯一會把
`auto_reply` 由 0 改成 1 的地方只有排程，而排程只看待恢復紀錄 ——
盤點指令若會建紀錄，就等於偷偷變成方案 B。

⚠ **不掛排程**，是手動跑的一次性工具。留著不刪：之後若有人懷疑
「這個群組是不是又被忘記了」，同一支指令就能回答。

⚠ 被否決的方案 B 是「migration 直接把那批建成待恢復紀錄、靜置後自己開回來」。
風險**不在**需求方提的那幾個群組（`auto_reply_at` 為 NULL，根本不符合條件），
而在「曾經開過、後來刻意永久關掉」的客戶 —— 那種會被開回來，而發給客人的
訊息收不回來。這種客戶存不存在只有需求方知道，不該由實作端決定。

---

## 實際實作（2026-11-07）

### 新增

| 檔案 | 內容 |
|---|---|
| `database/migrations/2026_11_07_000001_create_auto_reply_resume_table.php` | `auto_reply_resume` 表 |
| `app/Models/AutoReplyResume.php` | 含 `quietSince()`／`resumeAt()` |
| `app/Repositories/AutoReplyResumeRepository.php` | 待恢復／作廢／逐群組查詢 |
| `app/Services/AutoReplyResumeService.php` | 記帳、掃描恢復、組通知、倒數 |
| `app/Console/Commands/AutoReplyResumeCommand.php` | `auto-reply:resume`（每分鐘） |
| `app/Console/Commands/AutoReplyResumeAuditCommand.php` | `auto-reply:resume-audit`（手動，只讀） |
| `app/Http/Requests/Notification/UpdateAutoReplyResumeRequest.php` | 設定頁驗證 |

**沒有建 Criteria**：查詢只有三支、條件固定，`Criteria` 是給「同一張表多種可組合條件」用的
（`app/Criteria/Followup/` 那種分頁篩選）。這裡硬包一層只會多一個檔案。

### 修改

`AutoReplyService::toggle()`（加 `$params` / `$operator`、包 transaction）、
`TelegramChatService::getConversationList()`（多回 `auto_reply_resume_at`）、
`TelegramRepository::getAutoReplyAuditCandidates()`、`Console\Kernel`、
`AppSettingService`（兩個 KEY）、`NotificationSettingService`（section + update）、
`NotificationController`、`TelegramChatController`、`routes/web.php`、
`config/constants.php`、`config/rules.php`、`config/changelog.php`、
`ToggleAutoReplyRequest`、三語 `telegram_chat.php` / `notification.php`、
`notification/index.blade.php`、`telegram-chat/index.blade.php`、
`public/js/notification-setting.js`、`public/js/telegram-chat/{state,input,layout}.js`

### 實作時改掉的兩個設計決定

**1. `mode` / `quiet_minutes` 不是 `required_if`，而是 `nullable`。**

原本想用 `required_if:enabled,false`。但自動恢復功能關著時 modal 不會出現，
那一次送出本來就沒有這兩個值 —— 必填會變成「功能關著時連開關都按不動」。
改成 nullable，沒帶就由 `recordOff()` 補預設；**帶了就必須合法**（範圍仍然驗）。

**2. 功能關著時 `recordOff()` 什麼都不記（回 null）。**

原本想一律記、留著當稽核。但那樣會累積一批 PENDING，然後在某天功能被打開的
**那一分鐘同時全部恢復** —— 正是需求方否決掉的「一次全部開回來」。
要知道有哪些關著的，用 `auto-reply:resume-audit`（它看 `auto_reply_at`，不看這張表）。

⚠ 但 `closePendingByManual()` **刻意不看開關**：功能可以中途被關掉，而那時既有的
PENDING 還留著 —— 短路掉的話，客服自己勾回來之後那筆仍是 PENDING，
功能再被打開就會對一個已經開著的對話「恢復」並發一則莫名其妙的通知。

### 沒做的

- **「誰最常忘記勾回來」報表**：表已經存得下（`user_id` + `off_at` + `status`），但沒有人要看
- **永久關閉的獨立權限**：沿用 `telegram_chat.reply`，改記 Log（`'自動回覆被設為永久關閉'`）
- **清單上標示「永久關閉」**：群組清單只回 `auto_reply_resume_at`，永久的是 null 所以不顯示。
  未勾的勾選框本身就說明它是人工，再加一行只是重複（`resume_permanent` 語系 key 因此刪掉）

### 驗證過的

| 檢查 | 結果 |
|---|---|
| PHP / JS / Blade 語法 | 全部通過 |
| DI 容器解析（8 個類別） | 無循環依賴 ✅ —— `AutoReplyService` → `AutoReplyResumeService`，而後者不依賴前者 |
| `quietSince()` 取 `last_message_at`（客人剛講完） | ✅ |
| `quietSince()` 取 `off_at`（客人早就安靜、客服現在才關） | ✅ 不會一關就立刻恢復 |
| 通知文案（有 tag） | `@Mary｜轉人工 1 小時 35 分（設定靜置 30 分鐘）` ✅ |
| 通知文案（沒填 telegram_username） | 退回 `（阿明，未填 Telegram 帳號所以沒 tag 到）` ✅ 不是整則不發 |
| 排程查詢的 datetime binding | 執行時格式化成 `Y-m-d H:i:s`（Asia/Taipei），與其他 repository 一致 ✅ |
| 三語 key 數量 | telegram_chat 122/122/122、notification 253/253/253 ✅ |
| `php artisan optimize`（容器內） | ✅ config + route 已重新快取 |
| 路由與指令註冊 | ✅ `ajax-update-auto-reply-resume`、`auto-reply:resume`、`auto-reply:resume-audit` |

⚠ **稽核指令踩過的坑（第二次了）**：`grep ... $FILES` 在 zsh 不會對未加引號的變數斷詞，
整串被當成單一檔名，`ugrep` 回 `No such file or directory` 後輸出空結果 ——
**六項檢查全部假通過**。改用 `set --` ＋ `"$@"`，並先故意 grep 一個一定存在的字
確認指令真的會報出東西。同一個坑記在 [[../bugfix/2026-11-05-validation-message-swallowed]]。

## 上線要做的兩件事

1. **跑 migration**（建 `auto_reply_resume` 表）：
   ```bash
   cd ../laradock && docker-compose exec workspace bash -c \
     "cd /var/www/tripartite_gold_cs && php artisan migrate"
   ```
2. **到「通訊管理 → 通知設定」**：
   - 「求助單提醒」分頁 → 第二張卡「自動回覆自動恢復」→ **把開關打開**（預設是關的）
   - 「支援群組與話題」分頁 → 把「自動回覆已恢復」勾在放待接手清單的那個話題
3. （選用）跑 `auto-reply:resume-audit` 看上線前就已經關著的有哪些

## 相關

- [[auto-reply]] — 自動回覆本體，開關的原始設計
- [[auto-reply-natural]] — 現行的 intent 判斷與承接句
- [[support-group-topics]] — 通知發到哪個話題
- [[customer-followup]] — 同樣的「靜置夠久才動作」模式（`quiet_minutes`）
