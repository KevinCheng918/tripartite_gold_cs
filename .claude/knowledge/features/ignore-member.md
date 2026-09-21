# 忽略特定成員（不自動回覆）

> **狀態：已完成實作，待執行 migration 與權限指派。**

## 目標

客戶群組裡不是只有客人 —— 常有對方的工程師、我方業務、或會固定貼公告的成員。
這些人講的話不該觸發自動回覆，但客服仍然需要看到。

所以做成**每個對話各自的忽略名單**：名單裡的人發言時，訊息照常進對話視窗、照常推播，
**只是不會觸發 Claude 自動回覆**。

## 已確認的需求

| 項目 | 決定 |
|------|------|
| 忽略程度 | **只不自動回覆**，訊息照常顯示、照常推播 |
| 範圍 | **每個對話各自一份名單**，不是全域 |
| 怎麼指定人 | **兩種**：從這個群組發言過的人裡面挑（主要），或**手動輸入 @username**（備援） |
| 權限 | **新增獨立 keyword**，不跟回覆綁在一起 |
| 發言者清單 | 近 **30 天**、上限 **50 人**，**加搜尋框** |
| 操作紀錄 | **要記誰在什麼時候設的，並顯示在 Modal 裡** |
| 未讀數 | 被忽略的人的訊息**照樣算未讀** —— 客服仍然要處理 |
| 資料表 | **不動 `telegram_message`**（大表，ALTER 會鎖表），只建一張小的新表 |

## 為什麼要有這張新表

現在 inbound 訊息只存了 `sender_name`（Telegram 的顯示名稱），
**沒有存發話者的 username，也沒有存 user id**。

`telegram_message.sender_user_id` 不能拿來用 —— 那是**後台客服的 user id**
（`comment` 寫明「後台發送者 ID（僅 outbound）」，FK 指向 `user` 表）。

拿 `sender_name` 當比對鍵會出事：Telegram 的顯示名稱可以隨時改，
對方改個暱稱，忽略就**靜默失效**，而且沒有人會發現。同名同姓也會誤擋。

原本想在 `telegram_message` 加欄位，但那張表資料量大、加欄位要 ALTER、會鎖表。
改成**另建一張群組成員名冊**：一個群組只有幾個人，這張表永遠很小，
而且「誰發言過」本來就該是一份名冊，不該每次去大表做 `DISTINCT`。

## 資料設計

### 新表 `telegram_group_member`（唯一一張新表）

| 欄位 | 說明 |
|------|------|
| `telegram_group_id` | 哪個對話 |
| `telegram_user_id` | nullable。**發言過的人才有**；手動輸入的沒有 |
| `username` | nullable，**存小寫、不含 `@`**。對方沒設 username 就是 null |
| `display_name` | 最近一次看到的顯示名稱，**只給 UI 看，不參與比對** |
| `last_seen_at` | nullable。最後發言時間；**手動加的是 null，用這個就能分辨「手動」**，不必另外開欄位 |
| `ignored` | 是否不自動回覆，預設 `false` |
| `ignored_by` | 誰設的（FK `user`，`nullOnDelete`，欄位 nullable） |
| `ignored_at` | 什麼時候設的（nullable） |
| unique | `(telegram_group_id, telegram_user_id)`、`(telegram_group_id, username)` |
| index | `(telegram_group_id, ignored)` |

一張表同時是「發言過的人清單」和「忽略名單」—— 忽略只是這個人身上的一個狀態，
拆兩張表會變成要處理「名單裡有但名冊沒有」的對應問題。

username 一律**正規化後再存**（去掉開頭的 `@`、轉小寫），比對時走同一套正規化，
否則 `@Abc` 和 `abc` 會被當成兩個人。

### 名冊怎麼長出來

`TelegramChatService::handleIncomingMessage()` 存完訊息後，
用 `$message['from']` 的 `id` / `username` / 名字 upsert 一筆
（更新 `display_name`、`username`、`last_seen_at`）。

成本是**單筆 unique index 命中的 upsert**，而且這張表永遠只有幾十列。
不做條件判斷、一律寫 —— 名冊要隨時備妥，不能等到有人打開自動回覆開關才開始累積，
否則剛打開時清單是空的，還要等對方先講一句話才挑得到人。

## 判斷點

`handleIncomingMessage()` 結尾，dispatch 之前：

```php
if ($group->isAutoReplyOn() && filled($rawText) && !$this->isIgnored($group->id, $from)) {
    AutoReplyJob::dispatch($group->id, $rawText, $msg->id);
}
```

`isAutoReplyOn()` 是欄位判斷、排在 DB 查詢前面（Short-circuit）——
沒開自動回覆的對話完全不會查忽略名單。

忽略名單**整包快取**（key 帶 group_id，只存 `ignored = true` 的那幾筆），
異動時清掉該群組的 key。名單極少變動卻每則訊息都要讀，不該每則訊息查一次 DB。

比對時 **id 與 username 兩個都比**，任一個對上就算命中。

**只影響自動回覆這一段**。前面的存訊息、Broadcasting、Web Push 完全不動 ——
訊息照常進對話視窗，未讀數照算。

## UI

**入口放對話標題列**，在「刪除對話」旁邊加一顆 🔕 按鈕（`layout.js` 的 `renderHeader`），
只在**有權限**且該對話**有開自動回覆**時顯示 —— 沒開的話這個設定沒有意義。

點開 Modal：

```
不自動回覆的成員                                          [×]

已忽略
  張工程師                                              [恢復]
    王小明 於 09/21 16:30 設定
  @abc（對方 PM）                        手動           [恢復]
    李大華 於 09/20 09:12 設定

這個對話發言過的人（近 30 天）
  [ 🔍 搜尋名字或 username                              ]
  王先生                                                [忽略]
  李小姐  @lisa                                         [忽略]

手動加入（清單裡找不到人時用）
  [ @username ]  [ 備註（選填） ]               [ 加入 ]
  對方沒設 username 的話這裡填不了，請等他發言後從上面的清單挑
```

- 「發言過的人」= 這張表裡 `last_seen_at` 在 30 天內、`ignored = false` 的，
  依 `last_seen_at` 倒序取 50 筆
- **搜尋框只在前端過濾已載入的那 50 筆**，不打後端 —— 50 筆是一次就送得完的量，
  為了搜尋再發一輪 ajax 只會讓它變慢
- 手動加入的那幾筆 `last_seen_at` 是 null，UI 標「手動」

手動輸入的驗證：

- 格式沿用既有的 `TELEGRAM_USERNAME_REGEX`（`config/rules.php`，`@?[A-Za-z0-9_]{5,32}`），
  不另外寫一條
- **存不存在無法驗證**，Telegram 沒有這種查詢。所以要提示
  「填錯不會有任何錯誤訊息，只是不會生效」

不放進訊息的長按選單 —— 那裡目前是表情回應列（`reactions.js`），
混進一個管理動作，手機長按時很容易誤觸。

所有提示與確認一律走 **Modal**，不用 `alert` / `confirm`。

### 訊息上的灰色標籤

被忽略的人發言時，訊息泡泡加一個灰色小標籤「不自動回覆」，
讓客服當下就知道這則要自己回。

⚠️ 因為不動 `telegram_message`，每則訊息身上沒有發話者 id，
**這個標籤只能用 `sender_name` 比對名冊裡的 `display_name`**。
也就是說它是「盡力而為」的視覺提示：對方改名之後標籤可能不準。

**行為判斷完全不受影響** —— 要不要自動回覆是收訊當下用 id/username 判的，一定準。
標籤只是事後在畫面上標記，失準的代價是客服多看一眼，不是回錯話。

## 權限

**新增一個獨立 keyword**：

| keyword | 說明 |
|---------|------|
| `telegram_chat.ignore_manage` | 檢視與修改對話的「不自動回覆」名單 |

掛在 `config/permissionMap.php` 既有的 **`telegram_chat` 群組**下
（與對客話術的 `template_view` / `template_manage` 同一張卡片），
語系檔 `resources/lang/*/permission.php` 三語同步。

不沿用 `telegram_chat.reply` —— 這個動作會改變系統對客人的自動行為，
影響範圍比「回一則訊息」大，該由管理者單獨指派。

沒有這個權限的人：標題列不會出現 🔕 按鈕，後端 route 也擋
（`can:telegram_chat.ignore_manage`），但**灰色標籤照樣看得到** ——
那是狀態揭露不是管理動作，客服需要知道這則為什麼沒有自動回。

## 邊界情況

- **反問流程**：系統反問「您是指哪一題」後，如果是名單內的人回了「1」，
  不會被當成客人的回答 —— 加了這道判斷之後自然就對了
- **內部支援群組**不受影響，它本來就走另一條路，不經過自動回覆
- **名單內的人問了真問題**：不自動回，客服人工回。這是預期行為
- **對方改暱稱**：不影響比對（用 id / username）。名冊的 `display_name`
  會在他下次發言時自動更新
- **對方改 username**：手動加的那筆會**靜默失效**。這是選 username 的代價。
  從清單挑的那些不受影響（用 id）
- **username 被別人接手**：Telegram 的 username 放棄後可被他人註冊，
  理論上會誤擋到另一個人。實務上罕見，也是為什麼清單挑選要用 id
- **手動加的人後來發言了**：他的 id 會對上同一個 username 那筆嗎？
  **不會自動合併** —— upsert 是以 `telegram_user_id` 為鍵，會變成兩筆。
  所以 upsert 要先查同群組同 username 且 `telegram_user_id IS NULL` 的那筆，
  有的話**補上 id**而不是新增，否則名單會出現重複的人
- **設定者的帳號被刪**：`ignored_by` 是 `nullOnDelete`，Modal 顯示「已離職人員」
- **對話被刪除**：`telegram_group_id` 用 `cascadeOnDelete`，名冊跟著走

## 異動檔案

### 新增

| 類型 | 檔案 |
|------|------|
| Migration | `2026_09_21_000007_create_telegram_group_member_table.php`（**只有 CREATE TABLE，不碰 `telegram_message`**） |
| Model | `app/Models/TelegramGroupMember.php`（`ignoredBy()`、`isManual()`） |
| Repository | `app/Repositories/TelegramGroupMemberRepository.php` |
| Service | `app/Services/TelegramGroupMemberService.php`（名冊 upsert、`isIgnored()`、正規化、快取） |
| Request | `app/Http/Requests/TelegramChat/ToggleIgnoreMemberRequest.php` |
| Resource | `app/Http/Resources/GroupMemberResource.php` |
| 前端 | `public/js/telegram-chat/ignore-member.js` |

### 修改

| 檔案 | 內容 |
|------|------|
| `app/Services/TelegramChatService.php` | 收訊時 upsert 名冊；dispatch 前多一道 `isIgnored()` |
| `app/Http/Controllers/Admin/TelegramChatController.php` | `ajaxIgnoreMembers` / `ajaxToggleIgnore`；`ajaxMessages` 附帶 `ignored_names` |
| `routes/web.php` | 兩條 `ajax-`，掛 `can:telegram_chat.ignore_manage` |
| `config/constants.php` | `TELEGRAM.IGNORE`（天數、筆數、快取、動作） |
| `config/permissionMap.php` | `telegram_chat.ignore_manage` |
| `resources/lang/{tw,cn,en}/permission.php` | 權限名稱 |
| `resources/lang/{tw,cn,en}/telegram_chat.php` | 面板文案與訊息 |
| `public/js/telegram-chat/state.js` | `canIgnore`、`ignoredNames` |
| `public/js/telegram-chat/layout.js` | 標題列 🔕 按鈕 |
| `public/js/telegram-chat/messages.js` | 灰標籤、接住 `ignored_names` |
| `public/js/telegram-chat/input.js` | 切換自動回覆後重畫標題列 |
| `resources/views/admin/telegram-chat/index.blade.php` | `data-can-ignore`、載入新 JS |

### 實作上的兩個決定

- **灰標籤的資料跟著 `ajax-messages` 回**（`->additional(['ignored_names' => ...])`），
  不另開路由。標籤是狀態揭露，沒有管理權限的人也該看得到，
  但管理面板那條路由是掛權限的 —— 資料走訊息這條就不必為了標籤再開一條無權限端點
- **Modal 在第一次開啟時才用 JS 建出來**（比照 `quick-reply.js`），blade 不放版面

## 上線注意

- **要跑 migration**：`php artisan migrate`（只有 CREATE TABLE，沒有 ALTER，不會鎖到既有的大表）
- 改了 routes 與權限 → 容器內要跑 `php artisan optimize`
- 上線後要**去帳號管理勾 `telegram_chat.ignore_manage`**，沒勾的人看不到按鈕
- 名冊是從**上線後的訊息**開始累積的，剛上線時清單會是空的 ——
  這段期間要靠手動輸入 username
