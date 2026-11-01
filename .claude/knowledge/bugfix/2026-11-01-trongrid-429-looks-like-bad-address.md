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

## ⚠ 補記二：清了快取之後「還是會撞，但頻率沒那麼高」（同日）

需求方回報的原話。這句話本身就把範圍縮掉了一半：

> **節流有效（頻率下降），但剩下的撞擊不是我們自己造成的。**

確認過節流的狀態是跨整輪保留的 —— `TronGridService` 是建構子注入
`WalletNoticeService`，一次排程只有一個實例，所以 `$lastRequestAt` 整輪共用。

而且間隔 1100ms > 1000ms，所以**我們自己的兩次請求不可能落在同一個
一秒窗口裡**（無論 TronGrid 用固定窗口還是滑動窗口都一樣）。

結論：`allowed_rps(1)` 是**按對外 IP** 算的，額度被同一個出口上的其他東西
吃掉了。這種情況**把自己的間隔再拉長也治不了** —— 碰撞取決於對方的時機，
不是我們的頻率。

⚠ 想查「是誰在吃額度」的話要去看那台機器上還有什麼在打 TronGrid，
但客服系統不在 team-ops MCP 的 inventory 裡（那邊只有 king/slh/triu 等
專案），這次沒查到。

### 修法：撞到 429 就退避重試

| 設定（`config/tron.php`）| 預設 | 作用 |
|---|---|---|
| `rate_limit_retries` | 2 | 同一次查詢最多再試幾次 |
| `rate_limit_backoff_ms` | 700 | 退避基數（× 第幾次 ＋ 抖動）|
| `rate_limit_retry_budget` | 10 | **一輪總共**最多重試幾次 |

⚠ **只有 429 重試**，逾時與其他錯誤當場放棄 —— 逾時重試會吃掉兩倍的
`timeout`（15 秒 × 2），而排程只有一分鐘。

⚠ **一定要有抖動**：撞 429 代表有別人也在用這個額度。固定等一樣久的話
兩邊維持同樣的相位差，每次重試又剛好撞在一起。

⚠ **退避要自己把 `min_interval_ms` 算進去，兩者是 `max()` 不是 `+`。**
我一開始以為「下一輪的 `throttle()` 會再加 1100ms」—— 錯了，`throttle()`
是從**上次送出的時刻**起算的，退避睡掉的時間它照樣算進 elapsed。
實測間隔只有 **1.33 秒**（預期 1.8 秒以上），退避幾乎等於沒做。
修正後 1.99～2.39 秒。

⚠ 修正方式是讓退避睡滿整個間隔，**不要去改 `$lastRequestAt`** ——
那個欄位的語意是「上次真的送出的時刻」，塞一個非送出時間進去，
下一個讀的人會被騙。

⚠ **一輪的重試額度是「不要讓重試把一輪拖垮」的保險。** 如果額度被整個
吃掉，那會是每一次查詢都撞 —— 每個錢包各重試兩次的話，20 個錢包會從
22 秒變成兩三分鐘，排程被 `withoutOverlapping` 卡著跳過好幾分鐘，
通知反而更慢。額度用完就快速失敗，讓 log 去講真話。

⚠ 額度在 `canRetryRateLimit()` 回 true 的那一刻就扣掉，不要等重試真的送出
才扣 —— 那會讓「判斷」與「扣款」分開，日後多一個呼叫點就會漏扣。

### 順手修掉的誤導：用字串找「429」

原本是 `strpos($e->getMessage(), '429')`。Guzzle 的錯誤訊息會把整個網址包
進去，而網址裡有錢包地址 —— **TRON 地址是 base58，數字 1-9 都可能出現**，
所以「429」真的有機會出現在地址裡。那種地址只要逾時一次就會被貼上
「撞到速率限制」的 hint，把下一個看 log 的人帶往完全錯誤的方向。

改成看 HTTP 狀態碼（`RequestException::getResponse()`）。連線層的錯誤
（逾時、DNS）沒有 response，回 false。

### 實測八項

| 情況 | 結果 |
|---|---|
| 429 / 500 / 逾時 / 普通例外 / 訊息含 429 但是逾時 | 只有第一個判定為 429 |
| 第一次 429、第二次成功 | 送 2 次、回陣列、間隔 1.99s |
| 一直 429 | 送 3 次（retries=2）後放棄，5.63s |
| 逾時 | 送 1 次，不重試 |
| 額度設 3，連續四次查詢 | 送 3→2→1→1 次（額度歸零後不再重試）|
| 四個 config key 都設成 null | 退避與重試照樣生效（吃程式預設）|
| **重試成功** | log 增加 **0 bytes** |
| 重試用盡 | log 有 `"attempts":3` 與指向 `TRON_API_KEY` 的 hint |

⚠ `blank(0)` 在 Laravel 是 **false**，所以額度歸零之後不會被誤判成
「還沒初始化」而重新填滿（上表第五列就是在驗這件事）。

### ⚠ 還沒驗證的部分

正式環境裝上重試之後 429 的錯誤紀錄會不會消失。預期是**偶發碰撞會消失、
系統性被搶額度不會** —— 後者在 log 的 hint 裡講明了，看到就去設 API key。

## 相關檔案

| 檔案 | 說明 |
|---|---|
| `config/tron.php` | `min_interval_ms`（含「第一個成功、第二個被擋」的說明）、`rate_limit_retries`、`rate_limit_backoff_ms`、`rate_limit_retry_budget` |
| `app/Services/TronGridService.php` | `$lastRequestAt`、`throttle()`、`configInt()`（config 快取事故的集中說明）、`isRateLimited()`、`canRetryRateLimit()`、`rateLimitBackoff()`、`$rateLimitRetriesLeft`；429 的 log hint 與 `attempts` |
