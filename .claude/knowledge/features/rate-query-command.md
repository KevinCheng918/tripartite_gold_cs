# 用 Telegram 指令看今天的匯率補點訊息

> 需求（2026-10-14）：「我會輸入『今天匯率多少』，直接傳現有的匯率補點訊息就好」
>
> 決定：**有幾個系統就列幾則**（不必指名站台）

```
@機器人 今天匯率多少
  ↓
【LV】（＋繳款圖）
10/14 USDT 當前均價 32.5
付款地址：TYwab…（點擊可複製）
註1：實際補點會依照付款顆數乘上匯率，今日預存5萬 = 1539 U
…
```

拿到就能直接複製貼給客戶 —— 這正是需求方要的「現有的」那一份。

## ⚠ 訊息不是這支組的

走 `PaymentConfigService::buildTopupMessage($config, $rate)` —— 跟**餘點告警
的第二則**、**繳款設定頁的測試發送**同一支。

自己排一份的話，哪天公版的變數換了只會改到其中一邊，而這是**會被複製貼給
客戶**的內容。

## 為什麼一個系統一則

補點訊息跟著**繳款設定**走，而繳款設定是一個系統一份 —— 不同系統的收款方式
不一樣（付款地址、保底門檻、作業天數都可能不同）。併成一則會讓人貼錯給客戶。

每一則前面標 `【系統名】`，就是為了貼之前能一眼確認拿對了。

查詢用 `PaymentConfigRepository::getAllActive()`（新加的）——
「啟用」的判斷留在 SQL 裡，跟既有的 `getActiveBySystem()` 同一套，
不在 Service 裡比 `status === 1`。

## 三種情況各有各的話

| 情況 | 回什麼 |
|---|---|
| 匯率已定 ＋ 有公版 | 每個系統一則補點訊息（含圖） |
| **匯率還沒決定** | 「今天的匯率還沒決定 🙏 決定之後再問我一次」 |
| 有匯率但**沒人填公版** | 「今日匯率是 32.5，但目前沒有任何系統填了補點訊息公版」 |

⚠ **匯率還沒決定是正常狀態**（每天早上報價之前都是），要講清楚是「還沒決定」
而不是讓人以為系統壞了。

⚠ 第三種一定要**把匯率講出來**。只回一句「沒有資料」會讓人以為連匯率都沒設，
跑去看匯率頁卻發現好好的 —— 真正要去補的是繳款設定的公版。

⚠ 匯率還沒決定時**不組補點訊息**（`buildTopupMessage()` 自己就會擋）：
沒有匯率的補點訊息對客戶沒有意義（他不知道要匯多少），附一個過期的昨日匯率更糟。

## 圖與文字一起送：`sendPhotoToThread()`

`SupportGroupService` 原本只有 `sendPhoto()`（依通知類型分流到設定好的話題）
與 `sendToThread()`（純文字、指定話題）。指令要回在**使用者剛才說話的那個
話題**而且要帶圖，兩支都不合用，所以補了 `sendPhotoToThread()` ——
它只是 `sendToThread()` 的圖片版（`botService->sendPhoto()` 本來就吃 threadId）。

⚠ 文字當**圖說**一起送，不分兩則 —— 分開的話群組裡會看到圖跟文字被別人的
訊息插開。

⚠ **Telegram 圖說上限 1024 字，超過整則會失敗。** 超長時退回純文字（不附圖）
—— 寧可少一張圖，也不要整則發不出去。

## 解析器只回 yes/no

這一支**不抽任何欄位**：問的就是「今天」那一份，沒有站台、沒有時間、沒有
對象要認。所以 schema 只有一個 `intent`。

⚠ prompt 特別點出兩種容易混的：

- **「等一下跟客戶說今天匯率」是預約傳訊息**，不是現在要看 → `not_rate`
- 問站台餘點／虛擬機／卡片進度 → `not_rate`

## 權限：`telegram_chat.rate_query`

介面上：**帳號管理 → 編輯帳號 → 權限設定 → Telegram 客服 → 用指令查今日匯率**。

⚠ 回的是**客戶實際會收到的那一則**（含匯款地址與繳款圖），拿到就能直接貼給
客戶 —— 所以不跟「看一下匯率報價」混為一談，自己一項。

## 排在分派器第六棒

```
公告 → 發餘點告警 → 查餘點 → 查虛擬機 → 查卡片 → 【查今日匯率】 → 預約提醒
```

前面有**五支**都必須讓路，整條鏈實測（「今天匯率多少」）：

| 關卡 | 結果 |
|---|---|
| 1 公告 | `not_broadcast` ✓ |
| 2 發餘點告警 | `not_alert` ✓ |
| 3 查餘點 | `not_credit` ✓ |
| 4 查虛擬機 | `not_vm` ✓ |
| 5 查卡片 | `not_task` ✓ |
| 6 查匯率 | **`rate`** ✓ |

反向（匯率解析器要讓給別支）：查餘點／查卡片進度／排提醒／「等一下跟客戶說
今天匯率」全部 `not_rate` ✓。

## 檔案

| 檔案 | 動作 |
|------|------|
| `app/Services/RateQueryCommandService.php` | 新增 |
| `app/Services/AutoReply/RateQueryCommandParser.php` | 新增 |
| `app/Services/SupportGroupService.php` | 加 `sendPhotoToThread()` |
| `app/Repositories/PaymentConfigRepository.php` | 加 `getAllActive()` |
| `app/Services/PaymentConfigService.php` | 轉接 `getAllActive()` |
| `app/Services/TelegramCommandRouter.php` | 掛上第六棒 |
| `config/constants.php` | `RATE_QUERY_COMMAND` 區塊 |
| `config/permissionMap.php` ＋ `resources/lang/{tw,cn,en}/permission.php` | 新權限 |

沒有 migration、沒有新 route、沒有 callback（純讀，沒有按鈕）。

## 已知缺口

- 本機今日匯率未定，所以「有匯率」那條是**用 mock 的匯率**跑的 ——
  組出來的是真實繳款設定的公版與真實的繳款圖，排版驗過。
- 本機只有一個系統有啟用的繳款設定，**多系統多則**那條沒有真資料跑過
  （邏輯就是迴圈，每則各自呼叫同一支）。
- 沒有真的在群組發送過。
