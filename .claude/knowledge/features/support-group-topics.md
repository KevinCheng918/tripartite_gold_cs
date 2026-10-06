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

`constants.SUPPORT_TOPIC.TYPES` 定義六類，每類由對應 service 的 `NOTICE_TYPE` 宣告：

| key | 內容 | 哪支 service | 只能一個話題 |
|---|---|---|---|
| `auto_reply_ticket` | AI 轉問題、超時提醒、處理結果 | `AutoReplySupportService` | ✅ |
| `daily_rate` | 9:00 報價、每 30 分提醒、決定結果 | `DailyRateService` | ✅ |
| `ticket_handover` | 7:30 待接手清單 | `TicketHandoverService` | |
| `shift_notice` | 班表沒收到的人彙總 | `ShiftNoticeService` | |
| `vm_payment` | 9:30 虛擬機繳款通知 | `VmPaymentNoticeService` | |
| `station_credit` | 10:00 餘點告警與補點訊息 | `StationCreditAlertService` | |

⚠ **匯率歸「排程通知」類**（需求方確認）—— 它是排程發的，雖然也要客服引用回覆。

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

## ⚠ 上線要做的事

1. `php artisan optimize`（動過 `config/` 與 `routes/`）
2. 權限表勾新的 **`notification.view` / `notification.manage`** ——
   `shift_notice.*` 已不存在，原本勾那兩個的人會看不到選單。
   （`setting.*` 只是改了顯示名稱為「AI 引擎」，keyword 沒變，不用重勾）
3. 在每個話題裡輸入 `/topicid` 取得 id
4. 到「通訊管理 → 通知設定 → 話題分流」新增話題、勾選要收的通知
5. **按「測試發送」** —— 這步不能省：話題 id 填錯 Telegram 會整則拒收，
   那一類通知會從此默默消失，只有測試發送看得出來

## 相關

- [[auto-reply]] — 求助單的完整流程
- [[remind-escalation]] — 超時提醒與待接手清單
- [[daily-rate]] — 匯率報價（歸排程通知類）
- [[station-credit-alert]] — 餘點告警退回內部群組的情況
- [[shift-daily-notice]] — 班表通知（設定頁已併入通知設定）
