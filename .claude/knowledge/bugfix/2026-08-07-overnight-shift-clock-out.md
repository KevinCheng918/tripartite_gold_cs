# Bug 修復：跨日班（晚班）下班打卡顯示遲到又早退

## 問題描述

晚班（如 16:00–00:00）員工在凌晨 12 點後打下班卡，系統判定為「遲到 + 早退」。

## 根因

1. `clockOut()` 用 `now()->format('Y-m-d')` 查紀錄，跨日後找不到昨日的打卡紀錄
2. `calcEarlyLeaveAndOvertime()` 在 endTime 為 `00:00` 時把 endMinutes 設為 1440，但跨日後 nowMinutes 是很小的值（如 5），導致 `diff = 5 - 1440 = -1435`，被判定為 1435 分鐘早退
3. `getTodayRecord()` 同樣無法回傳昨日未完成的打卡紀錄

## 修復內容

### 異動檔案

| 檔案 | 變更 |
|------|------|
| `app/Services/AttendanceService.php` | `clockOut()` 今日找不到紀錄時往前查昨日未完成紀錄；`calcEarlyLeaveAndOvertime()` 新增 `$isOvernight` 參數，跨日時 nowMinutes += 1440；`getTodayRecord()` 也回傳昨日未完成紀錄；`clockIn()` 防止昨日未下班就打新上班卡 |
| `resources/lang/tw/attendance.php` | 新增 `msg.previous_not_clocked_out` |
| `resources/lang/cn/attendance.php` | 同上（簡體） |
| `resources/lang/en/attendance.php` | 同上（英文） |
