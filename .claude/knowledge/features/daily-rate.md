# 每日匯率報價

> 狀態：**已完成**。上線前還要做三件事：跑 migration、跑
> `DailyRateQuickReplySeeder` 建題庫那一題、把正式公版貼進匯率頁。

每天早上 9 點在內部支援群組報匯率，由自己人回覆決定當日對客報價；
沒回就每 30 分鐘提醒並 tag 主管；客人在匯率還沒定下來時問，先請他稍候。

## 現況：零件大多已經存在

| 已有的 | 位置 | 這次怎麼用 |
|---|---|---|
| MAX 交易所即時 USDT/TWD 匯率 | `UsdtRateService::getRateWithHistory()` | 9 點的訊息附上即時匯率當**參考值** |
| 發訊到內部支援群組 | `SupportGroupService::send()` | 直接重用 |
| 引用回覆的對應迴路 | `AutoReplySupportService::handleSupportMessage()` | 照同樣形狀寫 |
| webhook 分流到內部群組 | `TelegramWebhookController:69`（`isSupportChat()`） | 加一條分支 |
| tag 主管與老闆 | `UserRepository::getManagersForMention()`（level ≤ 2 且有填 Telegram 帳號） | 直接重用 |
| 定時提醒的階段控制 | `AutoReplyTicket` 的 `remind_count` / `last_reminded_at` | 照同樣欄位設計 |

**所以沒有新的外部整合、沒有新的發送管道、沒有新的權限群組。**

## 兩個可以簡化的地方

### 1. 「凌晨清空」不需要排程

用 `date` 當唯一鍵，「今天有沒有匯率」就是「今天這筆存不存在且 rate 有值」。
**過了午夜自然就查不到昨天的** —— 不需要一支清空的排程，也不會有
「清空失敗導致昨天的匯率被誤用」的風險。

### 2. 「00:00–09:00」與「09:00 後還沒回覆」是同一件事

兩者都是「今天的匯率還沒定下來」，對客人的回應也一樣（請他稍候）。
不用分別判斷時段，只要問「今天有匯率嗎」。

這也順便涵蓋了一個需求沒提到但會發生的情況：9 點報了、但到 11 點還沒人回，
客人這時候問 —— 一樣請他稍候，而不是回一個空的匯率。

## 資料表 `daily_rate`

| 欄位 | 說明 |
|---|---|
| `date` | 日期，**unique** —— 一天只有一筆 |
| `rate` | 當日對客報價。**null 表示還沒定下來** |
| `reference_rate` | 9 點時 MAX 的即時匯率，純記錄（事後對帳用） |
| `ask_message_id` | 9 點那則訊息的 Telegram message_id，引用回覆靠它對應 |
| `asked_at` | 9 點送出的時間 |
| `replied_by` / `replied_at` | 誰回的、什麼時候回的 |
| `remind_count` / `last_reminded_at` | 提醒次數與上次提醒時間 |

## 流程

### 09:00 報價

```
排程 rate:ask
  └─ 建立今天的 daily_rate（rate = null）
     ├─ 向 MAX 取即時匯率當參考（取不到就不附，不要讓整支失敗）
     ├─ 發訊息到內部支援群組
     └─ 記下 ask_message_id
```

訊息長這樣：

```
💱 今日匯率報價

參考匯率（MAX 即時）：32.15
請直接「引用這則訊息」回覆：
・回數字 → 當日對客報價就用這個數字
・回「好」 → 採用上面的參考匯率 32.15
```

> 「回好 = 採用參考匯率」是從需求推出來的解讀 —— 訊息裡既然附了即時匯率，
> 「好」最自然的意思就是「就用這個」。**這點要跟需求方確認。**

### 回覆解析

```
webhook → isSupportChat() → 有 reply_to_message
  └─ 用 message_id 找今天的 daily_rate
       ├─ 純數字（允許小數）→ 當日報價 = 該數字
       ├─ 「好」「ok」「可以」等 → 當日報價 = 參考匯率
       └─ 其他 → 回一句「看不懂，請回數字或『好』」，不寫入
```

⚠ 已經定下來之後又有人回覆：**要允許覆寫**（報錯了要能改），
但回一則確認訊息說明「今日匯率已從 X 改為 Y」，不要默默改掉。

### 沒人回就提醒

```
排程 rate:remind（每 30 分鐘，withoutOverlapping）
  └─ 今天已經問了、但 rate 仍是 null
       └─ 引用 ask_message_id 發提醒，tag 主管與老闆
          （getManagersForMention()，level ≤ 2 且有填 Telegram 帳號）
```

**待確認：提醒要有上限嗎？** 照需求字面是「一直沒回就每 30 分鐘提醒」，
但那會從早上 9 點 tag 到半夜。建議加一個停止條件（例如最多 N 次、
或到晚上幾點為止），避免變成沒人理會的雜訊。

### 客人問匯率

題庫的答案是**原文送出、沒有變數替換機制**（`quick_reply_item.answer`），
而匯率每天不同 —— 所以不能只靠題庫。

做法：題庫建一題「匯率」，用 `import_key` 標記（例如 `system.usdt_rate`），
AI 比對照常進行（準度沿用現有機制、不改 prompt），
只在**要送出答案前**攔截這一題，把內容換成動態的：

| 今天的狀態 | 回給客人 |
|---|---|
| 已經定下來 | 當日報價 |
| 還沒定下來（含 00:00–09:00） | 「今日匯率稍後為您確認，確認後第一時間回覆您」 |

> 攔截點在 `AutoReplyService` 送出答案之前，判斷
> `$item->import_key === 'system.usdt_rate'`。
> 這樣比對邏輯完全不用動，只換內容。

## 需求方已確認（2026-10-01）

1. **「回好」= 採用建議報價**（由 4H 均價算出，見下）
2. **提醒到 00:00 為止** —— 排程 `between('09:30', '23:59')`，
   跨過午夜就是新的一天，該報新匯率而不是繼續催昨天的
3. **不連動補點申請** —— 補點仍是每筆各自填 `exchange_rate`
4. **要能手動更改** —— 管理頁可以改任一天的匯率
5. **週末假日都報**，每天都報

> 公版由需求方提供中。在那之前用 `constants.DAILY_RATE.ASK_TEMPLATE` 的
> 預設版本，之後貼到後台（`app_setting` 的 `daily_rate.ask_template`）即可覆寫。

## 建議報價怎麼算

取 4H 均價到小數點後一位，但**零頭滿 .95 就報 .95**：

| 4H 均價 | 建議值 | |
|---|---|---|
| 32.9678 | 32.95 | 零頭 .9678 ≥ .95 |
| 32.96 | 32.95 | |
| 32.95 | 32.95 | 剛好 .95 |
| 32.9478 | 32.9 | 差一點點，捨去 |
| 32.88 | 32.8 | **捨去不是四捨五入** |

單純捨去到一位的話 32.9678 會變成 32.9，中間那 0.05 是白送的。

### ⚠ 乘 100 之後要先 round 再 floor

這不是理論上的潔癖，是會**少報錢**的：

```
32.3 * 100 = 3229.9999999999995   // PHP 的浮點數就是這樣
floor(3229.99…) = 3229            // 少了 1 分
floor(3229 / 10) / 10 = 32.2      // ← 報價變成 32.2，少報 0.1
```

在 25~40 這個匯率區間，直接 floor 會算錯的值有 **16 個**
（32.3、32.8、33.3、33.8、34.3…），**全都是少報**。
`floor(round($v * 100, 6))` 就沒事 —— 已驗證該區間 1501 個值全部正確。

## 踩到的坑

### `ITEM_COLUMNS` 原本沒有 `import_key`

對客那一端是靠 `import_key` 認出「這題是匯率題」才換成動態答案的，
而 `QuickReplyRepository::ITEM_COLUMNS` 原本沒帶這個欄位 ——
讀出來是 `null`，攔截永遠不會生效，**題庫裡的佔位文字會原樣送給客戶，
而且不會報錯**。

這是這個專案第三次踩同一類問題（前兩次見
[[2026-09-30-on-duty-reply-time]] 的「關聯 select 漏欄位」）。
已把 `import_key` 加進 `ITEM_COLUMNS` 並在那裡留下註解。

## 預估異動檔案

**新增**

| 檔案 | 內容 |
|---|---|
| `database/migrations/*_create_daily_rate_table.php` | 上表 |
| `app/Models/DailyRate.php` | |
| `app/Repositories/DailyRateRepository.php` | 今日查詢、依 ask_message_id 查詢 |
| `app/Services/DailyRateService.php` | 報價、解析回覆、提醒、對客文案 |
| `app/Console/Commands/AskDailyRateCommand.php` | `rate:ask` |
| `app/Console/Commands/RemindDailyRateCommand.php` | `rate:remind` |

**修改**

| 檔案 | 改什麼 |
|---|---|
| `app/Console/Kernel.php` | 09:00 報價、每 30 分鐘提醒 |
| `app/Http/Controllers/Webhook/TelegramWebhookController.php` | 內部群組的回覆多一條分支 |
| `app/Services/AutoReplyService.php` | 送出答案前攔截匯率題 |
| `config/constants.php` | 報價訊息、提醒訊息、對客的「稍後回覆」文案 |
| 題庫 | 建一題匯率題並標 `import_key` |

## 匯率頁（後台）

`admin/daily-rate`，權限 `daily_rate.view` / `daily_rate.manage`。

- **今日匯率**：大字顯示，還沒決定時會說明「客人問會先請他稍候」
- **歷史**：每天一列，含 4H 均價、建議值、誰決定的、提醒了幾次
- **手動修改**：平常匯率從 Telegram 回覆決定，這裡用來改錯或補登
  （可以補過去的日期，但擋未來 —— 明天的匯率要等明天早上報）
- **報價公版**：改完可以預覽。預覽走後端的 `previewAskText()`，
  **看到的排版就是實際送出去的** —— 變數替換只有一份實作（`renderAskText()`）

公版存 `app_setting` 的 `daily_rate.ask_template`，沒設定過就用
`constants.DAILY_RATE.ASK_TEMPLATE`。

## 走勢圖截圖

報價時一併附上 MAX 的走勢圖（`ScreenshotService`，headless Chrome）。

**截不到就只發文字** —— 報價不能因為截圖失敗就整則發不出去，這是前提。

### 環境需求與那四道牆

x86_64 Linux 裝 `google-chrome-stable` 就能用。但開發機（Apple Silicon Mac
上的 Docker）裝不起來，2026-10-01 逐一試過：

| 做法 | 結果 |
|---|---|
| `apt install chromium-browser` | Ubuntu 22.04 給的是 **snap 過渡包**，裝出來只是個印「requires the chromium snap」的 shell script |
| Google Chrome 官方 .deb | **沒有 Linux arm64 版** |
| Playwright / Puppeteer | 需要 **Node 14+**，容器是 v12（而且 Node 版本是 pin 過的，不該為截圖動它） |
| Debian repo 的 chromium | GPG key 缺失，混 repo 有拉壞系統庫的風險 |

所以 `canRun()` **不能只看檔案存不存在** —— 那個 snap stub 檔案在、
`command -v` 也找得到，卻完全不能用。實際跑一次 `--version`，
輸出要符合 `/(chrome|chromium)\s+\d+\./` 才算數。

### 只要 K 線圖那一塊

需求方要的是 MAX 畫面左邊那張 K 線圖（含成交量），不要右邊的成交明細、
掛單簿與下單面板。

**CLI 截圖沒辦法指定元素**，只能截整個視窗再裁。所以
`constants.DAILY_RATE.SCREENSHOT.CROP` 是對著 **1920×1080** 量出來的：

```
X=0  Y=60  WIDTH=1035  HEIGHT=660
```

⚠ **改了 WIDTH/HEIGHT 或 MAX 改版就要重新校正** —— 版面是跟著視窗寬度跑的。
校正最快的方式是按匯率頁的「測試截圖」看結果。設成 `null` 就不裁、送整張。

裁切失敗（座標超界、GD 讀不到檔）一律**保留原圖**：送一張沒裁好的，
總比整個截圖作廢好。

### 幾個實作上的點

- **不看 exit code**：headless Chrome 常常截圖成功卻回非 0（GPU、字型、
  dbus 的警告都算），看檔案有沒有生出來才準
- **`--no-sandbox`**：容器裡沒有 sandbox 需要的權限，不加會直接起不來
- **`--virtual-time-budget`**：圖表是 JS 畫的，要給它時間跑完。
  截到空白圖就是這個值不夠，調 `constants.DAILY_RATE.SCREENSHOT.WAIT_MS`
- **截完就刪**：截圖只是為了送出去，不刪的話 public 會一直長大
- **caption 上限 1024 字**：公版是客服自己維護的，寫長很正常。
  超過就退回「先發圖、再發文字」，並且**回傳文字那則的結果** ——
  引用回覆要對應的是文字訊息

`TelegramBotService::sendPhoto()` 本來就處理了「截圖太長 Telegram 不收」
（`PHOTO_INVALID_DIMENSIONS`）自動改用檔案傳送，整頁截圖正好用得上。

### 兩個測試按鈕

| 按鈕 | 做什麼 | 什麼時候用 |
|---|---|---|
| **立即報價** | 把 9 點那套完整跑一次（等同 `rate:ask --force`） | 測整條迴路 —— 送出後可以直接在群組引用回覆，驗證回覆解析 |
| **測試截圖** | 只截一張發到群組，不動今天的紀錄 | 環境裝好 Chrome 後先確認截得到、而且截到的是想要的畫面 |

「立即報價」會覆蓋今天已經報過的那則，所以有確認視窗。

## 上線前要做的三件事

1. `php artisan migrate`
2. `php artisan db:seed --class=DailyRateQuickReplySeeder` —— 建題庫那一題。
   已經建過的不會覆蓋，題目與答案的文字之後可以隨意潤飾
   （認的是 `import_key`，不是文字）
3. 把正式公版貼進匯率頁

另外 **內部支援群組必須先設定**（`auto_reply.support_chat_id`），
否則 9 點的報價送不出去，只會記一筆 warning。

## 相關

- [[auto-reply]] — 對客自動回覆的比對與送出
- [[station-topup]] — 補點的 `exchange_rate`（目前各筆自填）
- [[station-credit-alert]] — 同樣用內部支援群組與 `getManagersForMention()`
