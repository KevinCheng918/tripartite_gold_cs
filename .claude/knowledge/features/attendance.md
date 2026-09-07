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

### 日期一律標註星期

出勤明細、我的出勤、補打卡申請與審核（含審核確認彈窗）的日期都顯示成
`2026-09-08 (二)`，桌機表格與手機卡片皆同。

兩支輸出格式必須一致的共用函式，**改一邊就要改另一邊**：

| 用途 | 位置 |
|------|------|
| 前端（JS 渲染） | `window.withWeekday()` — `public/js/common.js`，全站已由 layout 載入 |
| 後端（Blade 初始渲染） | `App\Presenters\DatePresenter::withWeekday()` |

注意事項：

- JS 版刻意用 `new Date(y, m-1, d)` 拆組件建立，**不可**改成 `new Date(dateStr)` —
  後者會把 `2026-09-08` 當成 UTC 午夜，負時區會倒退一天而算出錯誤的星期
- 星期名稱**寫死中文未走語系檔**。JS 讀不到 PHP 語系檔，而
  `layouts/app.blade.php` 早有一份寫死的 `['日','一',…]`；
  若 PHP 走語系、JS 寫死反而更容易不同步。要三語系化需另外把語系傳進 JS
- `data-date`、`amendLookup` 的 key、跨日比較等**非顯示用途**的日期必須維持純
  `Y-m-d`，加了星期會送壞 API 或比對失敗

### 相關檔案
- Controller: `app/Http/Controllers/Admin/AttendanceController.php`
- Service: `app/Services/AttendanceService.php`
- Repository: `app/Repositories/AttendanceRepository.php`
- Model: `app/Models/AttendanceRecord.php`
- Presenter: `app/Presenters/DatePresenter.php`
- Views: `resources/views/admin/attendance/index.blade.php`, `detail.blade.php`
- JS: `public/js/attendance.js`, `public/js/attendance-detail.js`, `public/js/common.js`

## 待釐清

- （已全部釐清）
