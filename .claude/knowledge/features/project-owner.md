# 專案負責人

> 需求（2026-10-30）：「專案要有負責人，要有權限能夠修改、新增負責人，
> 負責人只會有一位。」
> 追加：「在專案管理這邊要明確標示負責人。」

**狀態：規劃完成、四題已裁示，尚未實作。**

四題裁示（2026-10-30）：

| 題目 | 決定 |
|---|---|
| 誰可以被指派 | **只有已勾選「參與這個專案」的人** |
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

## 要改／新增的東西

| 檔案 | 說明 |
|---|---|
| `database/migrations/…_add_owner_to_project_table.php` | **新增**。`owner_id` nullable ＋ FK nullOnDelete |
| `app/Models/Project.php` | 加 `owner()` 關聯（`select(['id','nickname','status'])`）|
| `app/Repositories/ProjectRepository.php` | `LIST_COLUMNS` 加 `owner_id`；`getAll()`／`getVisibleTo()`／`find()` 加 `with('owner')` |
| `app/Services/ProjectOwnerService.php` | **新增**。指派／清空負責人，含下面那幾個驗證 |
| `app/Http/Controllers/Admin/ProjectController.php` | 多一支 `ajaxAssignOwner()`，照既有的 `canTouchProject()` 再過一次 |
| `app/Http/Requests/StaffManage/AssignOwnerRequest.php` | **新增**。`owner_id` nullable + exists |
| `app/Http/Resources/ProjectResource.php` | 多 `owner`／`owner_id`／`owner_status` |
| `routes/web.php` | `ajax-assign-owner`（`can:project.assign_owner`）|
| `config/permissionMap.php` | `project.assign_owner` |
| `resources/lang/{tw,cn,en}/project.php` | 負責人、未指派、已停用、變更⋯⋯三語系 |
| `resources/lang/{tw,cn,en}/permission.php` | 新權限的說明 |
| `resources/views/admin/project/index.blade.php` | 基本資料加一列 ＋ 變更 modal |
| `config/changelog.php` | 版本紀錄 |

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

## 待裁示

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
