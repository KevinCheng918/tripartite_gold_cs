# 資訊不足時先跟客人要資料

## 現況

已完成並上線。`AskInfoCategorySeeder` 已跑，類別 `#14 需要補充資訊`（sort 9998）。

> **題庫裡這一類目前是空的，所以 AI 還不會自己追問** —— 要等同仁按④累積。
> 這是刻意的設計，理由見下面「核心原則」。

## 問題

客人常常只丟一句就沒了：

```
客人：訂單沒收到款
客人：這筆卡住了
客人：為什麼一直失敗
```

同仁得先跟他要代理帳號、訂單號，或問清楚是代收還是代付，才查得下去。

但求助單的框架預設「**同仁要提供答案**」——

```
請「引用回覆」本則訊息提供答案。
⚠️ 回答內容會原文轉給客戶，請用可以直接給客戶看的語氣。
```

同仁收到這種單子只能按「忽略」，**那次對話就白費了**：
下次有人用同樣的方式問，照樣轉人工，照樣得再問一次。

## 做法

分兩邊：讓同仁能把「要問什麼」記下來，以及讓 AI 學會自己判斷要不要問。

### 核心原則：AI 只決定「要不要問」，不決定「問什麼」

> **追問的內容一律是題庫原文，模型不准自己編。**
>
> 這跟 [[auto-reply]] 的「只准挑，不准寫」是同一條原則。
> 題庫還沒有相符的追問題目時，`item_id` 會是 null，那就照舊轉人工 ——
> 寧可轉人工，也不要讓它自己想要問什麼。

所以一開始（題庫還沒有追問題目）這個功能等於沒作用，
要等同仁按④累積出題目，覆蓋率才會慢慢上來。**這是刻意的**。

### 同仁這邊：求助單加「④ 先問客人，並記住要問什麼」

同仁引用回覆打要問的話 → 按④：

1. 那句話送給客人
2. 同時存進題庫，**自動歸到「需要補充資訊」類別**

跟「② 回覆客人並加入題庫」的差別只有一個：**不必選類別**。
少一步，而且類別固定才有下面那件事。

### AI 這邊：`needs_info`

schema 多一個 boolean。「要先跟客人要資料」跟「答不答得出來」是兩件事，
分開問才不會混在 `confidence` 裡。

prompt 教它：判斷資訊不足時，**要去「需要補充資訊」這一類裡挑題目**——
那些題目的答案就是「要跟客人要哪些資料」。

> 類別名稱同時是給模型看的線索。prompt 裡會標出每題的類別，
> 它靠這個分辨「這題是答案」還是「這題是要資料」。
> **所以類別名稱不要隨便改**，要改就連 `constants` 一起改。

prompt 也寫明什麼時候**不要**追問：

- 題庫那題的答案本身就含「麻煩提供…」——那題直接回答就好，不必再多問一次
- 客人只是在寒暄、或在提需求
- 前面的對話裡他已經給過那些資料了（靠 [[auto-reply-context]] 的脈絡）

### 決策層

`DECISION.ASK_INFO`。送出的內容與命中答案完全一樣（話術模板 + 題庫原文 + 分則）——
對客人來說「請提供訂單號」跟一般回覆沒有差別，不該長得不一樣。

> ⚠️ **追問不標記已回覆。** 這件事還沒處理完，客人補資料之前
> 既有的未回覆告警要繼續響，同仁才不會漏掉。

## 追問冷卻（必要，不要拿掉）

> ⚠️ 客人補了資料之後，模型有可能又覺得「還是不夠」而再問一次 ——
> 一來一往變成無止境的追問，客人會直接炸掉。
>
> `auto_reply.ask_info_cooldown`（預設 10 分鐘）：同一個對話在這段時間內
> **只追問一次**；第二次即使判斷資訊不足也照舊轉人工，交給同仁判斷還缺什麼。

快取 key 是 `auto_reply.asked.{group_id}`。設定成 0 等於關掉冷卻 ——
那是刻意的選擇，所以沒有 fallback 值蓋它。

預覽（`preview()`）沒有對話，不受冷卻限制，否則測不出來。

## 驗證過的五條路徑

| 情境 | 結果 |
|---|---|
| `needs_info=true` + 有追問題目 | `ask_info` |
| 冷卻期間內再來一次 | `wait`（不連續追問） |
| `needs_info=true` 但題庫沒有對應題目 | `wait`（轉人工，不自己編） |
| `needs_info=false` + `high` | `answer`（原本的行為沒變） |
| 預覽（無對話） | `ask_info`（不受冷卻限制） |

## 異動檔案

| 檔案 | 改什麼 |
|---|---|
| `config/constants.php` | `ASK_INFO_CATEGORY`、`ACTION.ASK_INFO`、`DECISION.ASK_INFO` |
| `config/auto_reply.php` | `ask_info_cooldown`；prompt cache key → v4 |
| `database/seeders/AskInfoCategorySeeder.php` | 建「需要補充資訊」類別 |
| `app/Services/AutoReply/ClaudeCodeMatcher.php` | schema 的 `needs_info`、prompt 的判斷規則 |
| `app/Services/AutoReplyService.php` | `decide()` 的 ASK_INFO 路徑、`canAskInfo()`／`markAsked()`、`replyAskInfo()` |
| `app/Services/AutoReplySupportService.php` | ④ 按鈕、`handleAskInfo()`、`askInfoCategoryId()` |

## 上線前要做

```bash
php artisan db:seed --class=AskInfoCategorySeeder
php artisan optimize
```

## 相關

- [[auto-reply]] — 自動回覆主體、「只准挑，不准寫」
- [[auto-reply-context]] — 對話脈絡（判斷「他剛剛已經給過了」靠這個）
- [[auto-reply-learning]] — 候選按鈕與問法樣本（同一套「從人工處理累積」的思路）
