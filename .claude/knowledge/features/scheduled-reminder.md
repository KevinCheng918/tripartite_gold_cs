# 預約提醒（內部群組 tag 機器人）

> **狀態：已實作（2026-10-13）。正式機要先跑 migration。**

## 需求（2026-10-13）

> 我想要在內部群組做一個預約提醒話題，我可以 tag 機器人，做出預約提醒，
> 時間到了，他會自動發送提醒訊息到相同話題，預約的內容是訊息，要能標注人，
> 還有選擇日期時間。

## 已確認的決策

| 題目 | 決定 |
|---|---|
| 怎麼輸入 | **自然語言，AI 解析**（「明天下午三點提醒 @abc123aa 對帳」） |
| 怎麼標注人 | **內容裡直接打 @帳號**，原文送出讓 Telegram 自己變成 mention |
| 查詢與取消 | **要**：列清單 ＋ 每筆附取消按鈕 |
| 重複提醒 | **只要單次** |

## 怎麼運作

```
在支援群組 tag 機器人
  └─ AI 解析（ReminderParser）
      ├─ create  → 建立 ＋ 複述時間與內容 ＋ 取消按鈕
      ├─ list    → 列出「這個話題」還沒發的（最多 10 筆）
      └─ unknown → 回一句範例，請他重講

每分鐘 reminder:send
  └─ 撈 remind_at <= now 且還沒發的 → 原文發回原本那個話題
```

## 關鍵決定

### 1. 判斷「有沒有 tag 我」要知道 Bot 自己的 username

`TelegramBotService::getMyUsername()`（`getMe` API，快取一天）。

⚠ **查不到時一律當成「沒 tag 到」。** 寧可整個功能暫時不動，也不要把群組裡
每一則訊息都丟給模型解析 —— 那會燒額度，而且會對閒聊亂回話。

### 2. 話題分流的「預約提醒」語意跟其他類型相反（2026-10-13）

「通知設定 → 話題分流」多了一項 **預約提醒**（`scheduled_reminder`），
但它的意思跟同一頁其他項目**不一樣**：

| | 其他類型 | 預約提醒 |
|---|---|---|
| 勾選的意思 | 這類通知**發到**哪些話題 | 哪些話題**可以排**提醒 |
| 發到哪 | 勾選的話題 | **一律發回排它的那個話題** |

⚠ 提醒不跟著分流走，是因為「在客訴話題排的提醒跑到別的話題跳出來」的話，
排它的人會找不到。

⚠ **一個都沒勾 ＝ 不限制**，不是「全部停用」。設定還沒填的那段時間不該整個
停擺 —— 這是話題分流一貫的規則（沒勾的通知發到主區，而不是不發）。
上線時忘了勾不會讓功能靜悄悄失效。

⚠ 勾了之後**群組主區就不能排了**（主區沒有 thread id，比不上任何一個勾選的
話題）。既然限定了話題，主區就不在範圍內。

⚠ 在沒開放的話題 tag 機器人**要回一句說明**，不能靜默 —— 而且那句話要講明
去哪裡設定。擋在**解析之前**，不准排的話題不該燒模型額度。

為此 `SupportGroupService` 多一支 public 的 `topicIdsFor($type)`：
跟既有的 `threadIdsFor()` 差別在**一個都沒勾時回空陣列而不是 `[null]`** ——
呼叫端要分得出「沒有人勾」（不限制）與「勾了主區」（發到主區）。

### 3. 一定要回在「使用者說話的那個話題」

走 `SupportGroupService::sendToThread()` 而**不是** `send()`：
後者依通知類型查「這類該發到哪幾個話題」，會把回覆帶到別的地方去。
`/topicid` 也是為了同一個理由走這支。

> `sendToThread()` 2026-10-13 多了第三個參數 `$keyboard`（取消按鈕要用）。

### 4. 內容原文送出，系統不解析 @帳號

Telegram 自己會把 `@xxx` 變成 mention。要 tag 誰完全由預約的人決定 ——
系統去解析那些帳號只會多一層出錯的機會（打錯字、沒綁定、同名）。

### 5. ⚠ AI 解析一定要複述

模型會解析錯時間，而**錯掉的提醒要到那個時間點才會被發現**。
建立成功的回覆一定帶時間與內容，並附一顆取消按鈕 —— 錯了當場就能改。

### 6. ⚠ 模型把整包 JSON 塞進 `content` 欄位（實測踩到）

第一次實測時，`structured_output` 長這樣：

```jsonc
{
  "intent": "create",
  "remind_at": "2026-10-14 15:00",
  "content": "{\"intent\":\"create\",\"remind_at\":\"2026-10-14 15:00\",\"content\":\"@abc123aa 跟客戶確認對帳單\"}"
}
```

`intent` 與 `remind_at` 都對，只有 `content` 被塞了整包 JSON ——
跟 [[2026-10-12-double-wrapped-json-prompt]] 同一個形狀。

兩個差異讓它消失：

1. schema 的 `content.description` 明寫「純文字、不要放 JSON」
2. prompt 拿掉 `**粗體**` 與 `⚠` 那些排版符號，改成平鋪直敘

**兩個防護都留著，而且程式端再擋一次**（`looksLikeJson()`：content 能被
`json_decode` 成陣列就當作解析失敗）。prompt 與 schema 都是請求，不是保證 ——
放過去的話，時間到了會有一串大括號發到群組裡。

### 7. 送失敗不標記，下一分鐘再試

`fire()` 是**送成功才標 SENT**。先標記再送的話，送失敗那筆就永遠不會再試了。
重複發一則提醒，比永遠不發好。

### 8. 取消與發送可能撞在一起

`cancel()` 與 `markSent()` 的狀態條件都寫在 `update` 的 where 裡，用回傳筆數
判斷誰先到。取消回 0 筆代表「剛剛已經發出去了」—— 要照實說**來不及取消**，
回「已取消」是在騙人，訊息已經出去了。

## 檔案

```
database/migrations/2026_10_13_000002_create_scheduled_reminder_table.php
app/Models/ScheduledReminder.php
app/Repositories/ScheduledReminderRepository.php
app/Services/ScheduledReminderService.php          建立／清單／取消／發送
app/Services/AutoReply/ReminderParser.php          自然語言 → 時間＋內容
app/Services/AutoReply/ClaudeCodeMatcher.php       generateStructured() 改 public
app/Services/SupportGroupService.php               sendToThread() 多收 $keyboard；
                                                   新增 public topicIdsFor()（沒勾時回空陣列）
app/Services/TelegramBotService.php                getMyUsername()（getMe ＋ 快取一天）
app/Console/Commands/SendScheduledReminderCommand.php
app/Console/Kernel.php                             reminder:send 每分鐘
app/Http/Controllers/Webhook/TelegramWebhookController.php  訊息攔截 ＋ rm: callback
config/constants.php                               REMINDER；SUPPORT_TOPIC.TYPES 加 scheduled_reminder
resources/lang/{tw,cn,en}/notification.php         話題分流那一項的標籤與說明
```

## 上線前置

1. **跑 migration**（`scheduled_reminder` 表）
2. `php artisan optimize`
3. 確認排程有在跑（`reminder:send` 每分鐘）
4. **到「通訊管理 → 通知設定 → 話題分流」勾選要開放的話題**
   （不勾就是所有話題都能排 —— 功能照常運作，只是沒有限制）
5. 在開放的話題 tag 機器人試一次，例如「@機器人 五分鐘後提醒我測試」

## 相關

- [[support-group-topics]] — 話題分流（這個功能刻意不走分流）
- [[auto-reply]] — `ClaudeCodeMatcher` 與 schema 的用法
- [[customer-followup]] — 同樣是「群組按鈕 ＋ 排程」的形狀
