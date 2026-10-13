# 用 Telegram 指令預約發送公告

> **狀態：需求方 2026-10-13 確認四項決定後實作。**

## 需求方的決定（2026-10-13）

| 題目 | 決定 | 備註 |
|---|---|---|
| 權限 | 要有 `telegram_chat.broadcast` | 跟後台發公告同一個 |
| 確認 | **兩段式**：先回預覽，按【確認預約】才成立 | |
| 發給全部客戶 | **支援** | 我原本建議不支援，需求方要 —— 確認畫面會標明「會發給 N 個群組」 |
| 解析器 | **另開一支專用的** | 我原本建議共用，需求方要分開 |

⚠ 「另開一支」帶來一個要處理的問題：**不能讓每則訊息都跑兩次模型**。
所以先用關鍵字分流（含「公告」才走公告那支），而公告解析器要能回
`not_broadcast`，讓呼叫端退回提醒那條路 —— 「明天提醒我發公告」這種句子
含「公告」但其實是提醒。
>
> ⚠ 這個功能的後果是**公告發到客戶群組**，而且預約的那一刻人已經離開了 ——
> 送錯對象或送錯內容都收不回來。設計的重點全部圍繞「怎麼讓錯誤在送出前被攔下」。

## 需求（2026-10-13）

> 「稍等20分鐘後，發送T01公告給LV_UAT群組」，我想要做這個預約提醒的文字預約傳送功能

## 好消息：兩塊積木都已經在了

| 已有的 | 在哪 |
|---|---|
| 公告範本與代號 | `broadcast_template.code`（UNIQUE）—— 「T01」就是它 |
| 公告的預約發送 | `TelegramBroadcastService::createScheduled()` ＋ `sendScheduled()` 排程 |
| 自然語言解析時間 | `ReminderParser`（預約提醒用的那支） |
| 在群組認出是誰 | `UserRepository::findByTelegramUserId()` |

**所以要做的不是新功能，是把這四塊接起來** —— 外加一層「送出前的確認」。

## ⚠ 三個必須先定的決定

### 1. 權限：群組裡誰都能發公告嗎

內部支援群組裡的每個人都看得到機器人。如果不檢查，**任何進得了那個群組的人
都能把公告發給所有客戶**。

建議：用 `findByTelegramUserId()` 把說話的人對應到後台帳號，
檢查他有沒有 `telegram_chat.broadcast` 權限。

- 沒綁定 Telegram 的人 → 拒絕，並告訴他去「我的帳號」綁定
- 綁了但沒權限 → 拒絕，並說明要哪個權限

⚠ 這跟預約提醒不同：那個只發到內部群組，群組成員本來就都看得到，
所以不必檢查。公告是對外的。

### 2. 確認：直接預約，還是要按確認

| | 預約提醒 | 預約公告 |
|---|---|---|
| 後果 | 內部群組多一則訊息 | **公告發到客戶群組** |
| 可逆 | 發出去也只是自己人看到 | **收不回來** |
| 現在的做法 | 直接建立 ＋ 複述 ＋ 取消鈕 | ？ |

建議：**兩段式，要按「確認預約」才成立**。bot 先回一則預覽：

```
📢 確認一下要發什麼

範本：T01《系統維護公告》
對象：LV_UAT（1 個群組）
時間：2026-10-13 15:12（20 分鐘後）

內容預覽：
親愛的客戶您好，本系統將於…（前 200 字）
附件：2 張圖、1 個檔案

[ 確認預約 ]  [ 取消 ]
```

按下「確認預約」才建立。沒按就是沒這回事 —— **預設不送**。

### 3. 對象：怎麼指定群組、能不能「全部」

- 範本用 `code` **完全相符**（T01），不做模糊比對 —— 猜錯就是錯誤公告
- 群組名稱：AI 只負責抽出「LV_UAT」這個詞，比對交給程式
- **比對不到就說不到，並列出可選的群組名稱**，不自動挑最像的

「發給全部客戶」要不要開放用指令做？建議**第一版不開放** ——
那是風險最高的操作，值得逼人走後台頁面。

## 怎麼運作（確認上面三點後）

```
在開放的話題 tag 機器人
  └─ 解析（intent 多一種 broadcast）
      ├─ 權限不足 / 沒綁定  → 拒絕並說明
      ├─ 範本或群組找不到   → 說哪一個找不到，列出可選的
      └─ 都對               → 回預覽 ＋【確認預約】【取消】
                                └─ 按確認 → createScheduled()（既有的）
                                             └─ 既有排程到點發送
```

## 實作時的幾個發現

### ⚠ 公告的「對象」存的是 **station id**，不是 telegram_group id

`telegram_broadcast.target_group_ids` 的欄位名與 `schedule()` 的參數名都叫
`group_ids`，但發送時解析的是站台：

```php
$allStations->whereIn('id', $broadcast->target_group_ids)   // ← station.id
    ->map(function ($station) { return $station->telegramGroup; })
```

所以「LV_UAT」要比對的是 **`station.name`**。我第一版寫成查 `telegram_group.title`
是錯的 —— 那樣指令找得到、到點卻發不出去。

為此 `findTargetStationByName()` **刻意走 `getTargetStations()`**（發送頁勾選清單
的來源）而不是自己下一條查詢：兩邊條件分岔的話，會出現「指令找得到這個站台、
時間到了卻沒發」那種最難查的不一致。

### 確認用的草稿放 cache，不進資料庫

`callback_data` 只有 64 bytes，塞不下公告內容，所以要先把草稿存起來、按鈕只帶
一把 key。存 cache（30 分鐘）而不是資料庫：那是個短命的中間狀態，**沒按確認
就該自然消失**，寫進資料庫還要處理「誰來清掉沒確認的那些」。

⚠ 草稿不在了（過期、或已經按過一次）**不能當成成功**，要回「這則預覽已經過期」
—— 使用者按了確認，一定要知道結果。

### 實測（同一組 prompt 與 schema）

```
「稍等20分鐘後，發送T01公告給LV_UAT群組」（現在 15:30）
  → intent=broadcast, send_at=2026-10-13 15:50, template_code=T01, target=LV_UAT

「明天早上提醒我要發公告給客戶」
  → intent=not_broadcast   ← 含「公告」但退回提醒那條路
```

兩個方向都正確。「稍等」沒有干擾解析，「群組」兩字也正確地沒被帶進 target。

## 異動檔案

```
app/Services/AutoReply/BroadcastCommandParser.php         新增（獨立的解析器）
app/Services/ScheduledBroadcastCommandService.php         新增（權限、查找、預覽、確認）
app/Services/ScheduledReminderService.php                 tag 機器人之後先分流給上面那支
app/Services/TelegramBroadcastService.php                 findTargetStationByName()
app/Repositories/BroadcastTemplateRepository.php          findByCode()
app/Http/Controllers/Webhook/TelegramWebhookController.php  bc: callback
config/constants.php                                      BROADCAST_COMMAND
```

**沒有動到**：`telegram_broadcast` 的資料結構、既有的 `schedule()` 與
`telegram:send-scheduled` 排程 —— 指令只是另一個建立預約的入口。

## 上線前置

1. `php artisan optimize`
2. **公告範本要填 `code`**（T01 這種）—— 沒填代號的範本用指令叫不到
3. 用指令的人要**綁定 Telegram** 且有 `telegram_chat.broadcast` 權限
4. 試一次：在開放的話題說「@機器人 30 分鐘後發送 T01 公告給 <站台名>」，
   確認預覽的範本與對象都對，再按確認

## 還沒做的

- **查詢／取消已排定的公告**：指令沒有做。預約後要改要取消，走後台
  「群發公告 → 歷史紀錄」。（預約提醒有清單指令，但那是發給自己人的；
  公告的清單牽涉到權限與對象，先不做。）

## 相關

- [[scheduled-reminder]] — 時間解析與 tag 機器人那一段
- [[broadcast-template]] — 範本與 `code`
- [[broadcast]] — 群發公告與既有的預約發送
