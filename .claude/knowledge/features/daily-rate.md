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

### 匯率已決定時直接發補點訊息（2026-10-02 上線）

客人問匯率時，**匯率已決定就直接發補點訊息（含繳款圖片）**，不是只回一句報價。
客人問匯率通常就是要補點 —— 直接給完整的匯款資訊，省掉一輪往返。

補點訊息公版本身帶 `{rate}`，所以匯率資訊沒有消失，客人還多拿到
「補 N 點要多少 USDT」與匯款資訊：

```
好的，為您查詢

————————
💰 10/2 USDT 當前匯率 32.95
補 50000 點約需 1518 USDT

（繳款資訊）
```
（附繳款設定的圖）

### 組裝抽出來共用，查詢各自保留

補點訊息的組裝搬到 **`PaymentConfigService::buildTopupMessage($config, $rate)`**，
回 `['text' => ..., 'image_url' => ...]`。餘點告警的第二則與這裡都呼叫它 ——
**共用的價值在於組出來的訊息一字不差**。

⚠ 那一支**只組裝、不查詢**。查詢策略兩邊天差地遠，硬要統一只會兩邊都不合用：

| | 餘點告警 | 客人問匯率 |
|---|---|---|
| 情境 | 一輪掃過所有站台 | 單次請求 |
| 匯率 | 整輪快取（`$todayRate`） | 查了就用 |
| 繳款設定 | 按 system_id 快取（`$paymentConfigs`） | 查一次 |

`StationCreditAlertService` 原本的 `topupMessage()` + `topupImage()` 併成
一支 `topupFor($station)`（查詢 + 委派組裝）—— 原本後者要吃前者的結果當參數
才知道「有沒有要附圖」，兩支永遠得成對呼叫，拆開沒有好處。
`usdtForBaseCredit()` 也跟著搬進 `PaymentConfigService`。

### 攔截點從 resolveAnswer 上移

原本在 `AutoReplyService::resolveAnswer()`，但那一支**只能回字串**而這次要附圖。
改到 `replyWithItem()`：

```php
if ($this->isRateItem($item) && $this->sendRateTopup($group, $opening)) {
    $this->telegramRepository->updateAutoReplyState($group, $item->id);

    return;
}
// 不適用就走原本的一般路徑
```

`sendRateTopup()` 回 false 時退回原路徑，有三種：

| 情況 | 回什麼 |
|---|---|
| 匯率還沒決定 | 「今日匯率稍後為您確認」（`customerAnswer()`，行為不變） |
| 這個對話找不到對應站台 | 報匯率 + 記 info log |
| 站台所屬系統沒填補點訊息 | 報匯率 + 記 info log |

後兩種**靜默退回**：客人還是得到有用的答案，只是少了匯款資訊。
但一定要記 log，否則「為什麼這個群組沒收到補點訊息」查不出來。

⚠ `sendRateTopup()` 整段包 try/catch —— 這是**附帶的加值**，查詢或組裝噴錯時
退回報匯率就好，不能讓客人連匯率都問不到（同
[[2026-10-02-rate-failure-blocks-credit-alert]] 的教訓）。

### ⚠ 這一則不加 AI 承接句

上線第一天就被需求方抓到：模型給的承接句是
**「老闆好，關於匯率的部分我這邊確認一下～」**，而下一行就是完整答案 ——
說要去確認、卻又立刻回答，客人看了會覺得前後矛盾。

承接句的內容**不可控**（每次由模型現生），所以不是調 prompt 的問題：
補點訊息本身就是完整的答案（日期、匯率、換算、匯款資訊），根本不需要前導句。

`sendRateTopup()` 直接送 `$text`，不經過 `joinOpening()`，連 `$opening`
參數都不收。**其他題目的承接句不受影響。**

> 要加招呼語的話，請加在繳款設定的**補點訊息公版開頭** ——
> 那是客服寫得了、看得到、而且確定不會跟內容打架的地方。

### ⚠ 這一則不分段

`AutoReplyService::send()` 會先過 `AnswerSplitter` 分段，但補點訊息走
**`sendWithImage()`，刻意不分段**：公版本來就是設計成一則，而分段後圖只能掛在
其中一則上，文字與圖就被拆開了。

### 新增的反查

`StationRepository::findByTelegramGroupId()` —— 客人問匯率時手上只有
`telegram_group_id`，要從它找到站台所屬的系統才知道該用哪一筆繳款設定。
這個反查原本不存在（`telegram_group_id` 是站台身上的欄位，之前只有正向查）。

### 實測（2026-10-02，15 項全過）

攔在 `sendReply()` 之前檢查送出的內容與圖，transaction + rollback：

| 情境 | 結果 |
|---|---|
| 匯率已決定 + 有公版 → 只送一則、含匯率/USDT/繳款資訊/承接句、**有附圖** | ✅ |
| 送出的文字與圖**與餘點告警第二則完全相同** | ✅ |
| 系統沒填補點訊息 → 退回報匯率、沒有圖 | ✅ |
| 對話找不到站台 → 退回報匯率 | ✅ |
| 匯率未定 → 「稍後為您確認」、沒有圖 | ✅ |
| 一般題目（非匯率）→ 照送題庫原文 | ✅ |
| **不帶 AI 承接句**（2026-10-02 補驗）→ 開頭就是答案 | ✅ |
| 一般題目的承接句不受影響 | ✅ |

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
- **php-fpm 帳號下要給一個可寫的 `$HOME`**，詳見下面獨立那節 —— 這是
  正式機唯一真正擋住截圖的問題，`--user-data-dir` 一個人解不掉
- **`--virtual-time-budget`**：圖表是 JS 畫的，要給它時間跑完。
  截到空白圖就是這個值不夠，調 `constants.DAILY_RATE.SCREENSHOT.WAIT_MS`
- **截完就刪**：截圖只是為了送出去，不刪的話 public 會一直長大
- **caption 上限 1024 字**：公版是客服自己維護的，寫長很正常。
  超過就退回「先發圖、再發文字」，並且**回傳文字那則的結果** ——
  引用回覆要對應的是文字訊息

`TelegramBotService::sendPhoto()` 本來就處理了「截圖太長 Telegram 不收」
（`PHOTO_INVALID_DIMENSIONS`）自動改用檔案傳送，整頁截圖正好用得上。

### ⚠ K 線圖在 iframe 裡：SELECTOR 用不了，只能整頁截圖

2026-10-03 看了 `/trades/usdttwd` 的實際 DOM 才確定的事：

```
外層頁面 max.maicoin.com/trades/usdttwd
├── 報價列（價格、24h 量、最高最低、漲跌）   ← class 是 emotion hash
└── <iframe src="/maxtv/...">                ← 圖表在這裡面
    └── #max_tv.TradingViewScreen
        └── <iframe src="/maxtv/charting_library/...">   ← 還有一層
```

三個結論：

1. **舊設定的 `#tv_chart_container` 永遠選不到** —— 它是 iframe **內部**的
   id，而 `$page->dom()->search()` 只搜當前 frame。每次都白等滿 30 秒才
   退回整頁，這就是「截圖有點久」的來源。
2. **要截的範圍橫跨 iframe 內外**（報價列在外、圖在內），本來就沒有單一
   元素包得住兩者 —— 所以這頁 `SELECTOR` 一定是 `null`，只能整頁 + `CROP`。
3. **別拿 DOM 上看到的 class 當 selector**：`css-g5y9jx` 是 emotion 產生的
   hash，`tradingview_44faf` 的後綴是動態的，改版甚至重新整理就變。

### 「等頁面好了」與「要截哪裡」是兩個設定

拆開之後才解得掉上面那個情況：

| 設定 | 做什麼 | 現值 |
|---|---|---|
| `WAIT_SELECTOR` | 等它出現＝骨架好了 | `iframe[src*="maxtv"]` |
| `SETTLE_MS` | 骨架好了再等多久，讓圖畫完 | `5000` |
| `SELECTOR` | 截哪個元素（null＝整頁） | `null` |
| `CROP` | 整頁之後裁哪一塊（null＝不裁） | **待校正** |

`WAIT_SELECTOR` 用 `src*="maxtv"` 而不是 id／class —— 路徑是 MAX 自己的，
比 hash 穩定得多，而且它是**外層** DOM 的元素，選得到。

⚠ **`SETTLE_MS` 是必要的，不是保險。** 跨 frame 等不到「圖畫好了」這個
事件 —— iframe 元素出現只代表容器在，不代表裡面畫完了。**截到空白圖表框
就把它調大。**

> 這跟前面移除 `WAIT_MS` 不衝突：那個是死設定（註解寫得煞有介事，但程式
> 從來沒讀過它）；`SETTLE_MS` 是真的接上線、也真的必要的那一個。

### 時間區間（4h）與介面語言（中文）

headless Chrome **沒有你瀏覽器的那些偏好**，所以預設拿到的是
「1d ＋ 英文」，跟自己開 MAX 看到的不一樣。兩件事分別處理：

| 要的 | 怎麼來的 | 設定 |
|---|---|---|
| 中文介面 | Chrome 的 `--lang`（UI 與 `navigator.language`）＋ `--accept-lang`（`Accept-Language` header）。**兩個都要**，網站才會回中文 | `LOCALE = 'zh-TW'` |
| 4h 區間 | 截圖前用 JS **按一下「4h」按鈕** | `CLICK_TEXTS = ['4h']` |

> 線索其實一直在 iframe 的 src 裡：`#symbol=USDT%2FTWD&interval=240&locale=zh_TW`
> —— `interval=240`（240 分＝4h）與 `locale=zh_TW` 是**瀏覽器當下的狀態**，
> 不是網址固定值。headless 沒有那些狀態，所以得自己弄出來。

⚠ **按鈕用文字找，不是 class。** MAX 的 class 全是 emotion 產生的 hash
（`css-g5y9jx`、`r-1loqt21`），改版就變；按鈕上的 `4h` 穩定得多。
文字在內層節點、可按的是外層帶 `tabindex` 的 div，所以 `clickByText()`
找到文字後要往上 `closest('[tabindex]')` 才按得到。

比對用的是 `trim()` 後的**完整相等**，所以不會誤中報價列的「24h 漲跌」
或區間列的 `1h`。

⚠ **按鈕要在 `SETTLE_MS` 之前按** —— 切換區間會重抓資料重畫，先按再等，
那段等待才涵蓋得到新區間的繪製。

按不到只記 warning 不中斷（`截圖前找不到要點的按鈕`）——
區間不對的圖總比沒有圖好。每次都是全新 profile，MAX 存在 localStorage
的選擇不會留下來，所以**每次截圖都得按一次**。

### 截圖很慢？查 SELECTOR，不是加等待時間

**沒有「等幾秒」的設定。** K 線圖是 JS 畫的，程式是去**等 `SELECTOR` 那個
元素出現**（`findNode()` → `waitUntilContainsElement()`，上限
`WAIT_TIMEOUT = 30 秒`）—— 慢的時候不會截到空白，快的時候也不用白等。

所以**慢的典型原因是選擇器選不到**：白等滿 30 秒 timeout，才退回整頁截圖。
網址錯掉時必然如此（404 頁上當然沒有 `#tv_chart_container`），
2026-10-03 回報的「有點久」就是這個。

兩個看得出來的訊號：

| 訊號 | 意思 |
|---|---|
| log 有「截圖選擇器等不到元素，改截整頁」 | 選擇器失效，這次白等了 30 秒 |
| 「截圖完成」的 `ms` 貼著 30000 | 同上 —— 這個欄位就是為了這件事加的 |

> ⚠️ 設定裡原本有 `WAIT_MS => 10000`，註解說是 `--virtual-time-budget`，
> **但程式從來沒讀過它、flag 也沒加** —— 調它不會有任何效果。
> 2026-10-03 移除。看到「可以調的等待時間」就去調，是這個死設定最大的害處。

### ⚠ 網址是 `/trades/`（複數），寫錯會**悄悄**截到 404 頁

```
✅ https://max.maicoin.com/trades/usdttwd
❌ https://max.maicoin.com/trading/usdttwd   ← 2026-10-03 之前設定裡寫的
```

寫錯不會噴錯 —— Chrome 正常啟動、正常截圖、正常送出，只是圖的內容是
MAX 自己的 404 頁（「您所搜尋的網頁不存在」）。**log 全綠，只有看截圖
才會發現**。

⚠ **MAX 對一般 HTTP 請求一律回 403**，所以 `curl` 試不出哪個路徑才對
（每個候選網址都回 403，分不出 404 與 200）。要確認只有一條路：
按匯率頁的「測試截圖」，看實際截到什麼。

同理，MAX 改版時這個設定會無聲失效 —— 走勢圖變成 404 截圖而沒有任何
錯誤訊息。發現「圖怪怪的」時先確認網址還在不在。

### ⚠ 測試截圖會過、09:00 排程卻沒圖：cron 與 php-fpm 不是同一個帳號

2026-10-04 正式機的實際 log：

```
09:00:03 WARNING 建不出 Chrome 工作目錄 → .../storage/app/chrome-profile/...
09:00:14 ERROR   截圖失敗 → touch(): .../storage/app/public/screenshot/rate-...png
                            because Permission denied   ms=10800
09:00:15 INFO    今日匯率已報出 {"with_chart":false}
```

**症狀的關鍵特徵：按「測試截圖」正常，但排程時段沒圖。** 兩者走的是
同一段程式（都是 `DailyRateService::captureChart()`），所以差別一定在
**執行環境**：

| | 身份 | 對 `storage/` |
|---|---|---|
| 按「測試截圖」 | 網頁 → **php-fpm 帳號** | 寫得進去 |
| 09:00 排程 | **cron 帳號** | ✗ 寫不進去 |

那兩個目錄是當初 php-fpm 建的、`0755` —— 擁有者以外不能寫。

⚠ **`ms=10800` 說明 Chrome 有起來、頁面載入了、圖也截好了**，只是在存檔
那一刻才被擋 —— 白跑 10.8 秒。這也反證了 `$HOME`／crashpad 那組修正是
有效的（見下一節），不要被 `Permission denied` 誤導成同一件事。

#### 程式這邊做的（不能取代維運修正）

1. **開 Chrome 前先確認寫得進去**（`canWriteTo()`）—— 不可寫就早退，
   不要跑完十秒才失敗
2. **log 寫明「現在是誰、目錄是誰的、權限幾」**，不然只看到
   `Permission denied` 根本不知道要改什麼
3. 工作目錄的**基底**改用 `0775`（子目錄仍是 `0700`）—— 兩個帳號同群組
   就能共用；但**已經存在的目錄權限改不了**，那是維運的事

> ⚠️ **程式改完截圖還是不會成功。** 上面三點只讓它早退與好查，
> 真正要修的是下面的權限／帳號。

#### 維運要修的

**實際採用：把那兩個目錄開 777**（需求方 2026-10-05 決定）。

```bash
cd /home/lv_cs_uat/cs
chmod 777 storage/app/public/screenshot storage/app/chrome-profile
```

⚠ **單層就夠，不要 `-R`。** 理由是這兩個目錄的使用方式：

| 目錄 | 底下的東西 | 誰建、誰刪 |
|---|---|---|
| `public/screenshot/` | 只有 png 檔，不再分層 | 建完送出就 `forget()` 刪掉 |
| `chrome-profile/` | **每次截圖新建一組** `<unique>/{profile,home}` | 誰建誰擁有，`removeWorkDir()` 用完整棵刪 |

`chrome-profile` 的子目錄雖然天天新建，但**從來不需要跨身份共用** ——
建它的人自己用、自己刪。只有**基底那一層**要兩邊都進得去，而基底不常
新建，所以 777 設一次就穩定。

刪檔案看的是**父目錄**的寫權限、不是檔案本身，所以 cron 建的 png
之後由 php-fpm 刪（或反過來）都沒問題。

> 更正統的做法是**讓排程用 php-fpm 同一個帳號跑**（Laravel 官方建議），
> 或兩帳號同群組 ＋ `chmod -R g+ws`。沒採用是因為這台只跑這個專案、
> 又是 uat，777 的代價可以接受 —— 權衡過的決定，不是不知道。

#### 後記（2026-10-05）：真正的根因是 umask，不是帳號

查佇列的事情時順手查清楚了，這台機器的權限設計**本來就是對的**：

```
drwxrwsr-x  apache  webdata   storage/app/claude-home
drwxrwsr-x  rduser  webdata   storage/logs
id rduser → groups=1001(rduser),1500(webdata)
```

php-fpm 是 `apache`、cron 是 `rduser`、**兩邊共用 `webdata` 群組**，
目錄 775 + setgid，crontab 那行還寫了 `umask 002`。照理說兩邊互通。

**卡住的原因是 `mkdir` 的 mode 會被 umask 遮罩**：

| 誰建的 | umask | `mkdir(0775)` 實際變成 |
|---|---|---|
| cron（rduser） | `002` | `0775` ✓ 群組可寫 |
| **php-fpm（apache）** | `022` | **`0755`** ✗ 群組不可寫 |

所以 php-fpm 建出來的目錄，rduser 就是進不去 —— 跟「帳號不同」無關，
**同群組也救不了，因為群組位元根本沒被寫進去**。

修法是建完之後明確 `chmod` 一次（**`chmod` 不受 umask 影響**），
已加在 `makeWorkDir()` 與 `ClaudeCodeMatcher::claudeHome()`。
往後新建的目錄一律 0775，與這台機器原本的 webdata + setgid 設計一致。

已經 `chmod 777` 的那兩個目錄不受影響（更寬鬆），不用改回來。

### ⚠ php-fpm 帳號跑 Chrome：要的是可寫的 `$HOME`，不是 user-data-dir

正式機（apache / php-fpm）第一次跑的實際錯誤：

```
Chrome process stopped before startup completed. Additional info:
mkdir: cannot create directory '/usr/share/httpd/.local': Permission denied
touch: cannot touch '/usr/share/httpd/.local/share/applications/mimeapps.list': No such file or directory
chrome_crashpad_handler: --database is required
```

**三件不同的事，`--user-data-dir` 一件都管不到**：

| 症狀 | 真正的原因 | 解法 |
|---|---|---|
| `mkdir /usr/share/httpd/.local` 被拒 | `/usr/share/httpd` 是 apache 的家目錄、不可寫。`mkdir`／`touch` 這種錯誤格式是 **shell 腳本**噴的 —— Linux 的 `google-chrome-stable` 是個 wrapper script，啟動前會做 desktop integration，它碰的是 **`$HOME`** | `envVariables` 給一個可寫的 `HOME`（連 `XDG_*` 一起指過去） |
| `touch .../applications/mimeapps.list` 說檔案不存在 | script 只 `mkdir $HOME/.local`，**沒有 `mkdir -p` 中間層** | 我們**預先把 `.local/share/applications` 建好** |
| `chrome_crashpad_handler: --database is required` | crashpad handler 被叫起來卻沒給 database 路徑 | `--disable-crash-reporter` + `--disable-breakpad`，不讓它啟動 |

> ⚠️ **教訓：第一次只改了 `--user-data-dir`，錯誤一字不差地又來一次。**
>
> 當時的推理是：「chrome-php 一定會傳 `--user-data-dir`
> （`BrowserProcess.php:405`），沒指定時用 `sys_get_temp_dir()`
> （同檔 497 行），所以根因是那個暫存目錄不可寫。」
>
> 前半段是對的（原始碼確實如此），**結論錯了** —— 錯誤訊息裡的路徑是
> `/usr/share/httpd/.local`，從頭到尾沒提 user-data-dir，也沒出現
> `Failed to create headless user data directory`。我是照著「維運回報的
> 那句話」去想原因，而不是照著「錯誤訊息實際說的路徑」。
>
> **錯誤訊息裡的路徑就是答案**：它說不能寫 `$HOME/.local`，要處理的就是
> `$HOME`。推測套件行為之前，先看它到底在抱怨哪個檔案。

所以每次截圖開一個這樣的工作目錄（`makeWorkDir()`）：

```
<USER_DATA_BASE>/<unique>/
├── profile/                          → --user-data-dir
└── home/                             → HOME 與 XDG_*
    └── .local/share/applications/    → 讓 wrapper script 寫得進去
```

基底是 `constants.DAILY_RATE.SCREENSHOT.USER_DATA_BASE`，預設
`storage/app/chrome-profile` —— `storage/` 本來就得讓 PHP 寫，權限對了這裡
就一定對，而且 `storage/app/.gitignore` 已忽略一切。

三個細節：

- **每次開新的**，不共用 —— Chrome 會對 profile 上鎖，共用的話兩張圖同時
  截就會卡住
- **用完整棵刪掉**（`removeWorkDir()`）—— 遞迴刪除前會先確認路徑真的在設定的
  基底底下，設定被改壞時不該變成刪別的東西
- **建不起來就回 `null`** 退回套件預設 —— 本來能跑的環境不該因為多了這段而壞掉

`envVariables` 是套件支援的選項（`BrowserProcess.php:127` 傳給 Symfony
Process 的 `$env`）。Symfony 的 env 是**我們設的優先、系統環境補上**
（`Process.php` 第 310-314 行用 `+=`），所以只覆寫這幾個、`PATH` 照樣繼承
—— 實測過 `PATH` 不會被清空。

### 兩個測試按鈕

| 按鈕 | 做什麼 | 什麼時候用 |
|---|---|---|
| **立即報價** | 把 9 點那套完整跑一次（等同 `rate:ask --force`） | 測整條迴路 —— 送出後可以直接在群組引用回覆，驗證回覆解析 |
| **測試截圖** | 只截一張發到群組，不動今天的紀錄 | 環境裝好 Chrome 後先確認截得到、而且截到的是想要的畫面 |

「立即報價」會覆蓋今天已經報過的那則，所以有確認視窗。

## `rate:check`：一次看清楚還缺什麼

```bash
php artisan rate:check
```

在要部署的那台機器上跑。純檢查、不改任何東西、不發訊息，隨時可以跑。

分兩種：

| | 缺了會怎樣 |
|---|---|
| **必要**（資料表、內部群組、題庫題目、chrome-php 套件） | 功能完全不會動 |
| **選用**（Chrome、中文字型、自訂公版） | 只是沒有圖／用預設公版，報價照常 |

狀態由 `DailyRateService::readiness()` 判斷，Command 只負責排版與
「該怎麼修」的文案 —— 之後要搬到後台頁面也不用動 Service。

### ⚠ 中文字型

純 CLI 的伺服器通常一個中文字型都沒有。MAX 的介面是中文的 ——
沒字型截出來會是**一排方框**，而且**不會有任何錯誤訊息**，
只會得到一張看起來很正常但全是豆腐塊的圖。

```bash
apt-get install -y fonts-noto-cjk
```

`chineseFontCount()` 用 `fc-list :lang=zh` 數。沒有 `fc-list` 指令時回 `-1`
當作「無法判斷」，不謊報成 0 —— 免得在沒有 fontconfig 的環境被誤導。

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
