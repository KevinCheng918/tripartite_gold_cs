# 用 Telegram 指令查站台餘點

> 需求（2026-10-13）：「我要再做一個功能，我如果說『幫我查一下LV_UAT餘點』，
> 要先做二次確認，然後去執行站台同步，回報站台點數」

在內部群組 tag 機器人說「幫我查一下 LV_UAT 餘點」，機器人先回一則確認，
按下【查詢】才去主系統同步一次，然後回報點數與同步時間。

```
@機器人 幫我查一下 LV_UAT 餘點
  ↓ 粗篩（含「餘點／點數」才跑模型）
  ↓ AI 解析 → intent=credit, station=LV_UAT
  ↓ 權限（telegram_chat.station_credit）
  ↓ 站台名稱完全相符
  ↓
🔍 要查 LV_UAT 的餘點嗎？            【查詢】【取消】
  ↓ 按【查詢】（再驗一次權限）
正在同步 LV_UAT 的餘點，請稍候…      ← 同一則訊息改掉
  ↓ StationService::syncInfo()
💰 LV_UAT
餘點：16390.94
同步時間：2026-10-13 14:05:22
```

## 檔案

| 檔案 | 角色 |
|------|------|
| `app/Services/StationCreditCommandService.php` | 新增。粗篩、解析、權限、找站台、確認、同步、回報 |
| `app/Services/AutoReply/StationCreditCommandParser.php` | 新增。一句話 → `intent` ＋ `station` |
| `app/Services/TelegramCommandRouter.php` | 新增。tag 機器人的指令分派（見下方「為什麼抽出來」） |
| `app/Services/ScheduledReminderService.php` | `handleMessage(payload)` → `handle($body, $threadId, $message)`；`stripMention()`／`isAllowedThread()`／`NOTICE_TYPE` 搬到分派器 |
| `app/Http/Controllers/Webhook/TelegramWebhookController.php` | 訊息改走分派器；callback 多一條 `sc:` |
| `config/constants.php` | `STATION_CREDIT_COMMAND` 區塊（prompt 與全部對外文案） |

沒有新 route、新語系檔、新 migration、新權限 keyword —— 這支指令完全
長在既有的東西上面。

## 為什麼要二次確認

看起來是唯讀查詢，實際上**不是**：`StationService::syncInfo()` 會打主系統 API，
並把 `credits` / `settings` / `synced_at` **寫回資料庫**，而且外部 API 要花幾秒。

所以它跟後台站台頁的「同步」按鈕是同一件事，不是「看一下數字」。

## 權限：`telegram_chat.station_credit`（獨立的一項）

介面上叫什麼：**帳號管理 → 編輯帳號 → 權限設定 → Telegram 客服 → 用指令查站台餘點**。

| | |
|---|---|
| keyword | `telegram_chat.station_credit` |
| 群組 label | `permission.group.telegram_chat` → Telegram 客服 |
| 項目 label | `permission.telegram_chat.station_credit` → **用指令查站台餘點** |

### 為什麼不沿用 `station.update`

第一版用的是後台站台頁「同步」按鈕的那個權限（`routes/web.php` 的
`ajax-sync-credits` → `can:station.update`），理由是「同一件事換個入口做卻不檢查，
指令就成了 RBAC 的後門」。

需求方否決（2026-10-13）：

> 編輯站台是後台編輯，這個指令查詢是指令查詢，兩個不一樣

說得對，而且我那個理由只看了一半。`station.update` 的語意是**能改站台設定** ——
為了讓人能在群組問一句「還剩多少點」就給出站台編輯權，是把洞開在另一邊，
而且那個洞比原本要防的大。

⚠ 一般化的教訓：「底層動作相同」**不等於**「該共用同一個權限」。
這裡底層確實是同一支 `syncInfo()`，但使用者在做的事不一樣 ——
一邊是維護站台設定，一邊是查一個數字。權限該照**使用者在做什麼**切，
不是照程式走到哪一支切。

⚠ 對外文案要寫**介面上看到的那幾個字**。第一版寫成「站台編輯」而介面是
「編輯站台」，當天就被問「`station.update` 是什麼？我怎麼沒看到」——
keyword 不會出現在介面上，近似說法會讓人在權限頁上找不到。

⚠ **按鈕要再驗一次。** 按按鈕的人不一定是下指令的人：有權限的 A 下了指令，
確認訊息留在群組裡，沒權限的 B 按下【查詢】。只擋指令的話 B 就看到點數了。

⚠ 沒權限時**另發一則訊息，不要 `editMessage()`** —— 編輯的話 B 按一下就把
A 的確認按鈕蓋掉，A 得重打一次。比較理想是用 `answerCallbackQuery` 的文字跳
一個只有 B 看到的提示，但那一次已經在 controller 答掉了，同一個 query 不能答第二次。

⚠ 【取消】不檢查權限：把自己群組裡一則確認訊息收掉，誰都可以。

## 同步失敗不回舊點數

主系統沒回資料時，資料庫裡那個數字可能是好幾天前的。拿它當「現在的餘點」
會讓人以為點數還夠 —— 所以照實說查不到，並講明「不報上一次的數字」。

餘點告警排程是同一條規則（`StationCreditAlertService::SKIP_SYNC_FAILED`
「主系統 API 沒回資料，跳過（不拿舊點數判斷）」）。

這也是結果訊息**一定附同步時間**的理由：沒有時間戳的數字看不出是剛查的還是舊的。

## 找站台：走 `getForCreditSync()`，不自己下查詢

`StationRepository::getForCreditSync($stationId = null)` 是餘點同步排程的來源，
已經篩掉「停用」與「沒設 api_url／api_key」的站台。指令與按鈕都走它：

- 自己寫一條的話，會出現「指令找得到這個站台、按下去卻同步不了」的不一致
- 按鈕按下去**重新查一次**：確認訊息會一直留在群組裡，三天後有人翻上去再按，
  站台可能已經停用或 api_key 被清掉 —— 那時候回「這個站台現在查不到了」，
  而不是去打一支不存在的 API

⚠ 這支的 select **沒有 `synced_at`**（那是餘點告警用的，不需要這一欄），
所以同步完要 `refresh()` 重撈全部欄位，不然結果訊息的同步時間會是空的。

⚠ 名稱**完全相符**（不計大小寫、去前後空白），不模糊比對 —— 猜錯會讓人
拿著別的站台的點數做判斷。

## 點數的格式：什麼都不做

`credits` 的 cast 是 `decimal:2`，轉字串就是「16390.94」（兩位小數、不加千分位），
跟餘點告警的 `formatCredits()`（`number_format($v, 2, '.', '')`）**輸出完全一樣**。
所以這裡不再格式化一次 —— 同一個數字在兩個地方長不一樣才是問題。

## 多站台：靠實際站台清單擋，不靠加強 prompt

prompt 明寫「一次只查一個站台；他講了兩個以上就填 unknown」，
**實測模型不聽**（2026-10-13，本機 `claude` CLI）：

| 說的話 | 模型回的 |
|--------|---------|
| 幫我查一下LV_UAT餘點 | `credit` / `LV_UAT` ✓ |
| LV_UAT還有多少點 | `credit` / `LV_UAT` ✓ |
| LV_UAT點數剩多少 | `credit` / `LV_UAT` ✓ |
| 幫我查餘點 | `unknown` / `null` ✓ |
| 提醒我明天下午三點查LV_UAT餘點 | `not_credit` ✓（退回提醒那條路） |
| 明天早上十點提醒大家對帳 | `not_credit` ✓ |
| **幫我查LV_UAT跟T01的餘點** | **`credit` / `"LV_UAT、T01"`** ✗ |

最後那一列沒照 prompt 回 `unknown`。不修 prompt（它剛剛就不聽），改在程式裡兜：
完全相符找不到時，數**這串字裡出現了幾個已知的站台名稱**，兩個以上就回
「一次只能查一個站台，您提到了：…」。

為什麼不猜「、」「跟」「和」這些分隔符：站台名稱本身可能含奇怪的字元，
猜分隔符會把合法名稱切壞。用真實清單比對不會。

⚠ **命中一個時仍然走「找不到」**，不拿它當答案。假設站台有 `T01` 沒有 `T011`，
使用者問 `T011` 時會「包含 T01」—— 直接查就回了 T01 的點數，而他問的是另一個站台。
只有命中兩個以上才改口說「一次只能查一個」。

⚠ 長的名稱先比（`dropContained()`）：同時有 `T01` 與 `T011` 時，字串 "T011"
包含兩者，不排掉會被數成「講了兩個站台」。

## 為什麼抽出 `TelegramCommandRouter`

原本「有沒有 tag 到我」「話題准不准」「這句話是哪一種指令」全寫在
`ScheduledReminderService` 裡。加到第三個指令時那支就變成「預約提醒 ＋ 路由器」——
下一個人要找分流邏輯會先翻 webhook，再翻到提醒的 service 裡才看到。

```
tag 機器人
  ├─ 含「公告」  → 預約發送公告  [[scheduled-broadcast-command]]
  ├─ 含「餘點」  → 同步站台餘點（本篇）
  └─ 其餘        → 預約提醒      [[scheduled-reminder]]
```

每一支 handler 的簽章都一樣（`handle($body, $from, $threadId): bool`），
回 `false` ＝「這不是我的事，換下一個」。加第四種指令只要寫一支 handler
掛進 `handlers()`，不必動任何既有的 service。

⚠ **順序有意義：提醒擺最後**，因為它同時是「看不懂」時的歸宿（會回一句範例）。
放前面的話後面兩種永遠輪不到。

⚠ **關鍵字只是粗篩，不是判斷。** 真正的判斷在各自的解析器裡 ——
「提醒我明天查 LV_UAT 餘點」含「餘點」卻是提醒，所以餘點那支回 `not_credit`
讓它往下走（上表實測過）。粗篩的用途單純是「不要每則訊息都跑三支模型」。

⚠ callback **沒有**走分派器：訊息有共用入口（tag 機器人）可以分，
按鈕本來就各帶各的前綴（`fu:` `bc:` `rm:` `sc:` `ar*`），在 controller
攤平分派比再包一層薄薄的 router 清楚。

## 話題限制：跟預約提醒共用同一個設定

「通知設定 → 話題分流」勾了「預約提醒」的話題才能下指令 ——
**三種指令共用這一項**（需求方決定：一個設定管全部比每種指令各一項好記）。

判斷擋在解析**之前**：不准用的話題不該燒模型額度。但也不能靜默，
不然使用者 tag 了沒反應只會以為壞了然後再試幾次。

一個都沒勾 ＝ **不限制**，不是「全部停用」—— 設定還沒填的那段時間不該整個停擺。

## 待釐清 / 已知缺口

- **[[scheduled-broadcast-command]] 的按鈕沒驗權限。** 本篇在 `handleCallback()`
  補了「按按鈕的人要再驗一次」，公告那支還沒 —— 有權限的 A 下指令、沒權限的 B
  按【確認預約】就能把公告發給客戶。**比本篇嚴重**（公告對外、不可逆）。本次沒動它。
- **要先綁 Telegram 使用者 ID** 才能用（跟公告指令同一個前提）。本機沒有已綁定的
  帳號，所以「有權限 → 成功同步」那條路**沒有實測過**；權限邏輯與線上在跑的
  公告指令相同（`findByTelegramUserId()` ＋ `hasPermission()`）。
- **同步是同步執行的**（webhook 等著 API 回來）。正式機 `QUEUE_CONNECTION=sync`，
  丟 Job 也一樣不會非同步，所以沒丟。API 很慢時 Telegram 會重送 webhook ——
  目前靠「先把訊息改成『正在同步…』」讓使用者不重按，但沒有防重複同步的鎖。
  同步本身是覆寫同一筆（無副作用累積），所以重複執行只是多打一次 API。
