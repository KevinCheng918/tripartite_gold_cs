# 未讀數量（對話列表 ＋ sidebar 總數）

> **狀態：已完成**（2026-10-11，v2.76）。migration 已在本機跑過。

## 需求

> 「如果客人有發話，我要在對話列表顯示未讀數量，點進去後代表已讀，
> 然後 sidebar 的 Telegram 客服要計算總數。例如，有兩個客人各發了兩則訊息，
> 我對話列表這邊兩個群組要分別顯示 2，然後在 sidebar 的 Telegram 客服要顯示 2」

需求方對四個問題的回答：

| 問題 | 回答 |
|---|---|
| sidebar 數對話數還是訊息總數 | **對話數**（兩個客人各發兩則 → sidebar 顯示 2） |
| 數字要多即時 | **接 Pusher，即時** |
| 「已讀」與「已回覆」要不要分開 | **要分開** |
| 已讀是全站共用還是每人各自 | **每人各自** |

## ⚠ 後兩個答案合起來改變了結構

「每人各自」的已讀**不可能存在訊息欄位上**——`telegram_message` 是一列一則訊息，
加個 `read_at` 只能表達「有人讀過」，表達不了「誰讀過」。

所以兩個語意是這樣分家的，訊息表不動：

| 語意 | 存哪 | 誰寫 |
|---|---|---|
| 已**回覆** | `telegram_message.replied`（現有欄位，回歸原意） | 送出訊息時（`mark_replied`） |
| 已**讀** | `telegram_group_read.last_read_at`（新表，每人每對話一列） | 點進對話時 |

結果比原本「訊息上加 `read_at`」更乾淨：點進去看不再污染 `replied`，
「誰問了還沒回」之後查得出來。

## 現況：對話列表那半已經做好了

| 需求 | 現況 |
|---|---|
| 對話列表顯示未讀數 | ✅ `getConversationList()` 回 `unread_count`，`layout.js` 畫紅色 pill badge |
| 點進去代表已讀 | ✅ `ajaxMessages()` 呼叫 `markAsRead($groupId)` |
| sidebar 總數 | ❌ 沒有 |

要改的是未讀的**定義**（從「訊息沒被回」換成「這個人沒看過」）跟 sidebar。

## 設計

### 新表 `telegram_group_read`

```
user_id            FK user              cascadeOnDelete
telegram_group_id  FK telegram_group    cascadeOnDelete
last_read_at       datetime             最後一次點進這個對話的時間
unique(user_id, telegram_group_id)
```

⚠ 沒有紀錄（第一次點進來之前）＝ **全部未讀**。訊息本來就只留 7 天
（`telegram:purge`），所以新人最多看到 7 天份，不會是無上限的歷史。

### 未讀怎麼算

```sql
-- 一次把「這個人在每個對話的未讀數」撈回來，不要每個群組問一次
SELECT m.telegram_group_id, COUNT(*) AS unread
  FROM telegram_message m
  LEFT JOIN telegram_group_read r
    ON r.telegram_group_id = m.telegram_group_id AND r.user_id = ?
 WHERE m.direction = INBOUND
   AND (r.last_read_at IS NULL OR m.created_at > r.last_read_at)
 GROUP BY m.telegram_group_id
```

⚠ **這修掉一個既有的 N+1**：原本 `getConversationList()` 在 `map()` 裡逐群組
呼叫 `getUnrepliedCount()`，群組有幾個就打幾次 COUNT，而這支每 30 秒被前端重載。
改成一次 GROUP BY，跟同一支方法裡 `filterRunning()` 的寫法一致。

sidebar 的數字＝上面結果裡 `unread > 0` 的**列數**（對話數），不是 SUM。

### sidebar

```
通訊管理
└─ 💬 Telegram 客服   [2]
```

```
GET /admin/telegram-chat/ajax-unread-count
→ { "groups": 2 }
```

⚠ **不在 `layouts/app.blade.php` 裡查 DB**。那支 layout 每一頁都跑，
在 blade 裡查等於替全站每個頁面加一次查詢。

### 即時更新：共用一個 Pusher 連線

材料現成：`TelegramMessageReceived` 已經廣播到 channel `telegram-chat`、
事件名 `telegram.message`，只是目前只有 Telegram 客服那一頁訂閱。

⚠ **不能讓 layout 跟 telegram-chat 頁各建一個 Pusher 實例** —— 同一個瀏覽器分頁
會開兩條 WebSocket，訂同一個 channel。改成 layout 建立 `window.AppPusher`，
`telegram-chat/main.js` 有就拿來用、沒有才自己建（它也可能被其他入口載入）。

⚠ **只有 sidebar 看得到這一項的人才連**（權限同 sidebar 的顯示條件）。
沒權限的人不輸出 `#app-realtime`，也就不載 Pusher JS、不開連線。

更新時機：
- 收到 `telegram.message` → 重拉一次 `ajax-unread-count`
- 自己點進某個對話 → 本地重拉（同一支）

## 檔案

**新增**
```
database/migrations/..._create_telegram_group_read_table.php
app/Models/TelegramGroupRead.php
app/Repositories/TelegramGroupReadRepository.php
public/js/unread-badge.js
```

**修改**
```
app/Repositories/TelegramRepository.php       getUnreadCountsByUser()（批次）取代 getUnrepliedCount()
app/Services/TelegramChatService.php          getConversationList() 改批次；markAsRead() 改寫新表；新增 unreadSummary()
app/Http/Controllers/Admin/TelegramChatController.php   新增 ajaxUnreadCount()
routes/web.php                                ajax-unread-count
resources/views/layouts/app.blade.php         sidebar badge、#app-realtime、載 Pusher 與 unread-badge.js
resources/views/admin/telegram-chat/index.blade.php     移除自己那份 pusher.min.js
public/js/telegram-chat/main.js               改用 window.AppPusher
resources/lang/{tw,cn,en}/telegram_chat.php   badge 的 aria-label
```

## ⚠ 實作時踩到的一個坑

`updateOrCreate()` **不是**原子操作 —— 它是「先 SELECT 再 INSERT」兩句話。
兩個分頁同時點進同一個對話會雙雙查不到、雙雙 insert，撞上 unique 索引丟例外。
（我第一版就寫成這樣，還在註解裡宣稱它避免了競態。`DB::pretend()` 印出
兩句 SQL 才看出來。）

改用 `upsert()`，出的是單一句 `INSERT ... ON DUPLICATE KEY UPDATE`：

```php
TelegramGroupRead::query()->upsert(
    [[...]],
    ['user_id', 'telegram_group_id'],   // 衝突鍵
    ['last_read_at', 'updated_at']      // 衝突時要更新的欄位
);
```

⚠ `upsert()` 是 query builder 層級的批次寫入，**不走 Eloquent 的 timestamps**，
`created_at` / `updated_at` 要自己填。

## 正式機上線前

- [ ] `php artisan migrate`（新表 `telegram_group_read`）

## 相關

- [[telegram-chat]] —— 對話窗本體
- [[auto-reply-progress]] —— 同一支 `getConversationList()` 裡的 `auto_reply_running`，
  已經是「一次撈整批」的寫法，未讀數比照它
- [[remind-escalation]] —— 「沒人回」的提醒走求助單，不是這裡的 `replied`
