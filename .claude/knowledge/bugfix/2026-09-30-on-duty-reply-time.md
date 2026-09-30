# 內部支援群組的提醒 tag 到不該負責的人

## 現象

求助單超時沒人回，系統 tag 當班客服 —— 但 tag 到的是「現在有上班的所有人」，
不是「現在負責回訊的那個人」。

## 根因

`isTimeInShiftRange()` 的寫法本身是對的，它會優先用回訊時間：

```php
$start = $shift->reply_start_time ?? $shift->start_time;
```

問題出在 **`ShiftAssignment::shift()` 關聯的 select 漏了那兩個欄位**：

```php
return $this->belongsTo(Shift::class)->select(['id', 'name', 'display_name', 'start_time', 'end_time']);
```

`reply_start_time` 永遠讀到 `null` → `??` 每次都 fallback 到上下班時間。
**不會報錯，只會悄悄 tag 錯人。**

班表的實際資料讓後果很明顯 —— 上班時間都是 12 小時、彼此大量重疊：

| 班別 | 上班 | 回訊 |
|---|---|---|
| 早班 | 08:00–20:00 | 08:00–14:00 |
| 午班 | 10:00–22:00 | 13:00–19:00 |
| 晚班 | 12:00–00:00 | 18:00–00:00 |
| 大夜班 | 22:00–10:00 | 23:00–09:00 |

| 時間 | 修正前 tag 到 | 修正後 |
|---|---|---|
| 09:00 | 早班、大夜班 | **早班** |
| 15:00 | 早班、午班、晚班 | **午班** |
| 19:30 | 早班、午班、晚班 | **晚班** |
| 23:30 | 晚班、大夜班 | 晚班、大夜班（回訊時段真的重疊，正確） |
| 05:00 | 大夜班 | 大夜班 |

## 修法

1. 關聯的 select 補上 `reply_start_time` / `reply_end_time`
2. `??` 改成 `filled()` 判斷 —— 欄位沒填時可能是**空字串**而不是 null，
   `??` 不會 fallback，`explode(':', '')` 會算出 00:00，變成整天都在班
3. 時間換算抽成 `timeToMinutes()`，順便用 `Arr::get()` 避免 `$parts[1]` 不存在

## 連帶影響（都是往好的方向）

`isTimeInShiftRange()` 有兩個呼叫點，兩個都跟著變準：

- `getOnDutyAssignments()` → `getOnDutyUserIds()` → **求助單超時的提醒 tag**
- `autoAssignOnDuty()` → **新對話自動指派給客服**

指派也改成看回訊時段，那本來就是對的 —— 誰負責回訊就指派給誰。

## 教訓

> ⚠️ **在關聯上寫 `select()` 時，想清楚「所有用到這個關聯的地方」需要哪些欄位。**
> 漏掉的欄位讀出來是 `null`，配上 `??` 或 `?:` 的預設值就變成靜默降級 ——
> 功能還在跑，只是跑錯，而且不會有任何錯誤訊息。
>
> 同一類的坑：`AttendanceRepository::LIST_COLUMNS` 必須含 `assignment_id`，
> 少了它 `$record->assignment` 永遠是 null，早退與加班會全部算成 0
> （見 [[2026-09-30-amend-overnight-clock-out]]）。

## 異動檔案

- `app/Models/ShiftAssignment.php` — 關聯的 select
- `app/Services/TelegramChatService.php` — `isTimeInShiftRange()`、新增 `timeToMinutes()`

## 相關

- [[auto-reply]] — 求助單的超時提醒
- [[scheduling]] — 班別的回訊時間欄位
- [[telegram-chat]] — 群組自動指派
