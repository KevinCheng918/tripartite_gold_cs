# 求助單逾時升級：催到第 10 次就把人叫起來

> **狀態：規劃中，尚未實作。** 本文件為實作前的設計稿，待需求方確認。

## 需求（需求方 2026-11-08）

> 「我想要用機器人打電話，如果當求助單提醒超過 10 次，就代表已經逾時一個
> 多小時了，我要機器人打電話給當班人，提醒要上線處理」

打不通時：

> 「重撥幾次，還是不接的話，就傳私人訊息給勾選清單」

## 為什麼拆成兩段做

「打電話」卡在外部條件（Telegram userbot 的 `api_id` 申請被 my.telegram.org
限流，見最後一節），而**整條升級鏈有一半跟電話無關**。

| 段 | 內容 | 相依 |
|---|---|---|
| **本文件** | 第 10 次觸發 → 私訊當班人 → 再沒處理 → 私訊勾選清單 | 無，現在就能做 |
| 之後 | 把「打電話」插進這條鏈的**第一步** | userbot spike 要先驗成功 |

⚠ 架構上電話只是這條鏈**多一個環節**，不是另一套東西 —— 所以紀錄表現在就
留好電話要用的欄位，省得之後再 migrate 一次。

## 「10 次 ≈ 一個多小時」要確認的相依

現行預設：首次 10 分鐘、之後每 10 分鐘 → 第 10 次約在開單後 **100 分鐘**，
跟需求方說的「一個多小時」吻合。

⚠⚠ **但那三個值都是設定頁可改的**，而且有一個會讓整個功能靜默失效：

> **次數上限（`KEY_REMIND_MAX_COUNT`，預設 30）如果被設成小於升級門檻，
> 提醒會在升級之前就停止 —— 電話永遠不會響，而且不會有任何錯誤。**

`getTicketsDueForRemind()` 用 `remind_count <= $max` 收單，所以上限設 8 的話
第 10 次根本不存在。

→ 設定頁要**擋下來**：次數上限必須大於升級門檻，存檔時驗證並說明原因。

## 升級鏈

掛在 `AutoReplySupportService::remindOne()` —— 那支本來就算好了 `$seq`
（這是第幾次）與 `$users`（tag 到誰＝當班的人），送出成功後接著判斷。

| 階段 | 觸發 | 動作 |
|---|---|---|
| 0 | 第 1～9 次 | 現行行為不變（群組 tag，第 3 次起加主管） |
| **A** | 第 **10** 次 | 〔之後〕打電話給當班人 ＋ **私訊當班人** |
| **B** | A 之後再 **N** 次仍沒處理 | **私訊勾選清單**（設定頁維護） |

⚠ **階段 A 要私訊而不只是打電話。** 電話把人叫起來，但電話裡沒有內容
（見電話那段的設計：只響不串音訊）—— 他醒了要知道是哪一張單，那得靠私訊。
所以就算電話那段永遠做不成，這一層本身就有價值：**群組 tag 他已經無視九次了，
私訊是更強的一層**。

### ⚠ 判定「有沒有處理」一律看單的狀態，不看他有沒有回應提醒

- **不能**用「電話接起來了」當確認 —— 那通電話接起來只會斷線，
  系統拿不到可靠的「他醒了」訊號
- **不能**用「他已讀私訊」當確認 —— Telegram bot 讀不到已讀狀態

唯一可信的訊號是**那張單真的被處理**（`status` 離開 PENDING／ANSWERED）。
沒有就繼續走下一階。

## 設定項

| 項目 | 建議預設 | 存在哪 |
|---|---|---|
| 總開關 | **關**（見下） | `app_setting` |
| 升級門檻（第幾次） | **10** | `app_setting` |
| 階段 B 再等幾次 | 5 | `app_setting` |
| 勾選清單（階段 B 私訊給誰） | 空 | `app_setting`，沿用既有 `KEY_*_MANAGER` 那套 |

⚠ **建議預設關**，跟交接那個功能相反。理由：這個功能會**私訊人**，而私訊清單
一開始是空的 —— 預設開等於部署完就開始私訊當班人卻沒有第二層接手。
上線時把清單設好再開。

## 紀錄表 `auto_reply_ticket_escalation`

```
id
auto_reply_ticket_id  FK auto_reply_ticket, cascadeOnDelete
stage                 tinyint  1=私訊當班人, 2=私訊勾選清單, 3=打電話（之後用）
seq                   int      觸發時那張單已經催到第幾次
user_id               FK user  nullable + nullOnDelete；通知/撥打的對象
user_name             string   名稱快照
result                tinyint  1=已送出, 2=送不出去（沒綁定/tag不到）, 3=通話未接（之後用）
detail                string   nullable；失敗原因或通話結果
created_at / updated_at
index (auto_reply_ticket_id, stage)
```

⚠ **一次升級 tag/撥給幾個人就幾列**（比照 `auto_reply_ticket_remind` 的做法）
—— 之後要回答「誰最常被叫起來」「誰最常收不到」才有資料。

⚠ **`result` 與 `stage` 的 3 現在用不到**，是給電話那段留的。
現在建好省一次 migration —— 這是刻意的前瞻，理由是電話那段是**已確認的
近期需求**（需求方正在申請 api_id），不是臆測。

## 為什麼不複用 `auto_reply_ticket_remind`

那張表記的是「**群組**提醒 tag 到誰」，`stage` 的語意是 1=當班/2=主管/3=收尾。
升級是**另一種管道**（私訊、電話），混進去之後「這張單被催了幾次」這個
數字會同時包含群組與私訊，超時統計的每個數字都要先問「這筆是哪一種」。
那正是 `conversation_followup` 當初不跟 `auto_reply_ticket` 共用的理由。

## ⚠ 私訊送不到的人

`StaffDmService` 要對方 **先私訊過 bot**（`telegram_dm_ready`）才送得到。
沒綁定的人：

- 階段 A 私訊不出去 → **在支援群組補一則**說明「X 沒綁定，收不到私訊」，
  不要靜靜失敗（比照班表通知的 `shift_test_not_bound` 處理）
- 這件事要記進 `result` = 2，之後查得到

## 預計異動檔案

### 新增

| 檔案 | 內容 |
|---|---|
| `database/migrations/*_create_auto_reply_ticket_escalation_table.php` | 紀錄表 |
| `app/Models/AutoReplyTicketEscalation.php` | Model |
| `app/Repositories/AutoReplyTicketEscalationRepository.php` | 寫入與查詢 |
| `app/Services/TicketEscalationService.php` | 判斷該不該升級、送出、記錄 |
| `app/Http/Requests/Notification/UpdateTicketEscalationRequest.php` | 設定頁驗證 |

### 修改

| 檔案 | 改什麼 |
|---|---|
| `app/Services/AutoReplySupportService.php` | `remindOne()` 送出成功後呼叫升級判斷 |
| `app/Services/AppSettingService.php` | 四個 KEY |
| `app/Services/NotificationSettingService.php` ＋ 設定頁 Request/Blade/JS | 設定區 ＋ **次數上限必須大於升級門檻**的驗證 |
| `config/constants.php` | `TICKET_ESCALATION`（階段、預設、文案模板） |
| `resources/lang/{tw,cn,en}/notification.php` | 設定欄位與訊息 |

⚠ 新增 `config/constants.php` 頂層 key 之前要先
`grep -n "^    'TICKET_ESCALATION'" config/constants.php` —— 2026-11-08 才踩過
`SHIFT_HANDOVER` 撞名、**PHP 重複陣列 key 無聲覆蓋**、把別的功能弄壞的坑。

## 待釐清

1. **階段 B 的「再 N 次」** 建議 5（＝再 50 分鐘）。要不要改成「再 X 分鐘」
   而不是次數？用次數的好處是跟現有的 `remind_count` 同一個尺度。
2. **總開關預設開還是關？** 建議關（理由見上）。
3. **階段 B 之後還要不要有階段 C？** 目前設計是私訊完清單就停（群組提醒仍會
   照常催到上限）。要不要再往上？
4. **電話那段**：等 userbot spike 驗證「能不能讓對方手機響」。
   my.telegram.org 的 `api_id` 申請 2026-11-08 被限流（`Too many attempts`），
   帳號不是新的、只是剛登入 —— 登入嘗試也算進同一個限制器，等冷卻即可。

## 相關

- [[auto-reply]] — 求助單流程與狀態機
- [[remind-escalation]] — 現行的群組提醒升級（第 3 次起 tag 主管）
- [[shift-handover-topic]] — 同一天做的另一個「叫人起來」的功能
