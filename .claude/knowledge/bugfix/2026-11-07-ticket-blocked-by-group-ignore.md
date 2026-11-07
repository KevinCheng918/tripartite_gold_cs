# 沒回覆卻說「這張單已經處理過了」，引用作答被擋

**日期**：2026-11-07（v3.79）
**回報**：「確認一下 AI 轉回來的問題回覆的『這張單已經處理過了。』觸發條件，
我發現我沒有做回覆，但他還是回覆這個，導致說我要引用回覆時，無法回覆，
要登入後台去回覆，這個如果判斷會失效就拿掉」

## 觸發條件

作答閘門是 `AutoReplyTicket::isClosed()`，它把 **4 個狀態**都當成「不准再作答」：

| 狀態 | 誰設的 | 真的處理完了嗎 |
|---|---|---|
| REPLIED (3) | 支援群組按「回覆客人」 | ✅ 答案已送給客人 |
| SAVED (4) | 支援群組按「加入題庫」 | ✅ 答案已入題庫 |
| **IGNORED (0)** | **`ignoreOpenByGroup()`** ← 病根 | ❌ **可能一個字都沒回** |
| EXPIRED (5) | `ticket:close-stale` | ❌ 只是放太久被系統收掉 |

### 病根：`ignoreOpenByGroup()` 是「整個對話一起關」

`AutoReplyTicketRepository::ignoreOpenByGroup($groupId)` 把**那個對話全部**
PENDING／ANSWERED 的單標成 IGNORED。它有 **3 個呼叫點**，全都在
`TelegramChatService`，條件是「客服對客人送出了一則**人工**訊息」：

| 位置 | 情境 |
|---|---|
| `sendReply()` ← 最常踩到 | 客服在聊天視窗打字／傳圖（`!$isAuto`） |
| `sendSharedFileReply()` | 客服從文件區挑檔案傳給客人 |
| `sendFileReply()` | 客服在輸入區上傳檔案 |

所以實際發生的事：

```
AI 答不出來 → 開單 → 轉到支援群組
客服（很自然地）先跟客人說一句「稍等，我幫您確認」
  → sendReply() 觸發 ignoreOpenByGroup()
  → 那個對話所有未處理的單 → IGNORED
客服回支援群組要引用作答
  → isClosed() 為 true
  → 「這張單已經處理過了」
  → 只能登入後台
```

⚠ **「我沒有做回覆」是準確的描述**：他沒有回覆*那張單*，但他對*那個客人*
送了訊息 —— 而那就足以把單關掉。送一個檔案、回另一個不相干的問題，
一樣會關掉。

⚠ 這不是 race condition，是**同一個狀態被當成兩種意思**：
`ignoreOpenByGroup()` 寫 IGNORED 的用意是「**不要再催這張單**」
（註解寫得很清楚：「否則會一路催到上限，報表上永遠紅著」），
而閘門把它讀成「**不准回答**」。挑錯了意思。

## 修法：閘門只擋「答案真的出去了」

`isClosed()` → 換成 `isAnswerDelivered()`，**只認 REPLIED／SAVED**。

```php
public function isAnswerDelivered()
{
    $delivered = [
        (int) config('constants.AUTO_REPLY.TICKET_STATUS.REPLIED'),
        (int) config('constants.AUTO_REPLY.TICKET_STATUS.SAVED'),
    ];

    // status 有 cast 成 integer，所以用嚴格比對
    return in_array((int) $this->status, $delivered, true);
}
```

三個閘門全部換掉（`AutoReplySupportService`）：

| 行 | 路徑 | 訊息 |
|---|---|---|
| ~387 | 引用回覆作答 | 「已經處理過了，如果要更新答案請直接到後台題庫修改。」 |
| ~490 | 按「用 #111 回覆」 | 「這張單已經處理過了。」 |
| ~693 | 按「要怎麼處理」 | 「這張單已經處理過了。」 |

⚠ **`ignoreOpenByGroup()` 本身沒有動。** 它停止催促的作用是對的 ——
客服已經親自回了客人，那張單不該再催。要修的是「讀」那個狀態的地方，
不是「寫」的地方。

⚠ **重新作答不會讓催促復活。** `getTicketsDueForRemind()` 只查
`status = PENDING`，而引用作答之後是 ANSWERED，撈不到。確認過才敢放行。

⚠ **EXPIRED 也一起放行。** 單被系統收掉，不代表同仁現在補的答案沒價值 ——
那個答案還要進題庫給下一次用。

⚠ **嚴格比對**：原本是 `in_array($this->status, $closed, false)`（寬鬆）。
`status` 有 `'status' => 'integer'` 的 cast，所以兩邊都轉 int 再嚴格比
—— 寬鬆比對下 `null == 0` 會成立，而 0 正是 IGNORED。

## 連帶修掉的一個「已知做不到」

`constants.AUTO_REPLY.HANDLED_NOTICE`（後台按「客服已自行處理」後發到支援
群組的那則）原本的註解寫著：

> ⚠ 不要在這裡寫「請引用回覆補答案」：單已經是結案狀態，
> 引用只會得到那句「已經處理過了」—— 給了做不到的指引比不給更糟。

**那個限制就是這個 bug。** 閘門修好之後引用作答真的可以用了，所以文案補上：

> 如果這一題該進題庫，可以引用這則訊息補上答案，再按「只加入題庫」。

⚠ 指引寫「只加入題庫」而**不是**「回覆客人」：客服已經自己回過了，
再按「回覆客人」會讓客人收到第二份答案。

## 已知的代價（需求方 2026-11-07 已指示「判斷會失效就拿掉」）

IGNORED 現在不帶「客人收到答案了沒」這個資訊，所以**按鈕也一起放行了**。
如果客服真的已經親自回完客人、又去按「回覆客人」，客人會收到第二份答案。

- 這是刻意接受的：舊行為下**那張單根本不能作答**，代價更大（要登入後台）
- 真正要按下去的人是刻意的，不是誤觸 —— 剛回完客人的人不會去按「用 #111 回覆」
- 要再收緊的話，該加的是「這個對話最近有人工回覆」的提示，而不是把閘門關回去

## 實測

| 狀態 | 值 | 擋作答？ | 結果 |
|---|---|---|---|
| IGNORED | 0 | 放行 | ✅ 本次修的主要情境 |
| PENDING | 1 | 放行 | ✅ |
| ANSWERED | 2 | 放行 | ✅ 可以改答案 |
| REPLIED | 3 | **擋** | ✅ |
| SAVED | 4 | **擋** | ✅ |
| EXPIRED | 5 | 放行 | ✅ |
| status 是字串 `'3'` | — | **擋** | ✅ 嚴格比對沒誤放 |
| status 是字串 `'0'` | — | 放行 | ✅ |

⚠ **本機 laradock 的 `auto_reply_ticket` 是空表**（0 筆），所以沒辦法用實際
資料反推是哪一種狀態造成的，診斷完全是從程式路徑追出來的。
要在正式環境確認的話，查「`status = 0` 但 `answer IS NULL` 且
`answered_at IS NULL`」—— 那一批就是沒人作答卻被關掉的單。

## 動到的檔案

| 檔案 | 說明 |
|---|---|
| `app/Models/AutoReplyTicket.php` | `isClosed()` → `isAnswerDelivered()` ← 源頭 |
| `app/Services/AutoReplySupportService.php` | 三個閘門換掉；`noticeHandled()` 註解更新 |
| `config/constants.php` | `HANDLED_NOTICE` 補上「可以引用補答案」 |
| `config/changelog.php` | v3.79 |

改了 `config/` → 已在容器內跑 `php artisan optimize`。

## 相關

- [[../features/auto-reply]] — 求助單流程與狀態機
- [[../features/auto-reply-learning]] — 引用作答之後把答案收進題庫
- [[../features/remind-escalation]] — 催促升級（靠 `status = PENDING`，所以放行不影響它）

---

## 收尾：按 PROMPTS.md 逐條稽核

需求方要求「嚴格嚴謹按照 PROMPTS.md」，所以把這次動過的三個檔逐條核對。
**找到 1 項真違反（自相矛盾的註解），修了。零行為變更。**

### 修掉的

`constants.AUTO_REPLY.HANDLED_NOTICE` 的第一段 ⚠ 還寫著舊世界的理由：

> 同仁還是會去引用它作答 —— 然後只拿到一句「這張單已經處理過了」

**那句話在這次修完之後就是錯的**（引用作答已經可以用了），而且**跟我自己
補在它下面兩行的那段 ⚠ 直接矛盾**。改成真正的理由：「同仁會在不知情的狀況下
重複處理同一題，而且不知道是誰處理掉的」。

⚠ 這是改行為時最容易漏的一種殘留：**註解寫的是「為什麼不能那樣做」，
而你剛剛把「不能」變成「能」了**。改閘門的時候要連著搜一遍有沒有別的地方
靠那個限制在解釋自己。這次用 `grep -rn "只拿到一句\|引用只會得到\|做不到的指引"`
掃過，另一處（`noticeHandled()` 的 docblock）在寫的當下就已經更新了。

### 刻意沒做的（附證據）

**三句「這張單已經處理過了」沒有搬進 `config/constants.php`。**

乍看該搬 —— 同一個檔案裡有 14 處從 `constants.AUTO_REPLY.*` 取文案，
而 `HANDLED_NOTICE` 就在那裡。但實際慣例是**按長度與用途分的**：

| 類型 | 放哪 | 數量 |
|---|---|---|
| 帶 `{placeholder}` 的長模板（求助單、提醒、處理回報） | `constants` | 14 |
| 操作確認的短句（「已忽略這張單」「✅ 已安排回覆客人」「找不到這張單。」） | 行內字面值 | 13 |

`editSupportMessage()` 的 13 個呼叫點裡有 11 個都是行內短句。
只把這兩三句搬走，會讓它們跟 11 個同性質的兄弟不一致 —— 那是製造不一致，
不是修掉不一致。要搬就 13 句一起搬，而那是另一個任務。

### 逐條核對結果

| 檢核項 | 結果 |
|---|---|
| `??`／`isset`／`empty`／`!filled` | ✅ 本次新增的 81 行 PHP 內零處 |
| 陣列直接下標取值 | ✅ 零處 |
| 字串 `.` 串接變數 | ✅ 零處 |
| `fn(` 箭頭函式／`else`／`elseif` | ✅ 零處 |
| `!== null`／`=== null` | ✅ 零處 |
| Model 無商業邏輯 | ✅ `isAnswerDelivered()` 是狀態述詞，跟同檔的 `isPending()`、`TelegramGroup::isAutoReplyOn()`、`ConversationFollowup::isClosed()` 同一類；而且是**取代**原有的那支，沒有給 Model 加新職責 |
| Service 無 HTTP 處理 | ✅ 只換了閘門呼叫與註解 |
| PHPDoc | ✅ `isAnswerDelivered()` 有 `@return bool`，並寫明四個狀態各自為什麼放行／擋 |
| SELECT 欄位／N+1／Transaction／Log | ✅ 不適用（本次沒有新查詢、新寫入、新外部輸入） |
| 語系 tw → cn → en | ✅ 不適用 —— 支援群組文案**刻意留在 config 不進語系檔**（既有決策：客戶與群組收到的內容不該因客服的語系而變） |
| 改 `config/` → `artisan optimize` | ✅ 容器內已執行 |

### 不加新版號

本次只改一段註解，**零使用者可見變更**。v3.79 已在修正那個 commit 裡，
而且它已經把使用者看得到的兩件事都寫進去了（引用作答可以用了、
後台標記後的通知多一句指引）。先例見
[[2026-11-05-validation-message-swallowed]] 的「不加版本紀錄的理由」。
