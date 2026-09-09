# 虛擬機管理（VM）

## 現況

已完成，持續迭代。

> 本文件於 2026-09-07 補建，起因是「新增虛擬機的站台可搜尋」這次改動。
> 功能範圍是依路由、Model、權限對照回填的，**部分實作細節尚未逐一查證**，
> 後續動到相關程式時請一併補完，不要把這裡的描述當成完整規格。

## 功能概述

管理各站台的虛擬機主機與月費帳務。

- **主機管理**：新增／編輯虛擬機（站台、hostname、內外網 IP、機型、規格、月費、VPN 費、Google 費、帳單日、備註）
- **開關機**：`ajax-toggle-power`，`vm_server.powered_off_at` 記錄關機時間
- **帳務**：每月產生帳單、上傳繳款證明、審核通過、直接標記已付
- **繳款通知**：把帳單資訊送到站台的 Telegram 群組（見 [[telegram-chat]]）
- 帳單有匯率欄位（`add_exchange_rate_to_vm_billing_table`）

## 帳務紀錄的操作按鈕依 `paid` 分支

`resources/views/admin/vm/index.blade.php` 依 `b.paid` 決定顯示哪些按鈕：

| `paid` | 狀態 | 按鈕 |
|--------|------|------|
| 0 | 未收款 | 複製文案／發送通知、上傳證明、標記已收 |
| 2 | 待審核 | **查看證明**、重新上傳、審核通過 |
| 1 | 已收款 | **查看證明**（2026-09-09 補上，先前完全沒有這個分支） |

關機的主機（`vm_server.power_status === 0`）一律不顯示任何操作按鈕，
每個分支都要帶 `!vmPowerOff`。

> **`paid = 1` 原本沒有任何分支**，一旦標記已收，繳款證明就再也沒有入口，
> 事後對帳查不到憑證。資料一直都在 —— `proof_image` 在 `VmRepository`
> 的查詢欄位裡、`VmBillingResource` 有回傳，而 `markPaid()` / `approvePaid()`
> 只更新 `paid` 與 `paid_at`，不會清掉證明。純粹是介面少了按鈕。
>
> 新增狀態分支時記得檢查：**這個狀態下該看得到的東西是不是也跟著消失了**。

## 站台可搜尋（2026-09-07）

新增／編輯 modal 的站台從 `<select>` 改為「文字輸入 + 隱藏 id + 可篩選清單」。

### 為什麼是行內展開而不是浮層

`#modal-vm` 用 `modal-dialog-scrollable`，`.modal-body` 有 `overflow-y: auto`，
而站台是**第一個欄位** —— 絕對定位的下拉會被 body 邊界裁掉。
因此清單直接接在輸入框下方展開（自身 `max-height:180px` 可捲）。

> 頁面上方搜尋列那組站台下拉（`js-vm-station-opt`）用的是絕對定位，
> 那裡不在 scrollable 容器內所以沒問題。兩組是**不同的 class**
> （modal 是 `js-vm-station-pick`），改動時不要混用。

### 三個容易漏掉的點

- **`required` 會失效**：原本 `required` 在 `<select>` 上，改成 hidden input 後
  瀏覽器不會驗證，必須在 submit handler 自己擋
- **改了文字沒重選**：在輸入框打字時要立即清掉隱藏的 id，
  否則使用者選了 A 再把文字改成 B，送出去的還是 A
- **站台名含引號**：名稱不要塞進 `data-name` 屬性，`A"B` 會把屬性截斷。
  只放 `data-id`，點擊時用 `findStation()` 從快取清單回查名稱

### 兩組站台搜尋下拉

主機列表與帳務紀錄各有一組（搜尋列用），互動邏輯共用 `bindStationSearch(prefix)`，
傳入 id 前綴即可（`{prefix}-text` / `{prefix}` / `{prefix}-dropdown`）。

原本是寫死三個 id 的 IIFE，加第二組得整段複製 —— 之後只在其中一邊修 bug 就會行為分岔。

> modal 內的站台選擇是**另一套**（`js-vm-station-pick` + 行內展開），
> 與這兩組（`js-vm-station-opt` + 絕對定位）不同，別混用。

帳務的站台條件下在 `vm_server.station_id`，不是 `vmServer.station` 關聯 ——
`vm_server` 表本身就有這欄，不必為了篩選再 join 一層 `station`。

### 站台清單快取

`vmStationList` 存第一次載入的結果，之後開 modal 直接用。
原本每次開都打一次 `/admin/stations/ajax-list?per_page=200`。

## 架構檔案

### Model / Migration
- `app/Models/VmServer.php`、`app/Models/VmBilling.php`
- `database/migrations/2026_08_12_000001_create_vm_server_table.php`
- `database/migrations/2026_08_12_000002_create_vm_billing_table.php`
- 後續 migration：繳款證明、額外費用、關機時間、匯率

### Controller / Service / Command
- `app/Http/Controllers/Admin/VmController.php`
- `app/Services/VmService.php`
- `app/Console/Commands/GenerateVmBilling.php` — `vm:generate-billing`，Kernel 每月 1 號 00:00

### Request
- `app/Http/Requests/Vm/*`

### View
- `resources/views/admin/vm/index.blade.php`

### 路由 / 權限
- `routes/web.php` — prefix `vm`
- `config/permissionMap.php` — `vm.view` / `create` / `update` / `billing_view` / `billing_upload` / `billing_approve`

## 注意事項

- 產生帳單只能選本月或之後的月份，不能補產生過去的月份
- 帳單日可填到 31，編輯時的驗證上限要與新增一致（曾經新增可填 31、編輯只准 28）
- 這頁的 JS 全部寫在 blade 的 `@section('scripts')` 內，與專案其他 admin 頁面一致
