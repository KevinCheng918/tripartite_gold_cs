# 自動回覆進行中的狀態標示

> **狀態：已完成實作。** 不需要 migration、權限或新路由，部署跑 `php artisan optimize` 即可。

## 目標

Claude Code CLI 跑一次要數秒到數十秒。這段期間後台完全沒有任何跡象，
客服不知道系統是不是正在處理，容易**自己也插手回一次**，客人就收到兩份答案。

所以要在後台標示「AI 正在回覆」，並且在結束時明確收掉。

## 為什麼不沿用「正在輸入」那套

既有的 `telegram.typing` 是**節流式**的：前端收到就顯示，4 秒後自動隱藏，
靠持續發事件維持。那適合「人在打字」這種持續行為。

自動回覆不同 —— 它有明確的開始與結束，中間可能 40 秒完全沒有事件。
用 4 秒自動隱藏的話，畫面會在 AI 還在跑的時候就把提示收掉，
等於沒標示。所以要做成**明確的開始／結束兩個事件**。

## 狀態存哪

用 **Cache，不落 DB**。

這個狀態的壽命只有幾十秒，寫進 `telegram_group` 等於為了暫時狀態
去寫一張每則訊息都要讀的表，而且 worker 掛掉時欄位會永遠留在「進行中」。

- key：`auto_reply_running_{groupId}`（一個群組一個 key）
- TTL：**180 秒**。Job 的 `timeout` 是 120 秒，留 60 秒緩衝 ——
  **worker 被砍掉、機器重開這類連 `failed()` 都不會觸發的情況，靠 TTL 自己收掉**，
  不會有對話卡在「AI 回覆中」永遠不消失
- 不用單一 key 存整包 group id 陣列：兩個 Job 同時改會互相覆蓋（race）。
  一個群組一個 key，列表查詢時用 `Cache::many()` 一次取回，沒有 N+1

## 事件

新增 `App\Events\AutoReplyProgress`（`ShouldBroadcast`，channel `telegram-chat`，
廣播名 `auto-reply.progress`），帶 `groupId` 與 `running`。

在 `AutoReplyJob::handle()` 裡：

```php
$this->markRunning(true);

try {
    $autoReplyService->handle($this->groupId, $this->text, $this->messageId);
} finally {
    // 不管成功、失敗、丟例外都要收掉 —— 沒有 finally 的話，
    // Claude 一失敗畫面就會卡在「AI 回覆中」直到 TTL 過期
    $this->markRunning(false);
}
```

`failed()` 裡也要再收一次：Job **逾時**是直接被 kill，`finally` 不保證跑得到。

## 後台呈現

兩個地方都要標，因為客服不一定正開著那個對話：

1. **對話視窗**（開著的那一個）——
   沿用 `#tg-typing-indicator` 的位置顯示「AI 正在回覆…」，
   但**不自動隱藏**，只由 `running=false` 的事件或安全逾時收掉
2. **左側對話列表** —— 該列加一個轉圈圖示，讓客服在看別的對話時也知道

### 初始狀態

重新整理頁面或切換對話時，Pusher 事件早就發完了，畫面要有辦法知道現況：

- `ajaxGroups` 每個群組多回一個 `auto_reply_running`（用 `Cache::many()` 一次取）
- 切換對話時直接看這個值決定要不要顯示

### 前端的安全逾時

即使有 `finally` 與 `failed()`，仍要在前端設一個保險：
顯示後 **180 秒**（與 Cache TTL 一致）自動收掉。

理由是 broadcast 本身可能丟失（Pusher 斷線、worker 送出前就被砍）。
少了這道，畫面會一直轉圈，客服會以為系統壞了。

## 邊界情況

- **客人連續傳兩則**：會有兩個 Job。第二個 Job 開始時 key 仍在，
  結束時第一個可能還在跑 —— 但兩者都會寫同一個 key。
  這是可接受的近似：只要「還有 Job 在跑」就顯示，最後一個結束時收掉。
  真要精確計數就得用 atomic increment，為了一個提示不值得
- **queue 是 sync 時**（local）：Job 在 webhook 的 request 內同步跑，
  兩個事件仍會照序送出，前端一樣收得到
- **被忽略的成員發言**：根本不會 dispatch Job，自然不會顯示
- **自動回覆關閉**：同上

## 異動檔案

| 類型 | 檔案 |
|------|------|
| Event（新） | `app/Events/AutoReplyProgress.php` |
| Service（新） | `app/Services/AutoReplyProgressService.php`（Cache 讀寫與廣播） |
| Job | `app/Jobs/AutoReplyJob.php`（`start()` + `finally` + `failed()` 三道） |
| Service | `app/Services/TelegramChatService.php`（`getConversationList` 帶 `auto_reply_running`） |
| Config | `config/auto_reply.php`（快取前綴與秒數） |
| 前端 | `main.js`（綁事件）、`layout.js`（提示元素、列表圖示、狀態還原） |
| 語系 | `telegram_chat.php` tw → cn → en |

不需要 migration、不需要新權限、不需要新路由。`state.js` 也不用改 ——
狀態直接掛在 `groupsData` 上，沒有額外的全域變數。

### 實作時補上的兩道防護

設定讀不到時（**部署忘了更新 config 快取**就會這樣）的後果比想像嚴重，
所以 `AutoReplyProgressService` 兩個值都有 fallback 常數：

- **前綴是 null** → key 會變成純數字 `1`、`2`，**撞到別人的快取**：
  讀到錯的資料，`forget` 還會把別人的東西刪掉，而且完全靜默
- **秒數是 null** → `(int) null` 是 0，**TTL 0 等於立刻過期**，
  提示在畫面上閃一下就消失，比沒做還糟

⚠️ 不能只靠 `config($key, $default)` 的第二個參數 ——
那只在 key 不存在時生效，key 存在但值是 null 時照樣回 null。

## 已確認

| 項目 | 決定 |
|------|------|
| 提示文字 | **「AI 正在查詢題庫並回覆客人…」**，講清楚它在做什麼 |
| 列表圖示 | **靜態機器人圖示**（`fa-robot`），不用轉圈 |
| 標示範圍 | **對話視窗與左側列表兩邊都要** |
| 回覆期間鎖輸入 | **不做**。自動回覆開著時輸入本來就鎖住，這題只影響「剛關掉開關但還有殘留 Job」這種極短暫的情況 |
