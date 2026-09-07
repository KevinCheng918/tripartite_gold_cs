# 財務管理

> 每月一筆 `finance_record`，收入自動從補點與虛擬機統計而來，支出手動登打。

## 相關檔案

| 層 | 檔案 |
|----|------|
| Controller | `app/Http/Controllers/Admin/FinanceController.php` |
| Service | `app/Services/FinanceService.php` |
| Repository | `app/Repositories/FinanceRepository.php` |
| Model | `app/Models/FinanceRecord.php`、`app/Models/FinanceExpense.php` |
| Request | `app/Http/Requests/StaffManage/{Store,Update}ExpenseRequest.php` |
| View | `resources/views/admin/finance/index.blade.php` |

## 收入模型（需求方確認）

**點數是共同單位，台幣與點數固定 1:1、不會變動。**

兩條**獨立**的收入線，**需求方明確要求分開看、不得合併計算**：

### 1. 補點收入（`credit_topup`）

| 種類 | `input_type` | 收到 | 換算 |
|------|-------------|------|------|
| USDT 補點 | 1 | USDT | × 該筆匯率 = 點數 |
| 台幣補點 | 2 | 台幣 | 1:1 = 點數 |

對帳要能同時看到：**點數合計**、**收到幾顆 USDT**、**收到多少台幣**。
`calcTopupStats()` 用 `input_type` 把這三個數字分開統計，細節見 [station-topup.md](station-topup.md)。

### 2. 虛擬機服務收入（`vm_billing`）

收 USDT，依繳款當下的 4H 均價匯率（`vm_billing.exchange_rate`）換算成可扣的系統點數。

### ⚠️ 不可合併

- 摘要卡與收入明細都是補點一組、虛擬機一組，**沒有跨兩者的合計值**
- `FinanceService::getMonthDetail()` 刻意不回傳 `totals` 之類的合併欄位
- **本月損益 = 補點點數 − 支出，不含虛擬機**（損益卡上有標註）

曾經一度把兩者加總後被需求方否決，不要再合併回去。

## 支出（`finance_expense`）

- 幣別 TWD / USD / USDT
- **外幣支出必須輸入匯率**（`exchange_rate`），存輸入當下的值而非即時查詢 —
  對帳看的是支出**發生當時**的成本
- `FinanceExpense::getTwdAmountAttribute()` 提供 `twd_amount`，已加入 `$appends`
- 舊資料若沒有匯率，`twd_amount` 回 **0** 而不是拿原幣值充台幣，
  避免把 USDT 金額當台幣灌進合計

### 已修掉的既有 bug

1. **外幣支出被完全排除在合計外** — 本月支出／已請款／未請款原本都是
   `if (currency === 'TWD')`，外幣支出等於沒被算進任何數字，損益也失真。
   現已全部改用 `twd_amount`。
2. **摘要卡的點數用匯率反推** — `totalRevenue * avgRate`。全部是 USDT 補點時
   剛好等於實際點數，但台幣補點的 USDT 與匯率都是 0，反推變 0，
   造成摘要卡 0 點、明細卻有實際點數。改成直接用後端的實際值。
3. **「N 筆收入」只算虛擬機** — 補點筆數從沒被算進去。

## 手動覆蓋機制

`finance_record` 的欄位為 null 時用自動統計值，填了值就以手動值為準：

`topup_usdt`、`topup_avg_rate`、`topup_credit`、`topup_twd`、
`vm_income_usdt`、`vm_income_count`

- `is_manual` 是看 `topup_usdt` / `vm_income_usdt` 是否有值
- 「重置」會把該組欄位全部設回 null
- **新增收入欄位時記得一併加手動覆蓋欄位**，否則會出現「一部分是手動值、
  一部分還是自動值」而對不起來 —— `topup_twd` 就是為此而加

## 前端注意事項

- 幣別／輸入方式這類切換，**`required` 必須跟著隱藏一起移除**，
  否則瀏覽器會卡在看不見的必填欄位上，表單送不出去又沒有錯誤訊息
- modal 的 `form.reset()` **不會觸發 change 事件**，要手動呼叫切換函式
- 本頁文案目前是寫死中文（全頁皆然），未走語系檔，
  見 [bugfix/2026-08-10-hardcoded-chinese.md](../bugfix/2026-08-10-hardcoded-chinese.md)

## 相關 migration

- `2026_08_21_000002_create_finance_tables.php` — 建表
- `2026_08_21_000003_add_exchange_rate_to_vm_billing_table.php` — VM 繳款匯率
- `2026_09_07_000008_add_topup_twd_to_finance_record_table.php` — 台幣補點手動覆蓋
- `2026_09_07_000009_add_exchange_rate_to_finance_expense_table.php` — 外幣支出匯率
