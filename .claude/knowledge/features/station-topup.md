# 站台補點／扣點

> 站台管理 → 補點紀錄。客服申請補點/扣點 → 有權限者審核 → 呼叫主站 API 加扣點 → 結果送 Telegram 群組通知。

## 相關檔案

| 層 | 檔案 |
|----|------|
| Controller | `app/Http/Controllers/Admin/StationController.php`（`ajaxTopupStore` / `ajaxTopupApprove` / `ajaxTopupReject`） |
| Service | `app/Services/CreditTopupService.php` |
| Repository | `app/Repositories/CreditTopupRepository.php` |
| Model | `app/Models/CreditTopup.php` |
| Resource | `app/Http/Resources/CreditTopupResource.php` |
| View | `resources/views/admin/station/index.blade.php` |
| 語系 | `resources/lang/{tw,cn,en}/station.php` |

## 兩種輸入方式（input_type）

`credit_topup.input_type`：

| 值 | 常數 | 說明 |
|----|------|------|
| 1 | `CreditTopup::TYPE_USDT` | 輸入 USDT 數量 × 匯率，換算出點數（預設，既有資料全部是這種） |
| 2 | `CreditTopup::TYPE_CREDIT` | 客人直接補點數，沒有 USDT 與匯率 |

`input_type = 2` 時 `usdt_amount` 與 `exchange_rate` **存 0**（欄位維持 NOT NULL，未改型別）。

### 台幣與點數是 1:1（需求方確認，不會變動）

直接輸入模式的欄位在 UI 上叫「台幣」，但值直接存進 `credit_amount`，
不需要另外的台幣欄位。財務頁的「本月損益」本來就是拿補點點數當台幣收入算
（`resources/views/admin/finance/index.blade.php` 註解：「簡化：用補點換算的點數當 TWD 收入」），
兩邊一致。

### ⚠️ 均匯率計算的陷阱

存 0 而不是 null，代表 **`AVG()` 不會自動略過**：

- `SUM(usdt_amount)` 加 0 → 沒影響 ✅
- `AVG(exchange_rate)` 會把 0 算進**分母**把平均拉低 ❌

所以**每一處均匯率計算都必須用 `input_type` 明確排除**。目前共兩處，新增第三處時務必比照辦理：

1. `FinanceRepository::calcTopupStats()` — 財務管理的補點平均匯率
   ```php
   AVG(CASE WHEN input_type = ? THEN exchange_rate END) as avg_rate  // 帶入 TYPE_USDT
   ```
2. `resources/views/admin/station/index.blade.php` 的統計卡 — 站台頁的「均匯率」
   用獨立的 `rateCount` 當分母（不能用總筆數 `count`），`input_type === 2` 的紀錄
   分子分母都不累加。

### 連帶修掉的財務摘要卡既有 bug

加入直接輸入點數後才浮出來的兩個舊問題（`resources/views/admin/finance/index.blade.php`）：

1. 摘要卡的「N 點」原本用 `totalRevenue * avgRate` **反推**。全部都是 USDT 補點時
   剛好等於實際點數，但直接輸入的紀錄 USDT 與匯率都是 0，反推就變 0 —
   摘要卡顯示 0 點、明細顯示 5000 點，兩邊對不起來。
   改成直接用 `t.credit + v.twd`（展開後與原公式同一個量，只是不繞過會歸零的匯率）。
2. 「N 筆收入」原本只算 `v.count`（VM 收款），補點筆數從沒被算進去。
   `calcTopupStats()` 補 `COUNT(*)`，前端改為兩者相加。

**尚未處理的既有落差**：本月損益是 `t.credit − 支出`，沒把 VM 收入 `v.twd` 算進去。
目前 VM 收入為 0 所以看不出來，之後有 VM 收款時損益會少算。動它會直接改到帳面
數字，需求方尚未決定。

### 刻意保留的行為

直接輸入點數的紀錄 **仍計入「總點數」加總**（`SUM(credit_amount)`），因為那是實際發出去的點。
只有 USDT 與匯率相關的統計把它排除。

## 審核流程不受 input_type 影響

`CreditTopupService::approve()` 送主站 API 時只用 `credit_amount`，兩種輸入方式走同一條路。
Telegram 通知也是直接轉發主站回傳的 `msg`。

### 重試按鈕已移除（2026-09-09）

`status = 3`（API 失敗）原本會顯示「重試」按鈕再送一次 `approve()`，
**需求方要求移除**，桌機表格與手機卡片兩處都已拿掉。

刻意**只移除前端顯示**，後端保留原樣：

- `approve()` 仍同時接受 `STATUS_PENDING` 與 `STATUS_FAILED`
- `STATUS_FAILED` 常數、狀態徽章、篩選都還在用

所以 API 失敗的紀錄仍會正常標示狀態，只是不能再從介面重送。
若日後要恢復，前端加回按鈕即可，後端不必動。

> 連帶修正：桌機版顯示 `-` 的條件原本是
> `!t.note && status !== 0 && status !== 3`，那個 `status !== 3` 是為了把
> 版位讓給重試按鈕。移除後條件簡化為 `!t.note && status !== 0`，
> 否則 API 失敗又沒備註的紀錄操作欄會變成空白。

## 前端注意事項

- 切換輸入方式時，**`required` 必須跟著隱藏一起移除**（`switchTopupInputType()`），
  否則瀏覽器會卡在看不見的必填欄位上，表單無法送出且沒有錯誤訊息。
- Modal 開啟時 `form.reset()` **不會觸發 change 事件**，要手動呼叫 `switchTopupInputType()`
  才會切回 USDT 模式。
- 按鈕樣式用 `btn-outline-secondary`（中性），不要用 `btn-outline-primary` — 專案沒有藍色按鈕語言。
  dark mode 的選中態必須在頁面 `<style>` 補
  `[data-theme="dark"] .btn-check:checked + .btn-outline-secondary { ... !important }`，
  因為 `public/css/custom.css:658` 用 `!important` 把 `background: transparent` 鎖死了，
  Bootstrap 原生的選中底色權重不夠會被壓掉。詳見 `features/feedback-ui-style.md`。

## 日期篩選的快捷鈕

篩選區的「開始日期／結束日期」下面有六顆快捷鈕（今日、昨日、本週、上週、
本月、上月），按下去直接帶入起訖並重新查詢，省掉再按一次搜尋。

### 日期計算在 `common.js`，不在這一頁

`window.DateRange`（`public/js/common.js`）提供這六種範圍，每支回
`{from, to}` 兩個 `YYYY-MM-DD` 字串。放共用檔的理由：日期範圍篩選到處都會
用到（財務、出勤、登入紀錄…），各頁各寫一份「本週怎麼算」遲早會不一致。

**其他頁面要加同樣的快捷鈕，只需要放按鈕 + 綁 click，不要再寫一次日期計算。**
語系也已經備好共用的 `common.date_range.*`（tw / cn / en）。

規則：

- 「本週」是**週一到週日**的完整一週，不是「週一到今天」
- 「本月」是**1 號到月底**的完整一月 —— 這跟補點紀錄原本的預設值一致，
  `initTopupDateDefaults()` 已經改成呼叫 `DateRange.thisMonth()`，
  不再自己算一份
- 以週一為一週之始，所以 `getDay()` 回 0（週日）時要往前推 **6** 天而不是 1 天

### ⚠ 不要用 `toISOString()` 格式化日期

`DateRange` 內部是用本地的 `getFullYear()` / `getMonth()` / `getDate()`
自己組字串。`toISOString()` 會先轉成 UTC，**台北時間當天上午 8 點以前
會變成前一天的日期** —— 早上九點按「今日」會查到昨天。

同一類的坑見 [[2026-09-30-utc-timestamp-display]]。

### 選中狀態

按下的那顆會加 `active`；**手動改動任一個日期欄位就把 `active` 拿掉**
（change 事件），否則畫面會標著「本月」但日期已經被改成別的範圍。
「重置」也會清掉標示 —— 它把日期還原成預設的本月，但那不是使用者按的。

⚠ 深色模式的 `.active` 底色要自己補 —— `custom.css` 用
`background: transparent !important` 把 `btn-outline-secondary` 鎖死，
只加 class 在深色模式看不出哪顆被選中（淺色模式本來就有）。
已補在 `custom.css`，詳見 [[feedback-ui-style]]。

## 相關 migration

- `2026_08_17_000001_create_credit_topup_table.php` — 建表
- `2026_08_17_000002_add_images_to_credit_topup_table.php` — 上傳憑證圖片
- `2026_09_07_000007_add_input_type_to_credit_topup_table.php` — 直接輸入點數
