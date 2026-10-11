# 求助單被誤關與漏關：`ignoreOpenByGroup()` 掛的位置不對

## 現象

兩個方向都錯，而且都看不出來：

- **誤關**：後台按一下「發送繳款通知」（或補點審核通過），那個客戶群裡
  **所有**還掛著的求助單全部變成「客服已自行處理」。客人的問題其實沒人回，
  但報表上那幾列從紅色變成已處理、提醒也停了。
- **漏關**：客服在對話視窗**傳檔案**給客人（對帳單、教學圖就是答案本身），
  單沒有被關掉 —— 那張單一路催到上限，報表上永遠紅著。

## 起因

「客服自己人工回覆了就不該再轉到支援群組」這條規則只掛在
`TelegramChatService::sendReply()` 的尾端：

```php
if (!$isAuto) {
    $this->ticketRepository->ignoreOpenByGroup($group->id);
}
```

兩個問題：

**一、`$isAuto` 的語意被當成「是不是排程跑的」。** 它真正問的是
「**這則訊息算不算客服親自回覆客人**」。`sendReply()` 的 11 個呼叫端裡，
有兩個是「人按的按鈕、但內容是系統組的通知」——
`VmController::ajaxSendNotice()`（繳款通知）與 `CreditTopupService`（補點結果）。
兩者都已經傳了 `mark_replied => false`（系統通知不該消掉「未回覆」狀態），
卻漏了同一個理由的 `is_auto => true`，於是走進那個 if，整群的單被清空。

**二、送檔案的兩支完全沒有這條規則**（文件區那支連 `markMessagesReplied()`
都沒有）。`sendFileReply()`（輸入區上傳）與
`sendDocumentFromSharedFile()`（從文件區挑）都是客服手動把東西送給客人，
跟送文字、送圖片同一件事，但兩支都沒呼叫 `ignoreOpenByGroup()`。

⚠ `ignoreOpenByGroup()` 是**整群一次關**（`status in (PENDING, ANSWERED)`），
所以第一個問題的誤傷範圍是那個群的全部未結單，不是一張。

## 修法

| 位置 | 改動 |
|---|---|
| `VmController::ajaxSendNotice()` | 補 `'is_auto' => true` |
| `CreditTopupService` 發補點結果 | 補 `'is_auto' => true` |
| `TelegramChatService::sendFileReply()` | 補 `ignoreOpenByGroup()`（無條件：唯一呼叫端是客服上傳） |
| `TelegramChatService::sendDocumentFromSharedFile()` | 補 `markMessagesReplied()` ＋ `ignoreOpenByGroup()`，條件 `filled($senderId)` |

最後一支用 `filled($senderId)` 而不是無條件：它沒有 `is_auto` 旗標，
而 `$senderId` 就是「誰送的」。目前唯一的呼叫端帶 `Auth::id()`，
但那個參數是 nullable —— 哪天有系統自動送檔的流程，那不算客服回覆。

那一支連 `markMessagesReplied()` 都沒有，所以從文件區傳檔案給客人之後，
那個對話在系統裡仍然算「欠著沒回」，未回覆告警會誤報。兩件事條件相同
（都是「客服親自回覆客人之後要做的事」），所以寫在同一個 `if` 裡。

## 教訓

> ⚠️ **`is_auto` 問的不是「誰觸發的」，是「這則訊息算不算客服親自回覆客人」。**
> 人按的按鈕也可能要傳 `true`：判準是內容。系統組的帳單、補點結果、餘點告警
> 都不是在回答客人的問題，所以既不該消掉「未回覆」狀態，也不該結掉求助單。
>
> 實用的檢查法：**`mark_replied => false` 與 `is_auto => true` 幾乎總是一起出現**，
> 因為它們問的是同一件事。新增 `sendReply()` 的呼叫端時，看到只有其中一個
> 就要停下來想 —— 這次的兩個 bug 都是這個形狀。
>
> ⚠️ 另一半是：**同一條規則散在多支送訊息的函式裡，就一定會有人漏掉。**
> 送文字、送圖片走 `sendReply()`，送檔案另外兩支 —— 規則只寫在第一支上，
> 另外兩支從第一天起就沒有。

## 異動檔案

- `app/Http/Controllers/Admin/VmController.php`
- `app/Services/CreditTopupService.php`
- `app/Services/TelegramChatService.php` — `sendFileReply()`、`sendDocumentFromSharedFile()`

## 相關

- [[2026-10-11-remind-report-still-pending]] — 求助單離開 `PENDING` 的全部路徑
- [[telegram-chat]] — 客服對話視窗
- [[auto-reply]] — 求助單流程
