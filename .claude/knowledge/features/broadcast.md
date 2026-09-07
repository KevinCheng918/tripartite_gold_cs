# 群發公告（Telegram Broadcast）

## 現況

已完成，持續迭代。

## 功能概述

把一則公告一次送到多個站台的 Telegram 群組，可帶圖片，支援預約傳送。

- 發送對象：全部群組 / 指定群組（選的是 **station.id，不是 telegram_group.id**）
- 可附圖：1 張走 `sendPhoto`、多張走 `sendMediaGroup`、無圖走 `sendMessage`
- 逐站台切換該系統的 Bot Token
- 送出後同步寫一筆 outbound 訊息進 `telegram_message`，客服在對話窗看得到
- 歷史紀錄可看每站台成敗明細（`send_results`）

## 預約傳送（2026-09-07）

### 精度只到「分鐘」

Laravel 排程最細就是 `everyMinute()`，秒級預約無法真正兌現。
因此 `scheduled_at` 驗證用 `date_format:Y-m-d H:i`（**帶秒會被擋下**），
前端 `datetime-local` 加 `step="60"`。刻意不做到秒，避免給出兌現不了的承諾。

### 目標站台在「發送當下」才解析

`schedule()` 只寫紀錄，不預先算出要送哪些群組。
`dispatch()` 執行時才去 `getActiveWithTelegram()` 撈站台 ——
預約期間站台被停用或解綁群組就不會誤送，`target_type=1`（全部）也會涵蓋新加入的站台。

### image_urls 必須落庫

> ⚠️ 預約的公告在建立當下不會送出。圖片網址若只存在 request 裡，
> 到了排程時間就找不到圖。因此新增 `image_urls` JSON 欄位，
> 圖片在**建立預約時**就上傳並把網址存進資料表。

### 立即與預約共用 dispatch()

`send()`（立即）與 `sendDue()`（排程）都呼叫同一支 `private dispatch()`，
兩條路徑的發送行為、結果回寫、對話紀錄寫入才不會分岔。

差別只在 `send()` 建立紀錄時就 `status = STATUS_SENT`、`sent_at = now()`；
預約的建立時是 `STATUS_PENDING`、`sent_at = null`，由 `dispatch()` 補上。

### 待發送的「發送對象」不能讀 send_results

> ⚠️ 歷史紀錄的發送對象 badge 點開是讀 `send_results`（每站台成敗）。
> **預約中的公告還沒送出，`send_results` 必然是空的**，第一版點開是一片空白。
>
> 現在 badge 會依 `status` 分流：待發送的改帶 `data-targets`
> （由 `target_group_ids` 對照 `$groups` 取出站台名稱），前端渲染「預定對象」清單。

`target_type = TARGET_ALL` 的預約要額外提示：對象在送出當下才解析，
畫面上顯示的只是**目前**的站台清單，屆時可能增減。

badge markup 抽成 `partials/target-badge.blade.php` ——
原本桌面表格與手機卡片各寫兩份（共四處），改一處就會漏掉其他三處。

### 附件（2026-09-07）

單一檔案選擇框（無 `accept`），送出前依 MIME 拆成 `images[]` 與 `files[]`。

> ⚠️ **`file_urls` 不能和 `image_urls` 合併**：
> `sendPhoto` 吃的是網址，`sendDocument` 吃的是**本地絕對路徑**。
> 因此 `file_urls` 存 `[{path, name}]` —— `path` 是 storage 相對路徑
> （送出時用 `Storage::disk('public')->path()` 轉絕對路徑），
> `name` 是原始檔名，否則客戶收到的會是 `時間戳_uniqid_原檔名`。

發送順序：每站台先送正文（圖片當 caption），再由 `sendFilesTo()` 逐一送附件。

**站台要全部送成功才記為 success**。正文送到但附件失敗仍算失敗 ——
否則客服看到「成功」就不會補送，客戶其實沒收到檔案。

限制：圖片 10 張／5MB，檔案 10 個／50MB（Telegram Bot API 上傳天花板）。
副檔名黑名單走共用的 `BlocksExecutableUploads` trait。

### 附件預覽的 index 錯位

> 原本預覽是在 `FileReader.onload` 裡 `append`，**回呼完成順序不保證**，
> 而移除鈕的 index 寫在 `data-remove` 屬性上 —— 選多張時會刪到錯的那一個。
>
> 改成同步依序建立節點、縮圖等 reader 回來再填 `src`，
> 移除鈕用**閉包**記住 index 而非讀屬性。

### 其他設計

- `sendDue()` 用 `scheduled_at <= now()` 而非等於：機器停過一段時間後，
  落後的預約要在下次執行時補送，不能因為錯過那一分鐘就永遠不送
- 單筆失敗不中斷其餘預約（迴圈內各自 try/catch）
- Kernel 用 `withoutOverlapping()`：站台多時一輪可能跑超過一分鐘，
  沒擋的話下一輪會重複送出同一則公告
- 取消預約只允許 `STATUS_PENDING`，已送出或已取消的回 422，避免歷史紀錄被改動
- `status + scheduled_at` 建複合索引，排程每分鐘掃一次不能全表掃描

## 架構檔案

### Model / Migration
- `app/Models/TelegramBroadcast.php` — 常數 `TARGET_ALL/SELECTED`、`STATUS_SENT/PENDING/CANCELED`、`isCancelable()`
- `database/migrations/2026_08_06_000001_create_telegram_broadcast_table.php`
- `database/migrations/2026_08_21_000001_add_send_results_to_telegram_broadcast_table.php`
- `database/migrations/2026_09_07_000001_add_schedule_to_telegram_broadcast_table.php` — status / scheduled_at / image_urls
- `database/migrations/2026_09_07_000002_add_file_urls_to_telegram_broadcast_table.php` — file_urls

### Controller / Service / Repository / Command
- `app/Http/Controllers/Admin/TelegramBroadcastController.php`
- `app/Services/TelegramBroadcastService.php` — `send()` / `schedule()` / `sendDue()` / `cancelSchedule()` / `dispatch()`
- `app/Repositories/TelegramBroadcastRepository.php` — `getDueScheduled()`
- `app/Console/Commands/SendScheduledBroadcastCommand.php` — `telegram:send-scheduled`，Kernel 每分鐘

### Request
- `app/Http/Requests/TelegramBroadcast/SendBroadcastRequest.php` — 圖片 10 張 / 5MB、檔案 10 個 / 50MB、`scheduled_at` 格式與 `after:now`
- `app/Http/Requests/Concerns/BlocksExecutableUploads.php` — 擋可執行檔的共用 trait，
  任務看板附件、Telegram 傳檔、群發公告三處共用（黑名單本體在 `config('rules.UPLOAD_BLOCKED_EXTENSIONS')`）

### View
- `resources/views/admin/telegram-broadcast/index.blade.php`
- `resources/views/admin/telegram-broadcast/partials/status-badge.blade.php` — 狀態標籤，桌面表格與手機卡片共用
- `resources/views/admin/telegram-broadcast/partials/target-badge.blade.php` — 發送對象標籤，同上

### 路由
- `routes/web.php` — prefix `telegram-broadcast`，全部掛 `can:telegram_chat.broadcast`

## 注意事項

- 權限沿用 `telegram_chat.broadcast`，**沒有另外新增 keyword**
- 前端 `localDatetimeValue()` 不能用 `toISOString()` 填 `datetime-local`：
  那會轉成 UTC，直接填會差一個時區，必須自己補零組字串
- 確認視窗用頁面內的 `#modal-broadcast-confirm`，不使用原生 `confirm()`（見 [[feedback-ui-style]]）
- 綁 confirm 的 OK 鈕前要先 `.off('click')`，否則連開兩次會觸發到上一次的 callback
