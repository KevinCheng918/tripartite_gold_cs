# 任務看板的可見範圍有兩個洞

**日期**：2026-11-01（v3.54）

做「任務卡異動通知」時要在訊息裡放卡片連結，順著「點進去會發生什麼」查下去
發現的 —— 兩個洞都**不是這次的改動造成的**，但通知的連結會讓它們更容易被
踩到（每則通知都邀請大家點卡片）。

## 洞一：十三支吃單筆的 API 完全沒有判斷

看板列表會依「參與專案」過濾（`buildBoard()` 的 `user_project_ids`），
但**吃單筆的 API 網址上只有一個 id** —— 沒參與那個專案的人只要改一下數字
就打得到。

有 `task_board.view` 的人可以：看任何一張卡的詳細（含描述、附件）、
讀留言、讀異動紀錄；有對應權限的話還可以**移動、封存、還原、改內容、
打勾、刪附件、新增留言、刪別人的留言**。

| 端點 | 原本 | 性質 |
|---|---|---|
| `ajaxTaskDetail` | ❌ | 讀（描述、附件）|
| `ajaxComments` | ❌ | 讀（留言常含客戶資訊）|
| `ajaxActivities` | ❌ | 讀（標題、經手人、時間軸）|
| `ajaxUpdateTask` | ❌ | **寫** |
| `ajaxMoveTask` | ❌ | **寫** |
| `ajaxArchiveTask` / `ajaxRestoreTask` | ❌ | **寫** |
| `ajaxUpdateChecklist` | ❌ | **寫** |
| `ajaxDeleteAttachment` | ❌ | **寫**（還會刪實體檔）|
| `ajaxStoreComment` | ❌ | **寫** |
| `ajaxUpdateComment` | 只檢查「是不是本人」 | **寫** |
| `ajaxDeleteComment` | ❌ | **寫**（刪任何人的留言）|
| `ajaxReorder` | ❌ | **寫**（一次一整欄）|

這正是系統規章那次的結論：
**「只擋畫面擋不住 —— 每一支都要再過一次，連唯讀的也要。」**
那次做對了（四支 API 都過 `canTouchProject()`），但任務看板從來沒補。

### 修法

Controller 加三支守門，全部用 `User::canAccessProject()` 當判準
（**不要自己比 `project_ids`** —— 同一條規則的第二份實作遲早分岔）：

| 方法 | 用在 |
|---|---|
| `canTouchTask(Task)` | 吃一張卡的那十支 |
| `canTouchComment(TaskComment)` | 留言的編輯與刪除（繞一層回卡片）|
| `canTouchAllTasks(array)` | `ajaxReorder` 的批次 |

⚠ `canTouchComment()` **卡片查不到時回 false 不是 true**：那代表資料不一致
（FK 是 cascadeOnDelete，正常不會發生），而「判斷不出來」時要擋住不是放行。

⚠ `canTouchAllTasks()` **一張碰不到就整批拒絕**，不要只把碰不到的濾掉 ——
正常的前端不會送出看不到的卡片 id，會送就代表有問題，悄悄做掉一半只會讓
那個問題更難發現。而且它會**先去查每張卡的 `project_id`**，不相信前端送來的
任何專案資訊。

⚠ `ajaxUpdateComment` 原本的「只有本人能編輯」**不足以代替這一關**：
補 `ajaxStoreComment` 之前，沒參與專案的人留得了言 —— 那些舊留言的作者
就是本人，照樣改得動。

⚠ 403 的訊息是「**您沒有參與這張卡片所屬的專案**」而不是「沒有權限」——
兩者的下一步不同：前者去找管理者加參與專案，後者去要權限。
（同 `project.regulation_no_access` 的理由。）

## 洞二：沒勾任何參與專案的人，看板上看得到**全部**卡片

比洞一更嚴重，因為它不需要改網址 —— 登入就看得到。

```php
// 原本
$params['user_project_ids'] = $isAdmin ? [] : ($user->project_ids ?? [-1]);
```

```php
// Repository
if (!empty($criteria['user_project_ids'])) {
    $query->whereIn('project_id', $criteria['user_project_ids']);
}
```

⚠ **`??` 只擋 null，擋不住空陣列。** 一個專案都沒勾的帳號 `project_ids`
是 `[]` ——

1. `[] ?? [-1]` → **`[]`**（`??` 不會觸發）
2. `empty([])` → true → **過濾整個跳過**
3. 結果：那個人看得到資料庫裡**每一張**卡片

而文件寫的規則正好相反：

> 「**沒勾任何參與專案＝一個都看不到**，不是『不限制』。」

跟 `433079d`（小幫手所有指令的權限檢查被 `null == 0` 繞過）是同一類錯誤 ——
**把「空值」當成「不限制」**。

### ⚠ 而且畫面早就自相矛盾，只是沒有人注意到

`index()` 算篩選下拉時用的是 `empty($userProjectIds)` → 非管理者沒勾就給
**空的專案清單**。所以同一個畫面上：

- 篩選下拉：「沒有任何專案可選」
- 看板本體：列出全部卡片

兩邊各寫了一份判斷，而且空陣列的處理不一樣。

### 修法

```php
$params['user_project_ids'] = $user->isAdmin() ? [] : ($user->projectIdList() ?: [-1]);
```

⚠ `?:`（不是 `??`）—— 空陣列也要換成 `[-1]`。

⚠ `[]` 在 Repository 的語意是「**不限制**」，所以它**只能給管理者**。
非管理者沒勾任何專案時要給 `[-1]`（一個不存在的 id）才會真的篩成空的。

⚠ 判準改用 `isAdmin()` 與 `projectIdList()` 兩支 helper；`index()` 那份
自己寫的判斷也收斂成 `canAccessProject()`。原本兩處各一份、處理還不一致，
正是漏洞的來源。

⚠ Repository 那一行的 fail-open 語意加了警語：**呼叫端一旦傳空陣列，
過濾就整個消失**。要改那個語意得把所有呼叫端一起改，而漏掉的那一個
就是下一個漏洞。

## 實測

```
資料庫裡未封存的卡片共 1 張

修正前：localCS01（level=4, 參與專案=[]）看板上看到 1 張   ← 漏洞
修正後：localCS01（level=4, 參與專案=[]）看板上看到 0 張   ✓
        Admin     （level=0, 參與專案=[]）看板上看到 1 張   ✓

canTouchTask(卡 #3, 專案 3)：
  Admin      → true   ✓
  localCS01  → false  ✓（修正前這一關不存在）
  canTouchAllTasks([3]) → false ✓
```

## ⚠ 上線前要知道的營運影響

補完之後，**沒有勾「參與專案」的帳號什麼卡片都看不到、通知連結點了也是 403**。

這個 dev 資料庫裡四個客服帳號的 `project_ids` 全部是 `[]` —— 如果正式環境
也有帳號沒勾，那些人會突然「看板變空、通知連結打不開」。

那是**正確的行為**（參與專案就是用來控制可見範圍的），但要先確認正式環境的
帳號都勾好了，不然會被當成壞掉。這跟專案負責人那次的教訓是同一件事：
[[project-owner]] 的「⚠ 最容易踩到的一件事：負責人可能看不到自己的專案」。

## 相關檔案

| 檔案 | 說明 |
|---|---|
| `app/Http/Controllers/Admin/TaskBoardController.php` | 三支守門 ＋ `noAccessResponse()`；13 支端點加判斷；`buildBoard()` 的 `?:` 修正；`index()` 收斂成 `canAccessProject()` |
| `app/Repositories/TaskRepository.php` | `getProjectIdsByTaskIds()`；`user_project_ids` 的 fail-open 警語 |
| `app/Services/TaskBoardService.php` | `projectIdsOfTasks()` |
| `resources/lang/{tw,cn,en}/task_board.php` | `msg.no_access` |
