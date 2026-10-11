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

⚠ **路由名沒有跟著搬，所以 sidebar 的亮燈要手動指定**（2026-10-11 修）。
那一頁的路由仍是 `admin.attendance.detail`（權限留在 `attendance.report`，
搬到 `report.` 群組會讓 keyword 跟路由群組對不起來），而選單是用
`routeIs()` 的前綴認人 —— 不處理的話進到明細頁會亮成「打卡出勤」：

```blade
{{-- 打卡出勤 --}} routeIs('admin.attendance.*') && ! routeIs('admin.attendance.detail')
{{-- 報表 --}}     routeIs('admin.report.*', 'admin.attendance.detail')
```

判準是「這一頁從哪個選單點進去、返回鍵回哪裡」，不是它的 URL 長什麼樣。
見 [bugfix/2026-10-11-attendance-detail-sidebar-active.md](../bugfix/2026-10-11-attendance-detail-sidebar-active.md)。

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

⚠ CSS：`tr.is-pending` 只設底色與 `--row-accent` 兩個值，**不碰 box-shadow**。
左條是 `.data-table` 共用的一條規則畫的，詳見
[[admin-table-layout]] 的「列狀態的左條」—— 各自寫 box-shadow 會互相整條覆蓋。

### 7. 紅標要有出口：「標記為已處理」（2026-10-11，v2.78）

標出「仍未處理」之後才發現：**那一列看得到卻碰不到**。一張單要離開 `PENDING`
只有支援群組那幾條路（引用求助訊息作答、訊息上的按鈕、後台對話視窗送訊息），
求助訊息被洗掉、引用掛不上、或同仁其實是用個人帳號在客戶群回的時候，
那一列就永遠紅著，提醒也會一路催到上限。
病根與全部路徑見 [bugfix/2026-10-11-remind-report-still-pending.md](../bugfix/2026-10-11-remind-report-still-pending.md)。

```
PUT /admin/report/ajax-mark-handled/{id}        can:report.remind_handle
  → AutoReplySupportService::markHandled()      狀態轉移一律走這支
  → AutoReplyTicketRepository::markHandledById()
```

⚠ **狀態改成 `IGNORED`（＝客服已自行處理），不是新開一個狀態。** 那個狀態的
定義本來就寫著「含客服已自行人工回覆」，語意完全吻合；為了「從後台按的」
另開一個狀態，只是讓六個狀態變七個，而報表上兩者要顯示的字一模一樣。

⚠ **狀態條件帶在 `update` 的 where 裡，不是先查再寫。** 同一張單很可能正好
有人在 Telegram 按按鈕 —— 先查後寫兩邊都會成功，後寫的會把「已加入題庫」
蓋回「客服已自行處理」。用回傳筆數判斷誰先到，0 筆回 422 而不是 500：
那不是錯誤，是別人比較快。

⚠ **一定要回報到支援群組**（`AUTO_REPLY.HANDLED_NOTICE`，引用原求助訊息）。
後台改了狀態而群組不知道的話，同仁還是會去引用它作答，然後只拿到一句
「這張單已經處理過了」。那則通知失敗只記 log，不能讓整個操作失敗 ——
狀態已經改掉了，丟例外會讓人以為沒成功而再按一次。

⚠ **權限是另一個 keyword**（`report.remind_handle`）：那顆會把別人的單結案、
而且會在群組發通知，看得到統計不等於可以替別人做這個決定。比照
[[customer-followup]] 的 index / manage。沒有這個權限的人連那一欄都不會出現。

⚠ 確認文字一定要寫「**不會回覆客人、也不會加入題庫**」。按的人很容易
以為系統會順便幫他回那位客人 —— 真的要回請走客服對話視窗。

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

**2026-10-11 追加（出勤明細的 sidebar 亮燈）**

```
resources/views/layouts/app.blade.php       打卡出勤排除 admin.attendance.detail；報表收進來
config/changelog.php                        v2.77
```

**2026-10-11 追加（標記為已處理，v2.78）**

```
app/Http/Requests/Report/MarkHandledRequest.php   新增；id 來自路由，用 validationData() 帶進驗證
app/Http/Controllers/Admin/ReportController.php   新增 ajaxMarkHandled()；注入 AutoReplySupportService
app/Services/AutoReplySupportService.php          新增「後台操作」一節：markHandled() / noticeHandled()
app/Repositories/AutoReplyTicketRepository.php    新增 markHandledById()（狀態條件寫在 where 裡）
app/Services/RemindReportService.php              forPage() 的 by_ticket 補 id
routes/web.php                                    新增 report/ajax-mark-handled/{id}（PUT）
config/permissionMap.php                          report group 新增 remind_handle
config/constants.php                              AUTO_REPLY.HANDLED_NOTICE
resources/lang/{tw,cn,en}/permission.php          remind_handle
resources/lang/{tw,cn,en}/report.php              操作欄、按鈕、確認句、四句 msg、確定/取消
resources/views/admin/report/index.blade.php      data-can-handle；確認與訊息兩個 Modal
public/js/report.js                               apiFetch 收 options；操作欄、確認、送出、重載
config/changelog.php                              v2.78
```

## 相關

- [[attendance-report-notice]] —— 打卡週／月報的統計與通知（後台這份跟它同一組數字）
- [[remind-period-report]] —— 超時週／月報
- [[admin-table-layout]] —— `.data-table` / `.row-actions` 這套表格樣式
- [[rbac]] —— 權限 keyword
