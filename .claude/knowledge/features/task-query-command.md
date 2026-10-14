# 用 Telegram 指令查某個人的卡片進度

> 需求（2026-10-14）：「我會輸入『確認一下 @aabb123 的卡片進度狀況』，要列出
> 這個人的所有卡片，待處理的、進行中的（含預計完成時間）、測試中（含預計完成
> 時間）、審核中（含預計完成時間）、已解決（2 天內完成的），如果其中一項沒有
> 內容，大標題就拿掉」

```
📋 小明 的卡片進度

🗒 待處理（2）
• 修正報表匯出
• 補上登入紀錄篩選

🔨 進行中（1）
• 虛擬機費用試算　（還有 3 天）

🧪 測試中（1）
• 公告管理搜尋　（今天到期）

🔍 審核中（1）
• 補點其他類型　（⚠️ 逾期 13 天）

✅ 已解決（2 天內，1）
• 修正提醒誤判
```

純讀本地資料、不發給客戶、沒有副作用 —— **不做二次確認**。

## 空的段落連大標題一起不印

需求方指定。實作上是**迴圈跑「有卡片的那幾段」**，不是「全部五段再各判一次
要不要印」—— 後者在加第六種狀態時很容易漏掉其中一個判斷。

本機實測：只有一張測試中的卡時，輸出就只有「🧪 測試中（1）」那一段，
其餘四個大標題都沒出現。

## 哪幾段附預計完成時間

| 段落 | 附期限 | 為什麼 |
|---|---|---|
| 待處理 | ✗ | 還沒開始做，那個日期沒有參考價值 |
| 進行中 / 測試中 / 審核中 | **✓** | 需求方指定 |
| 已解決 | ✗ | 已經做完了 |

⚠ 期限要有**三種**講法（逾期 N 天／今天到期／還有 N 天）。只寫「還有 N 天」
的話逾期的會變成「還有 3 天」—— 任務卡通知那邊踩過同一個坑。

⚠ 基準截到當天 00:00：`due_date` 是日期（沒有時分），`now()` 帶著時分秒 ——
不截的話今天到期會被算成「逾期 0 天」。

## ⚠ 「2 天內完成」只能從活動紀錄回推

**`task` 表沒有 `resolved_at`**。能用的只有：

| 來源 | 可不可用 |
|---|---|
| `task.updated_at` | **不行** —— 卡片解決之後任何一次編輯（改標題、改指派人）都會把它往後推，一張上個月解決的卡會被算成「兩天內完成」 |
| `task_activity` | ✓ 移動狀態時會記 `action='moved'` ＋ `changes={"狀態":{"from":2,"to":5}}` ＋ `created_at` |

所以 `getRecentStatusMoves($days)` 撈兩天內的 `moved` 紀錄，在 PHP 比對
`狀態.to === RESOLVED`，得出「最近完成的卡片 id」。

⚠ **`changes` 欄位型別是 `longtext`**（不是真的 JSON 欄位），所以撈回來在
PHP 比對，不在 SQL 裡解 JSON。兩天內的活動量很小。

⚠ 同一張卡可能被移進移出好幾次 —— 這裡只需要知道「有沒有出現過」，
所以用 `in_array` 判斷就夠。

## 指派人：兩個欄位都要看

`assignee_ids`（JSON 陣列，後來加的）優先，空的才退回 `assignee_id`（舊資料）。
做法與 `TaskNoticeService::assigneesOf()` 一致。

⚠ `assignee_ids` 的欄位型別也是 `longtext`，所以一次撈完再在 PHP 分人，
不逐人 `whereJsonContains`（不保證可用，而且是一人一趟查詢）。
`getOpenForNotice()` 本來就是這個做法。

## `@aabb123` 怎麼對到人

`UserRepository::findByTelegramUsername()` 已經存在（通知那邊在用），
而且它自己處理了 `@` 前綴與大小寫：

```sql
WHERE LOWER(TRIM(LEADING '@' FROM telegram_username)) = ?
```

⚠ prompt 特別交代「account 原文照抄，不要改寫、不要換成暱稱、不要去掉底線
或數字」—— 那是拿去比對資料庫的鍵，模型自作主張就查不到人。

⚠ 標題上顯示的是**暱稱**（`nickname`），沒有暱稱才退回 `@帳號` ——
`@帳號` 是拿來查的，不是拿來看的。

## 權限：`telegram_chat.task_query`

介面上：**帳號管理 → 編輯帳號 → 權限設定 → Telegram 客服 → 用指令查卡片進度**。

不沿用後台的 `task_board.view` —— 理由同前面幾支：「後台看看板」與
「用指令查某個人手上有什麼」是兩件事。

## 排在分派器第五棒

```
公告 → 發餘點告警 → 查餘點 → 查虛擬機 → 【查卡片進度】 → 預約提醒
```

⚠ 它前面有**四支**，全部都必須讓路。上一次加指令就是因為只測了新的那支跟
隔壁那支、沒測最上游的公告解析器，結果「發送餘點告警給 lv uat」被公告吃掉
（見 [[credit-alert-command]]）。這次整條鏈都測了：

| 關卡 | 「確認一下@aabb123的卡片進度狀況」 |
|---|---|
| 1 公告 | `not_broadcast` ✓ |
| 2 發餘點告警 | `not_alert` ✓ |
| 3 查餘點 | `not_credit` ✓ |
| 4 查虛擬機 | `not_vm` ✓ |
| 5 查卡片 | **`task` / `@aabb123`** ✓ |

反向也測了（卡片解析器要讓給別支）：查餘點／查虛擬機／傳餘點告警／排提醒
全部 `not_task` ✓。

## 檔案

| 檔案 | 動作 |
|------|------|
| `app/Services/TaskQueryCommandService.php` | 新增 |
| `app/Services/AutoReply/TaskQueryCommandParser.php` | 新增 |
| `app/Repositories/TaskRepository.php` | `getForAssigneeQuery()` ＋ `getRecentStatusMoves()` |
| `app/Services/TelegramCommandRouter.php` | 掛上第五棒 |
| `config/constants.php` | `TASK_QUERY_COMMAND` 區塊 |
| `config/permissionMap.php` ＋ `resources/lang/{tw,cn,en}/permission.php` | 新權限 |

沒有 migration、沒有新 route、沒有 callback（純讀，沒有按鈕）。

## 待釐清 / 已知缺口

- **本機沒有人填 `telegram_username`**，所以「@帳號 → 找到人」那一段
  **沒有用真資料測過**。查找走的是通知那邊已經在用的
  `findByTelegramUsername()`，沒有改動。
- 每段上限 15 張，超過顯示「…這一段還有 N 張」。
- **封存的卡片不列**（`status != ARCHIVED`），停用專案的卡片也不列
  （`onlyActiveProject()`）—— 跟每日通知一致。
- 「已解決」目前**不顯示完成時間**，只顯示在這一段裡就代表「兩天內」。
  需求方沒要求，要加的話資料是現成的（活動紀錄的 `created_at`）。
