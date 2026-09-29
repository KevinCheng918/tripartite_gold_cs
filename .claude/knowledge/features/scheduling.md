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

## 時間與日期選擇器（2026-09-29）

12 個欄位從 flatpickr 換成原生 `<input type="time">` / `<input type="date">`：
班別起訖、回訊時間（新增與編輯各一組）、代班起訖、換班的兩個日期。

原本都帶 `disableMobile: true` —— 那等於**強制手機也用 flatpickr 自繪的 UI**，
時間選擇器在手機上是一組很小的數字配上下箭頭，手指幾乎按不準；
而且 flatpickr 預設把 input 設成 `readonly`，連直接打字都不行。
原生的值固定是 `HH:mm` / `Y-m-d`，跟原本的 `dateFormat: 'H:i'` 一樣，
後端那排 `date_format:H:i` 不用動。

> **這兩個仍然是 flatpickr，不要順手改掉**：
> - `#assign-date` —— `mode: 'multiple'`，原生 date **不能多選**
> - 週次跳轉的日曆 —— 綁在 `#js-week-label` 這個**文字元素**上，根本不是 input
>
> 月份選擇器（出勤頁）同理，原生沒有「只選月份」這種控制項。
> 這幾個地方的 `disableMobile: true` 是對的，不是漏改。

### 12 / 24 小時制

原生 time 顯示成 `下午 01:00` 還是 `13:00`，**看 locale，沒有屬性可以直接指定**。
頁面的 `lang` 是 `zh-TW`，Chrome 會顯示「上午／下午」，跟系統其他地方寫的
`13:00` 對不起來，客服容易看錯。

每個 time input 掛 `lang="en-GB"`（24 小時制的 locale）解決：

| 環境 | 結果 |
|---|---|
| 桌機 Chrome / Edge | 24 小時制 ✅ |
| Android Chrome | 24 小時制 ✅ |
| iOS Safari | **不看 `lang`**，跟著裝置的「設定 → 一般 → 日期與時間 → 24 小時制」走 |
| Firefox | 依系統 locale |

`value` 送出去的**永遠是 24 小時制的 `HH:mm`**，只有顯示會變 —— 所以後端與
既有的 `.substring(0, 5)` 都不受影響。

### 兩個 CSS 的坑

> ⚠️ **`color-scheme: dark` 與 `filter: invert(1)` 不能並存。**
> shifts blade 原本用 `invert(1)` 把日曆圖示翻白，但 `color-scheme: dark`
> 已經讓瀏覽器把圖示畫成白的，再 invert 一次會翻回黑色，深色背景上反而看不見。
> 已移除 blade 裡那段，統一在 `public/css/app.css` 用 `color-scheme` 處理。

> ⚠️ **app.css 的規則只設 `color-scheme`，不要設顏色。**
> 加上 `[type="..."]` 之後特異性比
> `[data-theme="dark"] .modal-content .form-control` 還高，一旦指定 background，
> 同一個表單裡的時間欄位就會跟隔壁的文字欄位變成兩種底色。
> 顏色由 `public/css/custom.css` 的全域 `.form-control`（帶 `!important`）統一管。

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
