# 固定上下班時間（不排班的人也要算出勤）

> **狀態：已實作（2026-10-12，v2.85）。正式機要先跑 migration。**
>
> ⚠ **migration 沒跑之前帳號管理頁與打卡會直接壞掉** ——
> `UserRepository::LIST_COLUMNS` 已經加了那五個欄位，表上還沒有的話
> 每一次 User 查詢都是 SQL error。這是刻意的（不做相容層），
> 但上線順序不能顛倒。

## 需求（2026-10-12）

> 我現在有設定班別時間，但現在有個例外，我工程是沒有在排班內的，那我也要記錄他
> 上班時間、曠工、遲到、請假、早退、加班這些。我的想法是，在我帳號管理這邊可以
> 設定每個人的上下班時間，如果是輪班就寫輪班，如果是固定時間就用固定時間，
> 輪班就是吃原本的排班，如果是固定時間的話，如果是客服，就要顯示在排班紀錄，
> 如果是工程，就不顯示在排班紀錄。

## 現況：工程現在是「打了卡等於沒打」

出勤的四個判定**全部**由 `shift_assignments`（排班指派）驅動，沒有排班就什麼都算不出來：

| 判定 | 位置 | 沒有排班時 |
|---|---|---|
| 遲到 | `AttendanceService::clockIn()` → `calcLateMinutes($assignment->shift->start_time)` | `$assignment` 是 null，`late_minutes = 0`、狀態停在「未完成」 |
| 早退／加班 | `clockOut()` → `calcEarlyLeaveAndOvertime($assignment->shift->end_time)` | 同上，兩個都 0，狀態一律「正常」 |
| 曠工 | `MarkAbsentCommand`（每天 01:00） | 它遍歷的是**當天的 assignments**，沒排班的人根本不在迴圈裡，永遠不會被標曠工 |
| 排班名單 | `UserRepository::getCsUsers()` | 只收 `level = CS`，工程本來就進不去 |

請假與報表則**不需要改**：

- `leave_request` 不綁 assignment，工程現在就能請假
- 打卡報表 `AttendanceService::getReport()` 是「有打卡紀錄的人就進來」，
  工程只要開始有紀錄就會自動出現在報表與出勤明細裡

所以要做的事，是替「沒有 assignment 的人」補上一組標準上下班時間。

## 設計

### 1. `user` 表加四個欄位

```
work_mode        tinyint(4)    1 = 輪班（吃排班，預設）  2 = 固定時間
work_start_time  time          null，固定班才填
work_end_time    time          null，固定班才填。<= start_time 就是跨日班
work_days        varchar(20)   null，固定班才填。'1,2,3,4,5' = 週一到週五
show_in_shift    tinyint(1)    預設 0。固定班要不要出現在排班紀錄（只對固定班有意義）
```

⚠ **`work_days` 不能省。** 曠工是「該上班卻沒打卡」，沒有這一欄就不知道
週六日算不算 —— 不填的話固定班的人每個週末都會被標一天曠工。
輪班的人不需要它（有排班的那天才要上班，這件事 assignment 已經回答了）。

⚠ 放 `user` 表而不是開新表：固定班就是一組起訖加幾個星期，
一對一的資料開一張表只是多一次 join。

### 2. 抽一個共同入口：`resolveWorkTime($userId, $date)`

現在 `clockIn()`、`clockOut()`、`MarkAbsentCommand` 各自去拿 assignment。
改成三處都問同一支：

```php
// 回傳 null = 這天不用上班（輪班沒排到、或固定班不在 work_days 裡）
[
    'start'         => '09:00:00',
    'end'           => '18:00:00',
    'assignment_id' => 12 | null,   // 固定班沒有 assignment
    'is_fixed'      => false,       // 出勤明細「班別」欄要顯示哪一種
    'is_overnight'  => false,       // end <= start。既有的跨日計算吃這個
]
```

⚠ **不要在三個地方各寫一次 if（有排班用排班、沒有就看固定班）。**
那是同一條規則的三份拷貝，改一邊忘另一邊的典型形狀 ——
這個專案已經有過一次（求助單的結案規則散在三支送訊息的函式裡，
見 [[2026-10-11-ignore-open-tickets-scope]]）。

既有的 `calcLateMinutes()` / `calcEarlyLeaveAndOvertime()` / `calcEarlyClockInOvertime()`
吃的都是 `HH:mm:ss` 字串，**不必改** —— 換掉的只是那幾個字串從哪來。

### 3. 曠工：`MarkAbsentCommand` 多跑一輪

現在的迴圈是「當天所有 assignments」。固定班的人不在裡面，要另外跑：

```
固定班 ＋ 當天的星期在 work_days 裡 ＋ 在職 ＋ 沒有打卡紀錄 ＋ 沒請整天假
  → 建一筆 status = ABSENT、assignment_id = null 的紀錄
```

⚠ 「在職」要看 `status != DEACTIVATE` **與** `resigned_at`，
否則離職的人會一路被標曠工到天荒地老。

### 4. 排班紀錄：合成顯示，不寫實體 assignment

固定班的客服要出現在排班紀錄上。做法是**查詢時合成一筆虛擬的班**，
不在 `shift_assignments` 寫實體列。

> **為什麼不乾脆每天自動產生 assignment（那樣所有既有功能都不用改）：**
>
> 1. assignment 要指向一個 `shifts` 的班別，而固定班是**每個人的時間都可能不同** ——
>    等於要為每個人建一個 shift，而 `shifts.name` 是 UNIQUE，那張表會被塞成人數那麼多筆
> 2. 固定班的人不該出現在**換班／代班**的選單裡（他沒有班可以換），
>    寫了實體列就會跑進去
> 3. 改一次設定（換時間、改上班日、轉成輪班）就要回頭補或刪掉一整排未來的列，
>    而那些列可能已經被請假、代班引用了

顯示與否的判準：

判準是帳號上的 `show_in_shift` 開關，**不是 `level`**（需求方 2026-10-12）：

| | `show_in_shift = 1` | `show_in_shift = 0` |
|---|---|---|
| `work_mode = 輪班` | 照現況（有實體 assignment，一定顯示） | 同左 —— 這個開關對輪班沒有意義 |
| `work_mode = 固定` | **合成一筆顯示**「固定 09:00–18:00」 | **不顯示**（工程預設走這格） |

### 5. 出勤明細與報表的「班別」欄

固定班的紀錄 `assignment_id` 是 null，班別欄現在會是空的。
改成顯示「固定 09:00–18:00」（跟排班紀錄那筆合成的寫法一致）。

報表本身不用改 —— 它只看打卡紀錄，工程有紀錄就會自己進來。

### 6. 帳號管理的編輯表單

新增「出勤模式」一組欄位（`UpdateAccountRequest` / `StoreAccountRequest`
各補一份驗證）：

- 出勤模式：輪班／固定時間（radio）
- 選「固定時間」才展開：上班時間、下班時間、上班日（週一～週日的 checkbox）
- 時間用 `window.TimeSelect` 兩個下拉，**不要原生 `<input type="time">`** ——
  12/24 小時制由瀏覽器 locale 決定，會跟系統其他地方的 `13:05` 對不起來
  （理由見 [[scheduling]]）

## 需求方的決定（2026-10-12）

1. **上班日每個人自己勾，預設週一～週五。**
2. **國定假日照算。** 不做行事曆表 —— 國定假日固定班的人沒打卡**就是曠工**，
   那天不用上班的話走請假。
   > ⚠ 這代表每逢連假要有人替固定班的同仁送假單，否則報表上會多幾天曠工。
   > 哪天覺得煩了，再補一張「不用上班的日期」表（所有固定班共用），
   > 判定的位置已經收斂在 `resolveWorkTime()` 一支，加進去只會動那裡。
3. **顯示在排班紀錄用獨立開關，不綁 `level`。** 帳號上多一個勾選，
   跟客服／工程脫鉤 —— 哪天有「固定班但不想出現在排班表」的客服也接得住。
   ⚠ 這個開關**只對固定班有意義**：輪班的人有實體 assignment，本來就一定顯示。
4. **跨日固定班要支援**（例如 22:00–06:00）。`end_time <= start_time` 就是跨日。
   ⚠ `work_days` 指的是**開始上班那天**：勾了週五的 22:00–06:00，
   指的是週五晚上上班、週六清晨下班，不是週六晚上。曠工也在開始那天判。
   ⚠ 這一塊歷史上出過兩次 bug（[[2026-08-07-overnight-shift-clock-out]]、
   [[2026-09-30-amend-overnight-clock-out]]），接過來時要走既有那條路，
   不要另外寫一份跨日計算。

## 仍然不做

**固定班的人不能申請換班／代班** —— 他沒有班可以換。那兩個選單維持只收
有 assignment 的人。

## 實作時才發現的三件事

### 一、同一條規則其實散在**四個**地方，不是三個

設計時列了遲到／早退／加班／曠工。實作時才發現 `ClockAmendmentService`
（補打卡）有**第二套完整的計算**，四支方法全部綁 `$record->assignment->shift`，
而且各自寫了一次「`00:00` 代表到午夜」的換算。固定班的人補打卡一樣算不出
遲到早退 —— 跟正常打卡同一個病根。那四支現在都走 `resolveWorkTime()`。

出勤明細的 Blade 裡還有**第五份**（提早上班幾分、延後下班幾分是在 `@php`
區塊裡算的）。那一份改成吃 Controller 準備好的 `$workTimes`。

### 二、`end_minutes` 要在一個地方算好，不要讓每個呼叫端自己認跨日

既有班別用 `00:00` 表示「到午夜」，而固定班可以設到隔天任何時刻
（22:00–06:00）。如果讓每支計算自己判斷，就會像 `ClockAmendmentService`
那樣在兩個方法裡各寫一次 `if ($endMin === 0) { $endMin = 1440; }`，
而且那段**蓋不住 06:00 這種寫法**。

所以 `buildWorkTime()` 一律回「從上班那天午夜起算」的分鐘數：
18:00 → 1080、00:00 → 1440、跨日的 06:00 → 1800。後面的計算只管相減。

### 三、`UserRepository::LIST_COLUMNS` 是這個功能的單點故障

`resolveWorkTime()` 拿到的 User 就是 `find()` 回來的那個。少了 `work_mode`，
`isFixedWorkMode()` 會**安靜地一律回 false** —— 固定班的人又變回什麼都算不出來，
而且不會有任何錯誤訊息。那個常數上面原本就有一段警告說這個專案為
「select 漏欄位」修過好幾次（[[2026-09-30-on-duty-reply-time]]），這次差點又踩。

## 已知限制

**跨日班在午夜之後才打上班卡算不出遲到**（22:00 的班拖到 00:30 才打）。
`now()` 的 00:30 比上班時間 22:00 小，相減是負數；而且那時已經換日，
`resolveWorkTime()` 查的是新的一天（`work_days` 記的是開始那天），
根本拿不到前一晚那組時間。

⚠ **這不是這次改出來的** —— 輪班的跨日班本來就是如此。要修得連
「往前一天找還沒結束的跨日班」一起做，不在這次範圍。

## 異動檔案

```
database/migrations/2026_10_12_000003_add_work_mode_to_user_table.php  五個欄位
config/constants.php                                 USER.WORK_MODE
app/Models/User.php                                  casts、PHPDoc、isFixedWorkMode()、workDayList()
app/Repositories/UserRepository.php                  LIST_COLUMNS 補五欄；getFixedWorkModeUsers()
app/Services/AttendanceService.php                   resolveWorkTime() / buildWorkTime() /
                                                     workTimesForRecords()；clockIn、clockOut 改走它；
                                                     三支 calc 改吃分鐘數
app/Services/ClockAmendmentService.php               四支計算改走 resolveWorkTime()；兩份合成一份
app/Console/Commands/MarkAbsentCommand.php           第二輪：固定班
app/Services/ShiftService.php                        listFixedAssignments()（課表合成）
app/Services/AccountService.php                      workModeAttributes()
app/Http/Controllers/Admin/ShiftController.php       ajaxFixedAssignments()
app/Http/Controllers/Admin/AttendanceController.php  detail() 多傳 $workTimes
app/Http/Requests/ShiftAssignment/ListFixedAssignmentRequest.php  新增
app/Http/Requests/Account/UpdateAccountRequest.php   五個欄位的驗證
routes/web.php                                       shifts/ajax-fixed-assignments
resources/views/admin/accounts/index.blade.php       出勤模式表單 + 內嵌 JS
resources/views/admin/accounts/partials/row-actions.blade.php  編輯鈕的 data-*
resources/views/admin/attendance/detail.blade.php    班別欄與兩欄加班改吃 $workTimes
public/js/shifts.js                                  多抓一支合成排班；固定班色塊不可點
public/css/app.css                                   .tt-shift-fixed（虛線、不可點）
resources/lang/{tw,cn,en}/account.php                出勤模式文案、星期、三句驗證訊息
resources/lang/{tw,cn,en}/shift.php                  fixed_shift_name、range_reversed
config/changelog.php                                 v2.85
```

**沒有動的**：`StoreAccountRequest`（新增帳號只收帳號／暱稱／密碼，連 `level`
都是建完再編輯，出勤模式吃 DB 預設值＝輪班）、打卡報表與週月報
（它們只看打卡紀錄，工程有紀錄就自己進來了）。

## 相關

- [[attendance]] — 打卡出勤
- [[scheduling]] — 排班（輪班那一半）
- [[attendance-report-notice]] — 打卡週報／月報（不用改，但數字會因此涵蓋工程）
- [[report-page]] — 後台打卡報表
- [[staff-manage]] — `level` 的定義
