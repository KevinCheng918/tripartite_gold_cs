# ⚠⚠ 求助單的答案被送到另一個客人的群組（2026-11-10，v3.87）

> 需求方 2026-11-10 回報（附兩張截圖）：「我這邊 AI 轉過來的訊息，群組是 TR，
> 但是回覆到另一個客人了，這是嚴重錯誤」

**狀態：已修（v3.87）。⚠ 上線有順序要求，見文末。**

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
| 一筆都沒有 | 靜靜忽略 —— ⚠ 話題群組裡**每則閒聊**都帶 `reply_to_message`，這是最常走到的一條 |
| 對到的單屬於**別的** chat | 回一句說明 ＋ `Log::warning` |

⚠ **最後那種絕對不能靜靜忽略**：它跟閒聊的症狀一模一樣（沒反應），
同仁會以為系統壞了而一直重送，而我們要等客人抱怨才會知道。

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
  `repliedText()`；`openTicket()` 存 `ask_chat_id`；`handleAskInfo()` 的文案
- `app/Repositories/AutoReplyTicketRepository.php` — `findByAskMessageId()` 改回傳多筆
  ＋ `orderByDesc`；`COLUMNS` 補 `ask_chat_id`
- `app/Models/AutoReplyTicket.php` — PHPDoc ＋ cast
- `config/changelog.php` — v3.87

## 相關

- [features/auto-reply.md](../features/auto-reply.md) — 求助單迴路的全貌
- [bugfix/2026-10-26-duplicate-telegram-conversation.md](2026-10-26-duplicate-telegram-conversation.md)
  — **同一個外部事實的另一個方向**（supergroup 升級換 chat_id）
