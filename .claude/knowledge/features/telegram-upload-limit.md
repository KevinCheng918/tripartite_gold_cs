# 為什麼機器人只能傳 50MB，自己的帳號卻可以傳 2GB

> 2026-10-05 需求方問「客服上傳要加大到 100MB」時查的。
> **結論：當時決定不改**，程式維持 50MB。這裡留著調查結果，下次不用重查。

## 限制不在「機器人」，在中間那台橋接伺服器

兩條路徑根本不是同一套東西：

| | 走什麼 | 上限 |
|---|---|---|
| **你的 Telegram app** | **MTProto** —— 直接跟 Telegram 伺服器對話 | 2 GB（Premium 4 GB） |
| **我們的機器人** | **Bot API** —— Telegram 官方跑的一台 HTTP → MTProto **橋接伺服器**（`api.telegram.org`） | **50 MB** |

機器人傳檔時，檔案是先 multipart 上傳到**那台橋接機**，再由它轉給 Telegram。
50MB 是**那台機器**的限制，不是機器人身份的限制，也不是 MTProto 的限制。

所以「用自己帳號傳得過去」完全正常 —— 你根本沒經過那台機器。

## 官方給的解法：自己跑那台橋接伺服器

Bot API server 是**開源**的，可以自架（Local Bot API Server）。
官方文件（`core.telegram.org/bots/api#using-a-local-bot-api-server`）寫明自架後：

> Upload files up to 2000 MB
> Download files without a size limit

本專案目前用的是官方端點：

```php
// config/telegram.php
'api_base' => 'https://api.telegram.org/bot',
```

要改走自架的話，**程式這邊只要換這個設定**（`TelegramBotService` 全部走
`api_base`）—— 真正的成本在維運要架一台服務並顧它的生命週期。

## 其他相關上限

| 行為 | 上限 |
|---|---|
| 機器人上傳新檔（multipart） | 50 MB |
| 機器人下載檔案（`getFile`） | 20 MB |
| 用 `file_id` 或 URL 轉發 | 不受上述限制 —— 檔案已經在 Telegram 伺服器上，沒有經過橋接機上傳 |

> ⚠ 50MB / 20MB 這兩個數字沒能從官方文件頁直接抓到驗證（WebFetch 取到的
> 段落不含那幾句），但與程式裡既有註解一致
> （`SendFileRequest`、`SendBroadcastRequest` 都寫著「Telegram Bot API
> 上傳上限為 50MB」）。2000 MB 那句是從官方文件直接引用到的。

## 順帶查到的既有問題：實體檔案不會被清

跟上面無關，但查的時候發現：

`telegram:purge` 每天 02:00 跑，刪掉超過 `TTL_DAYS = 7` 天的訊息 ——
但 `TelegramRepository::deleteOlderThan()` **只 `delete()` DB 紀錄，
不碰 `storage/app/public/telegram/` 的實體檔案**。

所以客服傳過的每個檔案都永久留在磁碟上，而訊息紀錄 7 天後就沒了 ——
**檔案還在，但已經沒有任何紀錄指向它**。

目前（50MB 上限）還撐得住，但這是會慢慢長大的東西，值得哪天處理。
如果日後真的把上限加大，這件事就得一起解決。

## 相關

- [[telegram-chat]] — 客服對話本體
- [[shared-file]] — 另一個上傳點（20MB，存本站、不經 Telegram）
