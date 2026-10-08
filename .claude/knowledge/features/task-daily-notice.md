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
| `app/Repositories/TaskRepository.php` | `getOpenForNotice()` |
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
