# 專案負責人

> 需求（2026-10-30）：「專案要有負責人，要有權限能夠修改、新增負責人，
> 負責人只會有一位。」
> 追加：「在專案管理這邊要明確標示負責人。」

**狀態：已實作（v3.49，2026-10-30）。**

四題裁示（2026-10-30）：

| 題目 | 決定 |
|---|---|
| 誰可以被指派 | **只有已勾選「參與這個專案」的人**，而且**排除管理者**（追加）|
| 指派到沒參與的人 | **擋下來**，要求先去帳號管理勾選參與專案 |
| 負責人停用／離職 | **保留 `owner_id`，畫面標紅字提醒換人** |
| 新增專案時 | **就可以選，允許留空** |

## 現況

`project` 表只有 `created_by`（建立者），而且它**不是負責人** ——
那只記得「這筆資料是誰開的」，開完就不會再變。專案管理頁的基本資料區
現在顯示「建立者」，常被誤讀成負責人。

```
project: id / name / description / status / reactivated_at / created_by / timestamps
```

權限只有兩個 keyword：`project.view`（進得了這一頁）、
`project.edit`（改名稱、說明、狀態、以及規章的增修刪）。

## 「只會有一位」用欄位，不用關聯表

`project` 表加一欄 `owner_id`，**不是**開一張 `project_owner` 關聯表。

⚠ 一個欄位天生就只放得下一個人 —— **「只有一位」由結構保證，不靠程式檢查**。
關聯表則要另外寫「存檔前先刪掉舊的」或加 unique 索引，而那一步漏掉就會
出現兩個負責人，而且不會報錯。

| 欄位 | 型別 | 說明 |
|---|---|---|
| `owner_id` | `unsignedBigInteger` **nullable** | 負責人；FK → `user.id`，`nullOnDelete` |

⚠ **一定要 nullable**：FK 設 `nullOnDelete` 而欄位不是 nullable 的話，
負責人帳號被刪除的那一刻 MySQL 會直接報錯 —— 刪不掉帳號，而且錯誤訊息
指向一個跟帳號管理毫無關係的外鍵。（這個專案踩過，見
[[feedback_fk_nullable]] 的規則。）

⚠ **nullable 也是功能需求**：新專案開出來可能還沒決定負責人，
沒有「未指派」這個狀態的話，開專案的人會被迫先隨便填一個。

## 權限：新增 `project.assign_owner`

需求方明講「要有權限能夠修改、新增負責人」。

⚠ **不沿用 `project.edit`**：改說明、改狀態是維護資料；
**指派負責人是人事性質的決定** —— 它決定了這個專案出事找誰。
這個專案一貫照「使用者在做什麼」切權限（規章就從 `project.view`
裡切出 `telegram_chat.regulation`，理由同一個）。

所以負責人那個下拉**不包在 `project.edit` 區塊裡**，各自判斷：

| 權限 | 看得到負責人是誰 | 改得動 |
|---|---|---|
| `project.view` | ✅ | ❌ |
| `project.view` ＋ `project.edit` | ✅ | ❌ |
| `project.view` ＋ `project.assign_owner` | ✅ | ✅ |

⚠ **顯示不需要新權限**：需求方要「明確標示」，那是進得了這一頁的人都該
看到的資訊。把顯示也綁在 `assign_owner` 上，等於大部分人看不到負責人，
跟需求相反。

## ⚠ 最容易踩到的一件事：負責人可能看不到自己的專案

v3.44 把專案管理改成**只看得到自己有勾「參與專案」的專案**
（見 [[project-regulation]]）。所以：

> 指派一個沒有被勾選參與這個專案的人當負責人 ——
> **他登入後在專案管理裡看不到自己負責的那個專案。**

不會報錯，他只會覺得「我明明是負責人，這頁卻是空的」。

**裁示：擋下來。** 下拉只列出已參與這個專案的人，而且**後端再驗一次** ——
下拉是前端的清單，直接打 API 照樣塞得進一個沒參與的 id。

⚠ 錯誤訊息要講**下一步**：「◯◯◯ 尚未參與本專案，請先到帳號管理勾選
參與專案」。只回「不可以指派」的話，被擋的人不知道要去哪裡處理。

⚠ 這個驗證**不能只在 `ProjectOwnerService` 寫一次就算**：
`ajaxStore()`（新增專案時一起選）與 `ajaxAssignOwner()`（之後變更）
是兩條路徑，**兩邊都要過同一支驗證**。這跟規章那次的教訓一樣 ——
同一條規則兩份實作，分岔的那一邊就是漏洞。

## ⚠ 第二件：負責人離職之後

帳號有「停用」（`status`）與「軟刪除」兩種離場方式：

- **軟刪除**：`nullOnDelete` 只在真的 `DELETE` 時觸發，而這個專案的帳號是
  **軟刪除**（`softDelete()`）—— 所以 `owner_id` 會繼續指著一個已刪除的帳號。
- **停用**：資料還在，撈得到名字，但那個人已經不在了。

**裁示：兩種都保留 `owner_id`，畫面標紅字提醒換人。**

⚠ **軟刪除的那一種撈不到名字**：`owner()` 關聯預設會被 `SoftDeletes`
的 global scope 濾掉，結果是 null —— 畫面會顯示「未指派」，
跟「真的沒指派」混在一起。所以 `owner()` 關聯要 **`withTrashed()`**，
再用 `deleted_at` 分出「已離職」與「已停用」兩種紅字。

| 負責人狀態 | 畫面 |
|---|---|
| 正常 | 👤 王小明 |
| 停用（`status` 非正常）| 👤 王小明 <span style="color:red">（已停用，請更換負責人）</span> |
| 軟刪除 | 👤 王小明 <span style="color:red">（已離職，請更換負責人）</span> |
| `owner_id` 是 null | 未指派（橘色 badge）|

⚠ `owner()` 的 `select` 要含 **`status` 與 `deleted_at`** ——
少了它們就分不出上面那三種，而且不會報錯。

## 畫面：專案管理頁

需求方：「在專案管理這邊要明確標示負責人。」

基本資料區現在是五列（專案名稱／狀態／建立者／建立時間／最後更新）。
負責人**插在第二列**（名稱之後、狀態之前），而不是接在「建立者」旁邊 ——
擺在建立者旁邊的話兩個都是人名，一眼分不出哪個才是負責人，
而「建立者」恰好是現在最容易被誤讀成負責人的那一欄。

```
專案名稱    三方金流
負責人      👤 王小明            ← 新增，加粗、有圖示
狀態        啟用
建立者      李小華
建立時間    2026-08-17 10:23
最後更新    2026-10-30 14:02
```

- 沒指派時顯示 **「未指派」**（橘色 badge），不要顯示「-」——
  「-」看起來像沒這個欄位，橘色才看得出「這裡缺東西」
- 有 `project.assign_owner` 時，這一列右邊多一顆「變更」小按鈕，
  開一個只有負責人下拉的 modal
- 上方的專案 tab **不加負責人**：tab 只有專案名稱的寬度，
  再塞一個人名會擠成兩行

## ⚠ 管理者不可以當負責人（需求方 2026-10-30 追加）

> 「指派人不能有 admin。」

第一版沒排除管理者，結果管理者出現在**每一個**專案的候選名單裡 ——
因為 `canAccessProject()` 對管理者一律回 true，
而那個 true 的本意是「他看得到所有專案」，
**不是**「他適合當每個專案的負責人」。

**做法：`getAssignableForOwner()` 加 `where('level', '!=', LEVEL.ADMIN)`。**

⚠ 用 **SQL** 擋，不要撈回來再用 `isAdmin()` 篩：那是「這一頁要哪些人」的
條件，屬於查詢的事。

⚠ 排除之後**三個下拉（新增／編輯／變更）與後端的 guard 一次全中** ——
guard 問的就是「在不在候選名單裡」，所以不必另外寫一條「不可以是管理者」。
這正是把 guard 寫成「查名單」而不是「重新判斷一次規則」的好處。

### 兩個連帶要處理的事

**1. 錯誤訊息要分開講。** guard 原本只有一句「尚未參與本專案」——
直接打 API 指派管理者會得到那一句，而他跑去幫管理者勾參與專案
**還是不行**。所以多一支 `rejectReason()`：

```php
private function rejectReason($ownerId)   // 只決定「講哪一句」
{
    $owner = $this->userRepository->find($ownerId);

    if (filled($owner) && $owner->isAdmin()) {
        return 'owner_not_allowed_admin';
    }

    return 'owner_not_participant';
}
```

⚠ **`rejectReason()` 不參與「合不合格」的判斷** —— 合不合格仍然只由候選名單
決定。把判斷搬進來就變成同一條規則的第二份實作。

**2. 舊資料要標紅字。** 這條規則之前已經指派出去的管理者還留在
`owner_id` 上，而他現在**不在候選名單裡** —— 編輯視窗的下拉會落在
「未指派」，按下確認就把負責人清空，操作者以為自己什麼都沒改。

所以 `Project::getOwnerIssueAttribute()` 多一種 `admin`。

⚠ **`admin` 一定要排在 `not_participant` 前面**：`canAccessProject()`
對管理者一律回 true，所以管理者永遠不會被判成 `not_participant` ——
排在後面的話這一種永遠不會被回報。嚴重程度：
離職 ＞ 停用 ＞ 是管理者 ＞ 沒參與。

**3. 提示文案要寫出來。** `owner_select_hint`／`owner_create_hint` 都加了
「管理者不會出現在名單中」—— 管理者自己開這個下拉時找不到自己，
不說的話只會以為名單撈壞了。

## ⚠ 第三件：兩個裁示撞在一起（實作時才發現）

第 1 題裁示「只有已勾選參與這個專案的人可以被指派」，
第 4 題裁示「新增專案時就可以選負責人」—— 這兩條**同時成立時會互相抵銷**：

> 新增專案的那一刻**專案還不存在**，沒有任何人勾得了「參與它」。

所以新增表單的下拉如果也照第 1 題篩，名單只會剩管理者
（`canAccessProject()` 對管理者一律回 true），這個欄位等於沒用。

**做法：按「專案存在了沒」分兩份名單。**

| 路徑 | 名單 | 擋不擋「沒參與」 |
|---|---|---|
| 新增專案（`ajaxStore`）| `UserRepository::getAssignableForOwner()` — **全部啟用帳號** | ❌ 不擋（擋了等於沒這個欄位）|
| 編輯專案（`ajaxUpdate`）| `UserRepository::getOwnerCandidatesByProject()` — 逐專案篩 | ✅ `ProjectOwnerService::guardParticipation()` |
| 變更負責人（`ajaxAssignOwner`）| 同上 | ✅ 同上 |

⚠ 分界是「**這個專案存在了沒**」，不是「這是哪一支 API」—— 只有新增那一刻
專案還不存在，其餘一律照裁示擋。

⚠ 新增那條路徑放寬之後，**補救靠畫面**：存檔後專案卡片的負責人會標紅字
`not_participant`「尚未參與本專案，看不到這個專案，請到帳號管理勾選參與專案」，
而新增表單的下拉底下就先寫了 `owner_create_hint` 提醒這件事。
少了這兩段提示，新負責人會看到一個空白的專案管理頁而沒有人知道為什麼。

⚠ 兩支名單**共用同一趟查詢**：`UserRepository` 用一個 private 屬性 memo
（同一頁兩邊都要問，不 memo 就是兩趟一模一樣的 query）。

## ⚠ 「編輯專案」裡面也要有負責人（需求方回報才補上）

第一版把負責人**只放在那顆「變更」小按鈕**裡，編輯專案的視窗刻意不放
（理由是「`ajaxUpdate` 不收 `owner_id`」）。需求方 2026-10-30 回報：

> 「我編輯沒有負責人可以更新。」

**那個理由是倒過來的** —— 「API 不收」是實作現況，不是需求；
負責人是專案的基本資料之一，開了編輯視窗卻找不到它，
使用者只會以為功能沒做。側邊那顆「變更」是捷徑，不是替代品。

**做法：`ajaxUpdate` 也收 `owner_id`，而換負責人的編排放在
`ProjectService::update()` 裡。**

⚠ **transaction 與編排都在 Service，不在 Controller** —— PROMPTS.md
明訂 Controller 禁止直接 DB 呼叫，`DB::transaction()` 也算。
Controller 那一行就只有 `$this->projectService->update($project, $params);`。

```php
// ProjectService::update()
public function update(Project $project, array $params)
{
    // 短路：沒有要動負責人就只有一次寫入，不必開 transaction
    if (!Arr::has($params, 'owner_id')) {
        return $this->updateBasics($project, $params);
    }

    return DB::transaction(function () use ($project, $params) {
        $project = $this->updateBasics($project, Arr::except($params, ['owner_id']));

        return $this->ownerService->assign($project, Arr::get($params, 'owner_id'));
    });
}
```

⚠ **自己塞 `owner_id` 進 `$params` 就是開後門**：那會繞過
`guardParticipation()`（不可以是管理者、必須參與本專案），
「編輯」這條路徑就變成可以指派不合格的人。同一條規則必須只有一份實作。

⚠ **一定要包 `DB::transaction`**：負責人被擋下來的時候，名稱／說明／狀態
也不可以留下來 —— 不然使用者看到「指派失敗」，卻不知道自己改的名字
其實已經存進去了。（雖然只寫 `project` 一張表，原子性的理由照樣成立。）

⚠ `ProjectService` 因此依賴 `ProjectOwnerService` —— 不會循環，
後者只依賴兩個 Repository。

⚠ `UpdateProjectRequest` 的規則**一定要 `sometimes`**：沒有
`project.assign_owner` 權限的人表單裡沒有這個欄位，payload 不含 `owner_id`
—— 少了 `sometimes`，「沒送」會被當成「送了 null」而**把負責人清空**。
「沒送＝不要動」與「送 null＝清空」是兩件事。

⚠ `ValidationException` 要在 `ajaxUpdate` 也**單獨接**，不然
「這位同仁尚未參與本專案」會掉進 `\Exception` 變成 500「更新失敗」。

### 三個下拉共用一支 fill 函式

新增、編輯、變更三個視窗都有負責人下拉，而且「現任負責人不在名單裡要開
紅色警示」這個判斷三邊都要 —— 所以 `public/js/project.js` 裡是
`fillOwnerSelect()` ＋ `renderOwnerIssue()` 兩支共用。

⚠ 各寫一份的話，**分岔的那一份就是會把負責人清空的那一份**。

⚠ 選項由 JS 填、不在 Blade 寫死：兩種模式的名單不一樣（見上表），
Blade 沒辦法用同一段 `@foreach` 同時服務兩種。

## ⚠ 第四件：`ajaxStore` 的權限缺口（實作時才發現）

`ajax-store` 的 route 擋的是 **`project.edit`**（開專案的權限），
但 `StoreProjectRequest` 收 `owner_id` —— 所以：

> 只有 `project.edit`、沒有 `project.assign_owner` 的人，雖然畫面上看不到
> 那個下拉，**直接打 API 照樣指派得了負責人**。

那會讓 `project.assign_owner` 這個 keyword 形同虛設。

**做法：`ajaxStore()` 在呼叫 Service 之前，沒有 `project.assign_owner`
就把 `owner_id` 從 `$params` 裡 `Arr::except()` 拔掉。**

⚠ **拔掉而不是改寫成 null**：`null` 的語意是「明確指定未指派」，
跟「這個人無權決定」是兩回事 —— 雖然現在結果一樣，
但哪天加「預設負責人」的邏輯就會分岔。

⚠ 這類「route 的權限 ≠ 這個欄位要的權限」的缺口，**只要一個 Request 同時
服務兩種權限的人就會出現**。新增欄位時要問的是「收這個欄位要什麼權限」，
不是「這支 API 要什麼權限」。

## 要改／新增的東西

| 檔案 | 說明 |
|---|---|
| `database/migrations/2026_10_30_000003_add_owner_to_project_table.php` | **新增**。`owner_id` nullable ＋ FK nullOnDelete ＋ index |
| `app/Models/Project.php` | 加 `owner()` 關聯（`withTrashed()`，select 五欄）＋ `getOwnerIssueAttribute()` |
| `app/Repositories/ProjectRepository.php` | `LIST_COLUMNS` 加 `owner_id`；三支查詢共用 `LIST_RELATIONS = ['creator','owner']` |
| `app/Repositories/UserRepository.php` | `getAssignableForOwner()`（全部啟用）＋ `getOwnerCandidatesByProject()`（逐專案篩），共用一趟查詢 |
| `app/Services/ProjectOwnerService.php` | **新增**。`assign()`／`guardParticipation()`／`candidatesFor()`／`assignableUsers()` |
| `app/Services/ProjectService.php` | `store()` 收 `owner_id`；`update()` 編排「基本欄位 ＋ 換負責人」並包 transaction（原本的 body 收成 private 的 `updateBasics()`）|
| `app/Http/Controllers/Admin/ProjectController.php` | 多 `ajaxAssignOwner()`；`index()` 吐兩份候選名單；`ajaxStore()`／`ajaxUpdate()` 拔掉越權的 `owner_id` ＋ 單獨接 `ValidationException` |
| `app/Http/Requests/StaffManage/AssignOwnerRequest.php` | **新增**。`owner_id` nullable + exists（含 `deleted_at,NULL`）|
| `app/Http/Requests/StaffManage/StoreProjectRequest.php` | 加 `owner_id` |
| `app/Http/Requests/StaffManage/UpdateProjectRequest.php` | 加 `owner_id`（**`sometimes`**，理由見上一節）|
| `app/Http/Resources/ProjectResource.php` | 多 `owner`／`owner_id`／`owner_issue` |
| `routes/web.php` | `ajax-assign-owner`（`can:project.assign_owner`）|
| `config/permissionMap.php` | `project.assign_owner` |
| `resources/lang/{tw,cn,en}/project.php` | 負責人、未指派、三種紅字、JS 要的那幾句⋯⋯三語系各 49 個 key |
| `resources/lang/{tw,cn,en}/permission.php` | `project.assign_owner` 的說明 |
| `resources/views/admin/project/index.blade.php` | 基本資料加一列 ＋ 變更 modal ＋ 新增表單的下拉 ＋ `#project-app` 的 data-* |
| `public/js/project.js` | **新增**。整頁 JS 從 Blade 抽出來（PROMPTS.md 規範）|
| `config/changelog.php` | v3.49 |

⚠ `config/rules.php` **沒有要加的東西**：`owner_id` 的驗證規則裡沒有任何
長度／上限之類的魔術數字（只有 `nullable|integer|exists`），
硬塞一個常數進去反而是多一層間接。

⚠ **`LIST_RELATIONS` 這個常數是刻意的**：`getAll()`／`getVisibleTo()`／`find()`
三支都要 `with(['creator','owner'])`，各寫一份的話漏掉的那一支會在 Blade
逐筆查（N+1），而**畫面看起來一模一樣**，不會有人發現。

⚠ **`LIST_COLUMNS` 漏加 `owner_id` 的後果**：關聯會撈不到，
負責人永遠顯示「未指派」而且**不會有任何錯誤**。
這個專案為「select 漏欄位」修過好幾次
（`UserRepository::LIST_COLUMNS` 的註解列了兩個案例）。

## 明確不做

- **不做負責人變更歷程**（誰在什麼時候把負責人換成誰）。
  規章那邊也只記「誰最後改的」，同一個標準。要的話是另一個題目。
- **不做「負責人才能改這個專案」** —— 需求只說要有負責人這個欄位與
  指派權限，沒有說要用它去擋編輯。權限仍然只看 `project.edit`。
- 不通知新舊負責人（沒有這個需求；真要的話接 `StaffDmService`）。

## 變更 modal 的一個坑：現任負責人不在候選名單裡

負責人離職／停用／沒參與這個專案時，他**不在下拉的候選名單裡**，
所以開啟「變更負責人」時 `<select>` 會落在第一個選項「未指派」。

> 操作者會以為自己什麼都沒動 —— 按下確認就把負責人清空了。

**做法：`currentInList` 為 false 而且現任負責人有名字時，
modal 上方開一條紅色警示**「王小明：已離職，請更換負責人」，
講明「您看到的不是沒改，是現任負責人已經不能用了」。

⚠ 不要改成「把現任負責人硬塞進下拉並選中」：那樣按確認會送出一個
`guardParticipation()` 一定會擋掉的 id，使用者得到一個莫名的錯誤。

## 裁示紀錄（2026-10-30，四題皆已決定，粗體為採用的選項）

### 1. 誰可以被指派成負責人？

- (a) **任何啟用中的帳號**（最單純）
- (b) **只有已勾選「參與這個專案」的人**（跟第 2 題連動，不會有「看不到自己專案」的問題）
- (c) **只有組長以上**（`level <= 2`）

### 2. 指派了一個沒參與這個專案的人，怎麼辦？

- (a) **自動把他加進參與專案**（指派即授權；最順，但等於從專案頁改到人員設定）
- (b) **擋下來**，要求先去帳號管理勾選參與專案（最保守，但多一趟）
- (c) **允許，但在畫面上標警示**「這位同仁尚未參與本專案，看不到這個專案」

### 3. 負責人停用或離職（軟刪除）之後？

- (a) **保留 `owner_id`，畫面標紅字**「負責人已停用／已離職」（看得出要換人）
- (b) **自動清空成「未指派」**（乾淨，但沒人會注意到專案沒負責人了）
- (c) 停用帳號時**擋住**，要求先把他負責的專案轉移出去

### 4. 新增專案時要不要一起選負責人？

- (a) 新增時就可以選（可留空）
- (b) 新增完再另外指派（新增表單不動）
