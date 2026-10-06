# 內部支援群組分話題發送

> **狀態：已實作（2026-10-06）。** 上線前置作業見最後一節。

## 已確認的決策（2026-10-06）

| 題目 | 決定 |
|---|---|
| 設定方式 | **話題清單**：自己填話題，再**逐話題勾要收哪些通知** |
| 匯率那組 | 歸「排程通知」類 |
| 話題 id 怎麼取 | 做 `/topicid` 指令，在話題裡輸入就回答 |
| 彈性 | 每類通知各自指定，不寫死兩區 |

### ⚠ 一個通知可以勾在多個話題嗎

分兩種：

| 通知 | 可以勾幾個話題 | 為什麼 |
|---|---|---|
| 單向通知（待接手、班表、VM、餘點） | 幾個都行 | 發出去就結束了 |
| **需要客服引用回覆的**（AI 問題、匯率） | **只能一個** | 系統要記住「發出去那則的 message_id」才對得回來（`ask_message_id`、匯率的決定回覆）。發到兩個話題會有兩個 id，只能記一個，另一個話題的訊息就變成回覆了也沒反應 |

所以後者在設定頁會被驗證擋住，並說明原因。

### 沒勾的通知去哪裡

發到群組主區（General），也就是**現在的行為**。設定還沒填的那段時間不該整個停擺。

## 需求（2026-10-06）

1. 內部支援群組已經開啟 Telegram 的「話題」（Topics / Forum）功能，
   要能把系統發出去的訊息分到不同話題
2. 設定方式是**話題清單 + 逐話題勾要收哪些通知**
3. **「內部支援群組」從全域設定頁拿掉**，搬到「通訊管理 → 通知設定」，
   班表通知也併進去，用**多分頁**處理

## 技術上怎麼做

Telegram Bot API 的 `sendMessage` / `sendPhoto` 有 `message_thread_id` 參數，
值就是**話題 id**。帶了它訊息就進那個話題，不帶就進群組的「General」主區。

```
sendMessage(chat_id=-100…, message_thread_id=42, text=…)
```

⚠ **話題 id 填錯或話題被刪掉，Telegram 會整則拒收**（`message thread not found`）。
現在的程式對送出失敗只記 log，所以**填錯會變成「訊息全部默默消失」**——
這是這次改動最大的風險，下面的「測試發送」就是為了擋這件事。

⚠ **沒設定話題 id 時要退回現在的行為**（發到主區），不能變成不發。
設定還沒填的那段時間不該整個停擺。

### 話題 id 怎麼取得

| 做法 | 說明 |
|---|---|
| **A. 在話題裡輸入 `/topicid`，bot 直接回答**（建議） | 最不會出錯。webhook 收到的 update 本來就帶 `message_thread_id`，照著回一句就好 |
| B. 複製訊息連結自己看 | `https://t.me/c/1234567890/42/105` → 中間那個 `42` 就是話題 id。要人去數第幾段，容易看錯 |

⚠ 不能用「話題名稱」當設定值：Bot API 沒有「依名稱查話題」的方法。

⚠ **指令在群組裡常常帶著機器人名稱**（`/topicid@my_bot`）—— 從指令選單點選、
或群組裡不只一個 bot 時 Telegram 就會加上去。比對要切掉 `@` 後面那段，
不然使用者只會看到「輸入了沒反應」。

### 客服在話題裡回答求助單還能對上嗎

可以。求助單的對應鍵是 `reply_to_message.message_id`（引用回覆），
跟話題無關。

> ⚠ 但有一個 forum 群組特有的行為要知道：**話題裡的每則訊息都會帶
> `reply_to_message`**（指向話題的建立訊息），不只是真的引用別人的那些。
> `handleSupportMessage()` 現在就是看 `reply_to_message.message_id` 有沒有值 ——
> 改了之後，話題裡的閒聊也會通過那道判斷。
>
> 不會誤判成作答：接著 `findByAskMessageId()` 查不到就 return 了。
> 但這表示**那道判斷實際上失去了過濾作用**，日後在它後面加東西要注意。

## 通知類型

`constants.SUPPORT_TOPIC.TYPES` 只定義 key 與 `needs_reply`，
**顯示名稱與說明在語系檔** `notification.notice_type.*`
（那是設定頁的介面文字，要跟著操作者的語系跑；對客話術才留在 config）。

| key | 群組裡會收到什麼 | 哪支 service | 只能一個話題 |
|---|---|---|---|
| `auto_reply_ticket` | AI 轉來的問題（求助單、超時提醒、處理結果） | `AutoReplySupportService` | ✅ |
| `daily_rate` | 每日匯率報價（9:00 報價、提醒、結果） | `DailyRateService` | ✅ |
| `ticket_handover` | 早班待接手清單（7:30） | `TicketHandoverService` | |
| `shift_notice` | **班表沒收到的人** | `ShiftNoticeService` | |
| `vm_payment` | 虛擬機繳款（9:30） | `VmPaymentNoticeService` | |
| `station_credit` | 站台餘點告警（10:00） | `StationCreditAlertService` | |

⚠ **名稱要寫「群組裡會收到什麼」，不是「這是哪個功能」。**
`shift_notice` 原本叫「班表通知異常」—— 但班表通知本身是**私訊、不會進群組**，
進群組的只有「誰沒收到」那則彙總。用功能名當標籤會讓人以為是另一個功能。

⚠ **匯率歸排程通知而不是「要回覆的」那一類**（需求方確認）：它是排程發的，
雖然也要客服引用回覆。

## 實作

### 發訊路徑

```
各 service 的 supportGroup->send($text, $keyboard, $replyTo, self::NOTICE_TYPE)
  └─ SupportGroupService::threadIdsFor($type)
       讀設定頁的話題清單 → 哪幾個話題勾了這一類
       └─ 一個都沒勾 → 回 [null]（發到主區）
  └─ 逐 thread 呼叫 TelegramBotService::sendMessage(…, $threadId)
       └─ message_thread_id 帶進 API
```

⚠ `threadIdsFor()` **沒勾到時回 `[null]` 而不是空陣列** —— 回空陣列會讓
呼叫端的 foreach 跑 0 次，也就是一則都不發。那是最糟的失敗方式：安靜消失。

⚠ 多話題時 `send()` 回傳**第一則**的結果。所以 `needs_reply` 那兩類只能勾一個
話題（`UpdateTopicRequest::checkSingleTopicTypes()` 擋住），不然 `ask_message_id`
只記得到一個，另一個話題的回覆會變成「回了也沒反應」而且完全不報錯。

### `/topicid`

`AutoReplySupportService::answerTopicId()`，在 `handleSupportMessage()` 最前面攔
（它是直接輸入的，沒有引用，所以要排在「必須引用回覆」的判斷之前）。

⚠ 回覆**直接走 `SupportGroupService::sendToThread()`** 而不是 `sendToSupport()` ——
後者會套 `NOTICE_TYPE`，把回覆丟到「AI 問題」那個話題去，而這句一定要回在
使用者輸入的那個話題裡。

在主區輸入會回「這裡沒有話題 id」，不能靜默 —— 不然使用者會以為指令壞了，
然後去填一個錯的 id。

### 頁面重整

```
通訊管理
├─ Telegram 客服
└─ 通知設定        ← 新（四個分頁）
     ├─ 支援群組與話題   chat_id、用哪個 Bot、話題分流
     ├─ 求助單提醒       第一次提醒／之後每隔／最多幾次
     ├─ 班表通知         完整班表收件人
     └─ 超時提醒統計     完整統計收件人
```

⚠ **每個分頁各自一支 ajax**（`ajax-update-group` / `-remind` / `-topics` /
`-shift` / `-report`）。合成一支的話，存一個分頁會把其他分頁的值一起寫掉 ——
那些欄位在這次送出裡是空的。Request 也跟著拆成五個。

- 權限 keyword 從 `shift_notice.*` 改成 `notification.*`
- **「全域設定」改名「AI 引擎」**（選單、頁面標題、權限群組名、icon 齒輪→大腦），
  只剩 Claude 憑證、備援 API、用量 —— `SettingService` 的建構子也因此從 7 個
  依賴瘦到 3 個。路由前綴仍是 `setting`
- 刪除：`ShiftNoticeController`、`shift-notice` 的 view/js、`shift_notice.php` 三份語系
- 所有「請到全域設定…」的指路文字（指令輸出、`/topicid` 的回覆、語系）都改指向新位置

## ⚠⚠ 最大的坑：開啟話題會換掉 chat_id

**幫群組開啟「話題」功能時，Telegram 會把它從一般群組升級成 supergroup，
`chat_id` 跟著換一個。** 2026-10-06 實際踩到。

症狀有兩層，第二層比第一層嚴重：

1. 所有發到支援群組的訊息失敗，錯誤是
   `Bad Request: group chat was upgraded to a supergroup chat`
2. **`isSupportChat()` 用舊 id 比對新群組 → 判定「這不是支援群組」**
   —— webhook 不再攔截，內部群組的討論會被當成**客戶訊息**建進對話列表

新 id 在失敗回應的 `parameters.migrate_to_chat_id`。規律是
`-100` 接上舊 id 的絕對值（`-5348388981` → `-1005348388981`）。

### 怎麼拿到新 id

| 方法 | 說明 |
|---|---|
| **按「發送測試訊息」**（建議） | 遇到這個錯誤時會直接把新 id 寫在錯誤訊息裡讓人複製 |
| 查 log | `⚠ Telegram 群組已升級為 supergroup，chat_id 變了` 這一行有新舊 id |
| 群組連結 | `t.me/c/XXXXXXXXXX/…` → chat_id = `-100` + `XXXXXXXXXX` |

### ⚠ 換新 id 時會被 unique 規則擋住

`UpdateGroupRequest` 有 `unique:telegram_group,chat_id`（支援群組不能拿客戶對話
的群組來用）。但舊 id 失效的那段時間，系統認不得支援群組，**把它當成新客戶
建了一筆對話** —— 於是新 id 被自己建的那筆擋住，存不進去。

解法：到「Telegram 客服」把那筆對話刪掉，再回設定頁存。
錯誤訊息已經寫明這個情境。

> ⚠ 2026-10-06 當下使用者只看到 `The given data was invalid.` ——
> `notification-setting.js` 的 `errorMessage()` 把 `body.message` 排在
> `body.errors` 前面。Laravel 驗證失敗時兩個都會給，`message` 只是通用外殼。
> **`errors` 一定要先看。**（`setting-admin.js` 原本就是對的，是我抄過去時寫反。）

### 為什麼不自動改設定

Telegram 給的 `migrate_to_chat_id` 是權威值，自動寫回技術上可行。
**刻意不做**：那是悄悄改掉使用者填的值。改成把新 id 交給設定頁顯示出來 ——
使用者按測試按鈕時正好就站在那個欄位前面，複製貼上一次就好，而且他知道發生了什麼事。

## ⚠ 上線要做的事

1. `php artisan optimize`（動過 `config/` 與 `routes/`）
2. 權限表勾新的 **`notification.view` / `notification.manage`** ——
   `shift_notice.*` 已不存在，原本勾那兩個的人會看不到選單。
   （`setting.*` 只是改了顯示名稱為「AI 引擎」，keyword 沒變，不用重勾）
3. 在每個話題裡輸入 `/topicid` 取得 id
4. 到「通訊管理 → 通知設定 → 話題分流」新增話題、勾選要收的通知
5. **按「測試發送」** —— 這步不能省：話題 id 填錯 Telegram 會整則拒收，
   那一類通知會從此默默消失，只有測試發送看得出來

## ⚠ 共用的排版工具

`app/Services/Notify/NoticeText.php` —— `date()` / `shorten()` / `waited()`。

三支通知 service（班表、待接手、統計）原本各抄一份一模一樣的 `formatDate()`。
抽出來不只是為了不重複：**同一批訊息（7:00、7:30、8:30 連著發）的日期寫法
不一致，看起來就像不同系統發的。**

## ⚠ 兩個每分鐘跑的 N+1（2026-10-06 修）

| 哪裡 | 原本 | 現在 |
|---|---|---|
| `remindTimeoutTickets()` | **每張超時單**都重查一次當班人員與主管名單 | `remindTargets()` 整輪查一次，回 `on_duty` / `escalated` 兩組，`pickTargets()` 挑其中一組 |
| `RemindReportService::sendPersonal()` | **每位收件人**都 `getForDmByIds([$id])` 一次 | 整批撈一次再逐人送（內容逐人不同，但「能不能被私訊」的查詢不必逐人做） |

前者特別要小心：那支**每分鐘都在跑**，而且「同時卡住十張單」是常態。

## 相關

- [[auto-reply]] — 求助單的完整流程
- [[remind-escalation]] — 超時提醒與待接手清單
- [[daily-rate]] — 匯率報價（歸排程通知類）
- [[station-credit-alert]] — 餘點告警退回內部群組的情況
- [[shift-daily-notice]] — 班表通知（設定頁已併入通知設定）
