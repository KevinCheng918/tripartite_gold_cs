# Bug 修復：固定某一個 Bot 的群組讀不到圖片

## 問題描述

系統有兩個 Telegram Bot（分別對應不同系統 / 站台群組）。
其中**固定一個 Bot 所在的群組**，客人傳來的圖片在客服對話窗顯示成空氣泡
（只剩引用圖示與時間，沒有圖片）；另一個 Bot 的群組完全正常。

文字訊息一切正常，只有圖片／貼圖／影片／檔案抓不到。

## 根因

`app/Services/TelegramChatService.php` 的 `handleIncomingMessage()` **執行順序反了**：

```
213-273  解析媒體 → downloadTelegramFile() → getFileUrl()   ← 用到 token
292      switchBotToken($group)                             ← 才切換 token
```

`downloadTelegramFile()` → `TelegramBotService::getFileUrl()` 用的是
`$this->defaultToken`，也就是 `.env` 的 `TELEGRAM_BOT_TOKEN`。

Telegram 的 **`file_id` 是綁定 Bot 的**：用 A Bot 的 token 呼叫 `getFile`
去取 B Bot 收到的 `file_id`，會回 400（wrong file identifier）。

所以：

| 群組所屬 Bot | 結果 |
|---|---|
| `.env` 預設 token 的那個 Bot | token 剛好對，圖片正常 |
| 系統表另設 `bot_token` 的 Bot | token 不對，`getFile` 失敗 → `media_url` 存成 null → 空氣泡 |

「固定同一個 Bot 的群組壞掉」正是這個特徵 —— 跟網路、權限、群組設定都無關。

所有 Bot 的 webhook 都指向同一個 `/api/telegram/webhook`
（見 `TelegramSetWebhookCommand`），payload 裡沒有 Bot 身分，
只能靠 `chat_id → group → station → system.bot_token` 還原，
所以「先切 token 再下載」是唯一正確順序。

## 修法

把媒體解析拆成「取 file_id」與「下載」兩段，下載移到 `switchBotToken()` 之後：

| 項目 | 做法 |
|------|------|
| 新增 `parseMedia()` | 統一解析 photo / sticker / document / 其他，只回傳 `{type, file_id, prefix, name, fallback_text}`，**不下載** |
| `parseOtherMedia()` | 同步改成回傳 `file_id` + `prefix`，不再自己呼叫 `downloadTelegramFile()` |
| `handleIncomingMessage()` | 解析 → early return 檢查 → 找/建群組 → `switchBotToken()` → **才** `downloadTelegramFile()` |

early return（無文字也無媒體就跳過）仍排在建群組之前，
所以不會因為重構而為雜訊訊息建出空群組。

## 前提設定（修完仍要確認）

`switchBotToken()` 走的是 `group->station->system->bot_token`，任一環沒接上就會
fallback 回預設 token、症狀照舊：

1. 該群組在客服系統有**綁定站台**
2. 該站台有設定 **system_id**
3. 該系統的 **`bot_token`** 有填，且就是這個群組裡那支 Bot 的 token

新群組第一則訊息還沒綁站台，媒體仍抓不到（既有限制），綁定後之後的訊息才正常。

## 通則

**多 Bot 架構下，任何會打 Telegram API 的動作都必須先切 token。**
`file_id`、`message_id` 都是 Bot 作用域的識別碼，不能跨 Bot 使用。
新增會呼叫 `botService` 的流程時，第一件事是確認 `switchBotToken()` 已經跑過。

## 變更檔案

- `app/Services/TelegramChatService.php` — `handleIncomingMessage()` 重排；新增 `parseMedia()`；`parseOtherMedia()` 改為不下載；`downloadTelegramFile()` 的失敗 log 改為中性文案並附 `bot_id`
- `app/Services/TelegramBotService.php` — 新增 `getBotId()`（取 token 冒號前的數字，供 log 用，token 本身不入 log）

原本的 log 寫死「可能超過 20MB 下載上限」，把 token 用錯的情況也講成檔案太大，
是這次不好查的原因之一 —— 一併改掉。
