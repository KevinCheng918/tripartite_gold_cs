# 站台餘點告警

> 狀態：**已完成、migration 已跑、空跑實測通過**

每天上午 10 點同步所有站台的系統餘點，低於門檻就發 Telegram 告警提醒客戶補點。

## 為什麼要做

站台的系統餘點用完，主系統會**自動停用客戶的後台**。

在這之前 `station.credits` 只能靠客服到站台管理頁一個一個按「同步」，而實際資料顯示
沒人在按 —— 兩個站台裡一個從未同步過，另一個最後同步是一個多月前。等於沒有任何機制
會提前發現客戶點數快用完，只能等對方後台停用了才來問客服。

## 訊息長什麼樣

發給客戶（站台自己的 Telegram 群組）第一則 —— 餘點告警，**不附圖**：

```
⚠️<系統餘點告警>
index: GM支付系統餘點告警
credit: 當前系統餘點：16390.94
note: 建議補充系統點數，點數不足將導致系統自動停用後台
```

匯率已決定時，緊接著第二則補點訊息（**附繳款設定的圖**），
見「匯率已決定時，告警之後再發一則補點訊息」。

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

## 匯率已決定時，告警之後再發一則補點訊息

給客戶的是**兩則**訊息，先後送出：

```
第 1 則（餘點告警，不附圖）
⚠️<系統餘點告警>
index: GM支付系統餘點告警
credit: 當前系統餘點：16390.94
note: 建議補充系統點數，點數不足將導致系統自動停用後台
```

```
第 2 則（補點訊息，附繳款設定的圖）
————————
💰 10/1 USDT 當前匯率 32.95
補 50000 點約需 1518 USDT

（繳款資訊）

需要補點的話再麻煩告知我們，會立即為您處理 🙏
```

### 為什麼分兩則

這兩段要做的事不一樣：告警是「你的點數快沒了」，補點訊息是
「要補的話這樣匯款」。分開發，客戶回頭要找匯款資訊時不必在一長串
告警裡翻，繳款圖也只掛在真正需要它的那一則上 ——
告警那則配一張付款地址圖，客戶看不懂那張圖在幹嘛。

實作上：`sendTopup()` 負責第二則，`topupMessage()` 回傳的是
**不帶前後分隔的純文字**（它自己就是一則訊息）。

### ⚠ 補點訊息的任何問題都不能影響告警

匯率查不到、繳款設定查不到 —— 最多就是少發第二則，**絕不該讓第一則也發不出去**。

`todayRate()` 與 `paymentConfigFor()` 各自 try/catch（失敗一律當成「沒有」），
`run()` 的迴圈再加一層 per-station try/catch 當最後防線。

這曾經是個真實的 bug：那兩個查詢沒人接、`run()` 也沒接，匯率一失敗就讓
**所有站台**都收不到告警 —— 見 [[2026-10-02-rate-failure-blocks-credit-alert]]。

### 第二則失敗不算整則告警失敗

`sendTopup()` 自己 try/catch，失敗只記 log 並回 `false`，**不往上拋**。

告警那則已經出去了，這時回報失敗會讓下一輪重發一次一模一樣的告警 ——
對客戶是重複打擾。缺的那一段可以用站台列表的「補點通知」補發。

手動發送與測試發送的回傳都帶 `topup_sent`，前端會講明「告警已送出，
但補點訊息那則發送失敗」。

### ⚠ 匯率還沒決定就只發告警那一則

含凌晨到早上報價前、以及報了但還沒人回覆那段時間 —— 沒有匯率的
補點訊息對客戶沒有意義（他不知道要匯多少台幣），而附一個過期的
昨日匯率更糟。

`topupMessage()` 在沒有匯率時回**空字串**，`sendTopup()` 看到空的就
直接回 `true`（本來就沒有要送，不算失敗）。

### 內部群組是例外：併成一則，而且不附圖

發到內部支援群組的那則（站台沒設群組、或有補點單待審核）**不拆**，
前綴 + 告警 + 補點訊息併成一則，用 `topupSuffix()` 補 `\n\n` 分隔。

那則是「這個站台快沒點了，但沒發給客戶」的轉知，客服要的是一眼看完
整件事 —— 拆兩則只是讓同一件事在群組裡響兩次。圖同理不附：客服要做的
是去設群組或去審核補點單，不是照著付款地址匯款。

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

**留空就不發第二則**，站台沒有 `system_id`、或那個系統沒有繳款設定時也不發。

### `{usdt}` 為什麼是無條件進位

基準點數在 `constants.STATION.CREDIT_ALERT.TOPUP_USDT_BASE`（預設 50000）。

```
50000 / 31.9 = 1567.398…  →  1568
50000 / 32   = 1562.5     →  1563
50000 / 25   = 2000       →  2000（整除就不動）
```

進位的那個零頭是我們這邊收 —— 四捨五入會讓一半的情況少收。

### 圖附在補點訊息那則上

就是 `payment_config.image`（付款地址、二次確認提醒之類），
跟虛擬機繳費通知用的是同一張、同一個欄位。

**餘點告警那則不附圖，圖只掛在補點訊息那則。** 告警講的是「點數快沒了」，
配一張付款地址圖客戶看不懂那張圖在幹嘛；真要匯款的資訊在第二則，
圖跟著它才有意義。所以沒有第二則（匯率未定／沒填公版）時就沒有圖。

內部群組那則也不附圖（理由見上面）。

空跑的預覽會分段印出兩則，第二則標上 `附圖 網址` 或 `無圖` ——
不用真的發一次才知道客戶會跳幾次通知、圖掛在哪一則。

## 手動發送補點通知

站台列表每一列有一顆「補點通知」。按下去會**先同步點數**，然後
**不管有沒有低於門檻都發給客戶**。

### 跟自動告警的差別

| | 自動 | 手動 |
|---|---|---|
| 點數高於門檻 | 不發 | **照發** |
| 冷卻期內 | 不發 | **照發** |
| 有補點單待審核 | 改發內部群組 | **照發給客戶** |
| 站台沒設群組 | 退到內部群組 | **擋下來並說明** |

自動流程的那些保護是為了「不要亂吵客戶」；手動是人按的，按的人知道
自己要做什麼，不該替他擋。只有「沒有群組」會擋 —— 那不是判斷問題，
是真的沒地方發。

### 缺設定的站台按鈕直接 disable

缺 **API 網址／API 金鑰／Telegram 群組**任何一個，按鈕就是 disabled，
hover 會說缺哪一欄（文案在 `station.topup_notice_disabled` +
`station.topup_notice_missing.*`）。

這三個缺任何一個流程都走不完：手動發送會先同步點數（要 API），
再發到站台自己的群組（要群組）—— 不該讓人按下去才收到錯誤。

| 零件 | 位置 |
|---|---|
| 判斷 | `Station::$topup_notice_blockers`（accessor，回欄位名不回中文） |
| 按鈕 | `admin/station/partials/topup-notice-button.blade.php` |

抽成 partial 是因為表格檢視與卡片檢視都有這顆按鈕，各寫一份的話
disable 條件改了很容易只改一邊。

⚠ `disabled` 的 button 自己不觸發 hover，`title` 要掛在外層 `<span>` 上。

⚠ 那個 partial 的 PHP 一律用 `@php ... @endphp` 區塊，**不要混用
`@php(...)` 單行** —— Blade 的 `storePhpBlocks` 會把單行 `@php(`
一路吃到後面的 `@endphp`，中間的 `@if` / `@else` 全部不編譯，
整顆按鈕會以原始碼的樣子印在頁面上。

後端的擋法沒有因此拿掉（`sendManual()` 仍會回 `no_group` / `sync_failed`）——
按鈕 disable 只是省掉一次白按。

### 兩個仍然保留的行為

**先同步再發**：同步不到就不發，寧可讓人重按一次，也不要送出一個過期的點數。

**記 `credit_alerted_at`**：不記的話，明天早上的自動告警會再發一次同樣的
東西 —— 對客戶來說那是重複打擾，他分不出一個是人按的、一個是排程。

### 回應會講清楚這次的狀況

```
補點通知已發送給客戶，當前點數 16390.94（點數高於門檻，仍依您的指示發送、今日匯率尚未決定，只發了餘點告警那則）
```

因為「發出去了」跟「發出去的內容完整」是兩件事，按的人需要知道後者。
第二則發送失敗時也會講：「餘點告警已送出，但補點訊息那則發送失敗」。

### 測試發送

繳款設定列表上，**有填補點訊息的那幾筆**會多一顆「測試補點訊息」。

按下去會把**客戶實際會收到的那兩則**（告警／補點訊息＋圖）發到
**內部支援群組** —— 補點訊息是接在告警之後的第二則，單獨看看不出
實際效果，也看不出兩則合起來的順序對不對。客戶收不到，所以不需要
確認視窗。

⚠ **站台資料一律是捏的，站台名固定叫「測試」**
（`testStation()` + `TEST_STATION_NAME` / `TEST_CREDITS`）。

早期版本會優先拿這個系統底下第一個真實站台（想讓訊息「像真的」），
後來拿掉了 —— 測試訊息進的是內部支援群組，真實客人的站台名稱與當下餘點
出現在那裡，看到的人得先分辨那是不是真的在告警。要看某個客人的實際狀況，
站台列表的「補點通知」才是對的地方。

順帶好處：這個系統還沒有任何站台也測得起來，不必再有「取不到就捏一筆」
的分支。`StationRepository::firstBySystem()` 隨之移除（沒有其他使用者）。

回應會講清楚這次少了什麼（`PaymentConfigController::testTopupMessageKey()`）：

| 情況 | 訊息 |
|---|---|
| 都齊 | 兩則已發送（餘點告警、補點訊息＋圖片） |
| 繳款設定沒圖 | 兩則已發送，補點訊息那則沒有附圖 |
| 今天匯率未定 | 已發送，只有餘點告警那一則 |
| 第二則掛了 | 告警那則已發送，但補點訊息那則發送失敗 |

「匯率未定」那種在第一則訊息本身也會補一句說明
（`TEST_NO_RATE_NOTE`），免得看的人以為補點訊息壞了。

### ⚠ 每輪只查一次，不要每站都查

一輪告警會掃過所有站台，但「今天的匯率」只有一個、同系統的站台也共用
同一筆繳款設定 —— 原本每站各查一次，5 個站台就是 **10 次查詢**。

兩個都在 Service 內快取（`$todayRate`、`$paymentConfigs`），5 個站台降到
**2 次**。兩個細節：

- `$todayRate` 的初始值是 **`false`** 不是 `null` —— `null` 是合法結果
  （今天還沒決定匯率），用 null 當哨兵會變成每次都重查
- 查詢**失敗**時也把它設成 `null`（而不是留著 `false`）—— 否則哨兵還在，
  整輪每個站台都會重試一次已知會失敗的查詢
- 繳款設定的快取用 `array_key_exists` 而不是 `isset` —— 查過但沒設定時
  存的是 `null`，`isset` 會判成「沒查過」

Service 每次解析都是新實例（沒註冊成 singleton），所以快取只活在一輪裡，
不會跨請求髒掉。

### 匯率用 `trimZeros` 不是 `formatCredits`

點數固定兩位小數（`16390.94`），匯率去尾零（`30.5`）。
匯率報價訊息也是這樣顯示 —— **同一個數字在兩個地方要長一樣**。
用 `NumberPresenter::trimZeros()`，不要自己再寫一份。

公版本身不帶前後分隔 —— 給客戶時它自己就是獨立的一則。
只有併進內部群組那則時才由程式補 `\n\n`（`topupSuffix()`）：
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
| `app/Models/Station.php` | casts、PHPDoc；`$topup_notice_blockers` accessor（補點通知按鈕的 disable 判斷） |
| `app/Http/Resources/StationResource.php` | 兩個新欄位 |
| `app/Http/Controllers/Admin/PaymentConfigController.php` | 告警設定讀寫；`ajaxRenderTemplate` 支援 credit/threshold |
| `app/Http/Controllers/Admin/StationController.php` | 門檻欄位驗證 |
| `app/Console/Kernel.php` | 排程 `dailyAt('10:00')` |
| `routes/web.php` | `ajax-alert-setting` |
| `resources/views/admin/payment-config/index.blade.php` | 告警設定區塊（可摺疊、含預覽） |
| `resources/views/admin/station/index.blade.php` | 門檻欄位（兩處編輯按鈕的 data attribute 都要加）；補點通知按鈕改 `@include` partial |
| `resources/views/admin/station/partials/topup-notice-button.blade.php` | **新增**：補點通知按鈕，缺 API／群組就 disable |
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
