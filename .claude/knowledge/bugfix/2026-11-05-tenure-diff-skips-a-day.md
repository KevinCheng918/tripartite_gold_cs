# 到職日差一天，年資卻差兩天

**日期**：2026-11-05（v3.75）
**回報**：需求方貼內勤管理列表的截圖 ——
到職日 2025-10-01 顯示「1 年 1 個月 5 天」、2025-10-02 顯示「1 年 1 個月 3 天」。

## 病根：`DateTime::diff()` 的年月日拆解在 Asia/Taipei 會跳號

實測（基準 2026-11-05，PHP 7.4.33）：

| 到職日 | Asia/Taipei | UTC | 總天數 |
|---|---|---|---|
| 2025-09-30 | 1 年 1 月 **6** 天 | 1 年 1 月 6 天 | 401 |
| 2025-10-01 | 1 年 1 月 **5** 天 | 1 年 1 月 **4** 天 | 400 |
| 2025-10-02 | 1 年 1 月 **3** 天 | 1 年 1 月 3 天 | 399 |

台北那一欄的 **4 被跳過**，所以 10-01 與 10-02 看起來差兩天。

⚠ **總天數（`->days`）是對的**（401／400／399，兩個時區一致）——
壞的只有 `->y / ->m / ->d` 這三個拆出來的數字。

⚠ 跟 Carbon 無關、跟時間部分無關：純 `DateTime` ＋ `Asia/Taipei`、
`now` 設成 00:00，結果一模一樣。換成 UTC 就正常 ——
是 timelib 在有歷史時區轉換的時區下拆月份的行為。

## 修法：自己逐段推進，不要用 `diff()` 的拆解

```php
$years  = $hired->diffInYears($today);
$after  = $hired->copy()->addYearsNoOverflow($years);
$months = $after->diffInMonths($today);
$after  = $after->copy()->addMonthsNoOverflow($months);
$days   = $after->diffInDays($today);
```

每一步都是明確的日期運算。修正後：7 → 6 → **4** → 3 → 2，每差一天就差一天。

⚠ `addYearsNoOverflow` / `addMonthsNoOverflow` 不能換成一般版本：
1/31 加一個月在一般版本會溢位到 3 月。

⚠⚠ **`diffInYears` / `diffInDays` 不受這個問題影響**（它們是計數，不是
把一個區間拆成年月日）。所以 `LeaveQuotaService` 的特休級距是對的 ——
**不要連那裡一起「修」**。實測確認過三個邊界日期的 `diffInYears` 都正確。

## 順手合併了兩份重複實作

`StaffResource` 與 `AccountResource` **各自寫了一份** `calcTenure()`，
而且兩份都用了會跳號的拆解。合併成 `UserPresenter::tenureText()`。

⚠ 原本兩份的中文字是硬編碼的（「尚未到職」「今天到職」「N 年」），
合併時一起搬進 `resources/lang/*/account.php`。

## 相關檔案

| 檔案 | 說明 |
|---|---|
| `app/Presenters/UserPresenter.php` | `tenureText()`，含實測對照表 |
| `app/Http/Resources/StaffResource.php` | 改用共用的 |
| `app/Http/Resources/AccountResource.php` | 改用共用的，移除 `calcTenure()` |
| `resources/lang/{tw,cn,en}/account.php` | `tenure_*` |
