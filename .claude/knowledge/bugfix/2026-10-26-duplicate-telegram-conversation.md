# 同一個群組在對話列表出現兩個視窗

需求方 2026-10-26 回報：客服對話列表裡「ZPay - 系統詢問群」出現兩列，
最後訊息時間一個 22:51、一個 19:54。

## 不是列表查錯，是真的有兩筆

先排除了查詢問題：`getActiveGroups()` 只從 `telegram_group` 撈、沒有 JOIN，
不可能把一筆變兩筆。而 `chat_id` 有**唯一索引** —— 所以那兩筆的
`chat_id` 一定不一樣，只是標題相同。

## ⚠ 病根：群組升級成 supergroup 時 chat_id 會換一個

**開啟「話題」功能就會觸發升級**，Telegram 會發一個全新的 chat_id
（常見是 `-100` 接上舊 id 的數字，但不保證）。

收訊端 `TelegramChatService::handleMessage()` 的流程是
「`findGroupByChatId()` 查不到就建一筆」—— 新 id 的第一則訊息進來時當然查不到，
於是**又建了一筆**。舊那筆從此不再有訊息，停在升級前的時間。

⚠ **這件事專案本來就知道，但只做了一半**：`TelegramBotService::logMigration()`
早就會在送出失敗時把新 id 記下來（2026-10-06 加的）。
那一半處理的是「**送**不出去」，沒有人處理「**收**到的是新 id」。

一般化：**同一個外部事實（chat_id 會變）會從好幾個方向打進系統**，
只補其中一個方向，另一個方向會用別的症狀冒出來。

## 修法一：收訊端自己換過去

Telegram 兩邊都給線索，所以兩個都收：

| 訊息在哪 | 欄位 | 意思 |
|---|---|---|
| 舊群組的最後一則 | `migrate_to_chat_id` | 新 id 是這個 |
| 新群組的第一則 | `migrate_from_chat_id` | 舊 id 是這個 |

`migrateChatIdIfNeeded()` 兩個都認，所以**哪一則先進來都處理得了**，
把既有那筆的 `chat_id` 換成新的，而不是建新的。

⚠ **這裡可以自動改，跟支援群組設定不一樣**：那邊的 chat_id 是人在設定頁填的，
自動換掉等於悄悄改掉使用者填的值（所以 `TelegramBotService` 只記 log 讓人自己
貼）。這裡的群組是**收到訊息自動建的**，沒有人填過什麼。

⚠ **新 id 已經有一筆時不自動合併**：那是「已經重複出來」的狀態，
而整併要動 9 張表 —— 在 webhook 這種每則訊息都會跑的地方做太危險。
改成記一筆 error，訊息裡直接附上該下的指令。

## 修法二：既有的重複資料要合併

`php artisan telegram:merge-group`

```
# 先看有哪些重複（不帶參數）
php artisan telegram:merge-group

# 試算（預設不動手）
php artisan telegram:merge-group -- -123456789 -1001234567890

# 真的做
php artisan telegram:merge-group -- -123456789 -1001234567890 --force
```

⚠ **`--` 不能省**：chat_id 是負數，少了它 Symfony Console 會把 `-1234...`
當成選項並噴 `The "-1" option does not exist`。不帶參數的列表模式會把
完整指令（含 `--`）印出來讓人直接複製。

⚠ **找重複是用標題比對，不是 chat_id**：升級後的 id 跟舊的沒有可靠的對應關係，
而升級**不會改群組名稱**。

### 要搬 9 張表

`telegram_message`／`auto_reply_ticket`／`conversation_followup`／
`llm_usage_log`／`quick_reply_phrasing`／`station`／`telegram_group_read`／
`telegram_group_member`／`telegram_group_staff_allow`

⚠ **後面三張有唯一索引**（`(對話, 人)`），直接 UPDATE 會撞鍵。
那幾張的語意都是「這個人在這個對話的狀態」，所以舊對話那一筆**直接丟掉**
（新對話已經有更新的一筆）。

⚠ **整段包在 transaction 裡**：搬到一半失敗的話訊息會散在兩個對話底下 ——
那比原本的「兩個視窗」更難收拾。

⚠ **最後訊息時間取兩者較晚的**：升級前後的訊息可能交錯，直接沿用新對話的
會讓它在列表往下掉。

## 驗證

本機 DB 沒有重複資料（正式機才有），所以驗到的是：

- 重複清單查詢（本機回「沒有標題重複的對話」）
- 找不到 chat_id 時的提示
- `--` 的行為（不加會噴 `The "-1" option does not exist`，加了就正常）

⚠ **合併本身沒有在有資料的情況下跑過** —— 正式機請先不加 `--force` 看試算，
確認每張表要搬幾筆再執行。

## 相關位置

| 檔案 | 職責 |
|---|---|
| `TelegramChatService::migrateChatIdIfNeeded()` | 收訊端換 chat_id（防止再發生） |
| `app/Console/Commands/MergeTelegramGroupCommand.php` | 合併既有的重複資料 |
| `TelegramBotService::logMigration()` | 送出端（既有，只記 log 不自動改） |
