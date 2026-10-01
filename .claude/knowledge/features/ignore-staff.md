# 後台帳號一律不自動回覆（內部人員名單）

> **狀態：已完成，migration 已跑、全分支實測通過（2026-10-02）。**

## 目標

後台有帳號的人（我方客服、工程、主管）用**自己的 Telegram** 在客戶群組講話時，
系統不該自動回覆他 —— 那是同事在跟客人溝通，不是客人在問問題。

現有的 [[ignore-member]] 是**每個對話各自一份**忽略名單，自己人要一個對話
一個對話加，新開一個客戶群組又得再加一次。這份名單改成**全域、自動**。

## 已確認的需求

| 項目 | 決定 |
|------|------|
| 生效範圍 | **全域自動生效**：所有客戶對話一律不自動回覆名單內的人 |
| 例外 | **可在單一對話「特別打開」某個同事**，只對那個對話生效（見下方專節） |
| 與既有名單的關係 | **兩份並存**，任一份命中就不自動回覆。每對話的名單仍可加客戶方的工程師、PM |
| 名單內容 | **動態跟著帳號走**：判斷時直接比對 `user` 表，新增帳號自動生效、帳號移除自動失效，沒有人要維護 |
| 哪些帳號算 | **只有 `status = NORMAL`**。鎖定（LOCK）與停用（DEACTIVATE）都**不算** |
| 沒填 Telegram 帳號的人 | **不改必填**，但在名單面板明確列出「這些帳號還沒填，發言不會被屏蔽」 |
| 改掉 username 就失效 | **做回填**：首次用 username 命中時把 Telegram ID 記到帳號上，之後以 ID 為主 |
| 忽略程度 | 同 [[ignore-member]]：**只擋自動回覆**，訊息照常存、照常推播、照常算未讀 |
| 權限 | **不新增 keyword**，沿用 `telegram_chat.ignore_manage` |

## 兩個比對鍵：ID 優先、username 備援

`user` 原本**只有** `telegram_username`（給內部群組 tag 本人用的，選填），
而 username **隨時可以被改掉** —— 只靠它的話，同事改個帳號屏蔽就靜默失效。

所以加了 `user.telegram_user_id`（nullable）：Telegram 的使用者 ID 終生不變。

```
ID 對上        → 是同事（最可靠）
ID 沒對上      → 比 username
username 對上  → 是同事，順便把 ID 回填上去（見下一節）
兩個都沒對上   → 當成客人，照常自動回覆
```

剩下的代價只有一個：**同事沒填 username 就認不出來**（也沒有 ID 可回填）。
面板會把這些帳號列出來請他們補。

⚠ `telegram_username` 的存法不一致 —— 有人填 `@name`、有人填 `name`
（既有的 `UserRepository::findByTelegramUsername()` 就是用
`LOWER(TRIM(LEADING "@" FROM telegram_username))` 硬扛的）。這份名單一律
**正規化後再比**（去開頭 `@`、轉小寫），跟 [[ignore-member]] 走同一套規則，
否則 `@Abc` 與 `abc` 會被當成兩個人。

## Telegram ID 的回填

`StaffIgnoreService::backfillUserId()`，在「username 命中但這個帳號還沒有 ID」時觸發。

- **只會發生一次**：補完之後就走 ID 那條，不再進來
- **只補還沒有 ID 的那一筆**：兩個帳號填到同一個 username（人為填錯）時，
  至多補到其中一個，不會兩筆都被蓋成同一個人
- **補完立刻清名單快取**，否則快取裡的 `ids` 還是舊的，下一則訊息又會再補一次
- 欄位不出現在帳號管理的表單上 —— 那串數字沒人記得，也不該要求人去查

⚠ 這段跑在**收訊流程**裡，而且是這支服務唯一的寫入動作。整段包 try/catch
只記 log：訊息本身早就存好了，回填只是讓下一次比對更可靠，**它失敗絕不能
影響收訊**。

欄位用 `unsignedBigInteger` 而不是 `integer` —— Telegram 的使用者 ID 已經
超過 32 位元整數範圍（新帳號 10 位數起跳），`integer` 會溢位。
刻意**不設 unique**：萬一兩個帳號填了同一個 username，unique 會讓回填
直接噴錯而中斷收訊，而「只補沒有 ID 的那筆」已經擋掉重複了。

### 正規化抽出來共用

`TelegramGroupMemberService::normalizeUsername()` 本來有一份。新的 Service
再寫一份就會有兩套規則，哪天改了一邊就開始漏人。

抽成 `app/Presenters/TelegramUsernamePresenter`（比照既有的 `NumberPresenter`），
兩個 Service 都用它 —— 不讓 `StaffIgnoreService` 為了借一個方法去依賴
`TelegramGroupMemberService`。

- `normalize()` — 單筆；空值回 **null**（不是空字串），呼叫端一律 `filled()` 判斷
- `normalizeAll()` — 整批，順便濾空與去重，直接可以丟 `in_array`

`TelegramGroupMemberService::normalizeUsername()` 保留成一行委派，
既有呼叫端（Controller、本類別）不必動。

## 判斷點

`TelegramChatService::handleIncomingMessage()`，dispatch 之前多一道：

```php
if ($group->isAutoReplyOn() && filled($rawText)
    && !$this->staffIgnoreService->isStaff($from, $group->id)
    && !$this->memberService->isIgnored($group->id, $from)) {
    AutoReplyJob::dispatch($group->id, $rawText, $msg->id);
}
```

三個判斷的順序是刻意的，由便宜到貴：

1. `isAutoReplyOn()` — 欄位判斷，沒開自動回覆的對話什麼都不查
2. `isStaff()` — **一份全域共用快取**，所有對話共用同一個 key；
   認出是同事之後才會去讀該對話的放行例外
3. `isIgnored()` — 每個對話一個 key，命中 2 就不必再去碰它

## 名單怎麼取

`UserRepository::getTelegramIdentitiesForAutoReply()`：

```
select id, nickname, telegram_username, telegram_user_id
  where status = NORMAL
    and (telegram_username 有值 or telegram_user_id 有值)
  （deleted_at 由 SoftDeletes 自動排除）
```

**`status = NORMAL` 才屏蔽** —— 鎖定與停用都不算，他們發言時系統照常
自動回覆。這跟既有的 `getManagersForMention()` 用同一個條件。

條件是「有 username **或**有回填過的 ID」：回填過 ID 的人即使後來把
username 清空，仍然認得出來。

只取四個欄位（**不是 `SELECT *`**）。快取存的是**兩組映射**
（`telegram_user_id => user_id`、`username => user_id`）而不是 Model 集合 ——
序列化 Model 會把整個物件寫進快取，而比對只需要這兩組對應關係。

映射而不是單純清單，是為了讓命中的瞬間就知道「是哪個同事」，
接著才判斷得了該對話的放行例外。

快取 key 固定一個（`TELEGRAM.STAFF_IGNORE.CACHE_KEY`，不帶參數），
TTL 600 秒。失效點：

| 時機 | 位置 |
|---|---|
| 帳號編輯（動到 `telegram_username` / `status`） | `AccountService::update()`，transaction 之後 |
| 回填 Telegram ID | `StaffIgnoreService::backfillUserId()` |

`AccountService::update()` 不去比對「這次有沒有真的改到那兩欄」——
判斷的成本比一次 `Cache::forget` 還高，而帳號編輯本來就不是高頻動作。

`AccountService::create()` 不清：新帳號不帶 Telegram 身分，一定不在名單裡。
（`UserRepository::softDelete()` 目前沒有任何呼叫端，帳號刪除還沒有入口。）

> 名單極少變動，卻**每則 inbound 訊息都要讀**。不快取的話，每一則客人訊息
> 都會多一次 `user` 表查詢。

## UI：併進現有的「不自動回覆的成員」Modal

不另開頁面、不另開路由 —— 那個 Modal 就是「誰不會被自動回覆」的管理入口，
全域名單是同一個問題的另一半，分到兩個地方只會讓人找不到。

最上面加一塊勾選清單（版面與互動細節見下方「每對話的例外」專節）：

- 擺**最上面**：它全域生效，先讓人知道「這些人預設都不會被自動回」，
  下面那份每對話名單才是用來加客戶方人員的
- 資料併在既有的 `ajax-ignore-members` 回傳裡（多兩個 key：`staff`、`staff_missing`），
  不為了這個區塊再開一條路由
- 🔒（`fa-fingerprint`）= 已回填 Telegram ID，這個人改掉 username 也認得。
  hover 有說明
- `StaffIgnoreResource` **不回 `telegram_user_id` 本身**，只回
  `has_user_id` 布林 —— 那串數字對客服沒有意義，要知道的只是「認得出來嗎」

### 訊息上的灰標籤也要跟著標

被全域屏蔽的人發言時，訊息泡泡同樣要出現「不自動回覆」標籤 ——
不然客服看不出這則為什麼沒有自動回。

做法沿用既有那條路（不動 `telegram_message` 大表）：`ajax-messages` 回的
`ignored_names` 除了該對話的忽略名單，再併上**名冊裡 username 落在後台名單中**
的那些人的 `display_name`（`TelegramGroupMemberRepository::getNamesByUsernames()`）。

合併與去重在 `TelegramGroupMemberService::getIgnoredNames($groupId, $staffUsernames)`
裡做（同一個人可能兩邊都在），Controller 只負責把兩個 Service 接起來。

同既有限制：標籤是用 `sender_name` 比對 `display_name` 的「盡力而為」提示，
對方改暱稱可能不準；**行為判斷不受影響**，那是收訊當下用 ID／username 判的。

⚠ 只比對**名冊上有的人**。同事如果從沒在這個對話發言過，名冊裡沒有他，
標籤就不會出現 —— 但他一發言 `touch()` 就會寫進名冊，所以實際上只差第一則。

## 每對話的例外：特別打開某個同事

> **狀態：已實作，待跑 `telegram_group_staff_allow` 的 migration。**

需求：**預設把內部員工都忽略，除非有特別打開。** 全域屏蔽仍是預設值，
客服可以在**某一個對話**把某個同事放回自動回覆的範圍。

### 為什麼不沿用名冊的 `ignored` 欄位

`telegram_group_member.ignored` 的語意是「客服手動把這個人加進忽略名單」，
而這裡要記的是**反向的例外**：「這個同事在這個對話被特別放行」。

兩者混在同一個 bool 上會變成「沒有紀錄時代表什麼」說不清楚 ——
對一般人是「不忽略」，對同事卻是「忽略」。

更關鍵的是**鍵不一樣**：名冊以 Telegram 身分為鍵，而且**人要發言過才在名冊上**。
客服想預先打開一個還沒在這個對話講過話的同事時，根本沒有列可以標。

### 新表 `telegram_group_staff_allow`

以**後台帳號 id** 為鍵，不依賴名冊：

| 欄位 | 說明 |
|------|------|
| `telegram_group_id` | 哪個對話（`cascadeOnDelete`） |
| `user_id` | 哪個同事（FK `user`，**`cascadeOnDelete`**） |
| `allowed_by` | 誰打開的（FK `user`，`nullOnDelete`，欄位 nullable） |
| `allowed_at` | 什麼時候打開的 |
| unique | `(telegram_group_id, user_id)` |

**有列 = 特別打開**（這個對話會自動回覆他），沒列 = 預設忽略。
用「存在與否」表達狀態，不再多一個 bool —— 多一個 bool 就會有「有列但 false」
這種跟「沒列」意思相同的狀態，白白多一種要處理的情況。

`user_id` 用 `cascadeOnDelete` 而不是 `nullOnDelete`：帳號沒了，放行紀錄也該消失。
留著會變成一筆指向不存在的人的放行，而 `user_id` 又可能被新帳號重用 ——
那就等於默默放行了另一個人。

寫入用 `firstOrCreate`（unique 索引擋重複），收回直接**刪列**。

### 判斷邏輯

`isStaff()` 多收一個 `$groupId`，並拆成「認人」與「看例外」兩步：

```php
public function isStaff(array $from, $groupId)
{
    $userId = $this->matchUserId($from);   // 命中回 user_id（含 ID 回填），否則 null

    if (blank($userId)) {
        return false;
    }

    // 這個對話特別打開了他 → 不屏蔽，照常自動回覆
    return !in_array($userId, $this->allowedUserIds($groupId), true);
}
```

為此**名單快取從兩組清單改成兩組映射**：

```
ids       : [telegram_user_id => user_id]
usernames : [username         => user_id]
```

命中時直接拿到 `user_id`，不必再回頭查 `user` 表。

例外清單另外快取（key 帶 `group_id`：`ALLOW_CACHE_PREFIX`），
異動時清掉該對話的 key —— 與既有忽略名單的快取策略一致。

判斷順序不變（`isAutoReplyOn()` → `isStaff()` → `isIgnored()`）；
例外查詢只在「已經確定是同事」之後才發生，**一般客人的訊息不會碰到它**。

### ⚠ 回填不能用 `UserRepository::find()`

`find()` 的 select 是 `LIST_COLUMNS`，**裡面沒有 `telegram_user_id`** ——
讀出來的屬性是 null，回填就會判斷成「還沒補過」而**每則訊息重寫一次**
（而且它還 eager load `permissions`，對這件事是白撈）。

所以另開 `findForTelegramBackfill()`，只 select `id` + `telegram_user_id`。

這個坑是改寫回填時才發現的 —— 原本的實作直接取 `$user->telegram_user_id`
看起來很自然，但那一欄根本沒被撈出來。

### UI

內部人員區塊從唯讀 chips 改成**勾選清單**：

```
內部人員（預設都不自動回覆）
  ☑ 王小明 @wang 🔒          ← 勾 = 不自動回覆（預設）
  ☐ 李大華 @lee
       這個對話會自動回覆他；陳工程 於 10/02 14:30 打開
  ☑ 陳工程 @chen 🔒

  名單跟著帳號管理走，不在這裡增減。打勾＝不自動回覆（預設），
  取消打勾＝這個對話會自動回覆他。狀態為「正常」的帳號才算…

  ⚠ 這 2 個帳號還沒填 Telegram 帳號，他們發言不會被屏蔽：張三、李四
```

⚠ **勾選框與後端的 `allowed` 是反向的**：勾 = 沒有放行紀錄（不自動回覆），
取消勾 = `allowed`。所以 `checked = !allowed`，預設全勾。

- 被打開的那幾筆顯示**誰打開的、什麼時候** —— 比照忽略名單的
  「某某於某時設定」，客服需要知道這是誰的決定
- 送出失敗時**把勾選狀態還原**（`send()` 多收一個 callback）——
  不還原的話畫面顯示成功了、後端其實沒改，客服會以為設定生效
- 沒填 Telegram 帳號的同事照舊只列在警告列，**不給勾選框** ——
  他根本認不出來，給了勾選框等於謊稱有效

`StaffIgnoreResource` 多三個欄位（`allowed` / `allowed_by` / `allowed_at`）。
放行資訊由外部傳入（`collectionWithAllows()`），**不在 Resource 裡查 DB** ——
一列查一次就是 N+1。

### 順手補的深色模式缺口

這個 UI 會出現一整排 checkbox，而 `.form-check-input` **未勾選**那半
在深色模式沒有覆寫（Bootstrap 預設白底淺灰框，暗底上是突兀的白方塊）。

補 `[data-theme="dark"] .form-check-input:not(:checked)`。用 `:not(:checked)`
而不是直接寫 `.form-check-input`：後者與 `.form-check-input:checked` 特異性相同，
而它在檔案更後面，會把 checked 的金色底蓋掉。

（這是 `.badge.bg-light` 之後同一類的第四個缺口，見
[[2026-10-01-badge-bg-light-dark-mode]]。）

### 後端其他改動

- `ajax-toggle-ignore` 多兩個 action：`staff_allow` / `staff_block`，
  帶 `user_id` 而不是 `member_id`。路由與權限都沿用既有的
  `telegram_chat.ignore_manage`
- `ToggleIgnoreMemberRequest` 的 `user_id` 用
  `required_if:action,staff_allow,staff_block` + `exists:user,id`
- `usernames()` 改名 `ignoredUsernames($groupId)`，並**扣掉該對話的例外** ——
  被打開的同事會被自動回覆，訊息上再標「不自動回覆」就是標錯。
  扣除是兩份已快取清單的差集，不多打 DB

## 邊界情況

- **內部支援群組**不受影響 —— 它本來就不走自動回覆這條路
- **客服從後台回覆**是 outbound，不經過這個判斷，本來就不會觸發自動回覆
- **同事問了真問題**（例如在群組裡問客人資訊）：不自動回，由人處理。預期行為
- **客人的 username 剛好跟同事相同**：不可能，Telegram 的 username 全域唯一
- **名單是空的**（都沒填 username 也沒有 ID）：`isStaff()` 直接回 false，
  不會誤擋所有人
- **同事也在每對話名單裡**：兩份都命中，結果一樣，沒有衝突
- **同事被停用／鎖定後又發言**：不在名單內，系統會照常自動回覆他 —— 這是
  需求方確認過的行為
- **回填過 ID 的人被停用**：ID 還留在 `user` 表上，但名單查詢有
  `status = NORMAL`，所以不會被屏蔽。之後恢復正常狀態立刻又認得
- **反問流程**：系統反問後若是同事回了「1」，不會被當成客人的回答 —— 與
  [[ignore-member]] 同一道判斷，自然成立
- **被打開的同事**：那個對話會把他當一般客人處理（會自動回覆、灰標籤不出現），
  其他對話完全不受影響
- **被打開的同事帳號被刪**：放行紀錄跟著刪（`cascadeOnDelete`）——
  不留著指向不存在的人的放行
- **對話被刪除**：放行紀錄跟著走（`telegram_group_id` 也是 `cascadeOnDelete`）

## 異動檔案

### 新增

| 類型 | 檔案 |
|------|------|
| Migration | `2026_10_02_000001_add_telegram_user_id_to_user_table.php`（`user` 加一欄 + index） |
| Service | `app/Services/StaffIgnoreService.php`（`isStaff()`、名單快取、ID 回填、面板資料） |
| Presenter | `app/Presenters/TelegramUsernamePresenter.php`（username 正規化，兩邊共用） |
| Resource | `app/Http/Resources/StaffIgnoreResource.php`（名單一列，只回 `has_user_id` 不回 ID） |
| Migration | `2026_10_02_000002_create_telegram_group_staff_allow_table.php`（每對話的放行例外） |
| Model | `app/Models/TelegramGroupStaffAllow.php` |
| Repository | `app/Repositories/TelegramGroupStaffAllowRepository.php`（`allow()` / `block()` / 比對用的 id 清單） |

### 修改

| 檔案 | 內容 |
|------|------|
| `app/Services/TelegramChatService.php` | dispatch 前多一道 `isStaff()`，排在每對話名單之前 |
| `app/Services/TelegramGroupMemberService.php` | `normalizeUsername()` 改委派 Presenter；`getIgnoredNames()` 接 `$staffUsernames` 並去重 |
| `app/Services/AccountService.php` | 注入 `StaffIgnoreService`，`update()` 後清名單快取 |
| `app/Repositories/UserRepository.php` | `getTelegramIdentitiesForAutoReply()`、`getMissingTelegramIdentity()`、`findForTelegramBackfill()`；`findByTelegramUsername()` 的 select 加 `telegram_user_id` |
| `app/Repositories/TelegramGroupMemberRepository.php` | `getNamesByUsernames()` |
| `app/Http/Controllers/Admin/TelegramChatController.php` | `ajaxIgnoreMembers` 多回 `staff` / `staff_missing`；`ajaxMessages` 的 `ignored_names` 併入全域那批 |
| `app/Models/User.php` | `telegram_user_id` 的 PHPDoc |
| `config/constants.php` | `TELEGRAM.STAFF_IGNORE`（快取 key、秒數、放行清單的 key prefix）；`IGNORE.ACTION` 加 `staff_allow` / `staff_block` |
| `app/Http/Requests/TelegramChat/ToggleIgnoreMemberRequest.php` | `user_id` 的 `required_if` + `exists:user,id` |
| `public/css/custom.css` | `.form-check-input:not(:checked)` 的深色配對 |
| `resources/lang/{tw,cn,en}/telegram_chat.php` | 區塊標題、唯讀說明、未填提醒、永久識別的 hover 說明 |
| `public/js/telegram-chat/ignore-member.js` | `buildStaffHtml()` 唯讀區塊 |
| `config/changelog.php` | 一筆 |

**不需要** 新權限 keyword（唯讀展示沿用 `telegram_chat.ignore_manage`）、
**不需要** 新 route、**不需要** 改 blade（i18n 是整包 `@json(trans("telegram_chat"))`
送到前端的，新 key 自動帶上）。

## 上線注意

- **第一階段的 migration 已跑**（`telegram_user_id`，2026-10-02）
- ⚠ **`telegram_group_staff_allow` 的 migration 還沒跑** —— 沒跑之前，
  收訊判斷與面板都會噴 `Table ... doesn't exist`
- **同事要去帳號管理填 Telegram 帳號**，沒填的人認不出來。面板的警告列
  就是在講這件事 —— 目前本機只有兩個帳號，兩個都還沒填
- ID 是**第一次發言時**才回填的，所以剛上線時大家都還只有 username ——
  這段期間改 username 仍會失效

## 實測結果

### 第一階段：全域屏蔽與 ID 回填（2026-10-02）

驗證腳本全程包在 transaction 裡並 rollback，**沒有留下任何資料變更**
（事後逐欄確認還原：`telegram_user_id` / `telegram_username` / `status`
都回到原值，名單筆數回到 0）。

| 情境 | 預期 | 實測 |
|---|---|---|
| 名單為空（本機現況） | false | ✅ false |
| 客人（不在名單） | false | ✅ false |
| 發話者沒有 username | false | ✅ false |
| `@Test_Staff_User` 命中（含 `@`、大寫） | true | ✅ true |
| └ 回填 `telegram_user_id` | 寫入 | ✅ `8123456789` |
| 改掉 username 後用舊 username | false | ✅ false |
| 改掉 username 後用同一個 ID | true | ✅ true |
| 清空 username、只剩 ID | true | ✅ true |
| 帳號**停用** | false | ✅ false |
| 帳號**鎖定** | false | ✅ false |
| 面板 staff / missing | 1 筆 / `[localCS01]` | ✅ 相符 |
| `usernames()`（灰標籤用） | 正規化後小寫 | ✅ `["mixedcase_name"]` |

最後一項很關鍵：`usernames()` 回的是**正規化後**的值，而名冊
（`telegram_group_member.username`）存的也是正規化後的值 ——
兩邊格式一致，`getNamesByUsernames()` 的 `whereIn` 才比得到。
任一邊忘記正規化，灰標籤就會整排不出現。

其他驗證：

- 全檔 `php -l`；`ignore-member.js` 容器內 `node --check`
- `TelegramUsernamePresenter`：`" @AbC "` → `abc`、`@` → null、
  `normalizeAll(["@Ming","ming","","Lee"])` → `["ming","lee"]`（去重濾空）
- DI 解析：`StaffIgnoreService`、`TelegramChatService`、`AccountService`、
  `TelegramChatController` 四個都解得出來（建構子多了參數，grep 確認
  沒有別處手動 `new`）
- **尚未實測**：真的從 Telegram 發一則訊息走完 webhook（要同事先填好帳號）

### 第二階段：每對話的例外（待驗）

跑完 `telegram_group_staff_allow` 的 migration 後要驗這幾項：

| 情境 | 預期 |
|---|---|
| 沒有任何放行紀錄 | 同事照舊被屏蔽（行為與第一階段相同） |
| 打開某個同事 | 那個對話 `isStaff()` 回 false，**其他對話仍 true** |
| 收回放行 | 回到屏蔽 |
| 連點兩次打開 | 只有一列（unique + `firstOrCreate`） |
| 灰標籤 | 被打開的同事不出現「不自動回覆」標籤 |
| 面板 | 勾選狀態正確（`checked = !allowed`）、顯示誰打開的 |
| 送出失敗 | 勾選框還原成原狀 |

已完成的驗證：全檔 `php -l`、`ignore-member.js` 容器內 `node --check`、
`StaffIgnoreService` 與 `TelegramGroupStaffAllowRepository` 的 DI 解析。

## 相關

- [[ignore-member]] — 每個對話各自的忽略名單（這份的前身，兩者並存）
- [[auto-reply-natural]] — 自動回覆主流程
- [[rbac]] — `telegram_chat.ignore_manage` 權限
