# 繳款證明上傳、補點申請 → 私訊通知

> 需求（2026-10-27）：「我通知這邊要新增一下，如果有人上傳虛擬機的繳款證明，
> 或是補點申請，要做私人訊息通知」
>
> 三題裁示（2026-10-27）：**審核結果也通知**、**新開一份名單**、
> **帳單不記上傳者**（只寫進通知）。

## 通知的時機

| 事件 | 進入點 | 動作者的權限 |
|---|---|---|
| 上傳虛擬機繳款證明 | `VmController::ajaxUploadProof()` | `vm.billing_upload` |
| 繳款證明審核確認收款 | `VmController::ajaxApprovePaid()` | `vm.billing_review` |
| 申請補點／扣點 | `StationController::ajaxTopupStore()` | `station.topup_apply` |
| 補點審核通過／拒絕／API 失敗 | `StationController::ajaxTopupApprove()` / `ajaxTopupReject()` | `station.topup_approve` |

⚠ 需求方這次只說了「上傳」與「申請」，審核結果是問出來的 ——
[[approval-notice]] 那次就是先做申請、**隔一句話又補了審核結果**。

⚠ **「直接標記已收款」（`ajaxMarkPaid`）不通知**：那是沒有繳款證明、
管理者自己記一筆，沒有「誰送件在等你審」這件事。

⚠ 繳款證明**沒有「拒絕」這個動作** —— 介面上只有「審核通過」與
「直接標記已收款」，所以不像補點那樣分兩種結果。

## ⚠ 兩件事的共通點：都是「有人送件，等你審」

這正是 [[approval-notice]] 的形狀，所以**沿用同一套做法**：

- 一支 `FinanceNoticeService`，只負責組內容與決定發給誰
- 送出走 `StaffDmService::sendToUserIds()`
- 文案模板放 `config/constants.php`，收件人放 `app_setting`
- `push()` 整段 try/catch，而且呼叫端擺在 `DB::transaction()` **外面**

### ⚠ 通知失敗不能讓送件失敗

補點申請那支會寫 `credit_topup`、上傳那支會寫 `vm_billing` 並存檔。
**通知沒送到頂多主管晚點才看到；送件被例外中斷的話，同仁會以為自己沒送出
而重複送** —— 重複的補點申請要是被誤審，那是真的會動到客戶點數。

## 收件人：另一份名單

`finance_notice.manager_user_ids`，通知設定頁多一個分頁「財務申請」。

⚠ **不沿用「申請審核」那份**（需求方 2026-10-27 選的）：
請假、補打卡是人事，繳款證明、補點是錢 —— 審的人多半不是同一批。
合成一份的話，想收人事申請但不管財務的人就沒辦法只關掉其中一邊。
這也跟專案既有的做法一致（班表、任務、打卡報表、申請審核各一份，
理由見 [[approval-notice]]）。

⚠ 代價是通知設定頁變成**第九個分頁**，頁面確實有點擠 ——
所以那個分頁上寫了一句「這份名單跟申請審核是分開的」，
不然看的人會以為是重複設定。

## ⚠ 繳款證明不知道是誰傳的

`vm_billing` 沒有「誰上傳的」這個欄位，`uploadProof()` 也沒收 `$userId`。

需求方決定**不加欄位**：上傳者從 Controller 的 `Auth::id()` 傳進
`FinanceNoticeService`，**只寫進通知**，帳單資料本身不記。
日後要在帳單列表上看到「誰傳的」才需要加欄位跑 migration。

## 訊息長怎樣

```
💳 繳款證明上傳
小明 上傳了繳款證明
虛擬機：vm-tw-03
站台：順流
月份：2026-10　金額：120 USDT
→ 請到「虛擬機管理 → 帳單」審核
```

```
🪙 補點申請
小明 申請補點
站台：順流
點數：1,000,000
類型：系統點數
USDT：320　匯率：32.25
備註：客戶已轉帳
→ 請到「站台管理 → 補點紀錄」審核
```

### ⚠ 點數要補千分位

刻意**不走 `NumberPresenter::trimZeros()`** —— 那支不加千分位，因為它多半
用在發給**客戶**的文案。這裡是發給主管的內部通知，而主管要看的正是
「有沒有多打一個零」：`1000000` 跟 `10000000` 掃過去幾乎一樣，
`1,000,000` 跟 `10,000,000` 一眼就分得出來。

⚠ 匯率不補千分位、小數多留兩位 —— 它本來就是 30 上下的小數。

### ⚠ 補點與扣點要分得出來

`credit_topup.action_type` 有 1=補點、2=扣點。
**扣點是把客戶的點數拿掉**，主管看到時的緊張程度不一樣，標題就要講清楚。

### ⚠ 「通過了但主系統沒加成功」要獨立一則

`approve()` 有兩種結局：`STATUS_COMPLETED`（API 加扣成功）與
`STATUS_FAILED`（API 失敗）。**後者是「審了但點數沒進去」**——
混成一句「已通過」會讓主管以為事情結束了，但客戶的點數一點都沒動。
那一則用 ⚠️ 開頭，並明寫「客戶的點數還沒有變動，請重試」。

⚠ 呼叫端要傳 `approve()`／`reject()` **回傳的那個物件**，不是原本的
`$topup` —— 狀態是在裡面才更新的，拿原本那個會是「待審核」，
挑不到該用哪一則模板。

### ⚠「其他」這一類要特別標

`credit_type = other` 的申請**不會呼叫主系統 API**（需求方 2026-10-14 決定），
只是記一筆帳。通知裡要寫出來，不然主管會以為按下去客戶點數就會動。

### ⚠ 直接輸入點數時不要印匯率

`input_type = 2`（直接輸入點數）時 `usdt_amount` 與 `exchange_rate` 都是 0 ——
照印會變成「USDT 0 ／匯率 0」，看起來像壞掉的資料。

## 新增/修改的檔案

| 檔案 | 說明 |
|---|---|
| `app/Services/FinanceNoticeService.php` | **新增**。五則通知 ＋ 測試發送 |
| `app/Http/Requests/Notification/UpdateFinanceNoticeRequest.php` | **新增**。⚠ `exists:user,id`（表名沒有 s）|
| `app/Services/AppSettingService.php` | 新增 `KEY_FINANCE_NOTICE_MANAGER` |
| `app/Services/NotificationSettingService.php` | `forPage()` 多一段、`updateFinance()`／`testFinance()` |
| `app/Http/Controllers/Admin/VmController.php` | 上傳成功後、審核通過後各呼叫一次 |
| `app/Http/Controllers/Admin/StationController.php` | 補點申請／通過／拒絕後各呼叫一次 |
| `app/Http/Controllers/Admin/NotificationController.php` | `ajaxUpdateFinance()`／`ajaxTestFinance()` |
| `config/constants.php` | `FINANCE_NOTICE` 文案模板 |
| `routes/web.php` | 兩支路由（`can:notification.manage`）|
| `resources/views/admin/notification/index.blade.php` | 「財務申請」分頁 |
| `public/js/notification-setting.js` | `renderFinance()`／`bindFinance()` |
| `resources/lang/{tw,cn,en}/notification.php` | 三語系 |
| `config/changelog.php` | 版本紀錄 |

⚠ **沒有新的權限 keyword** —— 通知設定頁本來就有 `notification.manage`，
這只是多一個分頁。也**沒有 migration**。

## 驗證

16 種情境（reflection 叫 private method，`AppSettingService`／`StaffDmService`／
`UserRepository` 用假物件，Model 用 `setRawAttributes` ＋ `setRelation` 現做，
不碰 DB 也不打 Telegram）：

- 繳款證明：上傳、審核、**沒綁站台的機器不印站台那行**
- 補點：申請、扣點講成扣點（但「補點紀錄」是選單名稱，該留）、
  直接輸入點數不印「USDT 0 ／匯率 0」、`other` 要標「主系統點數不會動」、
  沒備註不印備註行
- 審核：通過／拒絕／**通過但 API 失敗**／待審核狀態不發送
- 名單：沒設定收件人就不送、測試發送、測試但沒收件人回 `no_manager`

⚠ **沒有真的私訊過** —— 要在通知設定頁勾人之後按「測試發送」才算驗完。

## 待釐清

- 「直接標記已收款」目前不通知。如果主管也想知道誰做了這個動作，
  那是另一個決定（它不是「有人在等你審」，所以先不做）。
- 送件人自己如果也在收件人名單上，會收到自己送件的通知。
  跟 [[approval-notice]] 行為一致，先不特別排除。
