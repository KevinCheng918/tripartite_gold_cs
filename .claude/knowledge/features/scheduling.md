# 排班功能

## 現況

已實作。

## 需求（已確認）

- **班別**：固定早/中/晚班三種時段
- **時段管理**：Admin 可調整各班別的起訖時間，員工帳號不可調整
- **無審核流程**：排班不需要審核，直接生效
- **報班**：員工可自行報班（選擇要上哪個班）
- **換班**：員工可與其他員工互換班別
- **不可取消**：報班後不能取消
- **與打卡比對**：
  - 排班時段沒上班 → 曠工（以分鐘計算）
  - 非排班時段打卡上班 → 加班時數（以分鐘計算）

## 架構

- **Schema**：`shifts`（班別定義，含 name/display_name/start_time/end_time/is_active/sort）、`shift_assignments`（報班紀錄，unique: user_id + date）、`shift_swaps`（換班紀錄，status: 0=待確認/1=已同意/2=已拒絕）
- **權限 keywords**：`shift.view`、`shift.update`（Admin 調整時段）、`shift.assign`（報班）、`shift.swap`（換班）

## 分層檔案

```
app/Models/{Shift,ShiftAssignment,ShiftSwap}.php
app/Repositories/{ShiftRepository,ShiftAssignmentRepository}.php
app/Criteria/ShiftAssignment/{AssignmentDateRangeCriteria,AssignmentUserCriteria,AssignmentShiftCriteria}.php
app/Services/ShiftService.php
app/Http/Controllers/Admin/ShiftController.php
app/Http/Requests/Shift/UpdateShiftRequest.php
app/Http/Requests/ShiftAssignment/{StoreAssignmentRequest,SwapRequest,RespondSwapRequest}.php
app/Http/Resources/{ShiftResource,ShiftAssignmentResource,ShiftSwapResource}.php
database/migrations/2026_07_23_00000{1,2,3}_create_{shifts,shift_assignments,shift_swaps}_table.php
database/seeders/ShiftSeeder.php
resources/views/admin/shifts/index.blade.php
resources/views/components/modal.blade.php
resources/js/admin/shifts.js
resources/lang/{tw,cn,en}/shift.php
```
