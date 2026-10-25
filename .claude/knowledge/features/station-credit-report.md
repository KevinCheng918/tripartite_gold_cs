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
目前沒有這種地方（補點走主系統 API 再同步回來），但日後要留意。

## 資料表 `station_credit_log`

| 欄位 | 說明 |
|---|---|
| `station_id` | 哪個站台 |
| `credits` | 同步當下的餘點，**可為 null** |
| `source` | 怎麼觸發的 |
| `created_at` | 什麼時候 |

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

## 驗證

**報表組裝五種情境**（repository mock，不碰 DB）：正常（含失敗那筆不進曲線、
單獨列出）／這段期間完全沒紀錄（`diff` 回 null 不是 0）／只有失敗沒有成功／
指定期間含當天整日／預設七天。

**寫入端五種情境**（API 與 repository 都 mock）：成功且點數有變／成功但點數
沒變（照樣記）／同步失敗（`credits` 為 null）／API 沒回 `admin_credit`
（退回站台現有值）／寫紀錄失敗（同步本身不受影響、只記 warning）。

⚠ **沒有真的跑過一次同步**（那會寫進正式資料），也**沒有在瀏覽器看過圖**。
上線後第一次排程跑完就會有資料。

## 待釐清

- 目前是**單一站台一頁**。需求方說「他的餘點」，所以先做單站；
  要多站疊在同一張圖比較的話是另一個題目。
- 沒有清理排程。量級估下來十年都還好，但如果站台數成長很多要回來看。
