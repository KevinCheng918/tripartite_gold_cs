# 封存清理：專案停用期間不計入那 30 天

> **狀態：已實作（2026-10-10）。** 需要跑一支 migration 才會生效，
> 由需求方自己執行（`php artisan migrate`）。

## 需求

> 「關閉期間不要計入那 30 天」

承接前一個改動：專案停用後，它的卡片不出現在看板、每日通知與封存清單，
停用期間也一筆都不會被 30 天清理刪掉（`c3ee4b4`）。

但那一版重新啟用之後，30 天是**繼續算**的 —— 停用當下就已經封存 60 天的卡，
重新啟用那一刻就到期，下一次有人打開封存清單時立刻被清掉。
對使用者來說就是「改回正常了，卡片卻回不來」。

## 做法：重新啟用後，整個專案重新給 30 天

```
project.reactivated_at  datetime nullable   ← 上次由停用改回啟用的時間

停用 → 啟用   reactivated_at = now()
清理條件      封存超過 30 天
              且 (reactivated_at 是 null  或  reactivated_at 已超過 30 天)
```

等價於：**期限 = max(封存時間, 上次重新啟用時間) + 30 天**。

| 情境 | 剩餘天數 |
|---|---|
| 封存 29 天、從未停用 | 1 |
| 封存 29 天、昨天剛重新啟用 | 29 |
| **封存 60 天、昨天剛重新啟用** | **29**（原本是 0，會立刻被刪） |
| 封存 5 天、40 天前啟用過 | 25（那次啟用早就過期，不影響） |

### ⚠ 為什麼是「重新給 30 天」而不是「扣掉實際暫停幾天」

需求方選的（2026-10-10）。比較過三種：

| 做法 | 代價 |
|---|---|
| **重新給 30 天**（採用） | `project` 加一個欄位，`task` 完全不動。停用 2 天也是重新給 30 天，比實際暫停寬鬆 |
| 扣掉實際暫停天數 | 要 `project.disabled_at` + `task.purge_after` 兩個欄位，而且重新啟用時要在 transaction 裡逐張卡補償 |
| 累加 `project.paused_days` | 只加一欄，但停用後才封存的卡也被延長，而且專案停用一年之後每張卡都多留一年 |

寬鬆的代價是「卡片留久一點」，不會有資料被意外刪掉 —— 方向是對的。

### ⚠ 只認「停用 → 啟用」這一個方向

`ProjectService::isReactivating()` 擋掉其他情況。編輯一個本來就啟用中的專案
（改名、改描述，表單照樣會把 `status` 一起送上來）**不能**重新計算期限 ——
否則只要有人去動一下專案名稱，整個專案的封存卡就又多活 30 天，永遠清不掉。

用 `Arr::has($params, 'status')` 而不是只看值：表單沒送 `status` 代表
「不動狀態」，跟「送了一個等於啟用的值」是兩件事。

### ⚠ 前端的「剩餘天數」必須跟著改

原本前端自己算 `30 -（今天 - 封存日）`。期限會往後推之後那個算法就錯了，
畫面會寫「剩 0 天」但其實還有 29 天。

改成讀後端算好的 `purge_at`（`TaskResource::purgeAt()`）——
**那支跟 `deleteArchivedOlderThan()` 是同一條規則，改一邊就要改另一邊。**

### ⚠ `Task::project()` 關聯要 select `reactivated_at`

原本只 select `['id', 'name']`。少了它 `purgeAt()` 永遠讀到 null，
剩餘天數就退回舊算法 —— 而且**不會報任何錯**。

### ⚠ 30 這個數字收進 config

`constants.TASK.ARCHIVE_PURGE_DAYS`。原本散在三個地方（Service 呼叫、
Resource 算期限、前端算剩餘天數），改一個漏兩個就會畫面與實際不一致。

⚠ 改完 `config/` 一定要跑 `php artisan optimize` —— 實作當下就踩到：
驗證腳本讀到 `ARCHIVE_PURGE_DAYS=`（空字串），期限全部算成「封存當下就到期」。

## ⚠ 順帶修掉的分層問題

`ProjectController::ajaxUpdate()` 原本**直接呼叫 Repository**，中間沒有 Service。
而「狀態切換要連動封存卡期限」是不折不扣的商業邏輯，塞在 Controller 裡
下一個人根本找不到。

所以新增 `app/Services/ProjectService.php`，建立／更新都搬進去。
Controller 變成「讀走 Repository、寫走 Service」。

## 動到的檔案

| 檔案 | 內容 |
|---|---|
| `database/migrations/2026_10_10_000001_add_reactivated_at_to_project_table.php`（新增） | `project.reactivated_at` |
| `app/Services/ProjectService.php`（新增） | 狀態切換連動；建立／更新 |
| `app/Http/Controllers/Admin/ProjectController.php` | 寫入改走 Service |
| `app/Repositories/TaskRepository.php` | `deleteArchivedOlderThan()` 的 `reactivated_at` 條件 |
| `app/Repositories/ProjectRepository.php` | `LIST_COLUMNS` 加欄位 |
| `app/Models/Project.php` | `$casts` datetime |
| `app/Models/Task.php` | `project()` 關聯加 select |
| `app/Http/Resources/TaskResource.php` | `purge_at` |
| `app/Services/TaskBoardService.php` | 天數改讀 config |
| `config/constants.php` | `TASK.ARCHIVE_PURGE_DAYS` |
| `resources/views/admin/task-board/index.blade.php` | 剩餘天數改讀 `purge_at` |

## ⚠ 跑 migration 前要知道

- 欄位 nullable、預設 null，**不會動到既有資料**
- 既有專案 `reactivated_at` 都是 null → 走舊規則，行為跟現在一模一樣
- **目前就已經是停用狀態的專案不必回填**（需求方決定）。我們不知道它是什麼
  時候關的，也不需要知道 —— 它下次被啟用時就會寫入，從那一刻起享有 30 天
- migration 沒跑之前，`reactivated_at` 不存在，看板相關查詢會直接報錯。
  **這支一定要跑**

## 驗證

本機沒有停用中的專案可以實測，所以：

- 清理 SQL 用 `DB::pretend()` 印出來（一行都沒執行），確認 `EXISTS` 子查詢
  帶著 `reactivated_at is null or reactivated_at < ?`
- `isReactivating()` 用假的 Project 跑五種轉換，只有「停用 → 啟用」回 true
- `purgeAt()` 用假的 Task／Project 跑上面那張情境表，四種全中

## 相關

- [[task-board]] —— 停用專案不顯示卡片的本體
- [[task-daily-notice]]
