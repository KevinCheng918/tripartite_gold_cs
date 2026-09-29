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

### 補打卡的日期與時間用原生 input（2026-09-29）

原本是 flatpickr 加 `disableMobile: true`。那個參數等於**強制手機也用 flatpickr
自繪的 UI** —— 時間選擇器在手機上是一組很小的數字配上下箭頭，手指幾乎按不準；
而且 flatpickr 預設把 input 設成 `readonly`，連直接打字都不行。

改用原生 `<input type="date">` / `<input type="time">`：

- 手機跳系統的滾輪選擇器，桌機可以直接輸入
- 值的格式固定（`YYYY-MM-DD` / `HH:mm`），**正好是後端 `date_format:H:i` 要的**，不必轉
- 零依賴，不用再同步 flatpickr 的值

> **月份選擇器（`report-month-picker`、`detail-month-picker`）要留著 flatpickr。**
> 原生沒有「只選月份」這種控制項，拿掉會退化成完整的日期選擇器。
> 這也是 `disableMobile: true` 在那裡仍然正確的原因。

兩個配套：

- **CSS 要自己補**（`public/css/app.css`）。這兩種 input 的內部是瀏覽器畫的，
  Bootstrap 的 `.form-control` 蓋不到：iOS 上內容會靠上、整體比其他欄位矮，
  要補 `min-height` 與 `-webkit-appearance: none`（**不會**關掉原生選擇器）。
  深色模式要加 `color-scheme: dark` —— 日曆／時鐘圖示與展開的面板都是瀏覽器畫的，
  只改 `color` 沒有用，不加會在深色背景上跳出一個白底的日曆。
- **`max` 要在每次開啟時重設**。頁面開著跨日的話，昨天算出來的 max 會擋掉今天。
  算今天用 `todayString()` 拆組件組出來，**不可以用 `toISOString()`** ——
  那是 UTC，台灣時間早上八點前會算成昨天。

順手補了後端驗證：`date` 加上 `before_or_equal:today`。
補的是「已經發生但沒打到」的卡，未來日期沒有意義，而前端的 `max` 擋不住直接打 API。

> ⚠️ 排班頁（`public/js/shifts.js`）還有 **15 處**同樣的
> `disableMobile: true` 時間／日期選擇器，手機上一樣難按。尚未處理。

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
