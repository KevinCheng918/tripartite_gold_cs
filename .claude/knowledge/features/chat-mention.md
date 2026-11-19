# 對話視窗打 `@` 叫人（v3.96，前後端都驗過；Telegram 那端待實送）

> 需求方 2026-11-19：「我後台的 Telegram 對話要能夠 @ 人，無論是自己人還是
> 站台客人，但要留意**有些人是沒有 id 的**。」
>
> 追加：「可以做成像 Telegram 一樣的介面，如果有打 @，就自動跳出來讓客服
> 可以選擇。」（附 Telegram 成員選單截圖）

## ⚠⚠ 核心難題：沒有 username 的人怎麼 @

截圖裡「權志龍」後面沒有 `@xxx` —— 那種人**打 `@名字` 是沒有用的**，
Telegram 不會把它變成 mention，對方不會收到通知。

Telegram 對這種人有另一套：**text_mention**，用 user id 做成連結。

```html
<a href="tg://user?id=123456789">權志龍</a>
```

**而我們剛好送得出去**：`TelegramBotService::escapeHtml()` 在全部轉義之後，
會把 Telegram 允許的標籤（`b i u s code pre a`）還原回真的 HTML，
`parse_mode` 也已經是 `HTML`。所以這條路不必改送出層。

⚠ 但 `a` 的屬性比對是 `[^&]*?`（不允許 `&`）—— `tg://user?id=123` 剛好沒有
`&`，可以過。之後若有人想加第二個參數就會踩到。

## 名單從哪來：只有這個對話的名冊

> 需求方 2026-11-19：「只要**有在群組內**的都要列，不在群組內的不用列，
> 因為列了也沒用。」

所以名單**只來自 `telegram_group_member`**（這個對話的名冊），
不去 `user` 表撈同仁 —— 同仁在這個群組講過話，名冊裡本來就有他
（`touch()` 記的是每一個發言者，不分我方客方）。

### ⚠⚠ Telegram 不給我們完整的群組成員名單

Bot API **沒有「列出所有成員」這種方法**（只有 `getChatMemberCount`、
`getChatAdministrators`、`getChatMember(user_id)` —— 最後那個還得先知道 id）。

所以「在群組內的人」我們只能用**看過的人**去近似：

| 進得了名冊 | 進不了名冊 |
|---|---|
| 在這個對話講過話的（`touch()` 記下來） | 在群組裡但**從來沒發言**過的 |
| 客服在忽略名單那一頁手動加的 | |

⚠ **這是功能的硬邊界，要讓客服知道**：潛水的人叫不出來，不是壞掉。
真的要叫他，請他先在群組講一句話，或到忽略名單那頁手動把他加進名冊。

每個候選算出一個「怎麼叫他」：

| 情況 | 送出去長什麼樣 | 對方收得到通知嗎 |
|---|---|---|
| 有 `username` | `@mei72163` | ✅ |
| 沒 username、有 `telegram_user_id` | `<a href="tg://user?id=…">權志龍</a>` | ✅ |
| 兩個都沒有（手動加進名冊、又沒發言過） | **不列進選單** | — |

⚠ **第三種要在選單裡誠實處理**：不是列出來然後叫不到，是**根本不列**。
列了他而對方沒收到通知，比沒有這個功能更糟 —— 客服會以為人已經叫到了。

## 介面：打 `@` 就跳出來

比照 `quick-reply.js` 的選單（同一頁已經有一個很像的東西，樣式與鍵盤操作
直接沿用）：

```
輸入框打 @ 或 @mei
   ↓
┌────────────────────────┐
│ 🔍 @mei                 │
│ ● 美美        @mei72163  │  ← 有 username
│ ● 權志龍      （沒有帳號）  │  ← 用 id 叫，標註一下
│ ● 小陳（我們）  @xiaochen  │  ← 自己人另外標
└────────────────────────┘
   ↑↓ 選、Enter／點擊 插入、Esc 關掉
```

- 只在 `@` 前面是**行首或空白**時才跳（不然打 email 會一直彈出來）
- 關鍵字比對 `username`、`display_name`、同仁暱稱
- 最多列 8 筆，先列**最近發言過的**（`last_seen_at`）—— 現在在講話的人
  比半年前出現過一次的人有用得多

## ⚠ 沒有 username 的人：輸入框顯示什麼

輸入框裡**不能直接塞 HTML**（客服會看到一串 `<a href=...>`）。

作法：輸入框插入 `@權志龍`，同時在前端記一筆
`{ label: '權志龍', id: 123456789 }`；**送出前**才把文字裡的 `@權志龍`
換成 `<a href="tg://user?id=123456789">權志龍</a>`。

⚠ **替換失敗要退化成純文字，不能擋著不送**：客服可能手動改過那段字
（刪掉一個字、中間插了標點）。找不到就當他不想 @ 了，照常送出 ——
**絕不能因為對不上就讓整則訊息發不出去**。

⚠ 只替換**第一個**出現的位置，而且用過就從清單移除 ——
同一個名字出現兩次時，兩次都換會變成兩個 mention。

## 後端

一支新的 ajax：

```
GET /admin/telegram-chat/ajax-mention-candidates?group_id=&keyword=
→ [{ label, username, user_id, is_staff, last_seen_at }]
```

- 權限沿用 **`telegram_chat.reply`**（能回覆的人才需要叫人），
  不另開 keyword
- Controller 只接參數 → 叫 Service → 回 Resource（照 PROMPTS 的分層）
- Repository 加「依對話與關鍵字找成員」；同仁那邊沿用 `UserRepository`

## 檔案清單

| 檔案 | 動作 |
|---|---|
| `routes/web.php` | 加 `ajax-mention-candidates`（`ajax-` 前綴，`can:telegram_chat.reply`） |
| `app/Http/Controllers/Admin/TelegramChatController.php` | 加 `ajaxMentionCandidates()`，用 `$params` 包裝 |
| `app/Http/Requests/TelegramChat/MentionCandidateRequest.php` ＋ `config/rules.php` | 驗證 group_id／keyword |
| `app/Http/Resources/TelegramChat/MentionCandidateResource.php` | 回傳格式 |
| `app/Services/TelegramMentionService.php` | 新增：合併名冊與同仁、算出「怎麼叫他」、排序與截斷 |
| `app/Repositories/TelegramGroupMemberRepository.php` | 加依關鍵字搜尋 |
| `public/js/telegram-chat/mention.js` | 新增：偵測 `@`、選單、插入、送出前的替換 |
| `public/css/telegram-chat-image-editor.css` 或新檔 | 選單樣式（CSS 要分離） |
| `resources/views/admin/telegram-chat/index.blade.php` | 載入 JS |
| `resources/lang/{tw,cn,en}/telegram_chat.php` | 選單文字（「沒有帳號」「我們」等） |
| `.claude/knowledge/features/chat-mention.md`、`INDEX.md` | 本檔與索引 |
| `config/changelog.php` | v3.96 |

沒有 migration —— 要的欄位（`telegram_user_id`、`username`、`display_name`）
名冊裡本來就有。

## 已經定案的兩件事（需求方 2026-11-19）

1. **只列這個對話名冊裡的人**，不去撈不在群組裡的同仁 —— 列了也叫不到。
2. **客人會看到被 @ 的同仁的 Telegram 帳號，沒關係** ——
   需求方：「因為一般 @ 也會出現帳號。」

## 實作後的補充

### 檔案

| 檔案 | 內容 |
|---|---|
| `app/Services/TelegramMentionService.php` | 名冊 → 候選（算「怎麼叫他」、標我方同仁） |
| `app/Repositories/TelegramGroupMemberRepository.php` | `searchForMention()` |
| `app/Http/Requests/TelegramChat/MentionCandidateRequest.php` ＋ `config/rules.php` | 驗證 |
| `app/Http/Resources/MentionCandidateResource.php` | 回傳格式 |
| `public/js/telegram-chat/mention.js` | 偵測 `@`、選單、插入、送出前替換 |
| `public/css/telegram-chat-mention.css` | 選單樣式（**獨立一檔**，不要塞進 image-editor 那支） |

⚠ `mention.js` 要排在 `input.js` **之前**載入 —— 輸入區建好時會呼叫
`T.bindMention()`。

⚠ CSS 載在 `@section('content')` 開頭，不是 `@section('css')`
（那個 yield 在 `<style>` 裡面，放 `<link>` 會失效 —— 圖片編輯器踩過）。

### ⚠ 選單開著時 Enter 不能送出訊息

這是最危險的一個細節：選單開著按 Enter 是「選定這個人」，
但輸入框上還有一個「Enter 送出」的 handler —— **沒處理好就會把一則
沒打完的話送到客戶群組**。

作法是 `keydown` 用 `capture: true` 綁，選單吃掉按鍵時 `stopPropagation()`。
實測 `sentByEnter: false` 確認過。

### ⚠ `%` 要跳脫

`searchForMention()` 的關鍵字進 `LIKE` 之前要 `addcslashes($keyword, '%_\\')` ——
客服打 `@50%` 的話，沒跳脫的 `%` 會變成萬用字元，把整份名冊撈出來。

### ⚠ 名冊裡「叫不到的人」不撈

`username` 與 `telegram_user_id` **兩個都沒有**的那種（手動加進名冊、
又從來沒發言過）送出去變不成 mention —— 列在選單裡只會讓客服
以為人已經叫到了。SQL 層就排除。

反過來，**忽略名單上的人照樣要列**：那份名單管的是「要不要自動回覆他」，
跟「客服想不想點他名字」是兩回事。

## 驗證（2026-11-19）

後端（tinker，交易內最後 rollback）：

| 情況 | 結果 |
|---|---|
| 有 username、最近發言 | 列出，只帶 username |
| 沒 username、發言過 | 列出，**只帶 user_id** |
| 手動加入（有 username、沒發言） | 列出，排在有發言的後面 |
| **兩個鍵都沒有** | **不列** |
| 忽略名單上的人 | 照樣列得出來 |
| 關鍵字比對中文顯示名稱 | 命中 |
| `keyword = "%"` | 0 筆（跳脫生效，沒有把整份名冊撈出來） |

前端（瀏覽器，攔截 API 餵三種候選）：

| 情況 | 結果 |
|---|---|
| 打 `@` | 選單跳出，三列排版正確（名字／「我們」標記／帳號） |
| ↓ 鍵 | 選到第 2 個 |
| Enter 選定 | 插入 `@權志龍 `，**沒有送出訊息** |
| 有 username 的人 | 插入 `@mei72163 `，不進待替換清單 |
| 送出前替換 | `請 <a href="tg://user?id=1002">權志龍</a>` |
| Esc | 關掉選單 |
| 打 `service@example` | **不跳選單**（`@` 前面不是空白） |
| 客服把名字改掉 | 原樣送出，不擋著不送 |
| 同一個名字出現兩次 | 只換第一個 |

⚠ **還沒驗到的：Telegram 那一端收到的樣子。** 要真的發一則帶 mention 的
訊息到群組，才看得出 text_mention 有沒有生效、對方有沒有收到通知。

## 按 PROMPTS.md 稽核（2026-11-20）

架構層全部符合：Controller 只接參數 → Service → Resource、用 `$params` 包裝；
Service 不碰 HTTP；DB 只在 Repository；有 Request ＋ `config/rules.php`、
有 Resource；Ajax route 有 `ajax-` 前綴；CSS／JS 分離；語系 tw → cn → en 同步。
效能：`select` 指定欄位、單次查詢無 N+1。安全：沒有寫入所以不需要交易。

⚠ **權限沒有新增 keyword 是對的**：`config/permissionMap.php` 只列 keyword
不列 route，而這支沿用 `telegram_chat.reply`（能回覆的人才需要叫人）。

修了三處：

1. ⚠ **`T.escapeHtml()` 早就存在，我又寫了一份。** mention.js 改用共用的。
   ⚠ 但 **image-editor.js 不能改**：那支有把文字塞進 `title="…"` 屬性的
   地方，而共用版走 `textContent → innerHTML` **不轉引號** ——
   名稱裡一個 `"` 就會把屬性切斷。那支保留自己的版本，並把理由寫進註解
   （不然下一個人會以為是忘了統一）。
2. ⚠ **連結格式兩邊各一份**：`constants.php` 的 `LINK_FORMAT` 根本沒人讀，
   真正在用的是 `mention.js` 裡那一份。**兩份同一件事只會讓人改了一邊
   以為生效了** —— 砍掉 config 那份，並把「URL 不能有 `&`」的警告
   搬到真正組字串的地方。
3. ⚠ **`keyword.max` 借用了「此欄位為必填」**：這裡是太長，不是沒填。
   文不對題的錯誤訊息比沒有訊息更難查。新增
   `msg.mention_keyword_too_long`（三份語系）。

### ⚠ 順手補上的：名字要轉義

`applyMentions()` 組的是 HTML，而 `label` 是**從 Telegram 來的外部資料** ——
有人把顯示名稱設成 `<b>老闆</b>` 的話，不轉義就會變成真的粗體送給客人。
實測 `<b>BOSS</b>` 會被轉成 `&lt;b&gt;BOSS&lt;/b&gt;`，連結本身照常產生。
