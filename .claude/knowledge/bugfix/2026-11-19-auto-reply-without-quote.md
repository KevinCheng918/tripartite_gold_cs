# 客人連問兩件事，我們回了兩則一樣的話，他分不出哪則回哪個

> 需求方 2026-11-19（附截圖）：「當客人連問兩個問題時，無論是稍等，還是 AI
> 可以直接找到題庫回覆的，都要用引用的，避免說連續訊息，客人會不知道在回應
> 哪一個。」

截圖裡的實況（10:42 同一分鐘）：

```
[客人] （圖）請幫協助刪除 3 和 6
[客人] （圖）請幫協助刪除 4
[我們] 老闆，刪除的部分已經轉由專員為您處理，再麻煩您稍等一下。 -A
[我們] 老闆，您要刪除的部分已經轉給專員為您處理，再麻煩您稍等一下。 -A
```

兩則回覆**長得幾乎一模一樣**（模型每次現寫，用字略有出入），
而且都沒有掛在任何一則上。客人只能猜：是兩件都收到了，還是同一件回了兩次？

## 根因：對客訊息一直都沒有引用

`sendReply()` 本來就支援 `reply_to_id`（會把後台訊息 id 換成 Telegram 的
`message_id`），但**只有追問客人那一支在用**
（`CustomerFollowupService::askCustomer()`）。自動回覆的五條路
——稍等、題庫答案、追問資料、寒暄、開單預排查——一條都沒傳。

`$messageId`（客人那則的後台 id）其實**早就從 `AutoReplyJob` 一路傳到
`handle()` 了**（開求助單要用），只是沒往送出那一層帶。

## 改了哪幾條路

| 路徑 | 什麼時候 |
|---|---|
| `AutoReplyService::replyWait()` | 轉人工時那句「稍等」 |
| `AutoReplyService::replyWithItem()` | 命中題庫，直接回答案 |
| `AutoReplyService::replyAskInfo()` | 資訊不足，先跟客人要資料 |
| `AutoReplyService::replyOpeningOnly()` | 判定是寒暄，只回一句 |
| `AutoReplyService::replyApplyDiagnose()` | 開單預排查直接回客人 |
| `AutoReplySupportService::replyToCustomer()` | 同仁寫的答案送回客人 |
| `AutoReplySupportService::replyWithItem()` | 同仁挑題庫原文回客人 |

後面兩條**尤其需要**：同仁的答案可能是幾十分鐘後才送出去的，
中間客人早就又說了別的話 —— 不掛回去，他得往回捲很久才知道這在回什麼。

## ⚠ 分段送出時只有第一則掛引用

答案長的時候會被 `AnswerSplitter` 切成好幾則。每則都引用同一句的話，
那段引用會在畫面上重複三四次，反而難讀 —— 後面幾則緊接在第一則之後，
本來就看得出是同一串。

## ⚠ 帶圖的那條路（匯率補點）不引用，而且刻意不傳

`sendReply()` 帶圖時走 `sendPhoto()`，而那一支（連同它內部會分流到的
`postMultipart()` / `sendDocument()`）**沒有 `reply_to_message_id` 參數**。

所以 `sendWithImage()` 連收都不收這個參數：傳了會被靜默丟掉，
而**「看程式碼以為有引用、實際上沒有」比不傳更糟**。
帶圖的自動回覆只有匯率補點一種，內容獨特（有金額、有繳款圖），
不會跟別則混淆。真要做得連 Bot API 那三支一起加參數。

## ⚠ 引用不到的時候會自己退回一般訊息

`reply_to_id` 吃的是**後台訊息 id**，`sendReply()` 會查 `findQuoted()`
換成 Telegram 的 `message_id`：

- 查不到（不同群組、id 不存在）⇒ 當作沒引用
- 查得到但 `telegram_message_id` 是 null（訊息有 7 天 TTL）⇒ 記一行 log，當作沒引用

求助單那兩條路的 `message_id` 是 `nullOnDelete` 的外鍵，所以**舊單一定會
走到這個退路**，那是正常的，不是壞掉。

## 驗證

本機交易內驗過 `findQuoted()`：後台 id 正確換成 `telegram_message_id`、
`telegram_message_id` 為 null 時照實回 null、id 不存在時查不到、
**用別的群組去查同一個 id 撈不到**（跨群組隔離）。

⚠ Telegram 上實際的引用樣子要上線才看得到 —— 本機沒有可以對話的客人群組。
