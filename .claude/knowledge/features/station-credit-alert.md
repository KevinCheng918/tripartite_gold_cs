# 站台餘點告警

> 狀態：**已完成，待跑 migration**

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

## 誤報防護

這功能會直接發訊息給客戶，誤報的代價比漏報高：

| 情境 | 處理 |
|---|---|
| API 掛掉 / 逾時 / 回非成功狀態 | `syncInfo()` 回 `null` → **跳過，絕不拿上次的舊點數判斷** |
| 站台與內部群組都沒設 | 記 warning 後跳過 |
| 站台停用 / 凍結 | `getForCreditSync()` 只撈 `status=1` |
| 連日低於門檻 | 冷卻天數控制 |
| 發送噴錯 | 逐站 try/catch，**不寫 `credit_alerted_at`**，下一輪會重試 |

> 「API 失敗就跳過」是這個功能最重要的一行判斷。
> DB 裡的 `credits` 可能是好幾天前的值，拿它來判斷會發出
> 「客戶其實早就補過點」的錯誤告警。

## 驗證不用等到上午十點

```bash
# 印出會告警誰、訊息長什麼樣，但不實際送出
php artisan station:sync-credit --dry-run

# 只跑單一站台
php artisan station:sync-credit --station=2 --dry-run
```

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
| `app/Repositories/StationRepository.php` | `CREDIT_SYNC_COLUMNS` + `getForCreditSync()`；`LIST_COLUMNS` 加兩欄 |
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
