# 匯率查不到時，整輪餘點告警跟著不發

## 現象

需求方回報：**匯率沒決定的那天，早上 10 點的餘點告警也沒發。**
這兩件事不該互相影響 —— 匯率只決定「要不要附第二則補點訊息」。

## 起因

兩個洞疊在一起。

### 一、取補點訊息的例外沒人接

`handleStation()` 在判斷完門檻之後會去組補點訊息：

```
topupMessage() → todayRate() → DailyRateService → 查 daily_rate 表
              → paymentConfigFor() → 查 payment_config 表
```

這兩個查詢**都沒有 try/catch**。任何一個拋例外（表不存在、連線中斷、
欄位對不上…），例外就會往上穿過 `handleStation()`。

### 二、`run()` 的迴圈也沒有 try/catch

```php
foreach ($stations as $station) {
    $results[] = $this->handleStation($station, $settings, $dryRun);
}
```

Command 層同樣沒有。所以一個站台噴錯 = **整個 command 中斷**，
後面的站台全部不會被處理。

更糟的是 class 的 docblock 當時寫著：

> 2. 每個站台各自 try/catch，一站發送失敗不影響其他站台

**那句話只對了一半** —— `send()` 內部確實有 try/catch（涵蓋「發送失敗」），
但**取資料的階段完全沒有保護**。文件聲稱的保障並不存在，讀的人（包括我）
會以為已經處理好了。

兩個洞加起來：匯率查詢一失敗，**所有站台都收不到餘點告警**，
而點數用完客戶的後台是真的會被停用。

## 修法

分三層，由內而外：

**1. `todayRate()` 自己接住** —— 失敗就當成「今天還沒決定匯率」（回 null），
這本來就是個合法狀態，下游早就處理得好好的（只發告警、不附補點訊息）。

失敗時把 `$todayRate` 從 `false` 設成 `null` 而不是留著 ——
否則哨兵還是 false，整輪每個站台都會重試一次失敗的查詢。

**2. `paymentConfigFor()` 自己接住** —— 失敗當成「這個系統沒設定繳款資訊」，
同樣寫進快取（存 null），整輪不重試。

**3. `run()` 的迴圈加 per-station try/catch** —— 最後防線，兌現 docblock
原本就承諾的事。新增 `SKIP_ERROR` 原因，結果表會顯示「處理時發生錯誤，
已跳過這一站（詳見 log）」。

走到第 3 層表示有沒被接住的例外，**那是要修的 bug**，所以記 `error`
而不是 `warning`；但它只能毀掉一個站台，不能讓整輪中斷。

docblock 也改了，把「補點訊息的問題不能影響告警」寫成明文規則。

## 教訓

> ⚠️ **附帶功能的失敗不能拖垮主功能。**
>
> 餘點告警的主功能是「通知客戶點數快沒了」，補點訊息是**附帶的**便利。
> 附帶的東西（匯率、繳款設定、圖片）壞掉時，正確行為是**少發那一段**，
> 不是連主功能一起放棄。
>
> 判準：問「這個查詢失敗時，使用者最少該收到什麼？」——
> 那個「最少」就是絕不能被例外穿過的部分。
>
> ⚠️ **docblock 寫了保障，就要確認它真的存在。**
>
> 「每個站台各自 try/catch」被寫在最顯眼的地方，於是沒有人再去確認 ——
> 包含後來改這支程式的我。**註解描述的保障必須指得出具體位置**，
> 不然它就只是一句願望。

## 驗證

讓 `DailyRateService::todayRate()` 一律拋例外（Mockery），跑 `--dry-run`
的唯讀路徑，9 項全過：

| 情境 | 結果 |
|---|---|
| 整輪不中斷、每個站台都有結果 | ✅ 2/2 |
| 沒有任何站台變成 `SKIP_ERROR` | ✅ |
| 呼叫 3 次 `topupMessage()` 只查 1 次匯率 | ✅ |
| `topupMessage()` 回空字串而不是拋 | ✅ |
| `todayRate()` 回 null（當成未定） | ✅ |
| 告警本文照樣產生（含站台名與餘點） | ✅ |

順帶確認了一件好事：**不符合告警條件的站台根本不會去查匯率**
（`sync_failed` / `above_threshold` 都在取補點訊息之前就 return）——
短路的順序是對的。

## 另外三個「告警不發」的原因（不是 bug）

查這件事時用本機資料跑了幾輪，實際撞到三個會讓告警不發的正常機制。
下次有人問「為什麼沒收到告警」，先用
`php artisan station:sync-credit --dry-run --station=N`（完全唯讀）看原因：

| 原因 | 說明 |
|---|---|
| `cooldown` | 上次告警在冷卻期內（預設 1 天）。**手動發送補點通知也會寫 `credit_alerted_at`**，所以前一天按過按鈕，隔天 10 點就會被擋 |
| `no_target` | 站台沒設 Telegram 群組，而內部支援群組也沒設定 |
| `above_threshold` / `sync_failed` | 點數充足；或主系統 API 沒回資料（刻意不拿舊點數判斷） |

第一個最容易誤判成「功能壞了」。

## 異動檔案

- `app/Services/StationCreditAlertService.php` — 三層 try/catch、`SKIP_ERROR`、docblock
- `app/Console/Commands/SyncStationCreditCommand.php` — `SKIP_ERROR` 的說明文字

## 相關

- [[station-credit-alert]] — 餘點告警功能本體
- [[daily-rate]] — 匯率來源（單向依賴，匯率那邊不認識站台告警）
