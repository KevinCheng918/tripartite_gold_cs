# ⚠⚠ 求助單的答案被送到另一個客人的群組（2026-11-10，v3.87）

> 需求方 2026-11-10 回報（附兩張截圖）：「我這邊 AI 轉過來的訊息，群組是 TR，
> 但是回覆到另一個客人了，這是嚴重錯誤」

**狀態：已修（v3.87 ＋ v3.88）。⚠ 上線有順序要求，見第五節。**

> ⚠ **這張單有兩個症狀，根源相同**：答案送錯客人（v3.87，第一～七節）與
> **交接專區被插話**（v3.88，第八節）。第八節同時指出了 v3.87 的一個盲點 ——
> 只讀到第四節會以為 `ask_chat_id` 就夠了，**它不夠**。

截圖一是內部群組的求助卡，第一行寫著「群組：TR - LV系統詢問群」；
截圖二是同一份答案出現在**別的客人群組**裡。

---

## 一、先確認那則是系統發的，不是同仁回錯視窗

截圖二結尾的 `-A` 正是 `constants.AUTO_REPLY.SIGNATURE`（`config/constants.php:3861`），
由 `replyToCustomer()` 在送出前透過 `appendFixedSignature()` 加上。

⇒ **系統自己送錯的**，不是人為操作。這一步決定了後面要查哪裡 ——
如果是同仁手打，查的會是權限與介面，完全不同的方向。

## 二、整條鏈沒有第二道確認

```
同仁引用回覆求助卡
  → findByAskMessageId(reply_to_message.message_id)   ← 唯一的判斷點
  → 答案寫進那張單、按鈕 callback_data 帶那張單的 id
  → 按「回覆客人」→ sendReply($ticket->telegram_group_id)
```

後面三步都**忠實地**照著「找到的那張單」走，所以只要第一步對錯，
答案就會原封不動送到別的客人那裡。而求助卡上印著的「群組：TR」
從頭到尾**沒有被比對過一次**。

## ⚠⚠ 三、病根：message_id 被當成全域唯一鍵

`AutoReplyTicketRepository::findByAskMessageId()` 原本是：

```php
->where('ask_message_id', $askMessageId)->first();
```

四個缺陷疊在一起：

| 缺陷 | 後果 |
|---|---|
| `ask_message_id` 只有普通索引，**沒有 unique** | 重複進得來 |
| `first()` **沒有 ORDER BY** | 多筆時撈到哪一筆是**未定義行為** |
| 查詢**不帶 chat 維度**（表裡根本沒存） | 跨 chat 的單互相搶對應 |
| **沒有時間窗** | 三個月前的舊單照樣會被選中 |

而 **Telegram 的 message_id 只在單一 chat 內唯一**。這張表存著歷來所有
支援群組的單 —— 支援群組換過 chat 之後，新舊兩批單的 ask_message_id
會從小數字開始重疊。

### 為什麼支援群組的 chat 會換

**開啟「話題」功能會讓群組升級成 supergroup，Telegram 換發一個全新的 chat_id。**

⚠ 這個專案 10/26 才為**同一個外部事實**修過一次（`9c52956`），
但那次修的是**客人群組**那一側，而且 commit 訊息自己寫了：

> ⚠ 這裡可以自動改，跟支援群組設定不一樣：那邊的 chat_id 是人在設定頁填的

⇒ **支援群組那一側明確沒有處理**，而這個系統正在用話題功能。

> **一般化（這次又印證一次）**：同一個外部事實（chat_id 會變）會從好幾個方向
> 打進系統。10/26 補了「收訊端」，這次從「求助單對應」冒出來 ——
> 補一個方向之後，要主動去找**還有哪些地方把 chat_id 或 message_id 當成
> 永久不變的東西在用**，而不是等下一個症狀。

---

## 四、修法：三層，而且刻意不只修第一層

### 第 1 層：對應鍵改成 (ask_chat_id, ask_message_id)

新增 `ask_chat_id` 欄位，開單送出求助訊息時連同 message_id 一起存。

⚠ **存當下的設定值，不是查詢時再去讀**：設定頁日後被改掉時，
舊單仍要認得出自己屬於哪一個 chat。

#### ⚠⚠ migration 回填：刻意只填「不重複」的那些

重複的那幾筆**正是事故的來源**，而我們無從判斷哪一筆屬於哪一個 chat
（舊 chat_id 已經沒有任何地方留著）。猜一個填下去等於**把錯誤對應固化成資料**，
比留空更糟。

留空的後果是「引用那幾張舊卡會沒反應」—— 而那種情況有第 2 層接住，
會回一句說明並記 log。**沒反應遠好過回錯客人。**

### 第 2 層：比對放在 Service，而且分成三種結果

`matchTicket()` 把一次查詢的結果分成三種，因為它們該有的反應完全不同：

| 情況 | 反應 |
|---|---|
| 對到本 chat 的單 | 正常作答 |
| 一筆都沒有 | 靜靜忽略 |
| 對到的單屬於**別的** chat | 回一句說明 ＋ `Log::warning` |

⚠ **最後那種不要改成靜靜忽略**：同仁是真的在求助話題裡引用求助卡，
沒反應的話他會以為系統壞了而一直重送，而我們要等客人抱怨才會知道。

> ⚠ **v3.88 補記**：上面「可以放心回話」的前提是呼叫端已經先擋掉
> 「不是機器人發的」與「不在求助話題」兩種 —— 見第八節。
> **沒有那兩道的話，這個回話會跑去交接專區插話**，v3.88 修的就是這個。

⚠ **Repository 刻意回傳多筆而不是 `first()`**，比對留在 Service：
那裡才知道「現在的支援群組是哪一個」，而且同一次查詢的結果還要用來分辨
「對不到」與「對到別的 chat」。**在 SQL 裡 `where ask_chat_id` 會把後者這個
資訊丟掉**，同仁就只會看到跟閒聊一樣的沒反應。

⚠ 碰撞本身仍然記 `Log::error`：補上 `ask_chat_id` 之後那一行**理應永遠不觸發**，
所以它一旦出現就是「還有一條沒想到的路徑」，不是雜訊。

### 第 3 層：按鈕的回饋要講出「要送給誰」

`✅ 已安排回覆客人。` → `✅ 已安排回覆〔TR - LV系統詢問群〕。`

⚠ 這一層是**最重要的**：前兩層擋的是「這一個已知的 bug」，
這一層擋的是**所有**會讓對應錯掉的原因，**包含還沒想到的那些**。
同仁按完就看得到要送給誰，跟手上那張求助卡第一行一眼對得上。

（`handleAskInfo()` 那句「已安排把問題送給客人」同理也帶上群組名 ——
那一條也是往客人那邊送。）

---

## ⚠⚠ 五、上線順序：migration 一定要先跑

**Laravel 8 的 `isGuarded()` 會呼叫 `isGuardableColumn()` 去查資料表實際有哪些欄位。**

欄位還沒建起來時，`$ticket->update(['ask_chat_id' => ...])` 會被
**靜靜丟掉而且不會報錯** ⇒ 新單全都沒有 chat_id ⇒ 每一次引用作答都變成
「對不到」⇒ 整個求助單迴路停擺。

本機實測踩到過：驗證腳本 14 項裡有 4 項失敗，查到底發現不是邏輯錯，
是欄位還沒建。

```bash
# ① 先跑 migration
php artisan migrate --path=database/migrations/2026_11_10_000003_add_ask_chat_id_to_auto_reply_ticket_table.php
# ② 再部署程式
# ③ php artisan optimize
```

### 上線後請確認回填結果

migration 會記一筆 log（`auto_reply_ticket 回填 ask_chat_id`），裡面的
`duplicated_groups` 就是**這次事故波及的規模** —— migration 跑完之後
就再也算不出來了。

想先知道規模的話，上線前跑這段：

```sql
SELECT ask_message_id, COUNT(*) AS c,
       GROUP_CONCAT(id) AS ticket_ids,
       GROUP_CONCAT(telegram_group_id) AS group_ids
FROM auto_reply_ticket
WHERE ask_message_id IS NOT NULL
GROUP BY ask_message_id HAVING COUNT(*) > 1
ORDER BY c DESC;
```

查那一次事故本身（答案落在哪張單 vs 問題本來在哪張單，兩張 id 不同就是對應錯了）：

```sql
SELECT id, telegram_group_id, ask_message_id, status, answered_at,
       LEFT(question, 60) AS q, LEFT(answer, 60) AS a
FROM auto_reply_ticket
WHERE answer LIKE '%商戶代理總覽%' OR question LIKE '%創建的渠道%'
ORDER BY id DESC LIMIT 20;
```

---

## 六、驗證（14 項全過，唯讀）

Repository／AppSetting／SupportGroup 全換成假的，用 reflection 塞進 private
屬性（跳過建構子的 12 個依賴），不碰 DB、不呼叫 Telegram。涵蓋：

對到本 chat、查無單**不回話**（閒聊那條最常走）、只對到舊 chat 要拒絕**並說明**、
碰撞時挑本 chat 那張（不是最舊那張）、`ask_chat_id` 為 null 的舊單一律不認、
全都不是本 chat、回覆文案帶群組名、群組被刪掉時不會壞。

## 七、檔案

**新增**

- `database/migrations/2026_11_10_000003_add_ask_chat_id_to_auto_reply_ticket_table.php`

**修改**

- `app/Services/AutoReplySupportService.php` — `matchTicket()`、`supportChatId()`、
  `repliedText()`、`isTicketTopic()`（v3.88）；`handleSupportMessage()` 的兩道短路
  （v3.88）；`openTicket()` 存 `ask_chat_id`；`handleAskInfo()` 的文案
- `app/Repositories/AutoReplyTicketRepository.php` — `findByAskMessageId()` 改回傳多筆
  ＋ `orderByDesc`；`COLUMNS` 補 `ask_chat_id`
- `app/Models/AutoReplyTicket.php` — PHPDoc ＋ cast
- `config/changelog.php` — v3.87、v3.88

## 相關

- [features/auto-reply.md](../features/auto-reply.md) — 求助單迴路的全貌
- [bugfix/2026-10-26-duplicate-telegram-conversation.md](2026-10-26-duplicate-telegram-conversation.md)
  — **同一個外部事實的另一個方向**（supergroup 升級換 chat_id）

---

## ⚠⚠ 八、第二個症狀：交接專區被插話（2026-11-11，v3.88）

> 需求方 2026-11-11 附截圖：「我在交接專區的回應，為什麼會在內部支援群組出現，
> 交接是內部交接欸，這是嚴重錯誤」＋補充「我有做話題區分，不能混在一起」

截圖：同仁在**交接專區**引用回覆一則交接內容（`LV_190 👥 LV - AI 專區 /
1.若水跟ED:USDT優化需追蹤自動上分是否正常`），機器人卻回了
「這張單已經處理過了，如果要更新答案請直接到後台題庫修改。」

### 這同時也是碰撞真實存在的證據

那則交接訊息的 message_id **碰巧等於某張舊單的 `ask_message_id`** ——
所以 `findByAskMessageId()` 查得到、`isAnswerDelivered()` 為 true、
於是回了那句。在 v3.87 之前，**「查得到」就是唯一的判斷**。

### ⚠⚠ 這暴露了 v3.87 的一個盲點

v3.87 的 `ask_chat_id` 只解決了「**表裡兩張單**互相撞號」。
但真正的碰撞範圍更大：

> **表裡的單** vs **群組裡那些根本不在表裡的訊息**（交接、任務卡、班表、
> 催單…）撞號 —— 這一側再怎麼比對 chat_id 也查不出來，**因為那一側的
> chat_id 就是現在這個群組**。

⚠ 連帶地，migration 回填那個「只填 ask_message_id 不重複的」條件也擋不住這種：
一張舊 chat 的單只要在表內不重複就會被回填成現在的 chat，從此**偽裝成本 chat
的單**，撞到它就會被當成正牌求助卡。

### 修法：兩道判斷，而且都擺在查 DB 之前

```php
// ① 引用的不是機器人發的訊息 ⇒ 不可能是求助卡
if (Arr::get($quoted, 'from.is_bot') !== true) {
    return;
}

// ② 不在求助單那個話題裡 ⇒ 不是作答
if (!$this->isTicketTopic($message)) {
    return;
}
```

| 防線 | 擋掉什麼 |
|---|---|
| `is_bot` | **所有同仁之間的對話** —— 截圖那個情境就在這裡被擋住 |
| `isTicketTopic()` | 別的話題裡**機器人自己**發的訊息（交接通知、任務卡、班表…） |

⚠ ②是**唯一**擋得住「撞到的舊單已被回填成本 chat」那種情況的防線
（驗證第 ③ 項就是在測這個）。

⚠ **擺在查 DB 之前是刻意的**：話題群組裡**每則訊息**都帶 `reply_to_message`，
不先擋掉的話每一句閒聊都要查一次資料庫。修完之後絕大多數在第一道就停住。

⚠ `isTicketTopic()` 要處理「一個話題都沒勾」：那時求助卡發在主區
（`threadIdsFor()` 回 `[null]`），而主區的訊息**沒有** `message_thread_id` ——
所以要反過來判斷「沒有 thread_id 才算」。直接 `in_array(null, [])` 永遠是
false，等於在沒勾話題的環境把作答整個關掉。

### 連帶簡化

有了②之後，`matchTicket()` 裡「對到別的 chat 要不要回話」就**不必再判斷**了
—— 能走到那裡的一定在求助話題裡，放心回。

### 驗證（20 項全過，唯讀）

在 v3.87 的 14 項之上補了完整的 `handleSupportMessage()` 路徑：
交接專區引用人的訊息／引用機器人訊息／**撞到已被回填成本 chat 的單**
（最危險的那種，只剩話題擋得住）、求助話題裡四種正常行為不能被擋掉、
以及「一個話題都沒勾」時主區與話題的行為。

⚠ 其中「連 DB 都不查」是用假 Repository 數呼叫次數驗的 —— 短路有沒有真的短路，
光看程式碼很容易自己騙自己。

---

## 收尾稽核（2026-11-11，v3.87 ＋ v3.88）

逐條對 PROMPTS.md 六節重掃兩版的全部改動。**架構／版本相容／安全三節無違反**
（沒有 Controller／Request／Response／Blade／新介面；寫入都是單表所以不需要
交易；外部輸入的異常都有 Log）。抓到三處違反，全在第 2、3 節：

| 違反 | 問題 | 修法 |
|---|---|---|
| **第 2 節 短路順序** | 「沒有文字就不可能是作答」是**純字串判斷**，卻排在 `is_bot` 與 `isTicketTopic()`（要讀設定）**後面**。貼圖／純圖片的引用得先走完兩道才被擋 | `blank($answer)` 提到 `blank($quotedId)` 之後。順序變成**由便宜到貴**：陣列取值 → 字串 → 陣列取值 → 讀 cache → 查 DB |
| **第 2 節 重複解析** | `Arr::get($payload, 'message.reply_to_message')` —— 上面三行才剛把 `message` 取出來存進 `$message`，又從 `$payload` 重走一次 dot notation | 改成 `Arr::get($message, 'reply_to_message', [])` |
| **第 3 節 陣列取值** | `handleSupportMessage()` 裡還有兩處 `$message['message_id']` 直接存取，而我這次在**同一個方法**新增的程式碼都用 `Arr::get()` —— 同一段程式碼兩種風格 | 兩處都改成 `Arr::get()` |

### ⚠ 一併確認：「擺在查 DB 之前」這句註解站不站得住

`isTicketTopic()` 會讀設定，所以要先確認它**不是**自己去查一次 DB ——
否則註解就是在騙人。查過了：`AppSettingService::all()` 是
`Cache::remember(…)` **整份快取**，`topicIdsFor()` 走的是 cache 不是 DB。

⇒ 這個設計正好是 PROMPTS 第 2 節那條「**config / cache 開關判斷先於 DB 查詢**」
的標準形態。

順手確認過但**刻意不改**：

- **`handleCallback()` 裡的 `$callback['id']`**：也是直接陣列存取，但**不在這次
  異動範圍**。順手改會讓這次的 diff 混進不相干的東西
- **`$answer = $text;` 這個賦值**：看起來多餘（可以直接用 `$text`），
  但那是既有程式碼，改它等於把稽核擴大成重構
- **`Criteria` 封裝**：`findByAskMessageId()` 是固定條件，照既有慣例手寫
- **`ask_chat_id` 沒有加索引**：查詢條件是 `where ask_message_id`（已有索引），
  chat 的比對在 PHP 端 —— 加了是白加

> **版本號**：**沒有加 v3.89**。三處都是內部正確性與效率修正，
> 使用者看到的行為**一字未變**。

### 驗證（23 項全過，唯讀）

在 v3.88 的 20 項之上補了**短路順序**本身的驗證：沒有文字時不查 DB
**也不讀話題設定**、不是機器人發的時不讀話題設定。

⚠ 這兩項是用假物件**數呼叫次數**驗的 —— 「有沒有真的短路」光看程式碼
很容易自己騙自己，而順序一旦被後人調動，這兩項會立刻紅掉。
