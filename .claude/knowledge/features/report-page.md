# 報表頁（內務管理 → 報表）

> **狀態：已完成**（2026-10-11，v2.71）

## 需求（2026-10-11）

> 「後台也要能看超時統計週報表跟月報表，然後把報表獨立一個頁面，
> 拉到 sidebar 內務管理裡面，然後區分 tab 看所有的報表」

需求方對四個問題的回答：

| 問題 | 回答 |
|---|---|
| 「所有的報表」包含哪些 | 打卡報表 ＋ 超時統計兩種 |
| 打卡出勤頁現有的「報表」分頁 | **搬到新頁面，舊的拿掉** |
| 超時統計要不要也能看日報 | 要，日／週／月都能看 |
| 權限怎麼切 | 兩個分頁各自一個 |

## 做出來的東西

```
內務管理
├─ 內勤管理
├─ 專案管理
├─ 報表            ← 新增
├─ 文件區
└─ 財務管理
```

| 分頁 | 權限 | 期間 |
|---|---|---|
| 打卡報表 | `report.attendance` | 週／月 |
| 超時提醒統計 | `report.remind` | 日／週／月 |

兩個分頁都是「期間切換 btn-group ＋ 上一期／下一期」，沒有日期選擇器 ——
報表看的是「已經過完的那一期」，翻頁比選日期直覺。

## 關鍵決定

### 1. 統計與排版拆開，兩邊走同一支

`RemindReportService` 原本是「查詢 → 直接組字串」，後台要表格就不能從字串反解。
中間切了一層：

```
collect($type, $range)   →  原始資料（tickets / users / names）
   ├─ buildText()        →  Telegram 用的字串
   └─ forPage()          →  後台用的結構化陣列（summary / by_ticket / by_user）
```

⚠ **兩邊一定要走同一支 `collect()`**。各查一次的話，後台顯示的數字跟主管在
Telegram 收到的那份會對不起來 —— 那種不一致最難查，因為兩邊各自都「對」。

打卡報表同理：`AttendanceReportService::forPage($range)`，內部走 `collect()`。

### 2. 打卡報表**不能**直接問 `AttendanceService::getReport()`

`getReport()` 只回「這段期間有打卡紀錄的人」—— 整期都請假的人會整列消失。
`AttendanceReportService::collect()` 另外撈了一次核准的假來補這些人，
所以 `forPage()` 走 `collect()` 而不是 `getReport()`。

順手補了 `emptyStat()` 缺的 `total_days` / `normal_days`（通知那邊沒用到所以一直沒發現）。

### 3. 報表頁本身不能綁 `can:` middleware

兩個分頁各自一個權限，有**其中一個**就該進得來，而 `can:` 是 AND。
所以 `ReportController::index()` 自己擋：兩個都沒有就 `abort(403)`。
兩支 ajax 則各綁各的 keyword，那裡才是真正拿得到資料的地方。

### 4. 出勤明細那一頁仍然由 `attendance.report` 管

報表列點下去會連到 `/admin/attendance/detail/{userId}`。只勾 `report.attendance`
的人看得到彙總但點不進明細 —— 那是刻意的：彙總是「誰遲到幾次」，
明細是「這個人每一天幾點打卡」。前端用 `data-can-detail` 決定那一列要不要做成可點。

`attendance.report` 的語系標題因此改成「查看出勤明細」，並從打卡出勤頁的
sidebar 顯示條件裡移除（只勾它的人在那一頁已經沒有任何分頁可看）。

### 5. 期間切換的 active 不能跟 hover 同色

全站的 `.btn-outline-secondary` 是「hover 深色填滿」，Bootstrap 的 `.active`
也是深色填滿 —— 滑鼠停在「週報」上時畫面有兩顆黑的，看不出現在在看哪一期。
`.period-switch .btn.active` 改用品牌金（深色模式 `#d4af37`）。

## 檔案

**新增**

```
app/Http/Controllers/Admin/ReportController.php
app/Http/Requests/Report/ShowReportRequest.php
resources/views/admin/report/index.blade.php
resources/lang/{tw,cn,en}/report.php
public/js/report.js
```

**修改**

```
app/Services/RemindReportService.php        拆出 collect() / forPage() / rangeFor()
app/Services/AttendanceReportService.php    新增 forPage() / rangeFor()；emptyStat() 補兩個 key
app/Services/AttendanceService.php          移除 getMonthlyReport()（已無人呼叫）
app/Http/Controllers/Admin/AttendanceController.php   移除 ajaxMonthlyReport()
routes/web.php                              移除 attendance/ajax-monthly-report；新增 report 三條
config/permissionMap.php                    新增 report group（兩個 keyword）
config/rules.php                            新增 REPORT_PERIOD_TYPE_IN
resources/lang/{tw,cn,en}/permission.php    group label + 兩個 keyword；attendance.report 改標
resources/lang/{tw,cn,en}/attendance.php    移除只給舊分頁用的 key；back_to_report 改文案
resources/views/layouts/app.blade.php       sidebar 新增「報表」；打卡入口條件移除 attendance.report
resources/views/admin/attendance/detail.blade.php     返回鍵改回報表頁
public/js/attendance.js                     移除「月報表」分頁與 loadReport/renderReport
public/js/attendance-detail.js              返回鍵改回報表頁
public/css/custom.css                       .period-switch .btn.active
```

## 相關

- [[attendance-report-notice]] —— 打卡週／月報的統計與通知（後台這份跟它同一組數字）
- [[remind-period-report]] —— 超時週／月報
- [[admin-table-layout]] —— `.data-table` / `.row-actions` 這套表格樣式
- [[rbac]] —— 權限 keyword
