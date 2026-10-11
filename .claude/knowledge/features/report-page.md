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

| 分頁 | 權限 |
|---|---|
| 打卡報表 | `report.attendance` |
| 超時提醒統計 | `report.remind` |

兩個分頁都是「開始日期 ＋ 結束日期 ＋ 六顆快捷鈕（今日／昨日／本週／上週／
本月／上月）」，版面與操作比照補點紀錄。快捷鈕按下去直接查，不必再按「查詢」。

預設期間刻意跟 Telegram 對齊：打卡報表是**上週**、超時統計是**昨天** ——
一打開就是同仁剛收到的那一份，對得上。

> 原本（v2.71）是「日／週／月切換 ＋ 上一期／下一期」，v2.73 換掉。
> 需求方要能自己選時間段，而快捷鈕已經完全涵蓋原本那三種期間 ——
> 兩套並存的話，「上一期」在自訂區間下沒有明確語意。

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

⚠ 2026-10-11 之後 `collect()` 還多做一件事：**濾掉主管以上**（含主管本人，
需求方指定）。所以**兩個分頁都不列主管以上**，跟 Telegram 那兩份一致 ——
這也是「兩邊走同一支 `collect()`」的額外好處，濾一次三個出口都生效。
細節見 [[attendance-report-notice]] 的「統計不列主管以上」。

⚠ 出勤明細頁（打卡出勤）**沒有**濾，那裡要查得到每一個人的紀錄 ——
`AttendanceService::getReport()` 不動，只有報表這一層濾。

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

### 5. 期間只有起訖，後端不吃「日／週／月」

快捷鈕在前端就用 `window.DateRange`（common.js）換算成日期了，後端只收
`start` / `end`。多一個 `type` 參數的話，「上週是哪七天」會有前後端兩套算法，
而它們遲早會不一致（補點紀錄、財務也都用同一支 `DateRange`，就是這個理由）。

⚠ 一定要有天數上限（`config('rules.REPORT_RANGE_MAX_DAYS')` ＝ 366）。
沒有的話，被亂改的網址可以要求「2020-01-01 到今天」，那是一次把幾年的出勤
與提醒紀錄全撈進記憶體再跑迴圈 —— 畫面卡死而且查不出原因。
Laravel 沒有內建規則能比較「兩個欄位相差幾天」，所以擋在 `withValidator()`。

⚠ 前端顯示錯誤時要挑 `errors` 裡那一句，不是 `message` ——
後者是 Laravel 的「給定的資料無效」，對使用者沒有意義。

### 6. 「仍未處理」要標起來（需求方 2026-10-11，v2.74）

超時統計的「依題目」裡，狀態還是 `TICKET_STATUS.PENDING` 的那幾列：
整列淡紅底 ＋ 左側紅條（`tr.is-pending`）＋ 狀態欄換成紅色 badge。
摘要後面也接一句「還有 N 題仍未處理」。

⚠ **「還沒處理」由後端判定**（`by_ticket[].pending`），前端不要自己比
`status === 1`。狀態的數字是 config 定義的，寫死在 JS 裡等於同一件事有兩個
定義，改了 config 那邊畫面會安靜地標錯。

⚠ **N＝0 的時候也要講一句**（「被提醒過的都已經處理完了」），
不然分不出「都處理完了」跟「這段期間沒資料」。

⚠ CSS 有兩個坑，都記在 `custom.css` 那段註解裡：
底色要用 `background-color`（`.data-table` 把 `--bs-table-accent-bg` 設成
transparent，所以那層 9999px 的 inset shadow 是透明的）；
hover 的金線跟紅條是同一個 td 的同一個屬性，hover 態要再寫一次把紅色寫回去，
不然滑鼠經過時那一列就不再是「未處理」的樣子。

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
app/Services/RemindReportService.php        拆出 collect() / forPage()
app/Services/AttendanceReportService.php    新增 forPage()；emptyStat() 補兩個 key
app/Services/AttendanceService.php          移除 getMonthlyReport()（已無人呼叫）
app/Http/Controllers/Admin/AttendanceController.php   移除 ajaxMonthlyReport()
routes/web.php                              移除 attendance/ajax-monthly-report；新增 report 三條
config/permissionMap.php                    新增 report group（兩個 keyword）
config/rules.php                            新增 REPORT_RANGE_MAX_DAYS
resources/lang/{tw,cn,en}/permission.php    group label + 兩個 keyword；attendance.report 改標
resources/lang/{tw,cn,en}/attendance.php    移除只給舊分頁用的 key；back_to_report 改文案
resources/views/layouts/app.blade.php       sidebar 新增「報表」；打卡入口條件移除 attendance.report
resources/views/admin/attendance/detail.blade.php     返回鍵改回報表頁
public/js/attendance.js                     移除「月報表」分頁與 loadReport/renderReport
public/js/attendance-detail.js              返回鍵改回報表頁
```

**2026-10-11 追加（不列主管以上）**

```
app/Repositories/UserRepository.php         getDmCandidates($excludeLeaderUp = false)；select 補 level
app/Services/AttendanceReportService.php    collect() 尾端加 withoutLeaderUp()；個人版改 getDmCandidates(true)
app/Services/RemindReportService.php        個人版改 getDmCandidates(true)
config/changelog.php                        v2.72
```

## 相關

- [[attendance-report-notice]] —— 打卡週／月報的統計與通知（後台這份跟它同一組數字）
- [[remind-period-report]] —— 超時週／月報
- [[admin-table-layout]] —— `.data-table` / `.row-actions` 這套表格樣式
- [[rbac]] —— 權限 keyword
