# 錢包有轉入／轉出就私訊通知（TRON）

> 需求（2026-10-27）：「我們都是用 Tronscan 去做 USDT 的轉入或轉出，我想要在
> 通知設定做一個通知，當我的錢包有轉帳進來或轉出就要通知，**只有通知就好，
> 不用做任何動作**，通知要包含時間、交易 hash、From、to、金額、幣別。」
>
> 五題裁示：**私訊名單**／**設最低金額門檻**／**所有 TRC20 代幣**／
> **每分鐘**／**不附圖，只發文字**。

## ⚠ 資料來源是 TronGrid，不是 tronscan.org 那一頁

`tronscan.org/address/{地址}/transfers` 是 React SPA，HTML 裡沒有交易清單，
**爬不到東西**。它背後的 `apilist.tronscanapi.com` 要 API key，而且有個長年
未修的問題：`limit` 給多少都只回 20 筆。

改用 **TronGrid**（TRON 官方節點 API）拿同一份鏈上資料：

```
GET https://api.trongrid.io/v1/accounts/{address}/transactions/trc20
    ?only_confirmed=true&order_by=block_timestamp,asc&min_timestamp=…&limit=200
```

- `min_timestamp` 就是增量輪詢要的游標 —— 不必整包拉回來自己比對
- `only_confirmed=true` 順便避開鏈重組（未確認的交易可能被回捲，
  通知出去之後才消失很難解釋）
- `limit` 真的到 200

⚠ `min_timestamp` 是**含等於**的，而且單位是**毫秒** ——
直接帶最後一筆的時間會把那一筆再拿回來一次，所以 `+1`。

⚠ 通知裡附的連結仍然指向 **tronscan.org**（需求方習慣看那個畫面）。

⚠ TronGrid 沒 key 也能跑，只是額度低。`TRON_API_KEY` 放 `.env`。

## ⚠ 三個最容易寫錯的地方

### 1. `value` 是未除以小數位的整數字串

TronGrid 回的是 `"1250000000"`，要除以 `10 ^ decimals`。
USDT 是 6 位，但**實測真的有 18 位的代幣**（`1326495690.215502 WIN`）——
`(float) $value / pow(10, 18)` 在那種數字上會丟精度，而這是錢。

`TronGridService::toDecimal()` 用**字串插小數點**，完全不碰浮點數。
資料庫存 `decimal(36,8)`，Model 也不 cast 成 float。

⚠ 組訊息時的千分位也不能先轉 float —— `WalletNoticeService::amount()`
先用字串砍掉尾零，**只有整數那一段**才交給 `number_format`。

### 2. 第一次加錢包會洗版

新增一個錢包時鏈上可能有好幾千筆歷史轉帳。第一輪必須先**建立基準**：
抓回來寫進資料表、標 `skip_reason = 'baseline'`、**一則都不發**。

沒有這一步的話，存檔那一刻會被幾千則私訊炸掉。
通知設定頁上也寫了這一句 —— 不寫會被當成功能壞掉。

### 3. 先寫資料庫再發送

先發後寫的話，「發送成功但寫入失敗」會讓下一輪重發同一筆。
先寫（`(address, tx_hash)` 唯一索引擋重複）再發，最壞是漏一則 ——
比重複洗版好。

發送成功才寫 `notified_at`。下一輪會把
`notified_at IS NULL AND skip_reason IS NULL` 且 **24 小時內**的補發
（`RETRY_HOURS`）；更舊的不追，免得服務中斷一週後一次倒幾百則出來。

## ⚠ 唯一鍵是 (address, tx_hash)，不能只對 tx_hash 唯一

同一筆交易可能同時是 A 錢包的轉出與 B 錢包的轉入 ——
兩邊都要各記一筆、各發一則。

### ⚠ 去重要一次問完，不要逐筆查

第一版寫成 `firstOrCreate()`：每一筆先 SELECT 再 INSERT。建立基準那一輪
會一口氣拿回 200 筆（翻頁上限內最多 1000 筆）—— 那就是 1000 次 SELECT，
正是專案規則說的 N+1。

改成 `existingHashes($address, $hashes)` 一次 `whereIn` 問完，
回 `tx_hash => true` 讓服務端 O(1) 判斷，只有真的新的才 INSERT。

⚠ 唯一索引**還是要留**，而且 `create()` 要接住 `QueryException`：
兩輪重疊時（`withoutOverlapping` 擋不住的極端情況）可能同時寫同一筆。
撞到就回 null、當成已經有了跳過 —— **不要讓一筆卡住整批**。
測試裡有一條就在驗「撞到之後，它後面那一筆照樣寫得進去」。

## 訊息排版

> 需求方 2026-10-27：「通知要整理一下，該換行要換行，要好看一點。」

```
💰 錢包轉入

錢包：公司主錢包
金額：1,250 USDT
時間：2026-10-27 15:44:24

From
TJ9aXcPq4mWnEbVdK8rLsYt2HgZ5QuNfMk

To
TKq2W7CbLkHFXsYrVnPmQdJ3eGtZ9aUxNb ← 本錢包

Hash
4f8c…（完整 64 碼）

🔗 在 Tronscan 上查看這筆交易
```

⚠ **長的值要自己一行**。地址 34 碼、hash 64 碼 —— 第一版寫成
`From：TJ9a…` 那種一行到底的格式，手機上會從地址中間折斷，
看起來像壞掉的資料，也不好對照。標題自己一行、值自己一行，折到也是整段折。

⚠ **三段之間空一行**：發生什麼事（方向、錢包、金額、時間）／誰跟誰
（From、To）／憑證（hash、連結）。擠成一整塊的話，急著看「多少錢」的人
要從七行裡面挑。

⚠ **「← 本錢包」標在 `</code>` 外面** —— 點 code 區塊複製地址時，
不該把這幾個字一起複製走。

⚠ 連結用 `<a href>` 而不是裸網址：裸的 tronscan 交易網址有 100 來個字元，
佔掉整整兩行還不能點。`TelegramBotService::escapeHtml()` 的白名單有 `a`，
但它的屬性比對是 `[^&]*?` —— **網址裡有 `&` 的話標籤會被吃掉**，
tronscan 的交易頁沒有 query string 所以沒事，日後換別的網址要留意。

⚠ 排版後整則約 **400 字元**，離 caption 上限 1024 還很遠。

## ⚠ 每一則都要講清楚是哪一個錢包

需求方 2026-10-27：「我有多個錢包，所以我可以設定多個錢包通知，
另外，通知訊息內容要註明是哪一個錢包。」

錢包那一行**一定要印，不能因為沒填備註就整行不見**（第一版就是那樣寫的）。
一個人盯好幾個錢包時，光看 From／To 分不出「這筆是我哪一個錢包」——
兩個地址裡有一個是我的，但是哪一個要自己比對 34 碼。

沒填備註就退而印地址：醜一點，但至少答得出問題。

## 跳過的三種情形都要分得開

| `skip_reason` | 什麼情況 | 為什麼分開 |
|---|---|---|
| `baseline` | 首次建立基準 | |
| `below_min` | 低於金額門檻 | 地址投毒那種 0.000001 |
| `self` | 自己轉給自己 | 併進 `below_min` 的話，問「這筆為什麼沒通知」會得到錯的答案 |

⚠ **地址投毒**：對方從長得很像的地址轉 0.000001 USDT 進來，誘使你下次複製到
錯的地址。沒有門檻的話通知會被這種洗版。預設門檻 1 USDT，
而且**低於門檻的仍然寫進資料表** —— 事後要查「那天到底有沒有這筆」查得到。

## ⚠ 為什麼不附截圖（做過又拿掉）

一度做成「每輪每個錢包截一張 `tronscan.org/#/address/{地址}/transfers`
附在第一則」。**需求方 2026-10-27 一句話點破：「應該會有問題，會有機器人
驗證阻擋。」** 實測確認：

```
$ curl -A '<一般瀏覽器 UA>' https://tronscan.org/
HTTP 403    server: cloudflare    cf-ray: …
頁面內容：challenge-platform / cloudflare
```

tronscan 整站在 **Cloudflare 後面，直接回 403 挑戰頁**。
headless Chrome 配機房 IP 幾乎過不了。

⚠ **真正的代價不是「沒有圖」，是「每輪多 30 秒」**：挑戰頁上等不到
`wait_selector` 指定的 `table`，會白等滿 `ScreenshotService::WAIT_TIMEOUT`
（30 秒）才放棄。排程每分鐘一次，三個錢包就是 90 秒 ——
比間隔還長，`withoutOverlapping` 會開始擋掉下一輪，通知跟著延遲。

⚠ **這跟資料來源沒有關係**，兩條路徑完全分開：

| | 走哪裡 | 會不會被擋 |
|---|---|---|
| 交易資料 | TronGrid 的 **JSON API** | ❌ 不是爬蟲，不會 |
| 截圖（已移除） | 用瀏覽器開 **tronscan 網頁** | ✅ Cloudflare 403 |

⚠ **既有的 MAX 匯率截圖能過不代表這個也能過**：MAX 只擋純 HTTP 請求
（`config/constants.php` 的註解寫了「MAX 對一般 HTTP 請求一律回 403」），
沒有 JS 挑戰；Cloudflare 的 challenge-platform 是另一回事。

拿掉之後一併還原的東西：`StaffDmService` 的 `$imagePath`／`CAPTION_LIMIT`
（整個功能只有這裡在用，留著就是死碼）、`config/tron.php` 的
`transfers_url`。`ScreenshotService` 回到只有報價在用。

⚠ 要補回「看完整紀錄」的脈絡，靠的是訊息最後那個可點的 Tronscan 連結 ——
**讓人點過去看**，而不是我們替他截。

## 設定（通知設定頁「錢包轉帳」分頁）

| key | 內容 |
|---|---|
| `wallet_notice.enabled` | 總開關，**預設關著** |
| `wallet_notice.addresses` | `[{address, label}]` JSON |
| `wallet_notice.manager_user_ids` | 收件人 |
| `wallet_notice.min_amount` | 最低金額門檻，預設 1 |

⚠ **收件人又是另一份名單**，不沿用財務申請那份：盯錢包進出的人不一定要
審補點申請（理由同 [[finance-notice]]）。

⚠ 地址格式在 `UpdateWalletNoticeRequest` 擋掉。pattern 放
`config/rules.php` 的 `TRON_ADDRESS_PATTERN`，跟
`TronGridService::isValidAddress()` **共用同一份** ——
寫成兩份遲早分岔，而分岔的後果是「通知安靜地不會來」，沒有人會發現。

## 新增/修改的檔案

| 檔案 | 說明 |
|---|---|
| `database/migrations/2026_10_27_000001_create_wallet_transfer_table.php` | **新增** |
| `app/Models/WalletTransfer.php` | **新增**。`DIRECTION_*`／`SKIP_*` |
| `app/Repositories/WalletTransferRepository.php` | **新增** |
| `app/Services/TronGridService.php` | **新增**。只負責拿資料與換算，不決定要不要發 |
| `app/Services/WalletNoticeService.php` | **新增**。判斷、組訊息、送出 |
| `app/Console/Commands/WatchWalletTransferCommand.php` | **新增**。`wallet:watch-transfers` |
| `app/Console/Kernel.php` | 每分鐘 ＋ `withoutOverlapping` |
| `app/Services/AppSettingService.php`／`NotificationSettingService.php` | 四個 key ＋ 讀寫 ＋ 測試發送 |
| `app/Http/Requests/Notification/UpdateWalletNoticeRequest.php` | **新增** |
| `app/Http/Controllers/Admin/NotificationController.php`／`routes/web.php` | 兩支路由（`can:notification.manage`，**沒有新權限**）|
| `config/tron.php` | **新增** |
| `config/constants.php`／`config/rules.php` | 文案模板 ＋ 地址 pattern 與上限 |
| `resources/views/admin/notification/index.blade.php`／`public/js/notification-setting.js` | 「錢包轉帳」分頁 |
| `resources/lang/{tw,cn,en}/notification.php` | 三語系 |

## 驗證

**57 種情境**（TronGrid／StaffDm／Repository 全部假物件，不碰 DB、不打外網）：

- 金額換算 7 種：6 位、18 位、位數不足要左補 0、超過 float 精度的大數、
  `0.000001`、沒有小數位、null
- 地址驗證 5 種：正確、空、少一碼、不是 T 開頭、含 base58 不收的 `0`
- 第一輪只建立基準（不發、全部標 baseline）
- 第二輪：轉入／轉出分得開、灰塵被擋但有留紀錄、千分位、尾零砍掉、
  幣別／From／To／完整 hash／tronscan 連結都在
- 重跑一輪不重發；送失敗不標記、下一輪補發出去
- 自己轉自己不通知且標成 `self`
- 短路：沒開／沒設錢包都不打 API；沒收件人不發
- 地址格式不對、API 掛掉（null）兩種都認得出來，不會被當成「沒有轉帳」
- 多錢包：兩個錢包都跑到、有備註印備註、**沒填備註退而印地址**
- 去重**一輪只查一次 DB**（不是逐筆），而且撞到唯一索引時它後面那筆照樣寫得進去
- 排版：長值自己一行、三段有空行、「← 本錢包」標在對的那一邊且在
  `</code>` 外面、跳脫後 `<a>`／`<code>`／`<b>` 都還在、網址沒被改掉
- **截圖真的拿乾淨了**：服務不再相依 `ScreenshotService`、建構子剩四個相依、
  `StaffDmService::sendToUserIds()` 回到兩個參數

**真的打過 TronGrid**（唯讀，拿公開的幣安熱錢包）：
一次拿回 200 筆、排序由舊到新、`from`／`to`／`hash`／`symbol` 都解得出來，
而且其中就有 18 位小數的代幣（`1326495690.215502 WIN`）——
正好是用 float 會爆掉的那一種。翻頁上限的警告也如預期出現。

**真的打過 tronscan.org**（唯讀）：確認回 403 ＋ Cloudflare 挑戰頁，
這就是不截圖的依據。

⚠ **沒有真的私訊過**。要在通知設定頁按「測試發送」確認名單收不收得到。

## 待釐清

- 只有 TRC20，**不含 TRX 本幣**（手續費用的那個）。要的話是另一支 API。
- 沒有歷史查詢頁（需求方：「只有通知就好」）。資料都在 `wallet_transfer`，
  日後要加不必重跑。
- 真的很想要圖的話，可行的做法是**用我們自己的資料在本機渲染一張卡片再截**
  （沒有 Cloudflare、不會白等、版面自己控制），但那張圖不會是 tronscan
  的畫面。目前沒做。
