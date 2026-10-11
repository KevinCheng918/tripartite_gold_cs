# 「我明明回了也加進題庫了，報表還是仍未處理」

## 現象

同仁在內部支援群引用回覆、也按了「回覆客人並加入題庫」，
後台「報表 → 超時提醒統計 → 依題目」卻還有一列紅底的**仍未處理**。

## 結論：報表沒有延遲，那一列是**另一張單**

「仍未處理」＝ 該列 `auto_reply_ticket.status` 當下仍是 `PENDING(1)`。
這份報表**沒有任何快取**，每次都即時查：

```
ReportController::ajaxRemind()
  → RemindReportService::forPage()   // 直接比 ticket.status === PENDING
  → AutoReplyTicketRemindRepository::getTicketsForRange()   // with('ticket.group')
```

前端也只是照著後端給的 `pending` 旗標上色（`public/js/report.js`），
不自己判斷狀態數字。所以畫面重整後還紅著，就代表那張單真的還沒結案。

而**報表是「依題目」逐張單列的**，`groupBy('auto_reply_ticket_id')`。
`openTicket()` 的規則是「客人每問一個問題各開一張單，只擋一模一樣的重複問題」——
所以同一段對話、同一個群組會有好幾張單，
回覆其中一張不會動到另一張。核對方式：**比對那一列的題目文字**
跟你剛剛在支援群回的那題是不是同一句。

## 一張單只有這幾條路會離開 PENDING

| 做法 | 結果狀態 | 程式位置 |
|---|---|---|
| 在支援群**引用那則求助訊息**打字作答 | `ANSWERED(2)`，再按按鈕才到終態 | `AutoReplySupportService::handleSupportMessage()` |
| 求助訊息上的按鈕（忽略／回覆／回覆並加入題庫／只加題庫／先問客人） | `IGNORED(0)` / `REPLIED(3)` / `SAVED(4)` | `handleAction()`、`handleCategoryChosen()` |
| 直接按候選題目的按鈕回覆 | `REPLIED(3)` | `handleCandidatePicked()` → `closeTicketWithItem()` |
| **從後台客服對話視窗**發訊息給那個客戶群（文字、圖片、檔案、文件區檔案都算） | 該群所有未結單一次標成 `IGNORED(0)` | `TelegramChatService::sendReply()` / `sendFileReply()` / `sendDocumentFromSharedFile()` → `ignoreOpenByGroup()` |
| `ticket:close-stale` 排程 | `EXPIRED(5)` | 預設 `STALE_DAYS = 0`＝**不自動收**

> 送檔案那兩條路原本沒有關單（漏關），繳款通知與補點結果則會誤關整群 ——
> 兩個方向都在 [[2026-10-11-ignore-open-tickets-scope]] 修掉了。

## ⚠ 缺口：直接用自己的 Telegram 帳號在客戶群回客人，單不會結

同仁在客戶群用個人帳號回話時，系統認得出他是自己人
（`staffIgnoreService->isStaff()`，所以不會再觸發自動回覆），
但**沒有任何地方去關那張單** —— `ignoreOpenByGroup()` 只掛在「後台對話視窗送出訊息」
那條路上。於是：

- 客人其實已經被回答了
- 求助單永遠停在 `PENDING`，提醒排程一路催到上限
- 報表那一列永遠紅著

這不是 bug 而是目前的設計邊界，但它會讓報表失真。
自動補這條路得先想清楚「同事在群裡講一句閒聊」不該把單關掉
（`ignoreOpenByGroup()` 是整群一次關，誤傷範圍是整群的所有未結單），
所以**先做的是手動出口**，不是自動判斷。

## 已補：報表那一列可以直接結掉（2026-10-11，v2.78）

紅標現在有出口了 —— 報表「依題目」每一列紅色「仍未處理」後面多一顆
「標記為已處理」（`PUT /admin/report/ajax-mark-handled/{id}`，權限
`report.remind_handle`）。狀態改成 `IGNORED`，並在支援群組回報一則
說明是誰處理掉的。做法與取捨見 [features/report-page.md](../features/report-page.md) 第 7 節。

⚠ 它**不回覆客人、也不寫題庫**。這一顆回答的是「這件事我在別的地方做完了」，
不是「系統幫我處理」。根因若是上面那個缺口，這顆是善後，不是修好。

## 教訓

> ⚠️ **報表顯示的是「單」的狀態，不是「客人有沒有被回答」。**
> 這兩件事只有在同仁走支援群的按鈕或後台對話視窗時才會對齊。
> 查「為什麼還是未處理」的第一步永遠是**比對題目文字是哪一張單**，
> 第二步才是問「那張單是用什麼方式回的」。

## 相關檔案（本次只讀未改）

- `app/Services/RemindReportService.php` — `forPage()` 的 `pending` 判定
- `app/Services/AutoReplySupportService.php` — 求助單的所有狀態轉移
- `app/Repositories/AutoReplyTicketRepository.php` — `ignoreOpenByGroup()`
- `app/Services/TelegramChatService.php` — 唯一呼叫 `ignoreOpenByGroup()` 的地方

## 相關

- [[report-page]] — 報表頁
- [[remind-escalation]] — 超時提醒的升級規則
- [[auto-reply]] — 求助單從開到結的全流程
- [[ignore-staff]] — 同仁在客戶群發言的辨識
