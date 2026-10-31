# 錢包「一直 TronGrid 查詢失敗」——而且跟地址無關

**日期**：2026-11-01（v3.56）
**回報**：「我發現我其中一個錢包地址，會一直 TronGrid 查詢失敗，
但是另一個錢包就不會。」

## 真正的原因：429，不是那個地址

production log：

```
production.ERROR: TronGrid 查詢失敗 {"address":"TYwab…","error":"Client error:
`GET …/transactions/trc20?only_confirmed=true&order_by=block_timestamp%2Casc
&limit=200&min_timestamp=1793447175001` resulted in a `429 Too Many Requests`
response: {"Error":"request rate of (getTrc20TransactionsByAccount) exceeded
the allowed_rps(1), and the query server is suspended …"}
```

兩個關鍵字：

- **`allowed_rps(1)`** —— 沒有 API key 時 TronGrid 每秒只准一次
- **`min_timestamp` 有帶** —— 所以這個錢包的基準早就建好了，
  不是卡在第一輪

而 `WalletNoticeService::run()` 是這樣跑的：

```php
foreach ($wallets as $wallet) {
    $results[] = $this->handleWallet($wallet, $params);   // 中間沒有任何間隔
}
```

> **清單裡第一個錢包永遠成功，第二個永遠被擋。**

症狀因此看起來像「某個地址有問題」—— 實際上只跟**它排第幾**有關。
而排程每分鐘跑一次，所以是「一直失敗」。

## ⚠ 我一開始的假設是錯的，記下來免得下次再繞

看到 `order_by=asc` 且第一輪沒有 `min_timestamp`，我推論是
「交易量大的地址第一次建基準太慢 → 15 秒逾時」，而且 `process()` 失敗時
什麼都不存、`latestBlockAt` 永遠是 null，會自我延續 ——
**那個機制是真的存在**（值得記住），但不是這次的原因。

實測一個交易量很大的公開地址，三種查法都是 1 秒出頭：

| 查法 | 耗時 |
|---|---|
| `asc` ＋ 沒有 `min_timestamp`（第一輪） | 1.19s |
| `desc` | 1.25s |
| `asc` ＋ 有 `min_timestamp` | 0.88s |

**log 一出來，二十秒就定案。** 這類「某一個固定失敗」的問題，
先去撈 log 裡的實際錯誤訊息，不要從程式反推可能性。

## 修法：在 `TronGridService` 做節流

`config/tron.php` 加 `min_interval_ms`（預設 **1100**），
`TronGridService` 用一個 instance 屬性記住上次呼叫時刻，送出前補足間隔。

⚠ **節流要做在 `TronGridService` 不是那個 foreach 迴圈**：
翻頁也會連發（`max_pages` 預設 5），兩邊都要擋。

⚠ **時間在送出前就記，不要等回應回來才記**：記在回來之後的話，間隔變成
「上一次結束到這一次開始」，而 TronGrid 算的是送達時間點 ——
一次查詢本身花掉的那一秒也要算進去。

⚠ **第一次呼叫不等**（`$lastRequestAt` 是 null 就直接走），
所以單一錢包完全沒有額外延遲。

⚠ 在這裡 `sleep` 是安全的：`TronGridService` 的唯一呼叫端是
`WalletNoticeService`，而它只有排程在跑 —— 設定頁的「測試」按鈕
（`WalletNoticeService::test()`）只發私訊、**不打 TronGrid**。

實測：第一次 0.000s，之後穩定 1.102s。20 個錢包約 22 秒，
排程一分鐘跑得完（而且有 `withoutOverlapping`）。

## 更根本的解：設 `TRON_API_KEY`

`config/tron.php` 本來就寫著「沒填也能跑，只是免費額度較低⋯⋯否則會開始
收到 429」—— 這次就是撞上了。設了 key 限制會寬很多。

節流是「**沒有 key 也不會壞**」的保險，兩者不互斥，建議都做。

⚠ 429 的 log 現在會多一個 `hint` 欄位指向這件事 ——
這個錯太容易被誤判成地址問題，而下一個看到的人未必會讀到這份文件。

## ⚠ 補記：節流上線之後 429 還是照來（同日）

部署後 log 仍然每隔幾分鐘一筆 429，但**那一筆有我加的 `hint` 欄位** ——
代表新程式確實上線了。

```
03:16、03:18  沒有 hint（舊程式）
03:20         有 hint（新程式）
```

原因是 **`tron.min_interval_ms` 是新增的 config key**，而正式環境的 config
是快取的（`config:cache`）—— 部署後沒跑 `artisan optimize` 的話那個 key
讀出來是 `null`：

```php
$intervalMs = (int) config('tron.min_interval_ms');   // null → 0
if ($intervalMs < 1) { return; }                      // ← 整個跳過
```

> `hint` 出得來（那是程式），節流卻沒生效（那是 config）。

⚠ **這是我寫得不夠防禦：保護機制不該能被「忘記清快取」靜默關掉。**

### 修法與一個踩到的細節

讀不到就退回程式裡的常數 `DEFAULT_MIN_INTERVAL_MS`。

⚠ **第一版修法是錯的**：我原本寫 `config('tron.min_interval_ms', $default)`
—— 但那個預設值**只在「key 不存在」時生效，key 存在而值是 null 就不會**
（`Arr::get()` 的語意）。測出來才發現。

所以要自己判斷：

```php
$configured = config('tron.min_interval_ms');
$intervalMs = filled($configured) ? (int) $configured : self::DEFAULT_MIN_INTERVAL_MS;
```

⚠ 用 `filled()` 而不是 `isset`／`??`，而且剛好正確：`filled(0)` 在 Laravel
是 **true**（數字一律 filled），所以「**明確設成 0**」會被保留（那是刻意關掉
節流，例如已經設了 API key），只有 null／不存在才退回預設。
「讀不到」與「設成 0」是兩件事。

四種情況實測：

| config | 間隔 | 結果 |
|---|---|---|
| 1100 | 1.101s | 生效 |
| null（沒清快取）| 1.100s | 生效（吃預設）|
| key 不存在 | 1.102s | 生效（吃預設）|
| 明確 0 | 0.000s | 正確關閉 |

### ⚠ 還沒驗證的部分

正式環境跑過 `artisan optimize` 之後 429 會不會消失，**還要觀察**。

如果清了快取還是 429，那就不是「同一個程序內連發」的問題 ——
而是這個 IP 的 1 rps 被別的東西吃掉（例如同一台機器上有別的環境也在跑
這支排程）。那種情況節流治不了，只能靠 `TRON_API_KEY`。

⚠ 也要記得 `allowed_rps(1)` 是**匿名**額度，而一輪可能有
「錢包數 × 最多 5 頁」次請求 —— 匿名額度本來就很緊。

## 相關檔案

| 檔案 | 說明 |
|---|---|
| `config/tron.php` | `min_interval_ms`（含「第一個成功、第二個被擋」的說明）|
| `app/Services/TronGridService.php` | `$lastRequestAt`、`throttle()`、`DEFAULT_MIN_INTERVAL_MS`；429 的 log hint |
