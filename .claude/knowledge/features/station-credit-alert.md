# 站台餘點告警

> 狀態：**已完成、migration 已跑、空跑實測通過**

每天上午 10 點同步所有站台的系統餘點，低於門檻就發 Telegram 告警提醒客戶補點。

## 為什麼要做

站台的系統餘點用完，主系統會**自動停用客戶的後台**。

在這之前 `station.credits` 只能靠客服到站台管理頁一個一個按「同步」，而實際資料顯示
沒人在按 —— 兩個站台裡一個從未同步過，另一個最後同步是一個多月前。等於沒有任何機制
會提前發現客戶點數快用完，只能等對方後台停用了才來問客服。

## 訊息長什麼樣

發給客戶（站台自己的 Telegram 群組）：

```
⚠️<系統餘點告警>
index: GM支付系統餘點告警
credit: 當前系統餘點：16390.94
note: 建議補充系統點數，點數不足將導致系統自動停用後台
```

站台沒設 Telegram 群組時改發到內部支援群組，前面多一段說明 ——
**要讓客服一眼看出這則沒發給客戶、以及為什麼沒發**，少了原因那行，看到的人只會以為系統壞了：

```
🔔 GM支付 的餘點告警沒有發給客戶
原因：這個站台沒有設定 Telegram 群組，麻煩協助手動通知客戶補點

以下是原本要發給客戶的內容：
⚠️<系統餘點告警>
...
```

## 幾乎沒有新零件 —— 主要是把現有的東西接起來

| 已有的 | 位置 | 怎麼用 |
|---|---|---|
| 站台資訊同步（`admin_credit` → `credits`） | `StationService::syncInfo()` | 直接重用，**沒有改動** |
| 主系統 API（失敗回 `null`） | `MainSystemApiService::getStationInfo()` | 間接用 |
| 發訊到站台群組 | `TelegramChatService::sendReply()` | 直接重用 |
| 發通知到站台群組的先例 | `CreditTopupService::sendTopupNotify()` | 照它的形狀寫 |
| 全域設定 + 快取 | `AppSettingService` | 存公版、門檻、冷卻天數 |
| 模板變數替換 | `PaymentConfigService::renderTemplate()` | 加 `{credit}` / `{threshold}` 後共用 |

`station.telegram_group_id` 這個欄位**早就存在也早就有寫入**
（`StationService::resolveTelegramGroupId()`，新增站台填 chat_id 時就會建群組），
但在這個功能之前**沒有任何地方讀它**。

## 設定放哪裡

全域三個值存 `app_setting`，在「繳款設定」頁維護（權限沿用 `payment_config.view` / `.manage`，
**沒有新增權限關鍵字**）：

| key | 預設 | 說明 |
|---|---|---|
| `station_credit.alert_template` | `constants` 的 `TEMPLATE` | 告警公版 |
| `station_credit.threshold` | 30000 | 全域門檻 |
| `station_credit.cooldown_days` | 1 | 幾天內不重複告警 |

per-station 的門檻覆寫在 `station.credit_alert_threshold`，填在站台管理的新增/編輯表單。

### ⚠ 門檻不能放 `station.settings`

`syncInfo()` 是 `'settings' => $info` **整包覆寫**，放進 JSON 會在下一次同步
被主系統 API 的回傳值沖掉。必須是獨立欄位。

### null 與 0 是兩件事

| 值 | 意思 |
|---|---|
| `null`（留空） | 沿用全域門檻 |
| `0` | 這個站台不告警（點數不可能小於 0） |

所以前端留空要送空字串讓 `ConvertEmptyStringsToNull` middleware 轉成 null，
**不能在 JS 裡先轉成 0**。`filled(0)` 是 `true`，`thresholdFor()` 就是靠這點區分兩者。

## 匯率已決定時，告警後面接補點訊息

餘點告警送出時，如果**今天的匯率已經決定**，就在後面接一段補點訊息：

```
⚠️<系統餘點告警>
index: GM支付系統餘點告警
credit: 當前系統餘點：16390.94
note: 建議補充系統點數，點數不足將導致系統自動停用後台

————————
💰 今日補點匯率：32.95
需要補點的話再麻煩告知我們，會立即為您處理 🙏
```

⚠ **匯率還沒決定就只發告警。** 含凌晨到早上報價前、以及報了但還沒人回覆
那段時間 —— 沒有匯率的補點訊息對客戶沒有意義（他不知道要匯多少台幣），
而附一個過期的昨日匯率更糟。

`topupMessage()` 在沒有匯率時回**空字串**而不是 null，直接接在告警後面
不會多出空行。

### 公版跟著系統走，不是全域一份

存在 `payment_config.topup_template`（每筆繳款設定一個欄位），
在繳款設定的新增／編輯視窗填。

**不同系統的收款方式不一樣**，補點訊息要講的匯款資訊自然也不同 ——
全域共用一份講不清楚。同一個系統有多筆繳款設定時取第一筆啟用的，
與繳款通知的取法一致（`VmController::ajaxSendPaymentNotice`）。

可用變數：

| 變數 | 內容 |
|---|---|
| `{rate}` | 今日匯率（去尾零，`30.5` 不是 `30.50`） |
| `{usdt}` | 補 50000 點需要多少 USDT，**無條件進位** |
| `{content}` | 這筆繳款設定的「繳款資訊」欄位 |

**留空就不附加**，站台沒有 `system_id`、或那個系統沒有繳款設定時也不附加。

### `{usdt}` 為什麼是無條件進位

基準點數在 `constants.STATION.CREDIT_ALERT.TOPUP_USDT_BASE`（預設 50000）。

```
50000 / 31.9 = 1567.398…  →  1568
50000 / 32   = 1562.5     →  1563
50000 / 25   = 2000       →  2000（整除就不動）
```

進位的那個零頭是我們這邊收 —— 四捨五入會讓一半的情況少收。

### 會一併附上繳款設定的圖

就是 `payment_config.image`（付款地址、二次確認提醒之類），
跟虛擬機繳費通知用的是同一張、同一個欄位。

**只有真的附了補點訊息時才給圖** —— 單獨一張付款地址圖配著「點數不足」
的告警，客戶會看不懂那張圖在幹嘛。

圖只跟著給客戶的那則走：內部群組是要客服去處理事情，再附一張付款地址圖
沒有幫助。

空跑的預覽會印出 `［附圖］網址`，不用真的發一次才知道會不會附。

### ⚠ 每輪只查一次，不要每站都查

一輪告警會掃過所有站台，但「今天的匯率」只有一個、同系統的站台也共用
同一筆繳款設定 —— 原本每站各查一次，5 個站台就是 **10 次查詢**。

兩個都在 Service 內快取（`$todayRate`、`$paymentConfigs`），5 個站台降到
**2 次**。兩個細節：

- `$todayRate` 的初始值是 **`false`** 不是 `null` —— `null` 是合法結果
  （今天還沒決定匯率），用 null 當哨兵會變成每次都重查
- 繳款設定的快取用 `array_key_exists` 而不是 `isset` —— 查過但沒設定時
  存的是 `null`，`isset` 會判成「沒查過」

Service 每次解析都是新實例（沒註冊成 singleton），所以快取只活在一輪裡，
不會跨請求髒掉。

### 匯率用 `trimZeros` 不是 `formatCredits`

點數固定兩位小數（`16390.94`），匯率去尾零（`30.5`）。
匯率報價訊息也是這樣顯示 —— **同一個數字在兩個地方要長一樣**。
用 `NumberPresenter::trimZeros()`，不要自己再寫一份。

分隔用的兩個換行由程式加（`"\n\n" . $template`）——
公版是客服在表單裡打的，不該要求他們自己在開頭留空行。

今日匯率來自 [[daily-rate]] 的 `DailyRateService::todayRate()` ——
單向依賴，匯率那邊不認識站台告警。

## 有補點單還沒審核時，改催自己人

餘點低於門檻、但這個站台**還有補點單在等審核**的話，告警不發給客戶，
改發到內部支援群組。

客戶已經申請補點了 —— 再發「請補充系統點數」等於在催一件他已經做完的事，
真正卡住的是我們這邊還沒審核。

```
⏳ GM支付 的系統餘點已低於門檻，但還有 2 筆補點單沒有審核
這則沒有發給客戶 —— 他已經申請補點了，再催一次只會造成困擾
麻煩盡快到後台審核，點數要審核通過才會進去

客戶目前的狀況：
⚠️<系統餘點告警>
...
```

### 發送目標的優先序

| 情況 | 發到哪 |
|---|---|
| **有補點單待審核** | 內部群組（催審核）—— 不論站台有沒有自己的群組 |
| 沒有待審核、站台有群組 | 客戶 |
| 沒有待審核、站台沒群組 | 內部群組（請客服手動通知） |
| 內部群組也沒設 | 不發，記 warning |

⚠ 「有待審核」時**不會**退回去發給客戶 —— 那正是要避免的事。
所以內部群組沒設定的話這則就發不出去（只有 log），這是刻意的取捨。

判斷用 `CreditTopupRepository::countPendingByStation()`，
狀態是 `CreditTopup::STATUS_PENDING`（= `0`）。

## 誤報防護

這功能會直接發訊息給客戶，誤報的代價比漏報高：

| 情境 | 處理 |
|---|---|
| API 掛掉 / 逾時 / 回非成功狀態 | `syncInfo()` 回 `null` → **跳過，絕不拿上次的舊點數判斷** |
| **站台不收費** | 餘點不會被扣，發告警只會讓客戶困惑 → 跳過（見下） |
| 站台與內部群組都沒設 | 記 warning 後跳過 |
| 站台停用 / 凍結 | `getForCreditSync()` 只撈 `status=1` |
| **啟用中但沒設 api_url / api_key** | 在 SQL 就被篩掉 → 另外撈出來印訊息 + 記 warning（見下） |
| 連日低於門檻 | 冷卻天數控制 |
| 發送噴錯 | 逐站 try/catch，**不寫 `credit_alerted_at`**，下一輪會重試 |

### 不收費的站台不告警

判斷依據是主系統 API 回的兩個開關 —— 站台詳細資訊頁把費率顯示成「不收費」
用的也是同一組（見 `station/index.blade.php` 的 `depositRateText` / `withdrawRateText`）：

| 欄位 | 意思 |
|---|---|
| `withholding_system` | 代收的手續費由系統代扣 |
| `withdraw_withholding_system` | 代付的手續費由系統代扣 |

**代扣才會消耗系統餘點。** 兩邊都不代扣的站台，餘點根本不會減少，
對它發「點數不足將導致系統自動停用後台」只會讓客戶困惑。

- 只要**有一邊**收費就要告警 —— 餘點是共用的，一邊扣就會見底
- 代付另外看 `withdraw`：代付功能本身沒開啟時，它的代扣開關沒有意義

⚠ 判斷用的是 **API 剛回的 `$info`**，不是 `$station->settings` ——
後者在空跑時沒有被更新，會是上一次同步的舊值。

> ⚠ **布林設定不能用 `filled()` 判斷。**
>
> 專案規則是「空值判斷一律 `filled`/`blank`」，但布林是例外 ——
> `filled(false)` 是 **`true`**（`blank()` 對 bool 一律回 `false`），
> 拿它當開關會把「關閉」讀成「開啟」。這類值一律 `(bool)` cast 後直接判斷。
>
> 同一個坑的另一面：`filled(0)` 也是 `true`、`blank(0)` 是 `false` ——
> 門檻填 `0` 能被當成「這站不告警」就是靠這個行為（見 `thresholdFor()`）。

#### 「明確不收費」與「API 沒給這個欄位」要分開

`isCharged()` 對空陣列會回 `false`，也就是**沒有收費資訊時當作不收費**
（寧可漏報也不要誤發給客戶）。但這帶來一個風險：主系統哪天改了欄位名或
版本不同，**每個站台**都會被判成不收費，整個告警功能就靜默失效了。

所以 `hasChargeInfo()` 用 `Arr::has()` 區分兩者 —— 要分的是「key 存不存在」，
不是「值是不是空」。明確設成 `false` 的站台 key 是存在的，那是正常的不收費；
key 整個不見才記 warning：

```
local.WARNING: 主系統 API 沒有回收費設定，無法判斷是否該告警
{"station_id":2,"station":"LV","keys":["admin_credit","system_rate", ...]}
```

`keys` 一起記下來，方便對照主系統到底回了什麼。

### 「沒設 API」跟「跳過」不是同一件事

`getForCreditSync()` 是在 SQL 的 `WHERE` 就把沒設 API 的站台篩掉的 ——
不是進了迴圈才跳過。差別在能見度：

| | 出現在結果表格 | 有 log |
|---|---|---|
| API 有設但沒回應（像「測試」站） | ✅ | ✅ |
| 沒設 api_url / api_key | ❌ | ❌（原本） |

原本後者是**完全靜默**的。問題是「啟用中卻檢查不到」通常不是刻意的
（api_key 被清空、貼錯），而餘點用完照樣會停用客戶後台 ——
靜默漏掉比漏報更糟。

所以 `getMissingApiForCreditSync()` 單獨把它們撈出來：

```
共檢查 2 個站台，送出 0 則告警
另有 2 個站台未檢查：甲站（缺 API 網址）；乙站（缺 API 網址、API 金鑰）
  這些站台啟用中但同步不到餘點。不打算接主系統的話，把狀態改成停用就不會再列出來。
```

正式跑時一併記 warning（空跑不記）：

```
local.WARNING: 有啟用中的站台因未設定 API 而未檢查餘點
{"count":1,"stations":[{"id":51,"name":"丙站","missing":["API 金鑰"]}]}
```

用 `warning` 而不是 `info`：這是需要有人處理的狀態。真的不打算接主系統的站台，
**把狀態改成停用就不會再被列出來** —— 那是消除這個警告的正確方式，
而不是去改 code 的判斷。

`--station=` 指定單一站台時不查這份清單：那是全量檢查才有意義的總結，
跑單站卻列出別的站台缺設定會看得莫名其妙。

> 「API 失敗就跳過」是這個功能最重要的一行判斷。
> DB 裡的 `credits` 可能是好幾天前的值，拿它來判斷會發出
> 「客戶其實早就補過點」的錯誤告警。
>
> 這不是假想情境 —— 上線第一次空跑就踩到了：「測試」站的 API 沒回資料，
> 而它的 DB 餘點是 `0.00`。少了這道防護，第一次跑就會誤發告警給客戶。

## 驗證不用等到上午十點

```bash
# 印出會告警誰、訊息長什麼樣。完全唯讀
php artisan station:sync-credit --dry-run

# 只跑單一站台
php artisan station:sync-credit --station=2 --dry-run
```

### `--dry-run` 是完全唯讀的

空跑**不走 `syncInfo()`**，而是直接問 `MainSystemApiService::getStationInfo()` ——
`syncInfo()` 會把 `credits` / `settings` / `synced_at` 寫進 DB，那就不算空跑了。
空跑的用途是安心確認「會發給誰、內容長怎樣」，它不該留下任何痕跡。

代價是空跑時 model 上的 `credits` 還是 DB 舊值，所以會把 API 取到的新點數
放回 model（只改記憶體、不 save），否則表格顯示舊點數、判斷卻用新點數，
看起來像 bug。

**所以空跑不會讓站台管理頁的餘點變新。** 要更新頁面上的數字，
跑正常的（不加 `--dry-run`）或按頁面上的「同步」按鈕 —— 兩者走的都是
`syncInfo()`，而站台列表沒有任何快取、每次載入都直接查 DB。

### 實測結果（2026-09-30）

```
| ID | 站台 | 餘點       | 門檻     | 結果                                        |
| 1  | 測試 | 0.00       | 30000.00 | 主系統 API 沒回資料，跳過（不拿舊點數判斷） |
| 2  | LV   | 9558030.24 | 30000.00 | 點數充足                                    |
```

這兩行剛好各驗證了一件事：

- **測試站**：API 沒回資料 → 跳過。它的 DB 餘點是 `0.00`，
  少了這道防護會立刻誤發告警給客戶 —— 這是整個功能最重要的一行判斷
- **LV 站**：API 回的新點數 `9558030.24` 與 DB 舊值 `9617399.36` 不同，
  顯示的是 API 新值 → 證明空跑真的去問了 API，而且判斷用的是新數字

跑完 DB 完全沒變（`credits` 還是 `9617399.36`、`synced_at` 還是 8/27），
確認唯讀成立。

## 檔案

**新增**

| 檔案 | 內容 |
|---|---|
| `database/migrations/2026_09_30_000001_add_credit_alert_to_station_table.php` | `credit_alert_threshold`、`credit_alerted_at` |
| `app/Services/StationCreditAlertService.php` | 同步 → 門檻 → 冷卻 → 發送 |
| `app/Services/SupportGroupService.php` | 內部支援群組發訊（從 `AutoReplySupportService` 抽出來共用） |
| `app/Console/Commands/SyncStationCreditCommand.php` | `station:sync-credit`，`--dry-run` / `--station=` |
| `app/Http/Requests/PaymentConfig/UpdateAlertSettingRequest.php` | 公版、門檻、冷卻天數驗證 |

**修改**

| 檔案 | 改什麼 |
|---|---|
| `app/Services/AutoReplySupportService.php` | 發訊實作委派給 `SupportGroupService`；移除變成孤兒的 `StationRepository` 依賴 |
| `app/Services/AppSettingService.php` | 3 個 `KEY_CREDIT_ALERT_*` 常數 + `getFloat()` |
| `app/Services/PaymentConfigService.php` | `renderTemplate()` 加 `{credit}` / `{threshold}`，`??` 改 `Arr::get` |
| `app/Services/StationService.php` | create/update 支援 `credit_alert_threshold`；create 的 `??` 改 `Arr::get` |
| `app/Repositories/StationRepository.php` | `CREDIT_SYNC_COLUMNS`、`getForCreditSync()`、`getMissingApiForCreditSync()`；`LIST_COLUMNS` 加兩欄 |
| `app/Models/Station.php` | casts、PHPDoc |
| `app/Http/Resources/StationResource.php` | 兩個新欄位 |
| `app/Http/Controllers/Admin/PaymentConfigController.php` | 告警設定讀寫；`ajaxRenderTemplate` 支援 credit/threshold |
| `app/Http/Controllers/Admin/StationController.php` | 門檻欄位驗證 |
| `app/Console/Kernel.php` | 排程 `dailyAt('10:00')` |
| `routes/web.php` | `ajax-alert-setting` |
| `resources/views/admin/payment-config/index.blade.php` | 告警設定區塊（可摺疊、含預覽） |
| `resources/views/admin/station/index.blade.php` | 門檻欄位（兩處編輯按鈕的 data attribute 都要加） |
| `public/css/custom.css` | `.pc-content-box` / `.pc-template-box` 的淺色樣式收進 CSS |
| `config/constants.php` | `STATION.CREDIT_ALERT` |
| `resources/lang/{tw,cn,en}/{payment_config,station}.php` | 文案 |

## 設計決策

**公版走 `app_setting` 而不是語系檔。** 理由跟 `constants.php` 的 `TOPUP_NOTIFY_FOOTER`
那句註解一樣 —— 語系會跟著客服後台的語言跑，客戶收到的內容不該因此變動。

**預覽走後端的 `ajax-render-template`，不在前端自己做字串替換。**
變數代換只有一份實作，客服在設定頁看到的就是客戶會收到的。

**`SupportGroupService` 是抽出來的，不是新寫的。** 內部群組發訊原本長在
`AutoReplySupportService` 的 private 方法裡。餘點告警也要走同一條路，
在告警服務裡再抄一份就是 [[2026-09-30-on-duty-reply-time]] 那種漂移的溫床。
`AutoReplySupportService` 裡留下的兩支薄方法**沒有邏輯**，只是讓 21 個呼叫點
讀起來是「送到支援群組」而不是「送到某個 chat_id」。

**點數不加千分位。** `number_format($v, 2, '.', '')` —— 主系統自己的告警就是
「當前系統餘點：16390.94」這個格式，客戶對得起來比較好認。

**`<b>` 而不是 Markdown 星號。** `TelegramBotService` 是 `parse_mode=HTML`，
`escapeHtml()` 只還原 `b/i/u/s/code/pre/a`，星號會原樣顯示。
順帶一提公版裡的 `<系統餘點告警>` 會被 escape 成 `&lt;...&gt;`，
客戶端顯示回 `<系統餘點告警>`，是正確的。

## 上線前要確認的資料問題

⚠️ 目前兩個站台的 `telegram_group_id` **都是 `1`**（同一個群組），
而「測試」站的 `credits` 是 `0` 且從未同步成功過。

如果測試站的 API 能正常回傳 0，功能一啟用就會立刻發一則告警到那個群組。
**先用 `--dry-run` 確認**，必要時把測試站的 `status` 改掉，或給它 `credit_alert_threshold = 0`。

## 待釐清

- 門檻目前是單一數字。如果之後想要「低於 30000 提醒、低於 10000 每天催」這種分級，
  要改成多段門檻，現在的 `credit_alert_threshold` 單一欄位就不夠了。
- 沒有「告警歷史」列表。`credit_alerted_at` 只留最後一次，
  要查「這個站台這個月被告警幾次」目前只能翻 log。

## 相關

- [[station-topup]] — 客戶補點的流程（告警的下一步）
- [[finance]] — 補點收入
- [[telegram-chat]] — 發訊與 Bot 切換
- [[auto-reply]] — 內部支援群組的另一個使用者
