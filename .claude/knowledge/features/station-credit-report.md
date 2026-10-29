# 站台點數報表（餘點曲線圖）

> 需求（2026-10-26）：「我每天早上 10 點都會去同步站台餘點，我想要多一個按鈕
> 是做點數報表，紀錄他的餘點的曲線圖，如果有去同步，就寫一筆資料並顯示，
> 無論是自動去同步，或是手動去按同步，都要做紀錄，然後繪製曲線圖報表，
> 顯示什麼日期什麼時間點數剩多少這樣」
>
> 補充：「點數報表進入後預設是看一週」
>
> 兩個決定（問過需求方）：**同步失敗也記一筆**、**點數沒變也照記**。

## ⚠ 只要掛在一個地方就全部涵蓋

「無論自動或手動」聽起來要改很多處，但**所有同步路徑都走同一支**
`StationService::syncInfo()`：

| 誰在同步 | 入口 | `source` |
|---|---|---|
| 每日排程 | `station:sync-credit` | `schedule` |
| 站台列表的同步按鈕 | `StationController::ajaxSyncCredits()` | `manual` |
| 小幫手「查餘點」指令 | `StationCreditCommandService` | `command` |
| 手動發餘點告警 | `StationCreditAlertService::sendManual()` | `alert` |
| 虛擬機費用試算 | `VmAffordabilityService` | `afford` |

所以**只在 `syncInfo()` 寫一筆**就全部涵蓋，日後新增同步路徑也不必記得補。

⚠ **排程與手動告警要分得開**：兩者都走 `StationCreditAlertService::run()`，
所以 `run()` 多收一個 `source`，排程那支傳 `schedule`，沒傳就是 `alert`。
不分的話每天的例行同步會顯示成「餘點告警」。

⚠ **空跑（`--dry-run`）不會留紀錄**：它刻意不走 `syncInfo()`
（只問 API 不寫 DB），所以也不會污染報表 —— 這是既有設計，剛好正確。

⚠ 反過來說，**繞過 `syncInfo()` 直接改 `credits` 的地方不會被記錄**。
補點是**唯一的例外**，而且是刻意的 —— 見下面「補點那幾筆」。

## 補點那幾筆（2026-10-30 追加）

> 需求：「點數報表，如果有補點，曲線圖要顯示原本是多少，補多少，現在是多少。
> 每一天的點，都要顯示跟昨天正負多少。」
> 追加：「商城點數要可以用另外一條線，可以跟原本的分開。」
>
> 兩個決定（問過需求方）：**點不變，每個點比前一個點**（同步是一小時一次，
> 不改成一天一個點）、**審核通過就記一筆餘點紀錄**。

`CreditTopupService::approve()` 成功後呼叫
`StationService::recordTopupCredit()`，`source` 是 `topup`。

⚠ **刻意不走 `syncInfo()`**：那會再打一次主系統 API，而補點回應裡的
`data.credit` 已經是權威的新餘額了 —— 再查一次只是多一次外部請求，
而且兩次之間客戶又消耗了點數的話，圖上那一跳反而對不起來。

⚠ **異動金額要存下來，不能用「跟前一個點的差」算**：前一次同步到補點之間，
客戶照樣在消耗點數 —— 兩點之差是「補的 ＋ 這段時間消耗的」。
所以多一個 `change_amount` 欄位，`before` 由報表用 `現在 − 異動` 算。

⚠ **商城點數必須是另一條線**：主系統 `AdminCreditService` 回的是
`'credit' => $admin->{$column}` —— **被補的那一種**的餘額。商城補點回的是
商城餘額，混進系統點數那條曲線會變成一個憑空的大跳。所以多一個
`credit_type` 欄位，報表拆成 `points`／`shop_points` 兩條線、兩個 y 軸
（量級可能差很多，共用一個軸會把小的那條壓平）。

⚠ **商城那條線目前只有補點那幾筆**：主系統 `SystemController::siteInfo()`
只回 `admin_credit`，沒有商城餘額 —— 每小時的同步取樣不到。要變成完整曲線，
得請主系統在 `siteInfo()` 多回一個商城餘額欄位；客服端這邊
（`credit_type` ＋ 第二條線）已經備好了。

⚠ **「其他」那一類不會進圖裡**：`approve()` 一開頭就轉去 `approveOther()`，
那一類主系統的點數根本沒動，畫進去會變成假的異動。

⚠ **這次之前的補點不會回頭補**：當時沒有留異動金額，推不出來。

## 資料表 `station_credit_log`

| 欄位 | 說明 |
|---|---|
| `station_id` | 哪個站台 |
| `credits` | 同步當下的餘點，**可為 null** |
| `credit_type` | `credit` 系統點數 / `shop_credit` 商城點數，預設 `credit` |
| `change_amount` | 這一筆的異動金額（**帶正負號**）；只有補點那幾筆有值 |
| `source` | 怎麼觸發的 |
| `created_at` | 什麼時候 |

⚠ `credit_type` 的 default 一定要是 `credit`：既有紀錄全部來自
`siteInfo()` 的 `admin_credit`，不補 default 的話舊資料會變成 null
而從兩條線上同時消失。

⚠ `change_amount` 要**帶正負號**（扣點是負的）。只存絕對值的話，
報表算「原本 ＝ 現在 − 異動」時扣點會算反。

⚠ **`credits` 可以是 null** —— 同步失敗也要留一筆（需求方選的）。
沒有這一筆的話，圖上某天沒有點就分不出是「沒人同步」還是「同步失敗」。
失敗那幾筆在曲線上是斷點，另外列成清單。

⚠ **點數沒變也照記**（需求方選的）：原話是「如果有去同步，就寫一筆資料」。
圖上會有一串平點，但那正好表達「這幾天確實有在同步，只是客戶沒動」。

⚠ **精度對齊 `station.credits`（decimal 20,2）**，而且 Model **不要把
`credits` cast 成 float** —— decimal 轉 float 在大數字上會丟精度，
而這是客戶的點數。保持字串，要運算時由呼叫端決定。

⚠ 索引只開 `(station_id, created_at)`：報表一定是「某站台某區間」，
單獨的 `station_id` 索引不必再加（複合索引的前綴就涵蓋了）。

### 量級

排程一天認領一次，加上手動與指令，一個站台一天個位數筆。
30 個站台一年 10 萬筆以內，**先不做清理排程**。

## ⚠ 寫紀錄失敗不能讓同步失敗

`logCredit()` 整段包 try/catch。點數本身已經更新好了，為了一筆報表用的紀錄
把整個同步往上拋，會讓排程、告警、指令全部跟著掛掉。

## 報表頁

站台列表每一列加一顆「點數報表」→ Chart.js 折線圖。

⚠ **用既有的 Chart.js 3.9.1**（儀表板的 USDT 匯率圖已經在用），不另外引一套。

⚠ **曲線用 `stepped: true` 而不是直線**：我們只在同步的那一刻取樣，
兩點之間實際發生什麼**並不知道**。直線會讓人以為中間是線性消耗，
階梯線表達的才是「上次同步到這次同步之間，我們只知道這兩個值」。
畫面下方也寫了這句說明 —— 不講的話，看的人會拿斜率去推估消耗速度。

⚠ **預設看一週**（需求方 2026-10-26）。每天同步一次的話一週約七個點，
看得出走勢又不會擠成一團。後端算好的期間會**回填到輸入框**，
不然兩個欄位是空的，看的人不知道圖上是哪幾天。

⚠ 期間的 `end` 要**含當天整日**（`endOfDay`）—— 用 `Y-m-d 00:00` 當上界的話，
今天早上同步的那一筆會被排除，而那正是最常要看的一筆。

⚠ 日期快捷鈕走共用的 `window.DateRange`（`common.js`），跟補點紀錄、報表頁
同一份定義 —— 不要自己算「上週」是哪幾天。

⚠ 滑過去的 tooltip 要顯示**來源** —— 「這個凹下去是真的掉點，還是剛好有人
手動同步了一次」分得出來才有意義。

### ⚠ 摘要小卡踩到的兩個 Bootstrap 5 grid 坑

第一版寫成 `<div class="col-md col-6"><div class="border rounded p-2 h-100">`，
畫面整個跑版 —— 四張卡變成兩兩一行、而且每張撐到將近 1000px 高。
兩個獨立的 bug：

**1. 裸的 `col-md` 打不過 `col-6`。** Bootstrap 5 的 grid 產生順序是
「所有斷點的 `.col-{bp}` ＋ `.row-cols-*`」先出，**然後才是**「所有斷點的
`.col-{bp}-N`」。所以在 `base.css` 裡 `.col-md`（第 606 行，`@media md` 內）
排在 `.col-6`（第 729 行，無 media query）**之前**，兩者 specificity 相同
→ **`.col-6` 永遠贏**，桌機也是一排兩張。
要「桌機四等分、手機兩等分」得寫 `col-md-3 col-6`（`.col-md-3` 在第 949 行，
壓得過 `.col-6`）。

**2. `h-100` 會把格子撐爆。** `height:100%` 的父層是 `.col-*`，而 `.col-*` 是
flex item、高度來自 flex line 的 stretch —— 兩邊互相要高度，Chrome 實測
算出 958px。等高要用 `d-flex`（外層）＋ `flex-fill`（內層），
不要用百分比高度。

⚠ 這件事**站台列表早就踩過**，`admin/station/index.blade.php` 第 1068 行就留著
「不加 h-100：讓每格依內容自適應高度」的註解 —— 寫新頁面前先看一下隔壁。

⚠ 驗證方式：把 `public/` 用 symlink 掛進 scratchpad，`php -S` 起一個靜態站，
用真的 `base.css` ＋ 真的 `station-credit-report.js` ＋ 假 `fetch` 重現，
再用瀏覽器量 `offsetHeight`／`getComputedStyle`。純看 code 看不出來，
這兩個都是「CSS 檔案裡的先後順序」與「flex 高度循環」造成的。

### 摘要卡固定四張

期間／期初／期末／變化。**沒有「同步失敗次數」那一張** —— 下面那張黃色的
「同步失敗的紀錄」已經把每一筆都列出來了，上面再放一個數字只是重複，
還會讓一排變成五張（`col-md-3` 的第五張會自己掉到第二行）。

⚠ 但「這段期間一筆成功的都沒有」要跟「根本沒人同步」講不同的話
（`empty_only_failed` vs `empty`）—— 不然畫面會同時出現「沒有同步紀錄」
和下方一整排失敗明細，自相矛盾。

## 權限：`station.credit_report`

⚠ **不沿用 `station.view`**：看得到站台列表（名稱、狀態）不代表該看得到
每天的餘點走勢 —— 那看得出客戶什麼時候補點、消耗多快，是營運資訊。
權限照**使用者在做什麼**切，是這個專案一貫的做法（見
[[station-credit-command]]）。

所以列表那顆按鈕**不包在 `station.update` 區塊裡**，各自判斷。

## 新增/修改的檔案

| 檔案 | 說明 |
|---|---|
| `database/migrations/2026_10_26_000001_create_station_credit_log_table.php` | **新增** |
| `app/Models/StationCreditLog.php` | **新增**。`SOURCE_*` 五種來源 |
| `app/Repositories/StationCreditLogRepository.php` | **新增** |
| `app/Services/StationCreditReportService.php` | **新增**。組報表資料 |
| `app/Services/StationService.php` | `syncInfo()` 多收 `$source` 並寫紀錄 |
| `app/Services/StationCreditAlertService.php` | `run()`／`handleStation()` 傳遞 `source` |
| `app/Console/Commands/SyncStationCreditCommand.php` | 排程傳 `schedule` |
| `app/Services/StationCreditCommandService.php`／`VmAffordabilityService.php`／`StationController.php` | 各自傳自己的來源 |
| `routes/web.php`／`config/permissionMap.php` | 兩支路由 ＋ 新權限 |
| `resources/views/admin/station/credit-report.blade.php` | **新增**。⚠ layout 是 `@yield('scripts')` 不是 `@stack` |
| `resources/views/admin/station/partials/row-actions.blade.php` | 加按鈕（桌機與手機共用這一份） |
| `public/js/station-credit-report.js` | **新增** |
| `resources/lang/{tw,cn,en}/station.php`／`permission.php` | 三語系 |

### 2026-10-30 追加（補點的點、每點差值、商城第二條線）

| 檔案 | 說明 |
|---|---|
| `database/migrations/2026_10_30_000002_add_topup_columns_to_station_credit_log_table.php` | **新增**。`change_amount` ＋ `credit_type` |
| `app/Models/StationCreditLog.php` | 加 `SOURCE_TOPUP`、`CREDIT_TYPE_CREDIT`／`CREDIT_TYPE_SHOP` |
| `app/Repositories/StationCreditLogRepository.php` | 報表欄位多兩欄；`latestByStation()` 只看系統點數 |
| `app/Services/StationService.php` | 新增 `recordTopupCredit()`；`logCredit()` 多收 `$changeAmount`／`$creditType` |
| `app/Services/CreditTopupService.php` | 注入 `StationService`，審核通過後 `recordCreditLog()` |
| `app/Services/StationCreditReportService.php` | 拆兩條線、`withDiff()` 算每點差值、補點那幾筆多帶 `before`／`change` |
| `public/js/station-credit-report.js` | 共用時間軸 ＋ 兩條線 ＋ 右側第二個 y 軸 ＋ tooltip 顯示原本／異動／現在 |
| `resources/lang/{tw,cn,en}/station.php` | 新增 `chart_label_shop`、`tooltip_*`、`credit_source.topup` |
| `config/changelog.php` | v3.45 |

## 驗證

**報表組裝五種情境**（repository mock，不碰 DB）：正常（含失敗那筆不進曲線、
單獨列出）／這段期間完全沒紀錄（`diff` 回 null 不是 0）／只有失敗沒有成功／
指定期間含當天整日／預設七天。

**寫入端五種情境**（API 與 repository 都 mock）：成功且點數有變／成功但點數
沒變（照樣記）／同步失敗（`credits` 為 null）／API 沒回 `admin_credit`
（退回站台現有值）／寫紀錄失敗（同步本身不受影響、只記 warning）。

⚠ **沒有真的跑過一次同步**（那會寫進正式資料），也**沒有在瀏覽器看過圖**。
上線後第一次排程跑完就會有資料。

### ⚠ JS 一定要掛 `?v=filemtime`（2026-10-30 修）

需求方回報「點數報表還是沒有顯示跟上一點的差值」，但程式是對的 ——
`credit-report.blade.php` 是**全站唯一漏掉 `?v={{ filemtime(...) }}`** 的
script 標籤，瀏覽器拿的還是舊版 `station-credit-report.js`。

⚠ 這種壞法特別難判斷：**畫面會有一半是新的**。語系是後端渲染進
`data-i18n` 的（所以圖例已經變成「系統餘點」），JS 卻是舊的
（所以 tooltip 用 Chart.js 預設格式 `系統餘點: 15,414.99`、沒有差值，
而舊版就有的「來源：手動同步」照樣顯示）。看起來像「功能只做了一半」。

順手把 `auth/login.blade.php` 漏掉的兩支也補上。
現在 `resources/views/` 底下只剩 TinyMCE 的 base path 沒有版本號 ——
那是函式庫目錄不是會變的檔案，本來就不該加。

### 2026-10-30 追加的驗證

**`withDiff()`**（反射叫私有方法，不碰 DB）：第一個點 `diff` 是 null 不是 0／
正負差值／**餘額為 0 的那個點之後照樣算得出 diff**（`filled(0.0)` 在 Laravel
是 true，數字永遠不 blank）／空陣列與單點不會爆。

**前端的對齊與 tooltip**（把純函式抽出來用 node 跑）：兩條線取聯集時間軸、
各自缺的位置補 null／補點那個點半徑 6 其餘 3／第一個點不顯示「±0」／
補點顯示原本 800 → 異動 +500 → 現在 1,300／商城扣點顯示 −3,000 且走
商城那條線的標籤。

⚠ **沒有真的審過一筆補點**（那會呼叫主系統 API 動到真的點數），所以
「審核通過 → 圖上多一個菱形點」這條路徑是推的，不是跑過的。
本機也**沒有任何商城補點紀錄**，商城那條線沒有真實資料看過。

## 待釐清

- 目前是**單一站台一頁**。需求方說「他的餘點」，所以先做單站；
  要多站疊在同一張圖比較的話是另一個題目。
- 沒有清理排程。量級估下來十年都還好，但如果站台數成長很多要回來看。
- **商城那條線要不要變成完整曲線**：得請主系統在
  `SystemController::siteInfo()` 多回一個商城餘額（`admin_shop_credit`
  之類），客服端 `syncInfo()` 再多記一筆 `shop_credit`。
  現在只有補點那幾個點。
