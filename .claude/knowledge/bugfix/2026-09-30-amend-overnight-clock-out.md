# 晚班補下班卡，核准後沒有生效

## 現象

晚班（例如 16:00–00:00）的人忘了打下班卡，事後申請補打卡。
他在凌晨 00:00 下班，所以日期選了**隔天**——
主管核准了，但那筆出勤紀錄的下班時間還是空的。

## 根因

出勤紀錄的模型是：**`date` 掛在班次開始的那一天，`clock_out` 存實際下班的時刻**。
晚班 10/01 上班、10/02 00:00 下班，那筆紀錄的 `date` 是 **10/01**。

但 `applyAmendment()` 只查申請日期那一天：

```php
$record = $this->attendanceRepository->findByUserAndDate($amendment->user_id, $dateStr);

if (!$record || !$record->clock_in) {
    Log::warning('補下班卡但無上班紀錄', …);
    return;   // ← 這張單就默默作廢了
}
```

申請日期是 10/02 → 查不到紀錄（那天根本沒上班）→ 只寫一行 log 就結束。

**兩種選法都不會對**：

| 使用者選 | 結果 |
|---|---|
| 隔天 10/02 | 查不到紀錄，申請作廢 |
| 當天 10/01 | 找得到紀錄，但 `clock_out` 變成 10/01 凌晨 —— 比上班時間 16:00 還早 |

而且就算找到紀錄，`calcEarlyMinutes()` 也算錯：
晚班 `end_time` 是 `00:00`（已被修正成 1440），補的時間 `00:00` 卻只有 0 分 ——
`1440 - 0 = 1440`，判定**早退整整一天**。

## 修法

跟正常打卡的 `AttendanceService::clockOut()` 對齊，那邊 2026-08-07 就修過
同一個坑（見 [[2026-08-07-overnight-shift-clock-out]]）。

1. **找不到申請日期的紀錄時，往前一天補查一次**，
   而且只接手「上班了但還沒下班」的那筆（`filled(clock_in) && blank(clock_out)`）——
   已經完成的不要覆蓋。
2. 接手前一天的紀錄時標記 `$isOvernight`，
   `calcEarlyMinutes()` / `calcOvertimeMinutes()` 把打卡分鐘數 **+1440**
   （抽成 `clockMinutes()`）。
3. `clock_out` 仍然存**申請日期**的那個時刻（隔天凌晨），
   紀錄的 `date` 維持前一天 —— 跟 `clockOut()` 存 `now()` 的模型一致。

## 驗證

晚班 16:00–00:00：

| 補的時間 | 早退 | 加班 |
|---|---|---|
| 隔天 00:00 | 0 | 0 |
| 隔天 00:15 | 0 | 15 |
| 當天 23:30 | 30 | 0 |
| 當天 00:00（沒有跨日旗標，舊行為） | **1440** | 0 |

日班 09:00–18:00 的 17:30 / 18:00 / 19:00 結果不變（30 / 0 / 0 早退、0 / 0 / 60 加班）。

## UI

補打卡表單的日期欄位下加了說明，**直接舉晚班的例子** ——
那是唯一會搞混的情況，講抽象規則不如把兩張卡都列出來：

```
請選「實際打卡」的那一天，不是班表上的日期。

  例：晚班 16:00 ～ 00:00，10/01 的班
  上班卡 → 10/01 16:00
  下班卡 → 10/02 00:00（隔天凌晨，系統會自動算回 10/01 的班）
```

後端雖然會自己往前找，但先講清楚可以少一次來回。
語系拆成四個 key（`amend_date_hint` + `amend_date_example_*`），
排版由 blade 控制，翻譯的人不必處理 `<br>`。

## 注意

> `findByUserAndDate()` **沒有** eager load `assignment.shift`，
> 所以 `$record->assignment->shift` 是 lazy load（兩次查詢）。
> 這裡是單筆、不在迴圈裡，不算 N+1，所以沒動 ——
> 那支被 `clockIn()` / `clockOut()` 共用，加了 eager load 反而讓
> 不需要班別的呼叫端多做兩次查詢。
>
> 但 `LIST_COLUMNS` **必須包含 `assignment_id`**，少了它關聯永遠是 null，
> 早退與加班會靜悄悄地全部算成 0。

## 異動檔案

- `app/Services/ClockAmendmentService.php` — `applyAmendment()` 往前查、`clockMinutes()`、兩支計算加 `$isOvernight`
- `resources/views/admin/attendance/index.blade.php` — 日期欄位的說明
- `resources/lang/{tw,cn,en}/attendance.php` — `amend_date_hint`

## 相關

- [[attendance]] — 打卡與補打卡
- [[2026-08-07-overnight-shift-clock-out]] — 正常打卡的同一個坑
