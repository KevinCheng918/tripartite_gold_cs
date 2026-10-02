# 消耗品管理（內勤）

> **狀態：已實作，16 項流程驗證全過（2026-10-02）。migration 已跑。**
>
> 上線前要做：**替每個內勤勾 `staff_manage.consumable_log`**、
> 主管勾 `staff_manage.consumable_view_all`。品項不用先建 —— 登記時直接打。

內勤人員會領到消耗品（例如一次發 10 張卡片），用掉之後要知道**誰在什麼時候
領了多少、用掉多少、還剩幾張**。

## 已確認的需求

| 項目 | 決定 |
|------|------|
| 品項 | **登記時直接打名稱**，不建清單、不限制選項（2026-10-02 改） |
| 誰登記 | **每個人登記自己的**（領用與使用都是）；管理者可以改別人的 |
| 誰看得到 | 自己只看得到自己的；**主管以上**才看得到所有人 |
| 追蹤粒度 | **只記數量**，不追到卡號 |
| 要記的欄位 | 日期、數量、**備註**（原本還有「用途」，確認用不到） |
| 位置 | 內勤管理頁新增第三個分頁（現有：人員名單、設備管理） |

## 跟現有「設備管理」不一樣

設備存在 `user.equipments`（JSON 陣列）—— 那是一份**清單**：這個人手上有筆電、
螢幕。沒有流水、沒有數量增減。

消耗品要的是**流水帳 + 餘額**，JSON 存不了（要依日期排序、要 SUM、要分組統計）。
所以開新表，不動 `equipments`。

## 資料設計

### 只有一張表 `consumable_record`

| 欄位 | 說明 |
|---|---|
| `user_id` | 哪個內勤（FK `user`，`cascadeOnDelete`） |
| `item_name` | **品項名稱，登記時自己打**（沒有品項表） |
| `type` | 1=領用（進）/ 2=使用（出） |
| `quantity` | 數量，正整數（方向由 `type` 決定，**不存負數**） |
| `happened_at` | **日期**（date）。可以補登過去的，不用 `created_at` |
| `note` | 備註（原本還有「用途」，需求方確認用不到） |
| `created_by` | 誰登記的（FK `user`，`nullOnDelete`）—— 分得出本人或代登記 |
| index | `(user_id, item_name)`、`happened_at` |

### 為什麼品項不開一張表

原本設計了 `consumable_item`（品項清單 + 啟用狀態 + 單位），需求方試用後
改成「登記時直接輸入」—— **不想為了記一筆消耗品先去建一個品項**。

代價是打錯字會分裂成兩個品項（「卡片」與「卡 片」各自算餘額）。
緩解的方式有兩個，都不限制使用者：

- `ConsumableService::normalizeItemName()` 去頭尾空白、把中間連續空白縮成一個。
  **只做到這個程度** —— 再多（全半形轉換、大小寫統一）就會開始改變使用者
  打的東西，那不是這個欄位該做的事
- 前端把已經用過的名稱放進 `<datalist>` 當建議，點一下就好；但輸入框本身
  不限制，打新的就是新的品項

### 為什麼領用與使用也不拆兩張

兩者只差一個方向。拆開的話「列出某人某品項的所有異動」要查兩次再合併排序，
餘額也要兩次 SUM 各寫一份篩選條件。

一張表加 `type` 就夠了，`quantity` 一律存正數、方向交給 `type` ——
**存負數會讓「這個人用了幾張」這種統計必須先判斷正負**，容易算錯。

### 餘額怎麼算

```
剩餘 = SUM(type=領用 的 quantity) - SUM(type=使用 的 quantity)
```

依「使用者 + 品項名稱」分組，在 SQL 裡用 `CASE WHEN` 一次掃描拿到進與出。

**不另外存餘額欄位。** 存了就有兩份真相，補登或修改歷史紀錄時一定會不同步。

## 畫面

內勤管理頁第三個分頁「消耗品」：

```
人員：[全部 ▾]   品項：[全部 ▾]                              [登記]

┌─────────────────────────────────────────────────┐
│ 王小明                                           │
│   卡片      領用 15   已用 3    剩餘 12          │
│   SIM 卡    領用 2    已用 0    剩餘 2           │
└─────────────────────────────────────────────────┘

點一列展開那個人該品項的流水：
  10/02  使用  3   客戶A開站      （王小明 登記）
  09/01  領用  10                 （管理者 登記）
```

- 剩餘 0 標黃、**負數標紅**（理論上不該發生，見下面的邊界）
- 登記視窗的品項是**輸入框 + `<datalist>` 建議**，不是下拉 ——
  可以打新的
- 從展開中的那一組按「登記」時，品項會先帶好 —— 多半就是要登記它
- 搜尋／篩選比照現有兩個分頁：**後端一次給完整清單、前端篩選**
  （見 [[staff-manage]]，那頁本來就是這個模式）

### ⚠ 列的識別用索引，不要把品項名稱塞進 `data-*`

品項名稱是使用者自己打的字串，裡面可能有引號或 CSS 選擇器的特殊字元 ——
當成 `querySelector` 的條件會直接壞掉。所以 DOM 上掛的是 `data-idx`，
對照 `state.visible` 取回那一列。

「展開中的是哪一組」則存 `{ userId, itemName }` 而不是索引 ——
篩選一改索引就變，但「展開的是誰的哪個品項」不該變。

## 權限

| keyword | 誰 | 能做什麼 |
|---|---|---|
| `staff_manage.view`（既有） | 進得了內勤管理頁的人 | 開得了消耗品分頁 |
| `staff_manage.consumable_log`（**新增**） | 全部內勤 | 登記**自己的**領用與使用 |
| `staff_manage.consumable_view_all`（**新增**） | 主管以上 | 看**所有人**的消耗品 |
| `staff_manage.edit`（既有） | 管理者 | 改／刪**別人的**紀錄、維護品項 |

⚠ 上線後要**替每個內勤勾 `consumable_log`**，否則他們連自己用掉幾張都登記不了。

### ⚠ 可見範圍在後端強制套用

沒有 `consumable_view_all` 的人，**不管送什麼 `user_id` 上來都只拿得到自己的**
（`StaffManageController::scopedUserId()`）。

前端「不顯示人員篩選」只是不要給他一個按了沒用的東西 ——
**前端藏起來的資料，改個網址就看得到**，範圍限制一定要在後端。

同理「能不能改這一筆」走 `canManage()`：本人隨時能動自己的，動別人的要
`staff_manage.edit`。route 的 middleware 擋得住「有沒有資格進這個端點」，
擋不住「送上來的 user_id 是不是自己」。

> 用 keyword 而不是判 `level <= LEADER`、也不是「登入就能用」：
> 權限一律由 permissionMap 控制，寫死身份或開特例都會讓權限表看不出全貌
> （見 [[rbac]]）。

## 邊界情況

- **用超過剩餘**：登記使用時檢查餘額，不足就擋下並說明還剩幾張。
  管理者改舊紀錄時也要檢查（改大數量可能讓餘額變負）
- **補登過去的日期**：允許 —— `happened_at` 是自己填的，不是 `created_at`。
  餘額是總量相減，跟先後順序無關，所以補登不會算錯
- **品項停用**：舊紀錄照常顯示與計算，只是不能再登記新的
- **品項刪除**：有紀錄就不准刪（`restrictOnDelete`），請改成停用 ——
  刪了之後那些流水會變成「不知道是什麼東西的 10 張」
- **帳號被刪**：流水跟著刪（`cascadeOnDelete`）。那個人都不在了，
  他的消耗品餘額沒有意義
- **登記者帳號被刪**：`created_by` 變 null，顯示「已離職人員」
  （比照忽略名單的 `ignored_by`）

## 異動檔案

### 新增

| 類型 | 檔案 |
|------|------|
| Migration | `2026_10_02_000004_create_consumable_record_table`（**只有一張表**） |
| Model | `app/Models/ConsumableRecord.php` |
| Repository | `app/Repositories/ConsumableRepository.php` |
| Service | `app/Services/ConsumableService.php`（餘額計算與三種檢查） |
| Request | `app/Http/Requests/Consumable/StoreRecordRequest.php` |
| Resource | `app/Http/Resources/ConsumableRecordResource.php` |
| 前端 | `public/js/staff-manage-consumable.js` |

⚠ 前端**獨立成 js 檔**而不是寫在 blade 的 `@section('scripts')` 裡 ——
這頁現有的兩個分頁是寫在 blade 內的（既存狀況），但 PROMPTS 要求
JS 分到 `public/js`，新的就不跟進了。設定走 `data-*` 傳進去（比照
telegram-chat）。

### 修改

| 檔案 | 內容 |
|------|------|
| `app/Http/Controllers/Admin/StaffManageController.php` | 消耗品的 5 個 ajax 端點、`canManage()`、`scopedUserId()` |
| `app/Repositories/UserRepository.php` | `getNamesByIds()`（見下方的坑） |
| `routes/web.php` | `staff-manage` 群組下的 `ajax-consumable-*` |
| `config/permissionMap.php` | `staff_manage.consumable_log`、`staff_manage.consumable_view_all` |
| `config/constants.php` | `CONSUMABLE`（type、備註長度與數量上限） |
| `resources/lang/{tw,cn,en}/staff_manage.php` | 分頁標題、欄位、訊息（各 37 個 key） |
| `resources/lang/{tw,cn,en}/permission.php` | 兩個新權限的名稱 |
| `resources/views/admin/staff-manage/index.blade.php` | 第三個分頁 + 載入 js |
| `config/changelog.php` | 一筆 |

### 踩到的坑：拿錯一支名字很像的查詢

人名本來想用 `UserRepository::getMentionableByIds()` —— 名字看起來就是
「依 id 拿使用者」，但它是為**內部群組 tag 人**寫的：排除工程、只取
正常狀態、而且**必須有 `telegram_username`**。拿來查人名會莫名其妙漏掉
一整批人（沒填 TG 帳號的、工程、停用的都不見）。

另開 `getNamesByIds()`，**不加任何狀態條件** —— 已經離職或停用的人，
他留下的紀錄仍然要顯示得出是誰。

> 這跟 [[ignore-staff]] 的 `find()` 缺欄位、[[vm]] 的 `BILLING_COLUMNS`
> 少 `updated_at` 是同一類錯誤：**挑共用方法時要看它的 where，不是看名字**。

### 上線要做的事

1. `php artisan migrate`（兩張新表）
2. 容器內 `php artisan optimize`（改了 route 與 permissionMap）
3. 帳號管理替**每個內勤**勾 `staff_manage.consumable_log`
4. 主管以上另外勾 `staff_manage.consumable_view_all`（沒勾就只看得到自己的）

品項不用先建 —— 登記時直接打名稱。

## 實測（2026-10-02，16 項全過）

transaction + rollback，跑完一整個生命週期：

| 情境 | 結果 |
|---|---|
| 領用 10 張「卡片」→ 剩餘 10 | ✅ |
| 使用 3 張、備註存進去 → 剩餘 7 | ✅ |
| **再打一個沒見過的品項「SIM 卡」** → 兩個品項、建議清單兩筆 | ✅ |
| **打成「 卡片 」（前後空白）** → 併進同一個品項、沒有變成第三個 | ✅ |
| 想用 100 張（只剩 12） | ✅ 擋下，帶出「目前剩餘 12」 |
| 把使用 3 改成 50 | ✅ 擋下（排除自己那筆後重算） |
| 刪掉其中一筆領用（還有另一筆撐著） | ✅ 刪得掉，剩餘正確 |
| 再刪第二筆領用（會短少 3） | ✅ 擋下並說明 |
| 兩個人的總覽／只看自己 | ✅ |
| rollback 後歸零 | ✅ |

**尚未實測**：瀏覽器上的互動（分頁切換才載入、展開流水、登記 modal、
datalist 建議）—— 那些要實際開頁面點。

## 相關

- [[staff-manage]] — 內勤管理（人員名單、設備管理）
- [[rbac]] — 權限 keyword
