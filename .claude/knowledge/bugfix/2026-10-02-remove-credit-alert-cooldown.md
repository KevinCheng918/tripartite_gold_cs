# 手動按過補點通知，隔天 10 點就不告警了

## 現象

需求方回報：手動按了站台列表的「補點通知」之後，**隔天上午 10 點的自動告警
就不發了**。預期是「不管我按過幾次，隔天還是要通知」。

## 起因

冷卻期（`cooldown_days`，預設 1 天）與 `credit_alerted_at` 的組合。

手動發送**也會寫 `credit_alerted_at`**（當初的理由是「不記的話明天早上的
自動告警會再發一次同樣的東西，對客戶是重複打擾」）。於是：

```
10/01 22:06  客服手動按「補點通知」  → credit_alerted_at = 22:06
10/02 10:00  排程跑到              → 22:06 + 1 天 = 10/02 22:06 還在未來
                                   → inCooldown() = true → 不發
```

排程固定 10:00 跑，而手動按的時間幾乎一定晚於 10:00 —— 所以**前一天只要
按過一次，隔天的自動告警必被吃掉**。這不是邊界情況，是常態。

## 修法：整個冷卻期移除

需求方確認「從程式完全移除」而不是只讓手動發送不寫時間 ——
**點數不足是持續存在的狀態，不是一次性事件**。客戶補點之前每天提醒一次
才是對的行為，冷卻期在這個場景沒有意義。

拿掉的東西（不留沒作用的設定）：

| 位置 | 內容 |
|---|---|
| `StationCreditAlertService` | `SKIP_COOLDOWN`、`inCooldown()`、`handleStation()` 的判斷、`globalSettings()` 的 `cooldown_days` |
| `AppSettingService` | `KEY_CREDIT_ALERT_COOLDOWN_DAYS` |
| `UpdateAlertSettingRequest` | `COOLDOWN_MAX`、驗證規則、三個錯誤訊息 |
| `PaymentConfigController` | 設定寫入的那一行 |
| `SyncStationCreditCommand` | `REASON_LABELS` 的冷卻說明 |
| `config/constants.php` | `COOLDOWN_DAYS` |
| 繳款設定頁 blade | 「重複告警間隔」欄位 + JS 的兩處 |
| `resources/lang/{tw,cn,en}/payment_config.php` | 欄位名、說明、三個驗證訊息 |

`credit_alerted_at` **保留寫入**，但降級成純紀錄（「最後一次告警是什麼時候」），
不再參與任何判斷 —— `StationResource` 有輸出它，日後想在站台列表顯示
「最後告警時間」隨時可用。兩處寫入點的註解都改了，講明它現在只是紀錄。

## 這樣改之後要知道的事

- **每天 10 點只要低於門檻就會發**，客戶補點之前每天收到一則。這是刻意的
- 還是有三道保護在：`above_threshold`（點數充足）、`sync_failed`（API 沒回
  資料就不拿舊點數判斷）、`internal_pending`（有補點單待審核就改催自己人）
- 排程有 `withoutOverlapping()`，所以不會因為上一輪還在跑就重疊發送
- ⚠ **`app_setting` 裡的 `station_credit.cooldown_days` 會變成孤兒資料列。**
  留著無害（沒有任何程式會讀它），要清的話自己 DELETE 那一列

## 驗證

重現原本的情境並確認不再被擋（transaction + rollback，走 `--dry-run` 路徑）：
站台低於門檻、`credit_alerted_at` 設成 2 小時前、有群組可發 —— 11 項全過。

| 檢查 | 結果 |
|---|---|
| 2 小時前才告警過，現在仍然要發 | ✅ `reason = null` |
| 不是被 cooldown 擋 | ✅ |
| 有發送目標、產生了告警內容 | ✅ |
| `globalSettings()` 的 key 只剩 threshold / template | ✅ |
| `COOLDOWN_DAYS` / `SKIP_COOLDOWN` / `KEY_CREDIT_ALERT_COOLDOWN_DAYS` 都不存在 | ✅ |
| rollback 後門檻、告警時間、群組都還原 | ✅ |

驗證過程中也撞到兩個本機環境的限制（不是 bug）：LV 沒設 Telegram 群組、
而且有一筆待審核的補點單 —— 兩者都會讓告警走到 `no_target`。
腳本在 transaction 內暫時補上群組、把補點單標成已完成，才測到發送路徑。

## 相關

- [[station-credit-alert]] — 餘點告警功能本體
- [[2026-10-02-rate-failure-blocks-credit-alert]] — 同一天修的另一個「告警不發」
