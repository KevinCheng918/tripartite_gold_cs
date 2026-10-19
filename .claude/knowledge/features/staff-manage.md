# 內勤管理

> 文件待補完 —— 目前只記錄名單排序與身份定義，人員/設備管理的其他細節尚未整理。

`resources/views/admin/staff-manage/index.blade.php`（內勤管理），分兩個區塊：
人員名單與設備領用。搜尋與排序**都在前端做**，後端 `ajaxList()` 一次給完整清單。

## 身份（`user.level`）

定義在 `config/constants.php` 的 `USER.LEVEL`：

| 常數 | 值 |
|---|---|
| `ADMIN` | 0 |
| `BOSS` | 1 |
| `LEADER` | 2 |
| `ENGINEER` | 3 |
| `CS` | 4 |

⚠ **數字越小身份越高**，所以「身份 高→低」是 `level` **升冪**。
寫排序或篩選時容易反過來。

## 名單排序一律是兩層的

只比一個欄位的話，同身份（或同年資）那幾個人的順序就看瀏覽器怎麼排 ——
名單每次重新篩選都可能換位置，看起來像壞掉。所以四個排序選項都有第二層：

| 選項 | 第一層 | 第二層 |
|---|---|---|
| 身份 高→低（預設） | `level` 升冪 | 年資長→短 |
| 身份 低→高 | `level` 降冪 | 年資長→短 |
| 年資 長→短 | 年資降冪 | 身份高→低 |
| 年資 短→長 | 年資升冪 | 身份高→低 |
| 預設（ID） | 後端的 `id` 降冪 | — |

年資的第二層一律「長→短」：同身份時資深的排前面。

實作是兩個 comparator 工廠 `byLevelThenTenure(dir)` / `byTenureThenLevel(dir)`，
`dir` 傳 `1` / `-1` 決定第一層方向，第二層固定。

## ⚠ 改排序預設值時，別忘了「重設」按鈕

搜尋區的「重設」原本是 `$('#staff-sort').val('id')` —— **把預設值寫死在第二個地方**。

把 option 的預設改成身份排序後，就出現「頁面載入是身份排序、
按了重設卻跳回 ID 排序」的不一致。這是改預設值時很容易漏掉的一處。

改成 `resetSortSelect(selector)`，讀 HTML 裡的 `option[selected]`，
人員與設備兩個區塊共用：

```js
$sort.val($sort.find('option[selected]').val() || $sort.find('option').first().val());
```

用 attribute selector 而不是 `:selected` —— 後者選到的是「使用者當前選的」，
那正是要被重設掉的值。設備區塊的下拉沒有 `selected` 屬性，
會退回第一個選項（`id`），行為跟原本一樣。

## 年資的計算與「沒填」的處理

`calcTenureMonths(hiredAt)` 回月數，**沒填或填了未來日期回 `-1`**。

所以沒填入職日的人會被當成「年資最短」：

| 排序 | 沒填入職日的人排在 |
|---|---|
| 年資 長→短 | 最後 |
| 年資 短→長 | **最前** |

> ⚠ 「年資 短→長」把資料不完整的人排到第一，是反直覺的 ——
> 「沒填」不等於「年資很短」。這是既有行為（`-1` 的設計從一開始就是這樣），
> 這次沒有動它。如果要改，合理的做法是讓沒填的人**不管升冪降冪都排最後**，
> 而不是繼續當成 -1 個月。

## 異動檔案

- `resources/views/admin/staff-manage/index.blade.php` — 排序 comparator、選項順序與預設值、`resetSortSelect()`
- `resources/lang/{tw,cn,en}/staff_manage.php` — 身份排序標籤加註「同身份依年資」

## 新增設備的人員選單（2026-10-19 修）

> 需求方：「設備管理的新增設備，這邊的選擇，要有其他 level 的人，
> 老闆、主管也可能會領用設備」

同一個頁面用了**兩個不同條件**：

| | 查詢 | 條件 | 誰進得來 |
|---|---|---|---|
| 設備**列表** | `getAllCsUsersWithDetail()` | `level != ADMIN` | 老闆1／主管2／工程3／客服4 |
| **新增設備**的下拉 | `getAllCsUsers()` | `level = CS` | **只有客服 4** |

結果是「列表看得到老闆、主管的設備，新增時卻選不到那些人」。

### 修法

`StaffManageController::index()` 的 `staffList` 改用
`getAllCsUsersWithDetail()` —— 跟列表同一支。

⚠ **不改 `getAllCsUsers()` 本身**：`DashboardService` 也在用它，那裡要的
真的是「客服」。只改這個呼叫點。

⚠ 原則跟同檔案的 `consumableUsers()` 一樣（那段註解早就寫了）：
**「列表看得到誰」就該是「登記得了誰」，條件只有一份。** 設備這邊沒照做，
所以才分岔。

⚠ 多撈了幾個用不到的欄位（到職日、設備），但內勤就幾十個人 ——
理由同 `consumableUsers()`，比讓同一個條件散落兩處划算。

管理者（level 0）仍然排除，跟列表一致。

### 驗證

本機只有 Admin 與客服，沒有老闆／主管帳號可以直接看出差異，所以比對 SQL：

```
舊 where `level` = ?   binding 4      → 只有客服
新 where `level` != ?  binding 0      → 老闆/主管/工程/客服都在
```

逐 level 對照：老闆、主管、工程 **由「選不到」變成「選得到」**；
客服不變；管理者仍選不到。整頁 `view()->render()` 通過，選單有正常填入。

## 相關

- [[rbac]] — 權限（`level` 是身份，跟權限 keyword 是兩套東西）
- [[attendance]] — 出勤
- [[scheduling]] — 排班
