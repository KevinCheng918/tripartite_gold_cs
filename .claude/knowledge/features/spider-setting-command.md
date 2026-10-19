# 小幫手幫忙做 Apollo 的 spider_setting

> 需求（2026-10-19）：「我最近都會有需要製作 Apollo spider-setting，我想要小幫手
> 能夠幫我製作，我會傳送資訊 tag 機器人」
>
> 兩個決定（問過需求方）：
> 1. 欄位對照表 → **先掃 65 支 Apollo 存成設定檔**（不即時讀主專案）
> 2. 產出 → **回一段可複製的 JSON**（不寫進任何系統）

```
@機器人 做一個 Ckpay 的 spider_setting，商戶號 12345、金鑰 abcdef
  ↓
🧩 Ckpay 的 spider_setting

{
    "index": "ckpay",
    "app_id": "12345",
    "app_key": "abcdef"
}
index 刻意用小寫，主系統有幾處是字串直接比對，請不要改成大寫。

⚠️ 還缺 3 個必填欄位：
・api_url
・check_url
・ping_url

💡 這些是選填（沒填會用程式裡的預設值）：
・currency

把缺的補上再傳一次，我重做一份給您。
```

## ⚠ `index` 要 snake_case，不是 Apollo 的類別名

這是整支最容易寫錯、而且**錯了不會報錯**的地方。

`Apollo::get($code)` 解析類別時會先 `Str::studly($code . 'Apollo')`，
所以 `ckpay` 與 `Ckpay` 都找得到 `CkpayApollo` —— 寫成大寫，通道照樣叫得起來。

但主專案另外有幾處是**把 `index` 當字串直接比對**：

| 位置 | 寫法 |
|---|---|
| `Models/Payment::isSelfHold()` | `Arr::get($setting, 'index') === 'self_hold'` |
| `Payments/Payment.php:1409,1433`（轉單費率） | `Str::is('self*', $spiderIndex) && !Str::is('self_hold', …)` |
| `Traits/AvatarCallback.php:2731`（USDT 回調） | `switch (…) { case 'chno': }` |
| `Payments/Payment.php:731` | 同上 `case 'chno'` |

寫成 `SelfHold` 的話，通道能用、開單能開，但**自家轉單會被收錯費率** ——
那種錯不會報，要等對帳才發現。所以產出一律 `Str::snake($code)`，
而且回覆裡特地加一行 `INDEX_NOTE` 叫人不要手動改成大寫。

`self_hold` 與 `chno` 這兩個觀察到的真實值，正好就是 snake_case，
也反過來證實了這個慣例。

**驗證**：64 個代號全部 `Str::studly(Str::snake($code) . 'Apollo')` 回得去
原本的檔名（`app/Apollos/{$code}Apollo.php` 存在），沒有例外。

## 欄位對照表是快照，不是即時的

`config/apollo_fields.php` 是掃 `tripartite_gold/app/Apollos/` 的 65 支檔案
產出來的：每一支裡的 `Arr::get($this->setting, 'xxx')` 就是它會讀的欄位，
**有給預設值的算選填、沒給的算必填**。

- `BASE`：`Apollo.php` 自己讀的（必填 `api_url`，選填 `protocol_type`、`cashier_url`）
- `CHANNELS`：64 個通道各自的（`Apollo.php` 不算通道，`Cashier` 等算）

⚠ **客服系統讀不到主專案的檔案**。兩個專案是不同的部署，唯一的管道是站台
的 HTTP API —— 所以沒辦法做成即時讀取。主專案新增通道或改欄位名之後，
這份表要**重新掃一次**，不然小幫手會少列欄位（而使用者會以為填完了）。

⚠ 認不出通道時的回覆**特地講明「可能是主系統新增、我這份對照表還沒更新」**
——不講的話，看的人會以為自己打錯字，一直重打同一個代號。

## 兩步問模型，各司其職

| 步驟 | 模型要做的事 | 為什麼分開 |
|---|---|---|
| `detect()` | 這句話是不是要做 spider_setting、是哪一個通道 | 一次問完的話，prompt 得塞進 64 個通道的全部欄位，又長又容易張冠李戴 |
| `extract()` | 把「商戶號 12345」對到 `app_id: "12345"` | prompt 只帶**那一個通道**的欄位清單 |

⚠ **模型不負責知道有哪些欄位**，欄位名一律來自對照表。讓它自己想的話，
它會編出 `merchant_key` 這種 Apollo 根本沒讀的鍵 —— 而這份 JSON 是要貼進
金流設定的，多一個沒人讀的鍵會讓人以為設好了。

兩道防線：
1. `extract()` 的 schema 把每個欄位**明寫成 property**（不是自由的 object），
   模型只能填清單上的鍵
2. `normalizeExtract()` 再把清單外的鍵一律丟掉

實測（真的呼叫 CLI）：

| 說法 | 對到 |
|---|---|
| 商戶號 12345、金鑰 abcdef | `app_id` / `app_key` |
| hash key 是 AAA，hash iv 是 BBB，商戶號 M001 | `hash_key` / `hash_iv` / `merchant_id`（三個都對，沒有張冠李戴） |
| 帳號 catuser、密碼 catpass | `api_username` / `api_password` |
| api 網址 `…?a=1&b=2`、回調 …/ping、查詢 …/check、幣別 TWD | 六個全對，網址的 `&` 原樣保留 |

## 一個欄位都沒對到也照樣回清單

「Ckpay 要填什麼」這種只是在問欄位的話，走的是同一條路（`$values` 空陣列）。
只回一句「沒讀到資料」的話，使用者看不到這個通道到底要哪些欄位，
而那正是他最需要的。

但**模型掛掉要跟「沒給值」分開講**（`EXTRACT_FAILED_HINT`）：不說的話，
使用者會以為自己剛才打的商戶號格式不對，然後把同一串重打好幾次。

## 排在分派器第六棒

`TelegramCommandRouter` 的順序：公告 → 餘點告警 → 查餘點 → 查虛擬機 →
查卡片 → 查匯率 → **spider_setting** → 預約提醒（看不懂的歸宿）。

⚠ **上游六支全部實測讓路**（這是 2026-10-14「發送餘點告警給 lv uat」被公告
解析器吃掉的教訓：那次只測了新的那支與隔壁，沒測上游）：

| 句子 | 公告 | 告警 | 餘點 | 虛擬機 | 卡片 | 匯率 | spider |
|---|---|---|---|---|---|---|---|
| 做一個 Ckpay 的 spider_setting，商戶號… | 讓路 | 讓路 | 讓路 | 讓路 | 讓路 | 讓路 | ✅ `Ckpay` |
| 幫我產一份 Gz 的 spider setting，hash key… | 讓路 | 讓路 | 讓路 | 讓路 | 讓路 | 讓路 | ✅ `Gz` |
| 我要新增一個金流通道的設定 | 讓路 | 讓路 | 讓路 | 讓路 | 讓路 | 讓路 | ✅ `unknown`（問哪一個） |

反向也測了 —— 這支**不會搶走別人的話**（9 句既有指令全部回 `not_spider`）：
查餘點、確認 Rich 還剩多少點、查虛擬機規格、發餘點告警、今天匯率多少、
查 @帳號 卡片進度、預約傳送訊息、明天提醒我發公告、設定補點通知群組。

## 權限：`telegram_chat.spider_setting`

「用指令做 spider_setting」。

⚠ 這支**只產出文字、不寫任何系統**，但照樣要權限，不是「唯讀就人人可用」：

1. 那段 JSON 是要貼進主系統金流設定的，貼錯直接影響收款
2. 它會把對方給的**商戶號、金鑰原文列回群組** —— 等於把金流憑證印在聊天室

⚠ 權限檢查擺在 `detect()` **之後**（確定是這支指令才檢查），
跟其他幾支指令一致 —— 不然別人在群組聊天會一直被回「你沒有權限」。

## 新增/修改的檔案

| 檔案 | 說明 |
|---|---|
| `config/apollo_fields.php` | **新增**。掃主專案 65 支 Apollo 得到的欄位對照表（BASE ＋ 64 通道，各有 `required`/`optional`） |
| `app/Services/AutoReply/SpiderSettingCommandParser.php` | **新增**。`detect()` 認通道、`extract()` 對欄位 |
| `app/Services/SpiderSettingCommandService.php` | **新增**。查對照表、組 JSON、列缺漏、驗權限 |
| `config/constants.php` | 新增 `SPIDER_SETTING_COMMAND`（兩段 prompt ＋ 全部回覆文字） |
| `config/permissionMap.php` | 新增 `telegram_chat.spider_setting` |
| `resources/lang/{tw,cn,en}/permission.php` | 權限名稱三語系 |
| `app/Services/TelegramCommandRouter.php` | 掛進第六棒 |

## 待釐清

- `Trc20Usdt` → `trc20_usdt`、`TwoOneCashier` → `two_one_cashier` 這兩個代號
  **沒有在主專案裡看到實際值**，是從類別名推出來的（round-trip 回得去，
  所以 Apollo 一定解析得到）。真的要用這兩個通道時，建議先去 `payment` 表
  確認既有資料的 `index` 怎麼寫。
- 對照表要**人工維護**：主專案改了欄位，這邊不會自動知道。
  目前沒有「過期提醒」機制。
