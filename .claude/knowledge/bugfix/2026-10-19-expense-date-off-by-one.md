# 支出紀錄選 18 號，顯示變成 10/17

> 需求方：「財務管理的支出紀錄這邊，我選 18 號，他會寫 10/17，我如果選 19 號，
> 他會寫 18 號」

差的正好是一天 —— 這種永遠先懷疑時區。

## 重現

```
app 時區：Asia/Taipei（+8）
Carbon：2026-10-18 00:00:00
轉成 JSON：2026-10-17T16:00:00.000000Z     ← 被換算成 UTC
substring(0,10) → 2026-10-17               ← 編輯時帶回的值
substring(5,5)  → 10-17                    ← 列表顯示的
```

`FinanceExpense` 的 `expense_date` cast 成 `'date'`，Laravel 轉 JSON 時會輸出
**UTC 的 ISO 字串**。台北是 +8，所以 `2026-10-18 00:00` 變成前一天的 16:00。
前端又直接 `substring()` 切字串，切出來自然是前一天。

## 修法：`'date:Y-m-d'`

```php
'expense_date' => 'date:Y-m-d',
```

⚠ **日期欄位沒有時間的意義，不該被時區位移。** 加了格式之後序列化就是
`2026-10-18`，前端怎麼切都不會錯。

⚠ PHP 端**不受影響** —— `$expense->expense_date` 仍然是 Carbon，
只有序列化那一步變了。

⚠ 不用 `serializeDate()` 覆寫：那會把 `created_at` / `updated_at` 一起變成
`Y-m-d`，時間就沒了。`cast` 帶格式只影響指定的那一欄。

### 跟 2026-09-30 那次的差別

[[2026-09-30-utc-timestamp-display]] 是**時間欄位**（上傳時間）顯示少 8 小時，
修法是前端用 `window.formatDateTime()` 換算回本地時間 —— 那是對的，
時間本來就有時區。

**日期欄位不一樣：它根本不該有時區。** 所以修在序列化這一端，
而不是叫前端去換算一個本來就不該被換算的值。

## 驗證

| 選的日期 | JSON | 列表顯示 |
|---|---|---|
| 2026-10-18 | `2026-10-18` | 10-18 ✓ |
| 2026-10-19 | `2026-10-19` | 10-19 ✓ |
| 2026-01-01 | `2026-01-01` | 01-01 ✓ |
| 2026-12-31 | `2026-12-31` | 12-31 ✓ |

跨年月邊界也不會退回前一天。PHP 端型別仍是 `Illuminate\Support\Carbon`。

## ⚠ 同一類還有三處（尚未修）

掃「前端對日期做 `substring` / `toISOString()`」找到的：

| 位置 | 問題 | 後果 |
|---|---|---|
| `staff-manage/index.blade.php:891` | `new Date().toISOString().substring(0,10)` 當歸還日期**存進資料** | 台北時間 **00:00–08:00** 之間歸還設備，會記成**前一天** |
| `staff-manage/index.blade.php:871` | 同上，確認視窗顯示的「今天」 | 同上，而且畫面上就寫錯 |
| `task-board/index.blade.php:541` | `new Date().toISOString()` 當「今天」 | 凌晨時段的篩選基準差一天 |
| `task-board/index.blade.php:2018` | `t.updated_at.substring(0,10)` | `updated_at` 是 UTC，凌晨時段顯示前一天 |

⚠ `new Date().toISOString()` **永遠是 UTC**。要本地日期得自己組
（`getFullYear()` / `getMonth()+1` / `getDate()`），不能切 ISO 字串。

前兩項比較嚴重 —— 那是**寫進資料**的日期，不是顯示而已。
