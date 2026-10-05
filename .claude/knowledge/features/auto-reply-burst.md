# 客人連發多則時只回一次（設計稿，**尚未實作**）

> **狀態：等需求方確認。** 第 1 項待確認會決定方案可不可行。

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
**每則各回一次**。這讓修法簡單很多：**只要讓前面幾則不要回**，
最後一則回覆時自然會帶到前面的內容。不需要把多則文字合併起來。

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

## ⚠ 待確認

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

#### 開 queue 要做什麼

`database` driver 就夠（不必上 redis）：

```bash
php artisan queue:table && php artisan migrate   # 一張 jobs 表
# .env
QUEUE_CONNECTION=database
```

再加一個**常駐 worker**（systemd 或 supervisor），跑
`php artisan queue:work --tries=1`。⚠ worker 要用**跟 php-fpm 同一個帳號**
跑，否則又是 [[daily-rate]] 那個 storage 權限問題。

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

- [[auto-reply-context]] — AI 看得到前面幾則，這是本方案不必合併文字的前提
- [[auto-reply]] — 自動回覆本體
