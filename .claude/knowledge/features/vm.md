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

## 每天 09:30 自動發繳款通知（2026-10-02 上線）

> 程式在 **`VmPaymentNoticeService`**，不在 `VmService`。
> 那邊管主機與帳單的 CRUD，這邊管「什麼時候、要跟誰、用什麼內容收錢」——
> 兩件事會各自長大，混在一起時 `VmService` 有一半篇幅在講通知
> （600 多行，拆完回到 249 行）。形狀比照 `StationCreditAlertService`
> 獨立於 `StationService`。

繳款通知原本要客服到帳務紀錄一筆一筆按「發送通知」。現在排程自動發，
**一直發到客戶繳費為止**。

`vm:send-payment-notice`，Kernel `dailyAt('09:30')->withoutOverlapping()` ——
排在匯率報價（09:00）之後、餘點告警（10:00）之前，三則對客訊息錯開時間。

### 發送對象與條件

| `paid` | 狀態 | 發給誰 | 發什麼 |
|---|---|---|---|
| 0 | 未收 | **客戶**（站台群組） | 繳款通知（繳款設定的文案 + 繳款圖） |
| 2 | 待審核 | **內部群組** | 催我方審核（不附圖） |
| 1 | 已收 | 不發 | — |

未收的額外條件（`VmRepository::getBillingsForNotice()`）：

- `due_date <= 今天 + DAYS_AHEAD`（2 天）—— **沒有下界**，逾期會一直發
- `vm_server.power_status = ON` —— 關機不發
- `vm_server.status = ACTIVE` —— 停用通常代表這台不服務了，還去收錢會出事

**待審核的只看 `paid = 2`，沒有其他條件**
（`getPendingBillingsForReminder()`）—— 客戶已經付錢了，證明擺著沒人審核
本身就是問題，跟應收日剩幾天、主機關不關機都無關。

### 完全不碰匯率

虛擬機收的是 **USDT**，而 `vm_billing.amount` 就是客戶要付的數字，直接用。

`vm_billing.exchange_rate` 不要碰：那是**客戶上傳繳款證明當下**記的
USDT/TWD 4H 均價（`VmService::uploadProof()`），用途是事後對帳，
跟「要通知他付多少」無關。

通知文案也沒有匯率變數 —— 繳款設定的公版只有 `{station}` / `{amount}` /
`{month}` / `{due_date}` / `{content}`。這跟餘點告警的補點訊息不一樣，
那邊才需要匯率。

### 文案組裝與手動發送共用

`VmPaymentNoticeService::renderPaymentNotice($systemId, $vars)` → `['text', 'image_url']`。

這段原本**寫在 `VmController::ajaxSendPaymentNotice()` 裡**，排程要用同一套，
所以抽到 Service —— 兩邊各寫一份的話，改了文案規則只會改到一邊。
Controller 改成呼叫它之後 `PaymentConfigService` 在那裡已經沒有使用點，
一併從建構子移除。

**文案是每個系統各自一份**：`payment_config` 依 `station.system_id` 取第一筆
啟用的，公版留空就退回「繳款資訊」本身（沿用手動發送原本的行為）。

### 四種退路

| 情況 | 發到哪 | `reason` |
|---|---|---|
| 站台有群組 | 客戶 | — |
| 站台沒設群組 | 內部群組（前綴說明為什麼沒發給客戶 + 原本要發的內容 + **該系統的繳款圖**） | — |
| 主機沒綁站台 | 內部群組（說明查不到繳款設定） | `no_station` |
| 這個系統沒有啟用中的繳款設定 | 內部群組（說明去補設定） | `no_config` |
| 內部群組也沒設定 | 不發，記 warning | `no_target` |

⚠ **「站台沒設群組」要拆兩則、而且要附繳款圖**（2026-10-03 需求方指定）：

| | 內容 |
|---|---|
| 第 1 則 | 說明「這則沒有發給客戶」與原因 |
| 第 2 則 | 繳款文案 ＋ **該系統的繳款圖** |

客服要把**第 2 則原封不動轉傳**給客戶 —— 併成一則的話他得先手動編輯掉
開頭那段說明。附圖同理：少了圖他還得自己回繳款設定翻一次，而且可能翻錯
系統的那張。圖就是 `$notice['image_url']`，本來就是依系統查出來的那一張。

說明走 `sendInternalOnly()` 的 `$prefix` 參數（獨立發一則）；
空跑預覽時會把兩則接起來顯示，不然看不到完整內容。

⚠ 第 2 則的 caption 有 **1024 字上限，超過整則失敗** ——
`sendInternalBody()` 超過就退回「先發圖、再發文字」。
詳見 [[station-credit-alert]] 的「圖說上限」那段（全站三處同一個做法）。

另外三種情況不附圖，而且理由各不相同：`no_station` / `no_config` 是**查不到
繳款設定所以根本沒有圖**；催審核（`pending`）則是**刻意不給** —— 客戶已經
付款上傳證明了，要催的是我方審核，附付款地址圖沒有幫助。

做法與補點訊息的 `StationCreditAlertService` 一致，見
[[station-credit-alert]] 的「內部群組是例外」。

每一筆各自 try/catch（`SKIP_ERROR`）—— 一筆噴錯不能讓整輪中斷，否則一個
不相關的小問題就讓所有客戶都收不到通知（見
[[2026-10-02-rate-failure-blocks-credit-alert]]）。

### ⚠ 刻意沒有防重複

一天跑一次（排程 + `withoutOverlapping()`）。**手動再跑一次就是再發一次** ——
需求方確認過的行為，所以沒有 `notified_at` 之類的欄位。

### ⚠ 一個客戶同一天可能收到多則

每筆未繳帳單各發一則。客戶有兩個月沒繳就會收到兩則（本機實測就是這樣：
2026-08 與 2026-10 各一則）。目前**不合併** —— 如果要合併成一則，
那是另一次需求。

### 踩到的坑：select 沒帶的欄位讀出來是 null

催審核訊息要顯示「上傳時間」，第一版直接用 `$billing->updated_at` ——
結果印出「上傳時間：—」。因為 `VmRepository::BILLING_COLUMNS` **沒有
`updated_at`**，沒 select 的欄位讀出來是 null **而且不會報錯**。

`getPendingBillingsForReminder()` 改成
`array_merge(self::BILLING_COLUMNS, ['updated_at'])`。

> 這跟 [[ignore-staff]] 的 `find()` 缺 `telegram_user_id` 是同一類錯誤。
> **用一個欄位之前先確認它在 select 清單裡** —— 這種錯不會噴錯，
> 只會讓訊息默默少一塊。

### 實測（2026-10-02）

空跑（完全唯讀）跑出兩筆真實帳單，文案與圖都正確套用。
其餘分支用 transaction + rollback 造資料，**19 項全過**：

| 情境 | 結果 |
|---|---|
| 待審核 → 催內部審核、不附圖、內容是催審核 | ✅ |
| 待審核 + 關機 + 停用 → **仍然照催** | ✅ |
| 未收 + 關機 → 根本不被撈出來 | ✅ |
| 未收 + 停用 → 也不被撈出來 | ✅ |
| 站台沒設群組 → 內部群組 + 前綴 + 原本要發的內容 | ✅ |
| 主機沒綁站台 → `no_station` + 說明 | ✅ |
| 內部群組也沒設 → `no_target`、不發 | ✅ |
| rollback 後四個欄位全部還原 | ✅ |

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
- `app/Console/Commands/SendVmPaymentNoticeCommand.php` — `vm:send-payment-notice`，Kernel 每天 09:30；`--dry-run` / `--billing=`
- `app/Services/VmPaymentNoticeService.php` — 繳款通知的文案組裝與自動發送

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
