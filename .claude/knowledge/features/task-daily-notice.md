# 每日任務卡通知

> **狀態：需求方 2026-10-08 確認後實作。**

每天早上私訊任務卡的狀況，兩種收件人（比照 [[remind-escalation]] 的每日統計）：

| 收件人 | 內容 |
|---|---|
| **所有在職同仁**（不含管理者） | 自己的：已過期幾項、進行中幾項、今日到期幾項 |
| 設定頁勾選的人 | 全部人的：每人一行 + 總計 |

## 已確認的決策（2026-10-08）

| 題目 | 決定 |
|---|---|
| 「進行中」指哪些 | **只算 `status = IN_PROGRESS(2)`**，看板上那一欄 |
| 三個數字會重疊怎麼算 | **各算各的，會重複** —— 一張進行中又今日到期的卡，兩邊各算一次 |
| 個人版發給誰 | **所有在職同仁都發**（沒卡的人收到一句輕鬆的話） |

⚠ **「各算各的」是刻意的**：三個數字各自回答一個問題（「有幾項逾期了」
「手上有幾項在跑」「今天要交幾項」），加起來本來就不該等於總卡數。
做成互斥的話「進行中」會少於看板上看到的數量，對不起來更難解釋。

## 2026-10-12：兩種通知都逐條列出標題

需求方要「不要只有數字」。改動：

| | 之前 | 現在 |
|---|---|---|
| 個人版 | 列逾期＋今日到期的標題，**進行中只給數字** | 三類都列 |
| 完整版 | 每人**一行數字** | 每人一行數字 ＋ 底下逐條列出他的卡 |

⚠ **清單要去重，數字不去重。** 一張卡可以同時逾期**又**在進行中 ——
數字那邊是「各算各的」（上面那條決策），但清單裡同一行標題出現兩次，
看的人只會以為系統壞了。所以 `uniqueTasks()` 合併三類時保留**第一次**出現的，
順序是逾期 → 今日到期 → 進行中，圖示跟著最急的那一類。

**結果是清單張數會少於三個數字相加** —— 那是對的，相加本來就會重複計算。

⚠ **個人版不分段。** 分成「要留意的／進行中」兩段的話，一張逾期又在跑的卡
會在兩段各出現一次。順序本身就是急迫度，圖示也看得出是哪一類。

⚠ **完整版要兩層上限，缺一不可**：

| 上限 | 預設 | 擋什麼 |
|---|---|---|
| `MANAGER_USER_MAX_LINES` | 5 | 一個人卡了二十張，把整則訊息吃光 |
| `MANAGER_MAX_LINES` | 30 | 二十個人各卡五張 —— 每個人都沒超標，加起來照樣爆 |

Telegram 單則 4096 字，超過是**整則送不出去**而不是截斷 ——
那會變成「最需要被看見的那天，報表反而沒發出來」。

⚠ 完整版**一行一張**（不像個人版拆成三行）：那一份是給主管掃的，
一張卡佔三行的話十個人就是一面牆。

## 怎麼算

```
未結束的卡 = status 不是 已解決(5) / 已封存(6)

已過期   due_date < 今天        （due_date 是空的不算）
今日到期 due_date = 今天
進行中   status = 進行中(2)      （不看 due_date）
```

⚠ **`due_date` 是 nullable**，沒填的卡不會出現在「已過期」與「今日到期」——
沒設期限的卡不該被說成逾期。

⚠ **一張卡可以指派給多個人**（`assignee_ids` 是 JSON 陣列）。
同一張逾期的卡會出現在每個被指派者的個人版裡 —— 那是對的，他們每個人都該知道。
完整版的「每人一行」同理，所以**各人數字加總會大於卡片總數**。

⚠ **沒有指派人的卡不計入任何人**，但完整版要單獨列一行 ——
沒人負責的逾期卡是最該被看見的，靜靜漏掉等於沒人管。

## 時間

08:00。前後是 07:00 班表、07:30 待接手、08:30 超時提醒統計 ——
四則主動訊息錯開，不會在同一分鐘收到一串。

## 會動到的檔案

| 檔案 | 內容 |
|---|---|
| `app/Services/TaskNoticeService.php`（新增） | 統計、組訊息、兩種收件人 |
| `app/Console/Commands/NotifyDailyTaskCommand.php`（新增） | `task:notify-daily` |
| `app/Console/Kernel.php` | `dailyAt('08:00')` |
| `app/Repositories/TaskRepository.php` | `getOpenForNotice()`；2026-10-10 起排除停用專案的卡片（`onlyActiveProject()`，理由見 [[task-board]]） |
| `app/Services/AppSettingService.php` | `KEY_TASK_NOTICE_MANAGER` |
| `app/Services/NotificationSettingService.php` | 設定頁多一個分頁的讀寫與測試 |
| `app/Http/Requests/Notification/UpdateTaskNoticeRequest.php`（新增） | 收件人驗證 |
| `app/Http/Controllers/Admin/NotificationController.php` | ajax 更新與測試 |
| `routes/web.php` | 兩條 ajax |
| `config/constants.php` | `TASK_NOTICE.*` 文案 |
| `resources/views/admin/notification/index.blade.php`、`public/js/notification-setting.js` | 第五個分頁 |
| `resources/lang/{tw,cn,en}/notification.php` | 語系 |

## 相關

- [[task-board]] — 任務看板本體（狀態、指派、`due_date`）
- [[remind-escalation]] — 每日統計，這支的收件人模型與排版都照它
- [[shift-daily-notice]] — 私訊同仁的共用做法（`StaffDmService`、`telegram_dm_ready`）
