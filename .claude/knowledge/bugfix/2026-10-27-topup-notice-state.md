# 補點通知：格式舊看不出來，沒送到也看不出來

> 需求方 2026-10-27：客戶收到的是
> 「永富支付系統餘額已增加 50008.4; 當前為 50278.74」，
> 但應該要長這樣：
>
> ```
> 🔔<ht支付`增加`系統餘額>
> 原本點數: 37,211.29
> 操作點數: +15,208.33
> 當前點數: 52,419.62
> ```
>
> 「補點訊息格式不對，錯了，這個是嚴重錯誤」

## 根因：那個站台的主系統是舊版

主系統 `AdminCreditService::handleCredit()` 在 commit `406fbfdc`
（2026-08-28「cs補扣點新增訊息回傳」）才改成三行格式：

```php
- 'message' => "{$site}系統{$columnLabel}已{$actionLabel} {$amount}; 當前為 {$admin->{$column}}",
+ 'message' => $message,   // 🔔<站台`增加`系統餘額> ＋ 原本／操作／當前
```

舊那行字串套進去，**一字不差**就是客戶收到的那句。
兩個站台收到不同格式，是因為 **ht 的主系統更新了、永富的還停在 8 月之前**。

## ⚠ 我一度想「客服系統自己組這段訊息」—— 需求方否決了

那樣可以不管主站版本、格式一律一致。但需求方的決定是
**「不要自己寫，我要用主站回傳的去發」**。

理由成立：點數異動的敘述是**主系統的事實**，客服系統改寫等於把一份
「我們以為發生了什麼」發給客戶。而且倒推「原本點數」本身就是推論
（主站沒回這個值），推錯了比格式舊更嚴重。

**所以訊息內容一個字都沒改，還是 `$result['msg']` 原文。**

## 改的是：把「有沒有通知到」變成看得見的

需求方：「有問題就顯示，可以做補傳按鈕也沒關係。」

⚠ 在這之前，以下三種情況**客戶什麼都沒收到，而後台只顯示「已完成」**，
真相只寫在 log 裡：

| 情況 | `notify_issue` | 有送出去嗎 |
|---|---|---|
| 站台沒綁 Telegram 群組 | `no_group` | ❌ |
| 主站沒回訊息（`msg` 空的）| `no_message` | ❌ |
| Telegram 拒收或丟例外 | `send_failed` | ❌ |
| **主站回的是舊格式** | `old_format` | ✅ **有** |

⚠ **`notified_at` 與 `notify_issue` 是兩件事，不能併成一個狀態欄**：
`old_format` 那一種是「訊息有送出去，只是格式舊」，兩欄都有值。
併成一欄就表達不了「已通知，但那台主系統該更新了」。

### 舊格式怎麼認

看訊息裡有沒有「**原本點數**」（`constants.STATION.TOPUP.NEW_FORMAT_MARKER`）。

⚠ 不做整段比對 —— 主系統日後再加欄位時，整段比對會把新格式也判成舊的。

## 補傳按鈕

⚠ **絕對不會再呼叫主系統 API**。點數已經加扣過了，重打一次就是
**重複加點**。補傳重發的是當初存在 `api_response` 裡的那段原文，
不會重算也不會動到任何點數（測試裡有一條直接掃原始碼確認
`resendNotice()` 裡沒有 `mainSystemApi`）。

⚠ 只有**已完成**的能補傳。待審核、拒絕、API 失敗本來就沒有通知可補 ——
讓它們按得下去只會送出一則不存在的事實。

⚠ 「其他」類型（`credit_type = other`）**不顯示通知狀態、也沒有補傳鈕**：
它不呼叫主系統 API、本來就不發通知，標上去反而像壞掉。

⚠ 補傳完還是沒送到（例如群組還是沒綁）要**照實回報**，不能回一句「已補傳」。

⚠ 權限沿用 `station.topup_approve` 而不是 `topup_view` ——
這會發訊息到客戶群，跟審核同一個等級。

## 新增/修改的檔案

| 檔案 | 說明 |
|---|---|
| `database/migrations/2026_10_27_000002_add_notify_state_to_credit_topup_table.php` | **新增** `notified_at`／`notify_issue` |
| `app/Models/CreditTopup.php` | `ISSUE_*` 四個常數 ＋ `notified_at` cast |
| `app/Services/CreditTopupService.php` | `notifyTelegram()` 多收 `$topup` 並記狀態；新增 `resendNotice()`／`markNotice()`／`looksOldFormat()` |
| `app/Http/Controllers/Admin/StationController.php`／`routes/web.php` | `ajaxTopupResendNotice()` ＋ 路由 |
| `app/Http/Resources/CreditTopupResource.php` | 多回兩個欄位 |
| `resources/views/admin/station/index.blade.php` | 狀態標示 ＋ 補傳鈕（桌機表格與手機卡片都要）|
| `config/constants.php` | `NEW_FORMAT_MARKER` |
| `resources/lang/{tw,cn,en}/station.php` | 三語系 |

## 驗證

17 種情境（Telegram 與 Repository 用假物件，不碰 DB 也不打主站）：

- 新格式：**訊息原封不動**（含 footer）、標成已通知、沒有問題
- 舊格式：**訊息照樣原封不動發出去**、有通知到、標成 `old_format`
- 沒綁群組／主站沒回訊息：不發送、標對原因、`notified_at` 是空的
- Telegram 拒收：標成 `send_failed`
- 補傳：重發的是存下來的原文、補完標成已通知
- **補傳的原始碼裡沒有 `mainSystemApi`**（不會重複加點）
- 待審核的補傳會被擋下來

⚠ **沒有真的跑過補點**（那會動到客戶點數），也沒有真的發到客戶群。

## ⚠ 既有紀錄要回填，不然整頁都是假警報

這一欄以前不存在，所以舊紀錄的 `notified_at` 全是 null ——
**列表上所有歷史紀錄都會變成紅色的「未通知」**。那會訓練同仁忽略這個標示，
反而讓真正沒送到的被蓋過去。

migration 裡一併把**已完成且有審核時間**的回填成
`notified_at = reviewed_at`。

⚠ 這是**推論不是事實**：沒有任何依據能知道當初到底有沒有送到。
選「當成已通知」是因為另一邊更糟 —— 標成未通知會讓人去按「補傳」，
把三個月前的補點通知重新發給客戶。

⚠ 用 `reviewed_at` 不是 `now()`：填 `now()` 會變成一整批紀錄都在
migration 那一刻通知，時間完全對不上。

⚠ `notify_issue` 留空 —— 不知道就不要編一個原因出來。

## 待辦

- **正式機要跑 migration**（`credit_topup` 加兩欄 ＋ 回填）。
- **永富那台主系統建議更新**到 `406fbfdc` 之後，格式就會自己變對；
  在那之前列表上那幾筆會標成「已通知，但主系統回的是舊格式」。
