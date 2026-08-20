# 打卡（出勤）

## 現況

已完成基礎功能，持續迭代中。

## 需求（已確認）

- **打卡標記**：遲到、早退、加班（與排班比對計算，以分鐘為單位）
- **查看權限**：
  - Admin：查看全體員工的出勤紀錄
  - 員工帳號：只看得到自己的上班情形
- **打卡方式**：目前暫定只記錄時間戳記，後續規劃加入定位與 IP 限制

## 已完成功能

### 核心
- 上下班打卡（記錄 IP）
- 遲到/早退/加班分鐘數自動計算
- 管理者月報表（全體員工彙整）
- 個人出勤明細頁（按月查詢）
- 補打卡申請與審核
- 請假資訊整合顯示

### 出勤明細頁 (`/admin/attendance/detail/{userId}`)
- 統計卡片：出勤天數、正常、遲到、早退、缺勤、加班分鐘
- 每日明細表含：日期、班別、上下班時間、遲到/早退/加班分鐘、狀態、請假、IP
- 補打卡標記（「補」badge）
- 月份切換（flatpickr month picker）
- 頁面標題顯示目標使用者暱稱
- 日期排序：月底到月初（降序）

### 個人出勤頁（我的出勤 tab，JS 渲染）
- 統計卡片：出勤天數、遲到、早退、加班
- 每日明細表含：日期、班別、上下班時間、遲到/早退/加班分鐘、狀態、請假
- 桌面版表格 + 手機版卡片雙版面
- 月份切換（上個月/本月）

### 相關檔案
- Controller: `app/Http/Controllers/Admin/AttendanceController.php`
- Service: `app/Services/AttendanceService.php`
- Repository: `app/Repositories/AttendanceRepository.php`
- Model: `app/Models/AttendanceRecord.php`
- Views: `resources/views/admin/attendance/index.blade.php`, `detail.blade.php`

## 待釐清

- （已全部釐清）
