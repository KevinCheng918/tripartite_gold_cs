# 小幫手驗 Gmail 憑證並做排程設定

> 需求（2026-10-19）：「我還有一個功能是 Gmail spider setting，可以驗證 Gmail
> 憑證，需要三項資料，client_id 也稱為帳號，client_secret 也稱為密碼，
> refresh_token，這三個，那我會需要小幫手幫忙驗證，資料是否正確，API 是否正常，
> 然後要製作 spider_setting」
>
> 補充（同日）：「spider_setting 會稱之為排程，Gmail 排程，ckpay 的排程」
>
> 兩個決定（問過需求方）：
> 1. `cashier_url` → **留空並註記請他自己補**（客服系統算不出來，寫錯會壞事）
> 2. 第三步 → **查信箱資料 ＋ 搜銀行信件**

```
@機器人 驗一下兆豐的 Gmail 排程
帳號 1234-abc.apps.googleusercontent.com
密碼 GOCSPX-xxxx
refresh token 1//0eXXXX
  ↓
📧 Gmail 憑證驗證

✅ ① 三組憑證填寫
✅ ② 換 access_token
　有效期 3599 秒
✅ ③ 連線 Gmail
　信箱：lv.payment@gmail.com（共 1,248 封）
　兆豐銀行入帳通知：最近 2 天有 3 封

🧩 排程設定（spider_setting）
{
    "index": "cashier",
    "cashier_url": "",
    "not_show": "overtime_at",
    "client_id": "1234-abc.apps.googleusercontent.com",
    "client_secret": "GOCSPX-xxxx",
    "refresh_token": "1//0eXXXX",
    "note": "1.收款帳戶屬於一次性收款帳號<請勿保存帳號>再次轉帳。2.訂單失效後請勿轉帳，避免資金遺失。"
}

⚠️ cashier_url 要您補上（我算不出來，它是那個站台自己的收銀台網址）。
```

⚠ **驗不過就不給排程設定** —— 跟主專案後台同一個規則
（`PaymentController::ajaxVerifyGmail()` 也是 `success` 才組設定），
免得把錯的憑證套進去。

## 「排程」就是 spider_setting

需求方的日常講法是「排程」／「排程設定」（主專案的語系檔也寫
`'spider_setting' => '排程設定'`）。兩支指令的 prompt 都明寫了這個同義詞，
而且特地講明「這裡的排程不是排班、也不是預約提醒」。

⚠ 加了這個同義詞之後要**反向測排程相關的講法不會被搶走**（已測，見下）：
「下週的排班幫我排一下」、「幫我排一個提醒」、「這個排程是不是掛掉了」、
「查一下虛擬機排程」都要落到預約提醒那一棒。

## 主專案已經有一支，但客服系統叫不到

`tripartite_gold/app/Services/GmailCredentialService.php` 做的正是這三步。
但它的對外入口是：

```
routes/web.php:227
Route::post('{payment}/ajax-verify-gmail')->middleware('permission:system:payment-edit')
```

後台 session 認證，而且要吃一個**既有的 `{payment}`**（第三步要它的
`bank_code`）。客服系統沒有 session 也沒有 payment id —— 叫不動。

所以這邊自己打 Google，但**判斷規則照抄那一份**：錯誤對照表是人家實測出來的。

## ⚠ 第二步才是真正在驗憑證，而且指得出是哪一組錯

這是整支最有價值的輸出。`client_id` 與 `client_secret` 錯的時候 Google 回的
`error` **都是 `invalid_client`**，只有 `error_description` 分得出來：

| Google 回的描述 | 其實是哪一組錯 |
|---|---|
| `The OAuth client was not found` | **client_id** |
| `Unauthorized` | **client_secret** |
| `The provided client secret is invalid` | **client_secret** |
| `Token has been expired or revoked` | **refresh_token** |
| `Bad Request` | **refresh_token** |
| （以上都不是）`error = invalid_grant` | refresh_token |
| （以上都不是）`error = invalid_client` | 只講「client_id 或 client_secret」 |

⚠ 比對前要先正規化（小寫、去頭尾空白、**去結尾句點**）：Google 同一種錯誤
有時給 `The OAuth client was not found.`、有時不帶句點。

⚠ **`exceptionBody()` 每次都要 `rewind()`** —— PSR-7 的 stream 讀過之後指標
會停在結尾，第二次 `getContents()` 只會拿到空字串。這支會被呼叫兩次
（寫 log 一次、組錯誤訊息一次），不倒回開頭的話後面那次解析不到
`error_description`，就**失去「指出是哪一組錯」的能力**（主專案踩過的坑）。

**實測**（真的打 Google，用亂填的憑證）：
`401 invalid_client` ＋ `The OAuth client was not found.` → 正確指向 `client_id`，
而且 log 與錯誤訊息**兩次都解析到描述**（證明 rewind 有效）。耗時約 100–280ms。

## ⚠ `index` 是 `cashier`，不是 `gmail`

Gmail 爬蟲不是一個獨立的 Apollo —— 它是**代收通道**（`index: cashier`）的
排程，三組憑證跟收銀台設定**放在同一份 `spider_setting` 裡**，各有各的讀者：

| 鍵 | 誰讀 |
|---|---|
| `client_id` / `client_secret` / `refresh_token` | `GmailSpiderBankDetail`（排程爬信） |
| `cashier_url` / `not_show` / `note` | 收銀台頁面 |
| `index: cashier` | `Apollo::get('cashier')` → `CashierApollo` |

所以產出的是**整份七個鍵**，不是只有三組憑證 —— `buildSpiderSetting()` 刻意
「不讀通道現有的設定，直接照建立通道時的基底重組」（同主專案），
避免把舊的殘留設定帶進來。

⚠ 這也是**它必須排在通用 spider_setting 指令之前**的原因：`Gmail` 不在
`config/apollo_fields.php` 的 64 個通道裡，順序反過來的話
「做一個 Gmail 的排程」會被通用那支接走，回一句「我的清單裡沒有 Gmail」。
（通用那支的 `NOT_FOUND_TEXT` 也補了一行指路，當成第二道保險。）

## ⚠ `cashier_url` 留空，不猜

主專案是算出來的：

```php
Converter::getCashierUrl()
  → 'https://cashier.' . config('database.connections.mysql.database')
    . (config('ui.default') == 'architect' ? '-gold-cashier.net' : '-share-approve.net')
```

讀的是**那個站台自己的資料庫名稱與後台皮膚** —— 客服系統兩個都沒有。

需求方選了「留空並註記」。理由：主專案的流程會把整份 `spider_setting` 換掉，
`cashier_url` 錯了**收銀台就連到別的站**。猜一個填進去是在拿收款開玩笑，
多一個手動步驟換「絕對不會寫錯」是划算的。

## 第三步：信箱資料 ＋ 銀行入帳通知

| 做什麼 | 為什麼 |
|---|---|
| `users/me/profile` → 信箱地址、總信數 | ⚠ **拿錯帳號的憑證一樣驗得過** —— 看得到信箱才能當場發現「這不是我要的那個信箱」 |
| 有講銀行才多搜一次該銀行的入帳通知 | 連「這個信箱真的收得到入帳通知」都驗到 |

⚠ **搜不到信不算失敗**（照主專案的規則）：那只代表這幾天沒有入帳，憑證本身
是對的。講成「驗不過」會讓人去改根本沒壞的設定。

⚠ **搜信那一步自己出錯也不算失敗**：信箱那一步已經過了，憑證確定是對的，
不該因為附加資訊失敗就說憑證壞了（回一句「搜信沒成功，不影響驗證」）。

⚠ 非 Gmail 的帳號（有 Google 帳號但沒開 Gmail）會回 `FAILED_PRECONDITION`
—— 要講明是「這個帳號沒有 Gmail 信箱」，不然看的人會以為憑證錯，
重新產一組一樣不會過。

### 銀行清單也是快照

`config/gmail_bank_queries.php` 是主專案 `GmailSpider::MAIL_SEARCH_QUERIES`
（12 家銀行的入帳通知主旨）＋ `MAIL_SEARCH_QUERIES_DAYS`（2 天）的快照，
銀行名稱取自主專案 `config/bankCode.php`。

⚠ 主旨要**原文照抄**，連空格都不要動 —— Gmail 的 `subject:` 是字面比對。

⚠ 主專案改了主旨之後這邊不會知道，而「搜不到信」在規則上算通過 ——
**看的人不會知道其實是主旨對不上了**。這是這份快照最大的弱點。

⚠ 614／616／619 三家農會共用同一個主旨「轉入交易成功」，不是複製貼上的錯。

⚠ 餵給模型的銀行清單只有這 12 家，**不讓它自己想銀行代碼**（台灣的銀行代碼
它記得不少，但能爬信的只有這幾家）；清單外的代碼在解析器就丟掉 ——
不丟的話會回「這家銀行沒有爬蟲」，而使用者根本沒提到那家銀行。

## 排在分派器第七棒

公告 → 餘點告警 → 查餘點 → 查虛擬機 → 查卡片 → 查匯率 → **Gmail 憑證** →
spider_setting → 預約提醒。

⚠ **上游六支全部實測讓路**（2026-10-14「發送餘點告警給 lv uat」被公告解析器
吃掉的教訓：那次只測了新的那支與隔壁，沒測上游）：

| 句子 | 結果 |
|---|---|
| 驗一下 Gmail 憑證，帳號… 密碼… refresh token… | ✅ gmail（三組憑證原文無誤） |
| 幫我做一個 Gmail 排程，帳號… | ✅ gmail |
| 這組**兆豐**的 Gmail 憑證幫我確認一下對不對… | ✅ gmail ＋ `bank=017` |
| 做一個 Ckpay 的**排程**，商戶號… | ✅ 讓給 spider（`Ckpay`） |
| Gz 的**排程設定**要填什麼 | ✅ 讓給 spider（`Gz`） |

反向也測了，這支不搶別人的話（10 句全回 `not_gmail`）：查餘點、查虛擬機、
發餘點告警、今天匯率、查卡片、提醒我發公告、設定補點通知群組、
排一個提醒、**下週的排班**、**做一個 Yongxin 的排程**。

加了「排程」同義詞之後，通用那支也反向測過 6 句（排班／排提醒／
「這個排程是不是掛掉了」／虛擬機排程／預約傳送訊息）全部 `not_spider`。

## 權限：`telegram_chat.gmail_credential`

「用指令驗 Gmail 憑證」。

⚠ **不沿用 `telegram_chat.spider_setting`**：那支只是查表組字串，這支會
**拿著憑證去打 Google**（外部請求，而且是金流入帳信箱的 OAuth 憑證）。
能做查表的人不一定該能拿憑證去打外部服務。

## Log 不寫憑證內容

照主專案的規則：只記 `error`、`error_description`、卡在哪一步。
憑證填錯是預期內的驗證結果，不是需要留證的異常 ——
而這是金流信箱的憑證，寫進 log 等於多一個外洩面。

## 新增/修改的檔案

| 檔案 | 說明 |
|---|---|
| `config/gmail_bank_queries.php` | **新增**。12 家銀行的入帳通知主旨 ＋ 搜幾天（主專案快照） |
| `app/Services/GmailCredentialService.php` | **新增**。三步驗證 ＋ `buildSpiderSetting()`，錯誤對照表照抄主專案 |
| `app/Services/AutoReply/GmailCredentialCommandParser.php` | **新增**。認意圖 ＋ 抓三組憑證與銀行代碼 |
| `app/Services/GmailCredentialCommandService.php` | **新增**。Telegram 這一側（驗權限、排版、驗過才給設定） |
| `config/constants.php` | 新增 `GMAIL_CREDENTIAL_COMMAND`；`SPIDER_SETTING_COMMAND` 補「排程」同義詞與指路 |
| `config/permissionMap.php` | 新增 `telegram_chat.gmail_credential` |
| `resources/lang/{tw,cn,en}/permission.php` | 權限名稱三語系 |
| `app/Services/TelegramCommandRouter.php` | 掛進第七棒（通用 spider_setting 之前） |

## 待釐清 / 沒驗到的部分

- **成功路徑沒有真憑證驗過**：第二步成功、第三步（信箱 profile、搜銀行信件）
  都只用假造的回傳測過排版。要拿一組真憑證在群組跑一次才算驗完。
- 搜信用的是**代收通道的那組憑證對該銀行的主旨** —— 如果一個信箱同時收多家
  銀行的通知，搜出來的數字只代表那一家。
- ⚠ **憑證會以原文出現在群組**（這是功能本身的性質：要複製那份 JSON，
  而且本來就是打進群組的）。Telegram 的訊息紀錄會留著這三組憑證 ——
  要不要「驗完自動刪訊息」是另一個題目，目前沒做。
