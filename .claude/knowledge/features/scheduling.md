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

### 時間最後不是原生 input，是兩個下拉

換成原生 `<input type="time">` 之後發現它**顯示 12 還是 24 小時制由瀏覽器／
系統的 locale 決定，網頁端控制不了**。客服看到「下午 01:05」、系統其他地方
寫「13:05」，很容易看錯。

> ⚠️ **`lang="en-GB"` 沒有用，不要再試一次。** 已經實測過：
> HTML 確實輸出了那個屬性，但 Chromium 的 time input 看的是**瀏覽器的 UI 語言**，
> 不吃元素的 `lang`。iOS Safari 更是只跟著裝置的「24 小時制」開關走。

所以時間欄位改成自己畫的**「時」「分」兩個 `<select>`**（`window.TimeSelect`，
在 `public/js/common.js`）：

- 選項固定是 `00`～`23` 與 `00`～`59`，**24 小時制 100% 可控**，不看任何系統設定
- 手機上 select 是系統原生的底部滾輪，比 flatpickr 那組小箭頭好按得多
- 日期欄位維持原生 `<input type="date">` —— 日期沒有 12/24 小時制的問題

用法是在原本的 input 標 `class="js-time-select"`，元件會把它轉成 `hidden`
並在後面插入兩個下拉。**id 與 value（`HH:mm`）都維持原樣**，所以既有的
`getElementById(id).value` 讀取完全不用改，後端那排 `date_format:H:i` 也不用動。

> ⚠️ **用程式設值必須走 `TimeSelect.set(id, value)`。**
> 直接改 `input.value` 兩個下拉不會跟著動，畫面會停在上一次開啟時的選擇。
> `set()` 自己會處理後端帶秒的格式（`08:00:00` → `08:00`）。

三個實作上的必要條件：

- **空選項要留著。** 沒有它，下拉一渲染就等於已經選了 `00`，
  使用者沒碰過的欄位會被當成 `00:00` 送出去。
- **`required` 要從 input 搬到兩個 select 上。** hidden input 不參與 HTML5 驗證，
  留在上面的話表單會在一個看不見的欄位上報錯，而且沒有任何提示。
- **`change` 事件要 `bubbles: true`。** 請假時長那類計算是掛在 `document` 上的
  事件委派，不冒泡的話它永遠收不到。

### 兩個 CSS 的坑

> ⚠️ **`color-scheme: dark` 與 `filter: invert(1)` 不能並存。**
> shifts blade 原本用 `invert(1)` 把日曆圖示翻白，但 `color-scheme: dark`
> 已經讓瀏覽器把圖示畫成白的，再 invert 一次會翻回黑色，深色背景上反而看不見。
> 已移除 blade 裡那段，統一在 `public/css/app.css` 用 `color-scheme` 處理。

> ⚠️ **app.css 的規則只設 `color-scheme`，不要設顏色。**
> 加上 `[type="..."]` 之後特異性比
> `[data-theme="dark"] .modal-content .form-control` 還高，一旦指定 background，
> 同一個表單裡的日期欄位就會跟隔壁的文字欄位變成兩種底色。
> 顏色由 `public/css/custom.css` 的全域 `.form-control`（帶 `!important`）統一管。
> 時分下拉本身是 `.form-select`，同樣吃那份全域樣式。

> ⚠️ **`.ts-select` 的 `font-size` 不可以小於 16px。**
> iOS Safari 在聚焦字級小於 16px 的表單元件時，會自動把整個頁面放大，
> 使用者得手動縮回去。現在設 `1.125rem`（18px）——
> **展開後是一長串純數字（時 24 項、分 60 項），選單裡每一項的字級是跟著
> select 走的**，所以放大這裡就等於放大整份選單，掃讀會輕鬆很多。
> 另外加了 `font-variant-numeric: tabular-nums`，個位數與十位數才不會左右跳動。

> ⚠️ **深色模式下 `.ts-select` 要加 `color-scheme: dark`。**
> 展開的選單是瀏覽器畫的原生 popup，CSS 選不到它的內容 ——
> 不加的話深色背景上會彈出一片白底的清單。
> 特異性要贏過 `custom.css` 的 `.form-select`（那裡有 `font-size: 1rem`），
> 用 `.ts-wrap .ts-select` 兩層剛好夠，而且 app.css 本來就排在 custom.css 後面。

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
