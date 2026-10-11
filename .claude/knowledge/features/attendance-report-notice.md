# 打卡週報表／月報表通知 ＋ 請假預告

> **狀態：已實作（2026-10-10）。沒有 migration。**
> 正式機上線前要跑一次 `attendance:fix-absent-on-leave --force`（見下方「🔴 一起修掉的 bug」）。

## 需求（2026-10-10）

| 項目 | 內容 |
|---|---|
| **週報表** | 統計週一～週日，**每週一 11:00** |
| **月報表** | 統計整個月，**每月 1 號 11:00** |
| 收件人 | 設定頁勾選的人（基本上主管以上）收全部人的；其餘同仁收自己那份 |
| 統計項目 | 遲到次數＋時間、曠工天數、早退次數＋時間、補打卡次數、請假幾天幾小時、加班總時數 |
| **請假預告** | 每天早上的班表通知，**請假前兩天到請假結束**告訴主管「今天誰請假、請到什麼時候」 |

需求方確認過的四件事：

1. 勾了完整報表的人**不再收個人版**（跟既有三個通知一致）
2. 週報與月報**都是 11:00**
3. 收件人是**新的一份**，週報月報共用
4. 誤記的曠工**要清掉**，寫一支一次性指令

## ⚠ 統計不列主管以上（2026-10-11）

需求方指定：**打卡報表不能加入主管以上，含主管本人。** 同一天也確認
超時統計的個人版比照辦理（見 [[remind-escalation]]）。

「主管以上」＝ `level <= LEADER`（ADMIN 0 / BOSS 1 / LEADER 2），
判斷用既有的 `User::isLeaderUp()`，不要再寫一次 level 比較。

實作在 `AttendanceReportService::withoutLeaderUp()`，**濾在 `collect()` 裡**：

⚠ **一定要濾在 `collect()`**，不是在三個出口各濾一次 —— Telegram 完整版、
個人版、後台報表頁都吃這一份，分開濾遲早會有一個出口漏掉，而「後台的數字
跟主管手機上那份對不起來」是最難查的那種 bug（理由同 [[report-page]]）。

⚠ **主管以上也不再收個人版**（`getDmCandidates(true)`）。這一條不是順便做的：
統計名單濾掉他之後，`$stats` 裡就沒有他那一列，而個人版找不到會退到
`emptyStat()` —— 那則私訊會寫成「全勤」。**發出去一份不存在的統計，比不發嚴重得多。**

⚠ 查不到的 user（帳號已刪）**留著**：不知道他是誰就沉默少掉一列，統計會對不起來。

⚠ `UserRepository::getDmCandidates()` 多了 `$excludeLeaderUp` 參數，**預設 false** ——
班表通知與任務通知的收件人仍包含主管，不能順手改掉共用名單。
那支的 `select` 也補了 `level`（漏掉的話 `isLeaderUp()` 讀到 null，全部人都會被當成主管）。

## ✅ 統計邏輯是現成的

`AttendanceService::getMonthlyReport()`（打卡出勤頁在用）算出來的欄位剛好就是
需求要的那一組，所以**沒有重寫統計**，只把它從「吃 `Y-m`」改成「吃起訖日期」：

```
getMonthlyReport($yearMonth)  →  只是換算成起訖，轉呼叫 getReport()
getReport($start, $end)       →  統計本體，週報月報與出勤頁共用
```

連帶三支 Repository 從「某個月」改成「某段期間」，順便修掉
`where('date', 'like', "{$yearMonth}%")` —— 欄位被 LIKE 包住吃不到 `date` 索引。

## 🔴 一起修掉的 bug：請整天假的人被記成曠工

`MarkAbsentCommand`（每天 01:00）原本只看「有排班 + 沒打卡」就標曠工，
**完全沒有檢查請假**。請整天假的人本來就不會打卡，所以他每請一天假就被記一天曠工。

這不只是數字難看，而是**直接讓需求裡那條規則永遠走不到**：
「沒有遲到、早退、曠工但有請假 → 就說幾號到幾號請假」——
請假的人身上一定掛著曠工，判斷式進不去那個分支。

⚠ **時段假不跳過**：請半天的人當天仍然要上班、仍然要打卡，沒打就是沒打。
所以只認 `hasApprovedFullDayOnDate()`。

### ⚠ 判斷請假不要在迴圈裡逐人查

`hasApprovedFullDayOnDate()` 很好用，但放在迴圈裡就是 N+1 ——
曠工標記每天要問一輪排班的人，一次性清理指令更是要問過**每一筆曠工紀錄**。

改用 `LeaveRequestRepository::fullDayLeaveMapByDateRange($start, $end)`：
一次撈完，回 `['2026-10-08' => [3, 7], ...]`，在 PHP 裡 `in_array()` 比對。

⚠ 一筆假會跨好幾天所以要逐日展開，但**只展開跟查詢區間有交集的那幾天** ——
不然一筆橫跨半年的假會灌爆那個陣列。

### 既有的錯誤資料

`php artisan attendance:fix-absent-on-leave`

- **預設是 dry-run**，只列出要刪哪幾筆；要真的動手必須加 `--force`
- 只刪「status = 曠工 **且** 當天真的有核准整天假」的紀錄
- 曠工紀錄除了 `status` 之外沒有別的內容（沒有打卡時間），刪掉是乾淨的 ——
  它本來就不該存在
- 包 transaction：要嘛整批清掉、要嘛一筆都不動
- ⚠ **一次性，不要掛進排程** —— 留著等於每天拿一個 DELETE 掃出勤表

本機沒有曠工紀錄所以測不到實際刪除，**正式機上線前要跑一次**。

## 文案規則

### 個人版

| 情況 | 內容 |
|---|---|
| 沒有遲到／早退／曠工 | `✅ 無遲到、早退、曠工情形` ＋ 一句勉勵的話 |
| 沒有狀況但有請假 | 先列請假區間，再 `✅ 其餘日子無遲到、早退、曠工情形` ＋ 勉勵的話 |
| 有狀況 | 逐項列遲到／早退／曠工的次數與時間，**不說勉勵的話** |
| 有加班、有補打卡 | 不論哪種情況都附在後面 |

### 完整版（主管）：每人一行，三種狀態

```
✅ 王小明　全勤　💪 加班 3.5 小時
🌴 李小美　請假 2 天，其餘無遲到、早退、曠工
⚠️ 張小強　遲到 2 次 35 分鐘、早退 1 次 10 分鐘　🌴 請假 1 天
```

| 狀態 | 條件 |
|---|---|
| ✅ 全勤 | 沒有遲到／早退／曠工，**而且沒有請假** |
| 🌴 有請假 | 沒出狀況，但請過假 → 報請假多久 ＋「其餘無遲到、早退、曠工」 |
| ⚠️ 有狀況 | 有遲到／早退／曠工 → 報次數與時間（有請假仍然附在後面） |

⚠ **請假不能算全勤**（需求方 2026-10-10 更正）。

一度做成「只要沒有遲到早退曠工就報全勤、請假附在後面當資訊」—— 那會讓
請了五天假的人跟整期全到的人掛同一個標籤，主管一眼看過去分不出誰真的每天都在。
判斷在 `AttendanceReportService::managerLine()`，三種狀態 Early Return。

⚠ 請假**也不算「出狀況」**：核准過的假不該讓人看起來像出了事，所以
`hasIssue()` 只看遲到／早退／曠工。「不是狀況」與「不是全勤」是兩件事。

⚠ 天與小時**不互相換算**：一天幾小時取決於班別，硬換會得到一個誰都不認得的數字。
所以是「2 天 3.5 小時」而不是一個總時數。

## ⚠ `getReport()` 只回「有打卡紀錄的人」

整段期間都在請假、或根本沒排班的人**不會出現在它的結果裡**。
所以 `AttendanceReportService` 做了兩件事：

1. 個人版從 `getDmCandidates()`（在職同仁名單）出發，查不到統計就當全部是 0
2. 請假另外從 `LeaveRequestRepository::getApprovedByDateRange()` 撈一次，
   用**同一支** `AttendanceService::summariseLeaves()` 算 —— 兩邊數字才會一致

少了第 2 步，「整週都請假」的人會收到一則完全空白的報表。

## 區間怎麼算

```
週報：Carbon::startOfWeek()->subWeek()  →  上週一 ～ 上週日
月報：subMonthNoOverflow()->startOfMonth()  →  上個月 1 號 ～ 月底
```

⚠ 週的起點用 `startOfWeek()`（Carbon 預設週一）而不是 `-7 days`：
補發時（`--date=` 指定別的日期）用減七天算出來的不會對齊週一。

⚠ 統計的都是**上一期**。當期還沒過完，統計沒有意義。

實測過三種：

| 執行日 | 參數 | 區間 |
|---|---|---|
| 2026-10-10（六） | weekly | 2026-09-28 ～ 10-04 |
| 2026-10-12（一） | weekly | 2026-10-05 ～ 10-11 |
| 2026-11-01 | monthly | 2026-10-01 ～ 10-31 |

## 請假預告

接在既有的 07:00 班表通知**主管那一則**後面（個人版不動 —— 同仁不需要
每天被告知別人請假）。

```
請假開始日 -2 天  ←─ 開始預告（SHIFT_NOTICE.LEAVE_NOTICE_DAYS）
請假開始日
    …              ←─ 請假中每天也報（主管不會在假期中間忘記這個人不在）
請假結束日        ←─ 最後一天
```

括號裡的狀態實測過：

```
10/12 ～ 10/14（2 天後開始）
10/11（1 天後開始）
10/10 ～ 10/13（今天起）
10/08 ～ 10/12（請假中）
10/11（13:00～18:00）     ← 時段假：那天他還是會上班，只是缺一段
```

⚠ **還沒開始的假一定要標出來**，不然主管會以為這個人今天就不在了。
⚠ 只看**已核准**的假。待審的還不確定會不會放。

## 勉勵的話

用 `EncouragementWriter::forAttendance($name, $period)`（新增）。

⚠ **不共用 `forPerson()`**：那支的 prompt 寫的是「昨天沒有求助單卡住」，
用在出勤報表會變成文不對題的肯定（他這週沒遲到，卻被誇沒有卡住題目）。
情境不同就該有自己的 prompt 與公版。

⚠ 一定要有公版退路，而且 **`--dry-run` 不生成** —— 空跑只是要看排版，
不該為了預覽燒掉額度也不該跑好幾分鐘。

## 動到的檔案

| 檔案 | 內容 |
|---|---|
| `app/Services/AttendanceReportService.php`（新增） | 統計整理、組訊息、兩種收件人 |
| `app/Console/Commands/AttendanceReportCommand.php`（新增） | `attendance:report --type=weekly\|monthly` |
| `app/Console/Commands/FixAbsentOnLeaveCommand.php`（新增） | 一次性清掉誤記的曠工 |
| `app/Console/Commands/MarkAbsentCommand.php` | **修 bug：請整天假不標曠工** |
| `app/Console/Kernel.php` | 兩條排程 |
| `app/Services/AttendanceService.php` | `getReport()`、`summariseLeaves()` |
| `app/Repositories/{Attendance,ClockAmendment,LeaveRequest}Repository.php` | 按區間查；`getUpcomingApproved()` |
| `app/Services/ShiftNoticeService.php` | 主管那則附請假預告 |
| `app/Services/Notify/EncouragementWriter.php` | `forAttendance()` |
| `app/Services/AppSettingService.php` | `KEY_ATTENDANCE_REPORT_MANAGER` |
| `app/Services/NotificationSettingService.php` | 設定頁第六個分頁 |
| `app/Http/Requests/Notification/UpdateAttendanceReportRequest.php`（新增） | 收件人驗證 |
| `app/Http/Controllers/Admin/NotificationController.php` | ajax 更新與測試 |
| `routes/web.php` | 兩條 ajax |
| `config/constants.php` | `ATTENDANCE_REPORT.*`、`SHIFT_NOTICE.MANAGER_LEAVE_*` |
| `resources/views/admin/notification/index.blade.php` | 新分頁 |
| `public/js/notification-setting.js` | 新分頁的讀寫 |
| `resources/lang/{tw,cn,en}/notification.php` | 語系 |

## 驗證

- 三種區間用 `--dry-run` 實跑過（上表）
- 排程註冊：`0 11 * * 1`（下次 10/12 11:00）、`0 11 1 * *`（下次 11/01 11:00）
- 請假預告的五種狀態用假的 Model 跑過（不碰 DB）
- 設定頁 `forPage()` 吐得出 `attendance` 區塊；兩條 route 都註冊了
- 三份語系檔 key 完全一致
- ⚠ **設定頁的新分頁沒有在瀏覽器上看過** —— 驗證當下 session 過期，
  不自行登入（會寫 `login_log`）。Blade 編譯過、JS parse 過、資料組得出來，
  剩下的是純視覺風險

## 相關

- [[attendance]] —— 打卡出勤本體
- [[shift-daily-notice]] —— 附請假預告的那則通知
- [[task-daily-notice]]、[[remind-escalation]] —— 兩種收件人的既有做法
