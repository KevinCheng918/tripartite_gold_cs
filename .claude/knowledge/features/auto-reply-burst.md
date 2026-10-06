# 客人連發多則時只回一次

> **狀態：已實作（2026-10-05）。** 佇列已切到 `database`，worker 掛在
> `schedule:run` 上，實測每分鐘會被拉起來。

## 問題

客人在短時間內連傳好幾句，其實在講同一件事：

```
22:37  老闆
22:37  這邊先直接儲值
22:38  不讓你為難
```

目前**每一則都會觸發一次自動回覆**，於是同一件事被當成三個問題各回一次。

## 現況（調查結果）

| | 現在怎麼運作 |
|---|---|
| 觸發 | 每則客人訊息 → `TelegramChatService` 丟 `AutoReplyJob::dispatch()` |
| 脈絡 | **AI 看得到前面的話**（`auto_reply.context`：前 6 則、15 分鐘內） |
| 防重 | **沒有**「這則是不是最新」的檢查 |

⚠ **所以問題不是「AI 不知道前面說了什麼」** —— 它有脈絡。問題單純是
**每則各回一次**，所以第一步是**讓前面幾則不要回**。

> ⚠️ 當時還下了一個結論：「不需要把多則文字合併起來，脈絡帶得到」——
> **那個判斷是錯的，上線當天就被推翻**，見下方「後記」。

## 方案：只讓「最後一則」回覆

`AutoReplyJob` 多兩個檢查點：

```
Job 開始   → 這則還是這個對話最新的客人訊息嗎？
             不是 → 直接結束（不呼叫 AI，省一次 CLI 錢）
AI 跑完後  → 再檢查一次（跑的這幾十秒裡客人又說話了）
             不是 → 不發送
```

兩個檢查點分工不同：**前面那個省成本，後面那個防競態**。
Claude CLI 要跑數秒到數十秒，中間客人很可能又補了一句。

### 要不要加延遲

光靠上面兩個檢查，涵蓋的是「CLI 還在跑的那段時間」。如果 CLI 跑很快
（3 秒）而客人 5 秒後才補第二句，第一則早就回出去了。

加一點 `->delay()` 可以把時間窗拉長，代價是**每次回覆都慢那幾秒**
（含只傳一句的客人）。

| 延遲 | 效果 | 代價 |
|---|---|---|
| 0 秒 | 只擋得住「CLI 執行期間」的連發 | 不影響回覆速度 |
| **5 秒**（建議） | 多數連發都擋得住 | 所有人都慢 5 秒 |
| 10 秒以上 | 幾乎都擋得住 | 回覆明顯變慢，可能被嫌慢 |

## ⚠ 後記（2026-10-06）：光「只回最後一則」是不夠的

上線當天就被需求方抓到一個矛盾：

```
第一則  {"data":[],"code":10002,"msg":"代理未授權"}   ← 這一則本身能命中題庫
第二則  這個是甚麼原因                                   ← 指代前一則
```

連發合併讓第一則讓位給第二則，結果**第二則轉人工了**。需求方的話：

> 第一句本身就有命中了，如果按照妳說第一句忽略，但第二句判斷時會把第一句
> 當脈絡的話，怎麼會把第二句轉人工處理

**合併機制反而讓命中率下降了。**

### 為什麼脈絡救不了

設計這個功能時我判斷「不必合併文字，因為脈絡帶得到前面幾則」。那個判斷錯了：

| | 模型拿到的東西 |
|---|---|
| 要比對的那句話 | 「這個是甚麼原因」← **主詞是「這個」，本身沒有任何可比對的內容** |
| 脈絡 | 參考資料，不是「要回答的問題」 |

脈絡的定位是「幫助理解」，而真正拿去跟題庫比的仍然是那句模糊的話。
**能命中的資訊在第一則裡，卻沒有進到被比對的文字中。**

### 改法：把這一輪的話接起來再比對

`TelegramRepository::getConsecutiveInbound()` 取「從上一次我方發言之後到
這一則為止」的所有客人訊息，`mergeRun()` 用換行接成一段：

```
{"data":[],"code":10002,"msg":"代理未授權"}
這個是甚麼原因
```

模型看到的才是**完整的問題**。

三個細節：

- **遇到 outbound 就斷** —— 那表示已經回過了，再往前是上一輪的事
- **合併進去的那幾則要從脈絡裡排除**（`buildHistory()` 的 `$excludeIds`），
  否則同樣的內容送兩次，佔 token 又讓模型看到重複的東西
- **轉人工時求助單用的也是合併後的問題** —— 同仁只看到「這個是甚麼原因」
  一樣查不下去

只有一則時完全不變（`mergeRun()` 在 `count() < 2` 時直接回原文）。

> 教訓：**「有脈絡」跟「要比對的句子本身有內容」是兩件事。**
> 判斷一個指代詞句能不能比中，看的是那句話本身，不是它旁邊有什麼。

## 實際做法（已上線）

| 位置 | 做什麼 |
|---|---|
| `TelegramChatService` | dispatch 時 `->delay(auto_reply.burst_delay)`，**預設 6 秒** |
| `AutoReplyJob::handle()` 開頭 | 第一道：客人在等待期間又說話了 → 直接 return，**連 AI 都不呼叫** |
| `AutoReplyService::handle()` | 第二道：AI 跑完後再確認一次 → 不送出 |
| `TelegramRepository::hasNewerInbound()` | 判斷依據 |

⚠ **第二道不能省。** CLI 要跑數秒到數十秒，客人很容易在那段時間補一句 ——
只有第一道的話，連著傳的三句仍會被回三次。

⚠ **第二道擺在「命中答案」與「轉人工」兩個分支之前。** 不然連發時會開出
好幾張內容幾乎一樣的求助單，同仁要一張張關掉。

### 判斷依據的三個細節

`hasNewerInbound()` 看起來只是一行查詢，但三個條件都是必要的：

| 條件 | 不這樣寫會怎樣 |
|---|---|
| `direction = INBOUND` | AI 自己發的回覆會被當成「客人又說話了」—— **一回覆就把自己判死**，從此沒有任何自動回覆送得出去 |
| `id >` 而不是 `created_at >` | 同一秒進來的多則訊息時間相同，用時間比會漏掉 |
| `blank($messageId)` 回 false | `auto-reply:test` 這類沒有 message id 的呼叫會全部被判定為過期 |

> 驗證時特別測了「**單則訊息不可被判定為過期**」—— 那個方向寫反的話，
> 不會報錯，只會讓所有自動回覆靜默停擺。

## 待確認（已解決，留著當紀錄）

### 1. ⚠ 正式機是 `sync` —— 佇列從來沒有真的生效（2026-10-05 確認）

`AutoReplyJob implements ShouldQueue`、`TelegramChatService` 也確實
`dispatch()` 了，但 **`QUEUE_CONNECTION=sync` 會當場同步跑完**，
那一行 dispatch 等於直接呼叫。

那段 dispatch 的註解寫著：

> 自動回覆丟佇列處理：Claude Code CLI 要跑數秒到數十秒，
> webhook 同步等下去會逾時，Telegram 會重送而造成重複回覆

**這個防護從來沒有生效過。** 於是三個問題同時存在：

| # | 問題 | 嚴重度 |
|---|---|---|
| 1 | **Telegram 可能重送** —— webhook 要等 CLI 跑完才回 200，而 `TelegramWebhookController` **沒有 `update_id` 去重**，重送就是重複處理、重複回覆 | 高 |
| 2 | **每則客人訊息佔住一個 php-fpm worker 數十秒** —— 同時幾個客人說話就可能把 worker 吃光，整個後台跟著卡 | 高 |
| 3 | 連發合併（本文件的主題）做不了 delay | 中 |

⚠ **這三個是同一個根因**，開了 queue 一起解決：webhook 立刻回 200
（不重送、不佔 worker），而且 delay 才有意義。

#### 不開 queue 的話，本方案還剩多少

「**AI 跑完後再檢查一次**」**仍然部分有效**，因為每個 webhook 是獨立的
HTTP request、php-fpm 會並行處理：A 還在跑 CLI 時，B 的 request 已經把
訊息寫進 DB 了，所以 A 發送前檢查得到 B。

但「Job 開始時檢查」幾乎無效（A 開始時 B 還沒到），`delay` 完全無效
（sync 直接忽略）。而且這條路**依賴 php-fpm 還有空閒 worker** ——
正是上面第 2 點不保證的事。

#### 開 queue：程式這邊已經備好（2026-10-05）

**不需要 systemd、不需要 supervisor、不需要動 crontab。**
`schedule:run` 本來就每分鐘在跑，worker 掛在那裡就好
（`Kernel::scheduleQueueWorker()`）：

```php
$schedule->command('queue:work --max-time=55 --tries=1 --timeout=180')
    ->everyMinute()->withoutOverlapping(2)->runInBackground();
```

每分鐘起一個、跑 55 秒自己退場，下一分鐘接手 —— 等於常駐，交接時有幾秒
空窗（進來的工作等下一輪）。

四個參數都不能亂改：

| 參數 | 為什麼 |
|---|---|
| `--max-time=55` | ⚠ **不要用 `--stop-when-empty`** —— 那個做完就退出，下一批要等下一分鐘，客人最多等 60 秒才收到回覆 |
| `--tries=1` | 與 `AutoReplyJob::$tries` 一致（不重試是刻意的） |
| `--timeout=180` | 要比 Job 的 `$timeout = 120` 大，讓 Job 自己逾時並被記錄 |
| `runInBackground()` | ⚠ **少了它 `schedule:run` 會卡著等 55 秒**，其他排程全被阻塞 |

⚠ **`sync` 時不排程**（`config('queue.default') === 'sync'` 就 return）——
所以切換只要改 `.env` 一個地方，現狀完全不受影響。

**切換步驟**（正式機）：

```bash
php artisan migrate          # 建 jobs 表（migration 已經在版控裡）
# .env: QUEUE_CONNECTION=database
php artisan optimize         # 清 config 快取，worker 才會被排進去
```

#### 帳號與權限：這台機器本來就設好了

2026-10-05 查證：

```
drwxrwsr-x  apache  webdata   storage/app/claude-home
drwxrwsr-x  rduser  webdata   storage/logs
id rduser → groups=1001(rduser),1500(webdata)
```

**php-fpm 是 `apache`、cron 是 `rduser`、兩邊共用 `webdata` 群組**，
目錄是 775 + setgid（新建的東西自動繼承群組），cron 那行也有 `umask 002`。
所以 worker 用 rduser 跑**寫得進** `claude-home` 與 `logs`，不必再開權限。

> ⚠ 那為什麼 [[daily-rate]] 的截圖目錄會卡住？**是 umask 削的**：
> `mkdir($path, 0775)` 的 mode 會被 umask 遮罩，php-fpm 的 umask 是 022，
> 實際只建出 `0755` → 群組不可寫 → rduser 進不去。
> 已在 `ScreenshotService::makeWorkDir()` 與 `ClaudeCodeMatcher::claudeHome()`
> 補上 **`chmod`（不受 umask 影響）**，往後新建的目錄一律 0775。

### 2. 延遲幾秒？

看上面那張表。建議 5 秒 —— 但這是「所有客人都慢 5 秒」換「連發的人只被回一次」，
要你決定划不划算。

### 3. 跳過的那幾則要不要留痕跡？

被跳過的訊息在對話視窗上看起來就是「沒有自動回覆」。要不要記 log
（方便事後確認是跳過還是壞掉）？建議記 info，不寫進對話。

## 會動到的檔案（預估）

| 檔案 | 改什麼 |
|---|---|
| `app/Jobs/AutoReplyJob.php` | 兩個檢查點 |
| `app/Services/TelegramChatService.php` | dispatch 加 `->delay()` |
| `app/Repositories/TelegramRepository.php` | 「這則之後還有沒有更新的客人訊息」查詢 |
| `config/auto_reply.php` | 延遲秒數 |
| `config/changelog.php` | 版本紀錄 |

## 相關

- [[auto-reply-context]] — 脈絡機制本身；⚠ 但脈絡**不能取代**合併，見「後記」
- [[auto-reply]] — 自動回覆本體
