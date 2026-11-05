# 請假申請回「The given data was invalid.」

**日期**：2026-11-05（v3.76）
**回報**：「我請假管理的申請請假，我沒有特休，它有顯示尚未取得特休（未滿六個月），
申請後他會顯示 The given data was invalid.」

## 病根一：`ValidationException` 的 `getMessage()` 寫死是那句廢話

`vendor/laravel/framework/src/Illuminate/Validation/ValidationException.php:56`

```php
parent::__construct('The given data was invalid.');
```

真正的訊息只在 `errors()` 裡。而 Laravel 的 `invalidJson()` 回的是
`$exception->getMessage()` —— 所以 `message` 永遠是那句話。

⚠ **兩種寫法都壞，實測過**：

| Controller 寫法 | 回給前端的 `message` |
|---|---|
| `catch (\Exception $e)` → 回 `$e->getMessage()` | `The given data was invalid.` |
| `catch (ValidationException $e) { throw $e; }` | `The given data was invalid.` |

第二種看起來是對的（既有 `ShiftController`／`AttendanceController`／
`ShiftCoverController` 都這樣寫），但交給 Laravel 渲染之後 `message`
一樣是那句 —— **「單獨接住往上丟」只解決了 500，沒解決訊息**。

## ⚠⚠ 這個問題已經被遇到五次，每次都在前端補一份

全專案有**十份各自獨立的 `apiFetch`**，呼叫端一律只讀 `body.message`。
而這五個檔案各自寫了一份「從 `errors` 撈第一句」的補救程式：

```
public/js/attendance.js:60
public/js/notification-setting.js:73
public/js/report.js:97
public/js/setting-admin.js:73
resources/views/admin/vm/index.blade.php:385
```

後兩份的註解甚至直接寫著「`message` 只有一句通用的 The given data was invalid.」。
**同一個源頭的問題補了五份下游。**

## 修法：覆寫 `Handler::invalidJson()`，一次修掉全部

```php
protected function invalidJson($request, ValidationException $exception)
{
    $errors = $exception->errors();
    $first = Arr::first(Arr::flatten($errors));

    return response()->json([
        'message' => filled($first) ? $first : $exception->getMessage(),
        'errors'  => $errors,
    ], $exception->status);
}
```

⚠ **`errors` 原封不動**。上面那五份補救程式是先讀 `errors`、讀不到才退回
`message`，所以照舊運作，不會變成顯示兩次 —— 這是能安全修源頭的前提，
改之前有確認過。

⚠ **順手修好的**：`ShiftController`（4 處）、`AttendanceController`（2 處）、
`ShiftCoverController`（3 處）那些 `throw $e` 的地方現在也會顯示真正的訊息了，
一行都沒動到。排班重疊、補打卡衝突之類的訊息本來全都是那句廢話。

⚠ **多欄位同時不合格時只回第一句**。刻意的 —— 這個專案的錯誤都用單行
toast（`showMessage()`）顯示，串五句會爆版；要全部的前端自己讀 `errors`。

⚠ 確認過沒有任何程式在比對 `'The given data was invalid.'` 這個字串。

## 病根二：「還沒有特休」被講成「額度不足，只剩 0 小時」

使用者看到的矛盾：表單底下寫「尚未取得特休（未滿六個月）」，
送出卻說「額度不足，目前只剩 0 小時」—— 後者的意思是「你有過但用完了」。

未滿六個月的新人會以為自己的特休被請光了，跑去追帳。

修法：`LeaveRequestService::quotaErrorFor()` 依 `period_start` 是不是 null
分流（`summaryFor()` 在未滿六個月或沒填到職日時回 `emptySummary()`）——
**跟表單那一行用的是同一個訊號**，所以兩邊永遠一致。

新增 `leave.msg.quota_not_yet`：
「您目前尚未取得特休（受僱未滿六個月），這段期間可改申請病假或事假」
—— 順便告訴他能做什麼，不只是說不行。

## 實測

| 檢查 | 結果 |
|---|---|
| Handler 回的 `message` | 「特休額度不足，目前只剩 0 小時」✅ |
| 多欄位同時錯 | `message` 取第一句，`errors` 兩個都在 ✅ |
| 沒填到職日 | 「尚未取得特休」✅ |
| 到職 2 個月 | 「尚未取得特休」✅ |
| 到職 7 個月 | 「只剩 36 小時」（3 日 × 12 時）✅ |
| 到職 3 年 | 「只剩 288 小時」（14 日本期 ＋ 10 日遞延）× 12 時 ✅ |
| 三語 | 都有 `quota_not_yet` ✅ |

## 動到的檔案

| 檔案 | 說明 |
|---|---|
| `app/Exceptions/Handler.php` | 覆寫 `invalidJson()` ← 源頭 |
| `app/Http/Controllers/Admin/LeaveRequestController.php` | 單獨接 `ValidationException`；`??` 換 `Arr::get` |
| `app/Services/LeaveRequestService.php` | `quotaErrorFor()` 分流兩種訊息 |
| `resources/lang/{tw,cn,en}/leave.php` | `quota_not_yet` |

## 相關

- [[../features/leave-quota]] — 特休額度
- [[2026-11-05-tenure-diff-skips-a-day]] — 同一天的另一個「兩份各自維護」
