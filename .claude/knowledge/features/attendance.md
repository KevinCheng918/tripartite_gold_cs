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

日期改用原生 `<input type="date">`（手機跳系統滾輪、桌機可打字、值固定 `YYYY-MM-DD`）。

**時間沒有用原生 `<input type="time">`** —— 它顯示 12 還是 24 小時制由瀏覽器
locale 決定，網頁端控制不了，客服會看到「下午 01:05」而跟系統其他地方的
`13:05` 對不起來。改成「時」「分」兩個下拉（`window.TimeSelect`），
完整說明見 [[scheduling]] 的「時間最後不是原生 input，是兩個下拉」。

兩者的 value 都還是 `YYYY-MM-DD` / `HH:mm`，**正好是後端 `date_format:H:i` 要的**，不必轉。

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

排班頁（[[scheduling]]）的 12 個欄位也在 2026-09-29 一併換掉了，做法相同。

### 原因必填 + 本月次數提示（2026-09-30）

**原因從選填改成必填。** 補打卡等於事後修改出勤紀錄，主管要據此核准，
沒有原因就無從判斷。前端 `required` + 後端 `required|string|max:500`，
驗證訊息走語系（`msg.amend_reason_required` / `amend_reason_max`）——
這個專案沒有 `validation.php` 語系檔，不自己給訊息就會吐英文的
`The reason field is required.`。

**申請視窗裡顯示本月已補幾次**（`本月已申請補卡：上班卡 2 次、下班卡 1 次`）。

- 數字直接用「我的補打卡申請」那支 ajax 已經載好的 `myAmendData` 算 ——
  它撈的是**全部**申請（`getByUser()` 沒有限制筆數），當月的一定在裡面，
  不必為了這個數字再打一次後端
- **被駁回的不算**：那通常代表「其實沒忘記打卡」，算進來會誤導
- 兩種都是 0 就整塊不顯示 —— 第一次補卡的人不需要被提醒什麼
- 措辭刻意中性，這是提醒不是指責

### 跨日班的補打卡（2026-09-30）

> ⚠️ **動到補打卡或打卡的計算之前，先讀
> [[2026-09-30-amend-overnight-clock-out]] 與 [[2026-08-07-overnight-shift-clock-out]]。**
> 同一個坑在正常打卡與補打卡各踩過一次。

要記得的模型只有一句：

**出勤紀錄的 `date` 掛在班次開始的那一天，`clock_out` 存實際下班的時刻。**

晚班 16:00–00:00，10/01 上班、10/02 凌晨下班 —— 那筆紀錄的 `date` 是
**10/01**，但 `clock_out` 是 **10/02 00:00**。所以：

- 用申請日期查紀錄會查不到（10/02 那天根本沒上班），要往前一天補查
- 早退／加班的分鐘數要把跨日的打卡時間 **+1440**，
  否則晚班補 `00:00` 會算成早退 1440 分（整整一天）

### 相關檔案
- Controller: `app/Http/Controllers/Admin/AttendanceController.php`
- Request: `app/Http/Requests/Attendance/RequestAmendRequest.php`（申請補打卡）、
  `RespondAmendRequest.php`（審核）
- Service: `app/Services/AttendanceService.php`、`app/Services/ClockAmendmentService.php`
- Repository: `app/Repositories/AttendanceRepository.php`、`app/Repositories/ClockAmendmentRepository.php`
- Model: `app/Models/AttendanceRecord.php`、`app/Models/ClockAmendment.php`
- Presenter: `app/Presenters/DatePresenter.php`
- Views: `resources/views/admin/attendance/index.blade.php`, `detail.blade.php`
- JS: `public/js/attendance.js`, `public/js/attendance-detail.js`, `public/js/common.js`

> 補打卡的兩支端點 2026-09-30 從內聯 `$request->validate()` 抽成 Request 檔。
> **打卡本身（`ajaxClockIn` / `ajaxClockOut`）沒有輸入要驗證**，仍收
> `Illuminate\Http\Request`（只取 IP 與 User-Agent），那不是漏抽。
>
> `RequestAmendRequest` 的字數上限寫成常數 `REASON_MAX` ——
> `rules()` 與 `messages()` 都要用到，分開寫遲早改一邊漏一邊。
> `RespondAmendRequest` 的 `in:` 用 `ClockAmendment::STATUS_*` 組出來，
> 不寫死 `1,2`。

## 個人每日出勤狀況：每一天都要有一列（2026-10-14）

> 需求方：「這邊要把每天都列出來，如果請假，就標注請假就好，不要有缺少」

明細頁原本直接跑 `getMonthlyRecords()` —— 那是**出勤紀錄**的清單，
所以**沒有紀錄的那天整天消失**。

⚠ 最常見的就是**請整天假**：`MarkAbsentCommand` 對請整天假的人**刻意不建
曠工紀錄**（那是先前修「請假被記成曠工」時改的），於是那天連一列都沒有 ——
看起來就像漏掉了一天。

### 做法：另開一支 `getMonthlyTimeline()`

回傳每一天一列：`['date' => Carbon, 'record' => ?AttendanceRecord, 'leave' => ?array]`。

⚠ **`records` 與 `timeline` 兩個都要傳給 Blade**，用途不同：

| | 用途 | 為什麼不能共用 |
|---|---|---|
| `records` | 上面那排統計 | 統計只能算**真的有出勤紀錄**的那幾天；用時間軸算的話，`$records->count()`（總天數）會變成「這個月有幾天」 |
| `timeline` | 下面的表格 | 每一天都要有一列 |

### 沒有紀錄的那天顯示什麼

需求方 2026-10-26 兩次指正，最後是**四種情況**：

| 情況 | 狀態欄 | 班別欄 |
|---|---|---|
| 有核准的請假 | `請假` ＋ 請假欄顯示整天或時段 | 照排班 |
| 那天**不用上班**、固定班 | `休假` | `-` |
| 那天**不用上班**、輪班 | `未排班` | `-` |
| 那天**要上班卻沒有任何紀錄** | `無打卡紀錄` | **顯示班別名稱** |

### ⚠ 第一次指正：「沒班」不等於「休假」

> 「輪班的人是沒有休假的，所以個人每日出勤狀況這邊是不會有休假的，
> 只有固定班才有休假」、「輪班的國定假日也沒休」

**休假是固定班才有的概念** —— 固定班在帳號上設了上班日（`work_days`），
其餘那幾天才叫休假。輪班的人沒有固定休假日，也不放國定假日。

### ⚠ 第二次指正：該不該上班要**查**，不是用推的

> 「不能統一叫做未排班，有班表可以確認他當天有沒有排班和排什麼班」

我第一版改成「輪班一律標未排班」—— 還是錯的，因為**班表查得到他那天有沒有
排班、排的是什麼班**。沒去查就等於把「排了班卻沒打卡」跟「本來就沒排班」
混成同一種，而前者是**資料有問題**（`MarkAbsentCommand` 本來會補成曠工紀錄，
沒補成代表排程沒跑或紀錄被刪），蓋掉它等於把異常藏起來。

所以 `getMonthlyTimeline()` 多回一個 `duty`：

| 出勤模式 | 怎麼判斷那天該不該上班 |
|---|---|
| 輪班 | 查 `shift_assignments`（`getByUserAndDateRange()`），順便帶回**班別名稱** |
| 固定班 | 沿用既有的 `workTimeFromUser()`，`null` ＝ 那天不用上班 |

⚠ 固定班刻意**沿用 `workTimeFromUser()`** 而不另寫一份判斷 ——
同一條規則兩個地方各長各的，就是日後只改到一邊的開始。

⚠ **沒打卡的日子也要看得到排了什麼班**（需求方要求），所以班別欄不再是 `-`。

### ⚠ 一般化

**「推論得出來」不等於「說得對」，而「查得到」就不要推。**

「沒紀錄又沒請假 ⇒ 那天沒班」這個推論本身成立，但
(1) 從「沒班」跳到「休假」多了一個這個制度裡並不成立的假設；
(2) 明明有班表可以直接查，卻用推的 —— 推論只能告訴你「沒班」，
查表才知道「有沒有排、排了什麼、是不是該有紀錄卻沒有」。

### 當月只排到今天

還沒到的日子列出來只是一長串空列，而且會讓人以為那幾天也缺卡。
過去的月份則是整個月。

### 順手修掉的 N+1

`getMonthlyRecords()` 原本是**一筆紀錄查一次請假**（`getApprovedOnDate()`），
一個月就是 31 次查詢。改成 `getApprovedInRange()` 一次撈完整個月，
再在 PHP 攤成「每天一筆」。

⚠ 攤開時要**夾在查詢的月份內**：一筆請假可能 8/28 開始、9/2 結束，
不夾的話會往月份外寫。

### 驗證（本機）

- 2026-09（過去月份）→ **30 列**，只有 9/08 有紀錄，其餘 29 列標「休假」
- 2026-10（當月）→ **15 列**，最後一列是今天
- 請假展開（用 mock 的請假資料）：
  - 整天假連三天（9/10～9/12）→ 三天都標 ✓
  - 跨月假（8/28～9/2）→ 9 月的時間軸只出現 9/01、9/02 ✓
  - 時段假（9/20）→ 顯示 `09:00~12:00` ✓
- 整頁實際 `view()->render()`：30 列、29 個「休假」、不再出現「暫無資料」

## 待釐清

- （已全部釐清）
