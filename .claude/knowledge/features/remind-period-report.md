# 超時統計的週報表／月報表

> **狀態：已實作（2026-10-10）。沒有 migration，也沒有新設定。**

## 需求

> 「超時統計也要有週報表跟月報表通知」

原本只有日報（每天 08:30 統計前一天，見 [[remind-escalation]]）。

需求方確認的四件事：

| 題目 | 決定 |
|---|---|
| 幾點發 | **08:30**（週一／1 號）—— 跟日報同一個時間，需求方要求時間一致 |
| 收件人 | **沿用日報那份**（`KEY_REMIND_REPORT_MANAGER`） |
| 個人版 | **不發**，只給勾選的人 |
| 日報 | **保留** |

後三項讓這次完全不用動設定頁、不用新增 `AppSettingService` key、不用加語系。

## 做法：一支 Service 吃 `type`

```
remind:report                      每天 08:30      前一天         ✅ 逐人發個人版
remind:report --type=weekly        每週一 08:30    上週一～上週日  ❌
remind:report --type=monthly       每月 1 號 08:30 上個月         ❌
```

三者只差在區間怎麼算與期間怎麼稱呼，其餘（收件人、統計、文案骨架）完全一樣，
所以是一支 `RemindReportService` 加 `type`，不是三支。

### ⚠ 三種都在 08:30，跟日報同一分鐘

需求方要求「時間一致」（2026-10-10，原本排在 11:30 刻意跟打卡報表錯開）。

代價講清楚：

```
一般的週一  08:30  超時日報（昨天）＋ 超時週報（上週）      → 主管兩則
1 號是週一  08:30  再多一則超時月報（上個月）              → 主管三則
```

三則**內容不同**（昨天／上週／上個月），不是重複發送 —— 但確實是同一分鐘
三則私訊。這是需求方明確選的，要改回錯開之前先問過。

⚠ 打卡報表維持 **11:00**（需求方指定不動），跟這一組分開。

目前整天的主動訊息時刻表：

```
07:00  班表通知
07:30  待接手清單
08:00  任務卡通知
08:30  超時提醒統計：日報 ＋ 週報(一) ＋ 月報(1 號)，含日報的個人版
09:00  匯率詢問
09:30  虛擬機繳款通知
10:00  站台餘點同步
11:00  打卡週報／月報
```

### 區間算法

跟 `AttendanceReportService::resolveRange()` 同一套：

```
daily   → 前一天（start = end）
weekly  → startOfWeek()->subWeek()        上週一 ～ 上週日
monthly → subMonthNoOverflow()->startOfMonth()
```

⚠ **不要用 `-7 days`** —— 補發時（`--date=` 指定別的日期）不會對齊週一。

## ⚠ 文案裡「昨天」原本寫死在 12 個地方

```
'EMPTY'          "昨天沒有任何超時的求助單"      → "{period}沒有任何超時的求助單"
ENCOURAGE.TEAM_* prompt／input／fallback 都寫「昨天」 → 帶 {period}
'HEADER'         "📊 超時提醒統計\n{date}"       → "📊 {title}\n{range}"
```

期間的說法走 `PERIOD_DAILY` / `PERIOD_WEEKLY` / `PERIOD_MONTHLY`
（昨天／上週／上個月），標題走 `TITLE_*`。

⚠ `ENCOURAGE.TEAM_*` 一定要跟著參數化，否則週報的鼓勵話會寫成
「昨天一題都沒卡住」—— 統計的是一整週，話卻在講昨天。
`EncouragementWriter::forTeam()` 因此多一個 `$period` 參數。

⚠ `PERSONAL_*` 與 `ENCOURAGE.PERSON_*` **不用動**：個人版只有日報會走到。

## ⚠ 日報的外觀不能變

- 標題仍是「超時提醒統計」（`TITLE_DAILY`）
- 期間那行仍是 `NoticeText::date()` 的「10/9（週五）」，不是
  「2026-10-09 ～ 2026-10-09」（`rangeText()` 對 daily 特別處理）
- 個人版照發

## ⚠ 簽章變了，呼叫端要一起改

```
run($date, $dryRun)            →  run($type, $endsOn, $dryRun)
test()                         →  test($type = TYPE_DAILY)
```

`test()` 給了預設值，所以設定頁的測試按鈕（`NotificationSettingService::testReport()`）
不用改 —— 那個分頁本來就是「超時提醒統計」日報。

Repository 三支也改成吃區間（底層本來就是 `whereBetween`，索引吃得到）：

```
countByUserForDate    → countByUserForRange($start, $end)
getTicketsForDate     → getTicketsForRange($start, $end)
getUserTicketsForDate → getUserTicketsForRange($start, $end)
dayRange($date)       → rangeOf($start, $end)
```

## 動到的檔案

| 檔案 | 內容 |
|---|---|
| `app/Services/RemindReportService.php` | `TYPE_*`、`resolveRange()`、`title()`、`periodWord()`、`rangeText()`；週月報跳過個人版 |
| `app/Repositories/AutoReplyTicketRemindRepository.php` | 三支查詢改吃區間 |
| `app/Console/Commands/RemindReportCommand.php` | `--type`、區間顯示、週月報不印個人版那行 |
| `app/Console/Kernel.php` | 兩條排程 |
| `app/Services/Notify/EncouragementWriter.php` | `forTeam($period)` |
| `config/constants.php` | `TITLE_*`、`PERIOD_*`、`RANGE`；`EMPTY` 與 `ENCOURAGE.TEAM_*` 參數化 |

## 驗證

```
daily    2026-10-09 ～ 2026-10-09  「📊 超時提醒統計 / 10/9（週五）」「昨天沒有…」  個人版 1 位
weekly   2026-09-28 ～ 2026-10-04  「📊 超時提醒週報表」「上週沒有…」              不發個人版
monthly  2026-09-01 ～ 2026-09-30  「📊 超時提醒月報表」「上個月沒有…」            不發個人版
--type=bogus → 擋下來
```

排程：`30 8 * * 1`、`30 8 1 * *`，日報 `30 8 * * *`。三條同一個時刻。

⚠ 本機沒有提醒紀錄，所以三種都走到 EMPTY 分支 ——
**「按單／按人」那兩段的週月報排版沒有用真實資料看過**。

## 相關

- [[remind-escalation]] —— 日報本體與提醒升級機制
- [[attendance-report-notice]] —— 同一套週／月報做法
