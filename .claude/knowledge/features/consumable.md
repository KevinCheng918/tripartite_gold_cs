# 消耗品管理（內勤）

> **狀態：已實作，17 項流程驗證全過（2026-10-02）。**
>
> 上線前要做：**替每個內勤勾 `staff_manage.consumable_log`**、
> 主管勾 `staff_manage.consumable_view_all`，然後先建品項。

內勤人員會領到消耗品（例如一次發 10 張卡片），用掉之後要知道**誰在什麼時候
領了多少、用掉多少、還剩幾張**。

## 已確認的需求

| 項目 | 決定 |
|------|------|
| 品項 | **多品項，可自己維護**（卡片、SIM 卡…），加新的不用改程式 |
| 使用紀錄誰登記 | **每個人登記自己的**；管理者可以改別人的 |
| 追蹤粒度 | **只記數量**，不追到卡號 |
| 要記的欄位 | 日期、數量、用途 |
| 位置 | 內勤管理頁新增第三個分頁（現有：人員名單、設備管理） |

## 跟現有「設備管理」不一樣

設備存在 `user.equipments`（JSON 陣列）—— 那是一份**清單**：這個人手上有筆電、
螢幕。沒有流水、沒有數量增減。

消耗品要的是**流水帳 + 餘額**，JSON 存不了（要依日期排序、要 SUM、要分組統計）。
所以開新表，不動 `equipments`。

## 資料設計

### `consumable_item`（品項）

| 欄位 | 說明 |
|---|---|
| `name` | 品項名稱（卡片、SIM 卡） |
| `unit` | 單位（張、個、片），純顯示用 |
| `status` | 1=啟用 / 0=停用。停用的不能再登記，但舊紀錄還看得到 |
| `sort_order` | 排序 |
| `note` | 備註 |

### `consumable_record`（流水）

| 欄位 | 說明 |
|---|---|
| `user_id` | 哪個內勤（FK `user`，`cascadeOnDelete`） |
| `consumable_item_id` | 哪個品項（FK，`restrictOnDelete` —— 有紀錄的品項不准刪，改停用） |
| `type` | 1=領用（進）/ 2=使用（出） |
| `quantity` | 數量，正整數（方向由 `type` 決定，**不存負數**） |
| `happened_at` | **日期**（date）。可以補登過去的，不用 `created_at` |
| `purpose` | 用途。使用時必填、領用時留空 |
| `note` | 備註 |
| `created_by` | 誰登記的（FK `user`，`nullOnDelete`）—— 分得出本人登記或管理者代登記 |
| index | `(user_id, consumable_item_id)`、`happened_at` |

### 為什麼是一張流水表而不是兩張

領用與使用只差一個方向。拆兩張表的話：

- 「列出某人某品項的所有異動」要查兩次再合併排序
- 餘額要兩次 SUM 相減，而且兩邊的篩選條件得各寫一份

一張表加 `type` 就夠了，`quantity` 一律存正數、方向交給 `type` ——
**存負數會讓「這個人用了幾張」這種統計必須先判斷正負**，容易算錯。

### 餘額怎麼算

```
剩餘 = SUM(type=領用 的 quantity) - SUM(type=使用 的 quantity)
```

**不另外存餘額欄位。** 存了就有兩份真相，補登或修改歷史紀錄時一定會不同步。
這張表的量級是「人數 × 品項 × 每月幾筆」，SUM 很便宜；真的慢了再加快取。

## 畫面

內勤管理頁第三個分頁「消耗品」：

```
人員：[全部 ▾]   品項：[全部 ▾]                      [品項管理] [登記]

┌─────────────────────────────────────────────────┐
│ 王小明                                           │
│   卡片     領用 10   已用 3    剩餘 7           │
│   SIM 卡   領用 2    已用 0    剩餘 2           │
│ 李大華                                           │
│   卡片     領用 10   已用 10   剩餘 0  ⚠        │
└─────────────────────────────────────────────────┘

點一列展開那個人該品項的流水：
  10/02  使用  2 張   客戶A開站    （王小明 登記）
  09/28  使用  1 張   測試用       （王小明 登記）
  09/01  領用  10 張               （管理者 登記）
```

- 剩餘 0 標黃、**負數標紅**（理論上不該發生，見下面的邊界）
- 搜尋／排序比照現有兩個分頁：**後端一次給完整清單、前端篩選**
  （見 [[staff-manage]]，那頁本來就是這個模式）

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
| Migration | `2026_10_02_000003_create_consumable_item_table`、`..._000004_create_consumable_record_table` |
| Model | `app/Models/ConsumableItem.php`、`app/Models/ConsumableRecord.php` |
| Repository | `app/Repositories/ConsumableRepository.php` |
| Service | `app/Services/ConsumableService.php`（餘額計算與三種檢查） |
| Request | `app/Http/Requests/Consumable/StoreRecordRequest.php`、`StoreItemRequest.php` |
| Resource | `app/Http/Resources/ConsumableRecordResource.php`、`ConsumableItemResource.php` |
| 前端 | `public/js/staff-manage-consumable.js` |

⚠ 前端**獨立成 js 檔**而不是寫在 blade 的 `@section('scripts')` 裡 ——
這頁現有的兩個分頁是寫在 blade 內的（既存狀況），但 PROMPTS 要求
JS 分到 `public/js`，新的就不跟進了。設定走 `data-*` 傳進去（比照
telegram-chat）。

### 修改

| 檔案 | 內容 |
|------|------|
| `app/Http/Controllers/Admin/StaffManageController.php` | 消耗品的 8 個 ajax 端點、`canManage()`、`scopedUserId()` |
| `app/Repositories/UserRepository.php` | `getNamesByIds()`（見下方的坑） |
| `routes/web.php` | `staff-manage` 群組下的 `ajax-consumable-*` |
| `config/permissionMap.php` | `staff_manage.consumable_log`、`staff_manage.consumable_view_all` |
| `config/constants.php` | `CONSUMABLE`（type、status、長度與數量上限） |
| `resources/lang/{tw,cn,en}/staff_manage.php` | 分頁標題、欄位、訊息（各 58 個 key） |
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
5. 先建品項（卡片…），才登記得了領用

## 實測（2026-10-02，17 項全過）

transaction + rollback，跑完一整個生命週期：

| 情境 | 結果 |
|---|---|
| 領用 10 → 領用 10／已用 0／剩餘 10 | ✅ |
| 使用 3 → 剩餘 7 | ✅ |
| 想用 100（只剩 7） | ✅ 擋下，訊息帶出「目前剩餘 7」 |
| 把使用 3 改成 5 → 剩餘 5 | ✅ |
| 想改成 20 | ✅ 擋下（排除自己那筆後重算） |
| 刪掉領用 10（已用掉 5） | ✅ 擋下，說明「會短少 5」 |
| 先刪使用、再刪領用 | ✅ 剩餘回到 10 → 歸零 |
| 停用的品項登記新紀錄 | ✅ 擋下 |
| 刪除有紀錄的品項 | ✅ 擋下，叫人改成停用 |
| 兩個人的總覽／只看自己 | ✅ 2 列／1 列 |
| rollback 後兩張表歸零 | ✅ |

**尚未實測**：瀏覽器上的互動（分頁切換才載入、展開流水、登記 modal、
品項管理）—— 那些要實際開頁面點。

## 相關

- [[staff-manage]] — 內勤管理（人員名單、設備管理）
- [[rbac]] — 權限 keyword
