# 小幫手幫忙做 Apollo 的排程設定（spider_setting）

> 需求（2026-10-19）：「我最近都會有需要製作 Apollo spider-setting，我想要小幫手
> 能夠幫我製作，我會傳送資訊 tag 機器人」
>
> 後續補充（同日，依序）：
> - 「spider_setting 會稱之為排程，Gmail 排程，ckpay 的排程」
> - 「有時候會只需要代收，有時候只需要代付，有時候兩個都要，都要的話，都要給」
> - 「api_url 這些都要填寫上去，order_group 這個也要」
> - 「每一個都要 api_url 才對」
> - 「selfpay index 有 self_cashier，是用別人的收銀台」
> - 「同單多組，這個也是在講 order_group」
> - 「不開的話，就連同 order_group 都不要」
> - 「self 的 check_url 固定用 api_url ＋ /api/withdraw/info，ping_url 固定用
>   api_url ＋ /api/agent/info，self 代表自己的主系統，一定要對」
> - 「self 固定代收用 self_cashier，代付用 self」
> - 「`"not_show":"overtime_at"` 這個也要補上去 self cashier」
>
> 兩個決定（問過需求方）：欄位對照表**先掃 65 支 Apollo 存成設定檔**（不即時讀
> 主專案）、產出**回一段可複製的 JSON**（不寫進任何系統）。

```
@機器人 做一個 Ckpay 的代收排程，api 網址 https://pay.ck.com/api、商戶號 12345、金鑰 abcdef
  ↓
🧩 Ckpay 的排程設定（spider_setting）
index 刻意用小寫，主系統有幾處是字串直接比對，請不要改成大寫。

【代收】
{
    "index": "ckpay",
    "api_url": "https://pay.ck.com/api",
    "app_id": "12345",
    "app_key": "abcdef",
    "ping_url": ""
}

⚠️ 還缺 1 個必填欄位：
・ping_url

💡 通道的選填欄位，要用再自己加：
・cashier_url　・currency　・protocol_type

💡 代收頁面／對帳的選填欄位：
・amount_deduct　・company　・contact　・image_alert_seconds　・not_show　・note　・order_group
```

## 「排程」就是 spider_setting

需求方的日常講法是「排程」／「排程設定」（主專案的語系檔也寫
`'spider_setting' => '排程設定'`）。兩支指令的 prompt 都明寫了這個同義詞，
並特地講明「這裡的排程不是排班、也不是預約提醒」。

⚠ 加了同義詞之後**反向測過**：「下週的排班幫我排一下」、「幫我排一個提醒」、
「這個排程是不是掛掉了」、「查一下虛擬機排程」都要落到預約提醒那一棒。

## 代收與代付是兩份設定

`payment` 一列只有一種 `type`，所以一個通道兩種都做 ＝ 兩列、兩份
`spider_setting`。**兩種都要就回兩段 JSON**，不是把欄位混在一份裡
（混成一份會讓人以為貼一次就好）。

欄位怎麼分代收／代付？**靠方法名**：

| 範圍 | 方法 |
|---|---|
| `deposit` | `getPay*`、`verifySign`（回調）、`getRate`、收銀台相關 |
| `withdraw` | 方法名含 withdraw（`generateWithdrawRecord`、`sendWithdraw`、`checkWithdrawStatus`） |
| `shared` | 建構子、`initSet()`、`getBalance()` 等兩種都會走到的 |

一個鍵同時出現在兩邊就歸 `shared`。例：`Ckpay` 的 `check_url` 只有代付在讀、
`Bgp` 整支只有代付。

⚠ **沒講要哪一種時兩份都給**（`unsaid`）：猜一種的話，猜錯就是只給了他不要的
那一份，他得再問一次；兩份都給最多是多看一段。而且回覆會多一句說明
「您沒說代收還是代付，兩種都給您（是兩份設定，不是一份）」——
不講的話他會納悶為什麼跑出兩段，甚至以為要兩份都貼。

### `TYPES`：哪些通道只做一種

`config('apollo_fields.TYPES')` 記了 31 個只做代收、12 個只做代付、21 個兩種
都做。判斷依據是**有沒有那一種方法**。

⚠ 存在的理由是**不要產出沒用的東西**：有人問「Sbs 的代收排程」時，照樣組一份
出來的話，他會拿著一份這個通道根本不做的設定去貼。現在會回
「Sbs 沒有代收這一種，我這份對照表裡它做的是代付」。

⚠ 回覆要**講出它做的是哪一種** —— 只說「不支援」的話，他不知道是自己記錯了
通道，還是記錯了方向。

⚠ 上游常把代收與代付當成兩個產品（`Teimshen` 只做代付、`TeimshenCashier` 只做
代收），所以「同一家只有一種」是常態，不是掃錯。

⚠ 對照表沒分類到的通道（主專案加了新的）**兩種都給 ＋ 寫一筆 warning**，
不要回「不支援」—— 那會變成「新通道永遠做不出排程」，而使用者看不出是對照表
過期了。

## ⚠ 第一版漏了兩整類欄位

需求方抓到的（「api_url 這些都要填寫上去，order_group 這個也要」）：

| 漏掉的 | 原因 |
|---|---|
| `BASE`（`api_url`、`protocol_type`、`cashier_url`） | 設定檔裡有，但**服務根本沒讀它** —— 只組了通道自己的欄位 |
| `EXTRA`（`order_group`、`amount_deduct`、`company`、`contact`、`image_alert_seconds`、`not_show`、`note`） | 掃描只看 `app/Apollos`，而這些是 Controller／Trait／Blade 在讀的 |

`api_url` 是 `Apollo.php` 基底在讀的，**每一個通道、代收代付都要**
（需求方：「每一個都要 api_url 才對」）。

找出 `EXTRA` 的方法：掃**所有** decode 過 `spider_setting` 的檔案（不只 Apollo），
找 `Arr::get($setting, 'x')` / `$setting['x']` / `$this->settings['x']`，
再跟現有對照表 diff。一般化：**同一份資料有好幾種讀法時，只掃其中一種就是漏**。

## ⚠ 必填一律寫進 JSON，選填沒給就不要寫

| 分類 | 進 JSON 嗎 | 列進「還缺」嗎 |
|---|---|---|
| `required` | **一律寫，沒給就留空字串** | 會 |
| `optional` | **只有給了才寫** | 不會（另外列成清單） |

必填留空字串是為了「拿到一份可以直接填的範本」（需求方要的），
而選填**絕對不能**寫空字串進去，兩個理由：

1. `Arr::get($setting, 'currency', 'TWD')` 遇到 `"currency": ""` 回的是空字串
   **不是** `TWD` —— 空值會把程式裡的預設值蓋掉
2. 需求方 2026-10-19：「不開的話，就連同 order_group 都不要」——
   開關型欄位留一個空值在設定裡，下一個看的人會以為有設過

⚠ 同一個鍵在兩處分類不同時**必填優先**（`cashier_url` 在 `BASE` 是選填、
在 `Totoken` 是必填）—— 少問一個必填會讓人貼上一份跑不起來的設定，
多問一個選填只是多打一次字。

⚠ 選填分兩組列（**通道的** vs **代收頁面／對帳的**）：混成一張九行的清單，
看的人會以為那些頁面欄位也要去問上游。

## ⚠ 開關型欄位的值不能照抄人話

`order_group`（口語「**同單多組**」）＝ 通道接受一次送多筆匯款帳號。
主系統判斷它只用 `filled()`／`blank()`。

實測「同單多組要開」被模型抄成 `"order_group": "開"` —— **能跑，但資料是錯的**，
下一個看到的人會以為這裡可以填中文。所以 `config('apollo_fields.FLAGS')` 列出
開關型欄位，解析器一律改寫成 `"1"`，**不靠 prompt 自律**。

實測四種模型輸出（`"開"`／`"1"`／`"要"`／`null`）→ 產出
（`"1"`／`"1"`／`"1"`／不寫這個 key），非開關欄位不受影響。

反向也測了：「這家不支援同單多組」→ 模型不填 → key 不出現 ✅

## ⚠ `index` 要 snake_case，不是 Apollo 的類別名

這是整支最容易寫錯、而且**錯了不會報錯**的地方。

`Apollo::get($code)` 解析類別時會先 `Str::studly($code . 'Apollo')`，
所以 `ckpay` 與 `Ckpay` 都找得到 `CkpayApollo` —— 寫成大寫，通道照樣叫得起來。

但主專案另外有幾處是**把 `index` 當字串直接比對**：

| 位置 | 寫法 |
|---|---|
| `Models/Payment::isSelfHold()` | `=== 'self_hold'` |
| `Payments/Payment.php:1409,1433`（轉單費率） | `Str::is('self*', $spiderIndex)` |
| `Traits/AvatarCallback.php:2731`、`Payment.php:731` | `case 'chno'` |

寫成 `SelfHold` 的話通道能用、開單能開，但**自家轉單會被收錯費率** ——
那種錯不會報，要等對帳才發現。所以產出一律 `Str::snake($code)`，
回覆裡還加一行 `INDEX_NOTE` 叫人不要手動改成大寫（印在標題底下**只印一次**，
不是每一段都印）。

**驗證**：64 個代號全部 `Str::studly(Str::snake($code) . 'Apollo')` 回得去
原本的檔名，沒有例外；`self_hold` 與 `chno` 這兩個觀察到的真實值正好就是
snake_case。

## 自家主系統（self）那一組

需求方 2026-10-20 的三條：
1. 「self 固定代收用 self_cashier，代付用 self」
2. 「self 的 check_url 固定用 api_url ＋ /api/withdraw/info，
   ping_url 固定用 api_url ＋ /api/agent/info，self 代表自己的主系統，
   **一定要對**」
3. 「`"not_show":"overtime_at"` 這個也要補上去 self cashier」

### 代收與代付用不同的 index（`SCOPE_INDEX`）

不管他說的是 `self` 還是 `self_cashier`，產出時都按這張表換：

| 要哪一種 | 實際 index |
|---|---|
| 代收 | `self_cashier` |
| 代付 | `self` |

⚠ 兩個代號的**欄位本來就不一樣**（`self` 有代付的 `check_url`、
`self_cashier` 沒有），用錯就接不起來。

⚠ 所以 `supportedTypes()` 遇到有 `SCOPE_INDEX` 的代號要回「兩種都做」——
看的是「這組代號合起來」做什麼，不是單一個代號（`SelfCashier` 在 `TYPES` 裡
只做代收，但問「self 的代付」是合理的）。

⚠ 每一段的標題都帶上 index（`【代付】self`）—— 兩段長得很像，不標會貼錯。

### 能算出來的就不要讓人手打（`DERIVED`）

| 欄位 | 值 | 主專案哪裡在用 |
|---|---|---|
| `check_url` | `api_url` ＋ `/api/withdraw/info` | `routes/api.php` 的 `withdraw/info`（`PaymentController@withdrawInfo`），`SelfApollo::checkWithdrawStatus()` 打它 |
| `ping_url` | `api_url` ＋ `/api/agent/info` | `routes/api.php` 的 `agent/info`（`UserController@apiAuthInfo`），`SelfApollo::getBalance()` 打它 |

⚠ **手打錯一個字母，代付查單會一直查不到、看起來像上游沒回應** ——
這就是「一定要對」的意思。

⚠ **會蓋掉對方給的值**（固定值與算出來的以我們為準），但一定要在回覆裡
列出「這幾個我自己填的」—— 不講的話他會以為是自己打過的，下次就不會再給、
也不會發現被換掉。實測給一個拼錯的 `.../infoo` 會被換成正確的。

⚠ 結尾斜線要處理：`rtrim($apiUrl, '/')` 再接，不然變成 `https://x//api/...`。

⚠ `api_url` 沒給就算不出來 —— 那幾個欄位留空、跟著列進「還缺」，但要**另外
講一句**「check_url、ping_url 不用給我，補上 api_url 我會自己算」。
少了這句，他看到「還缺 api_url、check_url、ping_url」會以為要去問三個值。

### 固定值（`FIXED`）

`self_cashier` 的 `not_show` 固定 `overtime_at`（收銀台不顯示逾時時間）。
主專案建立代收通道時的基底（`PaymentController::resolveSpiderSetting()`）
與 Gmail 憑證產出的 `CASHIER_NOT_SHOW` 都是這個值。

⚠ `FIXED` 與 `DERIVED` 都只在**那一種真的有這個欄位**時才填
（`self_cashier` 是代收、沒有 `check_url`，就不該冒出一個 `check_url`）。

### 口語別名（`ALIASES`）

需求方把這一組叫「**selfpay**」，但對照表上沒有這個代號（檔案是
`SelfApollo`）—— **實測模型遇到「selfpay 的排程」會回 `channel: null`**，
變成「我聽不懂你要哪個通道」。

修法是兩道：別名餵進 prompt（讓模型對到 `Self`）＋ 比對時再查一次別名
（萬一模型照抄別名）。兩道都做，因為漏接的下場是功能叫不出來 ——
跟 2026-10-13 拿掉關鍵字粗篩同一個理由。

實測五種講法（`self`／`self 代付`／`self 代收`／`selfpay 兩種都要`／
`self_cashier`）全部認得，模型照抄 `selfpay` 時服務這一側也接得住。

## 幾個代號光看名字猜不到（`NOTES`）

| 代號 | 說明 |
|---|---|
| `self` | 自家系統轉單（個人銀行），收銀台是自己的 |
| `self_cashier` | 自家系統轉單，但自行包裝收銀台畫面 ＝ **用別人的收銀台**（需求方確認） |
| `self_hold` | **代付專用**：開單直接進「等待重送」，不讀任何設定 |
| `cashier` | 建立代收通道時的預設 index，Gmail 爬蟲也掛在它底下 |

有 `NOTES` 的代號，回覆會在標題底下多印一行說明。

⚠ `SelfHold` 一個欄位都不讀，所以它的產出只有 `index` ＋ `api_url`（基底的）
—— 那是正確的，不是掃漏了。

## 欄位清單是快照，不是即時的

`config/apollo_fields.php` 掃的是 `tripartite_gold/app/Apollos/` 的 65 支檔案。

⚠ **客服系統讀不到主專案的檔案**（不同部署，唯一管道是站台的 HTTP API），
所以沒辦法做成即時讀取。主專案新增通道或改欄位名之後要**重新掃一次**，
不然小幫手會少列欄位（而使用者會以為填完了）。

⚠ 認不出通道時的回覆特地講明「可能是主系統新增、我這份對照表還沒更新」——
不講的話，看的人會以為自己打錯字，一直重打。

## 模型只做對應，不想欄位名

| 步驟 | 模型要做的事 |
|---|---|
| `detect()` | 這句話是不是要做排程、哪一個通道、代收還是代付 |
| `extract()` | 把「商戶號 12345」對到 `app_id: "12345"` |

⚠ **模型不負責知道有哪些欄位**，欄位名一律來自對照表。讓它自己想的話，
它會編出 `merchant_key` 這種 Apollo 根本沒讀的鍵 —— 而這份 JSON 是要貼進
金流設定的，多一個沒人讀的鍵會讓人以為設好了。

兩道防線：`extract()` 的 schema 把每個欄位**明寫成 property**（不是自由的
object）；`normalizeExtract()` 再把清單外的鍵一律丟掉。

分兩步問的理由：一次問完的話，prompt 得塞進 64 個通道的全部欄位，
又長又容易張冠李戴。

## 驗證

**代收／代付的判斷（真的呼叫模型，8/8）**：代收排程、代付排程、「代收代付
兩個都要」、沒講、「出款的排程」、「收款的排程」、「Sbs 的代收排程」、
「入款跟出款」—— 通道與 scope 全對。

**欄位對應**：代收＋代付合併的 15 個欄位清單一次問，7 個值全對且網址的 `&`
原樣保留；「上游手續費 15」第一次漏掉 `amount_deduct`，補了講法對照之後對到。

**回覆排版 15 種分支**：代收／代付／兩種／沒講／只做代付的通道被問代收／
一個欄位都不讀的通道／有 NOTES 的／只有 shared 的／`cashier_url` 在兩處
分類不同的（Totoken）／通道不在清單／沒指名通道／不是這支指令／對應失敗／
沒權限。

**分派器**：排第八棒（Gmail 之後）。上游七支實測讓路。

## 新增/修改的檔案

| 檔案 | 說明 |
|---|---|
| `config/apollo_fields.php` | 欄位對照表。`BASE`／`EXTRA`／`ALIASES`／`SCOPE_INDEX`／`DERIVED`／`FIXED`／`FLAGS`／`TYPES`／`NOTES` ＋ 64 個通道（各自分 shared／deposit／withdraw） |
| `app/Services/AutoReply/SpiderSettingCommandParser.php` | `detect()` 認通道與代收／代付、`extract()` 對欄位、開關型欄位改寫成 `"1"` |
| `app/Services/SpiderSettingCommandService.php` | 合併 BASE＋EXTRA＋通道、每一種組一段 JSON、列缺漏、驗權限 |
| `config/constants.php` | `SPIDER_SETTING_COMMAND`（兩段 prompt ＋ 全部回覆文字） |
| `config/permissionMap.php` | `telegram_chat.spider_setting` |
| `resources/lang/{tw,cn,en}/permission.php` | 權限名稱三語系 |
| `app/Services/TelegramCommandRouter.php` | 掛進第八棒 |

## 待釐清

- `Trc20Usdt` → `trc20_usdt`、`TwoOneCashier` → `two_one_cashier` 這兩個代號
  **沒有在主專案裡看到實際值**，是從類別名推出來的（round-trip 回得去，
  所以 Apollo 一定解析得到）。真的要用時建議先去 `payment` 表確認既有資料的
  `index` 怎麼寫。
- 對照表要**人工維護**：主專案改了欄位，這邊不會自動知道，目前沒有過期提醒。
- `TYPES` 的判斷是方法名，**不是上游合約** —— 如果某個 Apollo 的代付是靠別的
  方法名實作的，會被判成「只做代收」。目前 64 支都對得起來。
