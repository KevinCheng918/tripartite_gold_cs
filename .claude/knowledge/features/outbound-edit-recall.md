# 改掉／撤回已經送出去的客服訊息（**已實作，v4.16**）

> 需求方 2026-11-25：「我客服打出去的話，透過 Telegram 機器人送出去，
> 這個我能改對話內容嗎」

---

## 結論：可以，零件都在

| 需要的東西 | 現況 |
|---|---|
| Telegram 那一則的 id | ✅ `sendReply()` 已經存進 `telegram_message.telegram_message_id` |
| 改文字的 API | ✅ `TelegramBotService::editMessageText()` 已經有 |
| 刪訊息的 API | ✅ `deleteMessages()` 已經有 |
| 後台顯示「已編輯」 | ✅ `edited_at` ＋ `broadcastEdited()` 都有（目前只用在**客人**改自己的訊息） |

所以不是從頭蓋，是把既有的零件接到「我方訊息」這一側。

---

## ⚠⚠ 五個限制，會決定它能做成什麼樣子

### ① 有一批訊息改不動（沒有 `telegram_message_id`）

本機 40 則 outbound 裡有 **24 則是 null** —— 早期送出的沒存。
沒有那個 id 就指不到 Telegram 那一則。

⚠ **前端要用這個決定按鈕出不出現**，而且判斷條件**必須跟後端一致**
（同 [[leave-cancel]] 的 `can_cancel`）—— 不一致的話，
使用者會看到一顆按下去就被拒絕的按鈕。

⚠ 正式站的比例要另外查。

### ② 圖片不能編輯，但**刪得掉**

> 需求方 2026-11-25：「圖片能刪除嗎，不能改沒關係」

⚠ `deleteMessage` **不分訊息類型** —— 圖片、檔案、貼圖都刪得掉。
挑類型的只有「編輯」。

所以這一版**不做 `editMessageCaption`**：圖說要改就撤回重傳，
少一條路要維護，而且結果更乾淨（客人不會看到一張圖的說明突然變了）。

### ③ 時限：編輯 **12 小時**（我們自己訂的），撤回 **48 小時**（Telegram 的）

> 需求方 2026-11-25：「編輯給 12 小時就好」

⚠⚠ **兩者不一樣是刻意的，不要為了「一致」把它們對齊：**

| | 時限 | 誰訂的 | 為什麼是這個長度 |
|---|---|---|---|
| **編輯** | **12 小時** | 我們 | 編輯是**默默改掉**。隔得越久，客人越可能已經讀了並照著做 —— 那時改字只會讓他跟我們對不上帳 |
| **撤回** | 48 小時 | **Telegram** | 撤回是**讓訊息消失**，動作本身看得見。講錯話要收回時，窗口短反而害人 |

⚠ 所以**編輯比撤回短**，不是寫錯。

⚠ 刪除那 48 小時是 Telegram 的硬限制，這個專案已經踩過並寫在
`deleteMessages()` 的註解裡：超過**永久刪不掉**，而且
**刪不掉會被靜默跳過、整批仍回傳成功** —— 所以撤回要自己判斷時間，
不能只看 API 有沒有回成功。

⚠ **錯誤路徑還是要做**：Telegram 可能因為別的理由拒絕（訊息被刪、
bot 被踢出群組）。那時要把真正的原因顯示給操作者，不要只說「失敗」。

### ④ 長答案是分好幾則送出的

`AnswerSplitter` 會把三四百字的題庫答案切成數則。
所以「編輯」的對象是**某一則**，不是「那個回答」——
UI 上就是點哪一則改哪一則，不要試圖把它們當成一個整體。

### ⑤ ⚠⚠ 客人很可能已經看過了

Telegram 會標「已編輯」，所以不算騙人。但他可能**已經照舊內容做了事**。

這就是為什麼需求方要的是**兩種工具**，而不是只有編輯：

| 狀況 | 用哪一個 | 為什麼 |
|---|---|---|
| 打錯字、漏字（意思沒變） | **編輯** | 客人就算看過舊的也不會做錯事 |
| 內容講錯了（金額、時間、承諾） | **撤回** ＋ 重發／補更正 | 默默改掉更危險 —— 他可能已經讀了並照著做 |

---

## 已定案（需求方 2026-11-25 拍板）

| # | 問題 | 決定 |
|---|---|---|
| 1 | 要解決哪一種 | **兩種都要**：編輯（錯字）＋ 撤回（講錯了） |
| 2 | 要不要留原文 | **不留，直接覆蓋** |
| 3 | 誰可以改 | **本人 ＋ 有權限的人** |
| 4 | 編輯時限 | **12 小時**（撤回維持 Telegram 的 48 小時） |
| 5 | 自動回覆送出的 | **不能編輯，但可以撤回** |
| 6 | 沒有 `telegram_message_id` 的舊訊息 | **就算了**，兩個動作都不給 |

### ⚠⚠ 第 5 點：AI 寫的可以撤、不能改

> 需求方 2026-11-25：「自動回覆的不能改，但是能撤回」

這個區分是對的，而且**不要為了少寫一個判斷就放寬**：

- **改掉** AI 說的話 ＝ 把機器講的變成像人寫的，
  事後看那一則會以為是同仁打的 —— 而出事時追的正是「誰說的」
- **撤回** 是誠實的動作：那句話不該說，所以收回

⚠ 判斷依據是 `telegram_message.is_auto`。

### ⚠ 第 6 點：舊訊息直接不給，不要做半套

> 需求方 2026-11-25：「原本不能改的就算了」

沒有 `telegram_message_id` 就指不到 Telegram 那一則。
⚠ **不要只改後台顯示** —— 那會變成「後台寫 A、客人看到 B」，
比不能改更糟。兩個按鈕一律不出現。

### ⚠⚠ 第 2 ＋ 第 3 的組合要留意

「不留原文」＋「別人也能改」＝ **別人可以改掉你跟客人說過的話，
而且不留痕跡**。而客服系統裡「我到底跟客人講了什麼」正是事後
最常被追的事。

⚠ 折衷（需求方已知悉）：**不存原文**（照決定），
但**記下「誰在什麼時候改過」** —— `edited_at` 本來就有，
再加一個 `edited_by` 就夠。這跟「不留原文」不衝突，是另一件事。

---

## 兩個動作各自的適用範圍（一張表看完）

| | **編輯** | **撤回** |
|---|---|---|
| 人工送出的純文字 | ✅ 12 小時內 | ✅ 48 小時內 |
| 人工送出的圖片／檔案 | ❌ 不給 | ✅ 48 小時內 |
| 自動回覆送出的 | ❌ 不給（需求方指定） | ✅ 48 小時內 |
| 沒有 `telegram_message_id` 的舊訊息 | ❌ 不給 | ❌ 不給 |
| 客人傳來的訊息 | ❌ 不是我們的 | ❌ 不是我們的 |

⚠ **撤回的條件比編輯寬很多** —— 它不挑訊息類型、不挑是人還是 AI 送的。
唯一的門檻是「那是我方送出的」＋「48 小時內」＋「有 message_id」。

## 流程

```
客服在對話窗點自己送出的那一則
   ├─ 【編輯】→ 改文字 → editMessageText／editMessageCaption
   │              ├─ 成功 → 更新 content、edited_at、edited_by，推播給其他人
   │              └─ 失敗 → 把 Telegram 的原因顯示出來（例如超過時限）
   │
   └─ 【撤回】→ deleteMessage
                  ├─ 成功 → 後台標成「已撤回」（⚠ 不刪那一列）
                  └─ 失敗 → 明確說「超過 48 小時，Telegram 不讓刪了，
                             請改補一則更正」
```

⚠ **撤回不要把後台那一列刪掉**：客人那邊看不到了，但「我們曾經送過
這句話」仍然是事實。刪掉的話，事後連「為什麼客人會那樣以為」都查不出來。

---

## ⚠ 追加需求：要標出「誰改的／誰撤的」

> 需求方 2026-11-25（實作到一半追加）：「編輯完按下確認後，要標示誰已編輯，
> 然後收回的話，也要標示誰已收回」

氣泡上直接寫「已由 OOO 編輯」「已由 OOO 撤回」。

⚠⚠ **`edited_by` 空的是正常情況，不是漏寫。** `edited_at` 這個欄位
**同時服務兩件事**：

| 誰改的 | `edited_at` | `edited_by` | 畫面顯示 |
|---|---|---|---|
| 客服（這個功能） | 有 | **有** | 已由 OOO 編輯 |
| 客人自己在 Telegram 改他自己那句話 | 有 | **null** | 已編輯 |

所以語系要留**兩版**（`message_edited` ／ `message_edited_by`），
前端靠「有沒有名字」分辨。只留有名字那一版的話，客人改自己的訊息時
會顯示「已由 編輯」。

⚠ `edited_by`／`recalled_by` 的 FK 是 `nullOnDelete`，人離職被刪掉
關聯就是 null —— Resource 用 `Arr::get($this->editedByUser, 'nickname')`，
不要直接 `->nickname`。

---

## 實際動到的檔案

| 檔案 | 動作 |
|---|---|
| `database/migrations/2026_11_25_000001_add_edit_recall_to_telegram_message_table.php` | 🆕 `edited_by`、`recalled_at`、`recalled_by`（`edited_at` 本來就有） |
| `app/Models/TelegramMessage.php` | 🆕 `isOutbound()`／`isRecalled()`／`canEdit()`／`canRecall()`／`withinHours()`、`editedByUser()`／`recalledByUser()` |
| `app/Services/TelegramBotService.php` | `editMessageText()` 改成回傳 `['ok', 'description']`（原本只回 bool，拿不到失敗原因） |
| `app/Services/TelegramChatService.php` | 🆕 `editSent()`、`recallSent()`、`editFailMessage()` |
| `app/Repositories/TelegramRepository.php` | 🆕 `findOutboundForEdit()`；`getMessagesByGroup()` 補四個欄位 ＋ eager load 兩個關聯 |
| `app/Http/Controllers/Admin/TelegramChatController.php` | 🆕 `ajaxEditSent()`、`ajaxRecallSent()`、`canTouchSent()` |
| `app/Http/Requests/TelegramChat/EditSentRequest.php` | 🆕 ＋ `config/rules.php` 的 `TELEGRAM_EDIT_CONTENT` |
| `app/Http/Resources/TelegramMessageResource.php` | 🆕 `sender_user_id`／`is_recalled`／`can_edit`／`can_recall`／`edited_by_name`／`recalled_by_name` |
| `config/constants.php` | 🆕 `TELEGRAM.EDIT_HOURS = 12`、`RECALL_HOURS = 48` |
| `config/permissionMap.php` | 🆕 `telegram_chat.edit_others`（⚠ 要 `optimize`） |
| `routes/web.php` | 🆕 `ajax-edit-sent`（PUT）、`ajax-recall-sent`（DELETE） |
| `public/js/telegram-chat/edit-recall.js` | 🆕 兩個 modal |
| `public/js/telegram-chat/messages.js` | 兩顆按鈕、已撤回標籤、`data-raw-content` |
| `public/js/telegram-chat/state.js` | 🆕 `canEditOthers`、`escapeAttr()` |
| `resources/views/admin/telegram-chat/index.blade.php` | `data-can-edit-others` ＋ 新 script |
| `resources/lang/{tw,cn,en}/telegram_chat.php` | 20 個鍵 ×3 |

---

## ⚠ 實作時踩到的四個坑

### ① 權限叫 `edit_others`，不是 `edit_sent`

規劃時寫 `telegram_chat.edit_sent`，但那會變成「要有這個權限才能改
自己說過的話」—— 而改自己的話本來就該含在回覆權限裡。
最後是 `telegram_chat.edit_others`：**只管動別人的**。

⚠ 路由上掛的是 `can:telegram_chat.reply`（進得來的門檻），
「是不是自己的」在 Controller 的 `canTouchSent()` 判 ——
路由中介層看不到那一則是誰送的。

### ② `fa-rotate-left` 在這套後台是**空白方塊**

這個後台帶的是 **FontAwesome 5**，`fa-rotate-left` 是 6 才有的名字。
寫錯不會報錯，就是畫出一個空格。FA5 要用 `fa-undo`。

### ③ `escapeHtml()` 不會處理半角引號

`state.js` 的 `escapeHtml()` 走 textContent → innerHTML，瀏覽器
**不把 `"` 轉成 `&quot;`**（在文字節點裡它不危險）。塞進
`data-xxx="…"` 就會被提早收掉、整串 HTML 歪掉。
已新增 `escapeAttr()`，並把 `data-sender`／`data-text` 一併換過去。

### ④ 不要把 `findOutboundForEdit()` 的結果包成 Resource

那支的 select 只有判斷要用的幾欄。包成 `TelegramMessageResource`
的話 `sender_name`、`media_url`、`reactions`、`edited_at` 全是
**null**（Laravel 對沒 select 的欄位不報錯，就是給你 null）。
兩支 ajax 改成只回一句成功訊息，前端本來就會整份重載。

---

## ⚠⚠ 稽核時才發現：插方法會把上一支的 PHPDoc 孤立掉

按 PROMPTS.md 回頭稽核 v4.16 時抓到的，**這是會重複發生的一類**：

在兩支方法之間插一支新的時，如果插點落在
**上一支的 PHPDoc 與它的 `function` 之間**，就會變成

```php
/** 原本那支的說明 @return void */
/** 新插進來這支的說明 @return TelegramMessage */
public function 新方法() { … }

public function 原本那支() { … }   // ← 註解被偷走了
```

⚠ **`php -l` 過、StyleCI 過、功能也完全正常** —— 壞掉的只有文件：
原本那支變成沒註解，而新那支頭上頂著一段講別人的說明。

這次掃出 **4 處**，其中 2 處是這個功能造成的
（`findQuoted()`、`broadcastEdited()` 的註解被偷走），
另外 2 處是更早以前留下的 **stale 重複**
（`handleIncomingMessage()`、`downloadTelegramFile()` 下面都已經有
更完整的一份，上面那份是舊的殘留）—— 那兩份直接刪掉。

### 怎麼掃

```php
// 連續兩個 docblock 中間沒有東西 ＝ 有孤兒
trim($lines[$i]) === '*/' && strpos(trim($lines[$i + 1]), '/**') === 0
```

⚠ 修的時候要先分清楚是**哪一種**：
- 下面那支**沒有**自己的註解 → 把孤兒**搬下去**（`hasNewerInbound()` 就是）
- 下面那支**已經有**而且更完整 → 孤兒是殘留，**刪掉**（不要兩份並存）

---

## 已驗證（2026-11-25）

後端 10 種情況（全程 `beginTransaction` → `rollBack`，沒有寫進正式資料）：

| 情況 | 結果 |
|---|---|
| 編輯成功 | content／edited_at／edited_by 都寫了，送出去的字沒有重複署名 |
| Telegram 回 `message is not modified` | **不當成失敗** |
| Telegram 回 `message to edit not found` | 翻成「那一則在 Telegram 上已經不存在了」，**DB 沒被改到** |
| 其他錯誤（bot 被踢出群組） | 附上 Telegram 原文 |
| 撤回成功 | **那一列還在**，只是多了 recalled_at／recalled_by |
| 撤回過的再撤／再改 | 都擋下來 |
| 自動回覆 | 不能改、撤得掉 |
| 圖片 | 不能改、撤得掉 |
| 客人傳來的 | 兩個都不行 |
| 13 小時前的 | 不能改、還撤得掉 |

前端（claude-in-chrome，本機）：
- 40 則 outbound → 撤回鈕 40 顆、編輯鈕 16 顆（只有純文字）—— 符合規則
- 兩個 modal 文案、紅色撤回鈕、「已由 OOO 編輯／撤回」都正確
- 沒有名字時退回「已編輯」「已撤回」
- 一頁 44 則只用 **4 次查詢**（沒有 N+1）

⚠ **Telegram 那一側是用替身驗的** —— 驗的是「Service 收到 ok／收到
錯誤之後做了什麼」，不是 Telegram 本身。真的改得掉、撤得掉要在
正式站上看。

---

## 部署後要做的事

1. `php artisan optimize`（改了 `routes/`、`config/`）
2. 到帳號管理把 **`telegram_chat.edit_others`** 指派給該有的人 ——
   不指派的話大家只能動自己送的（這是安全的預設）
