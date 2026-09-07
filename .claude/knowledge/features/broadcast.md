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

### Controller / Service / Repository / Command
- `app/Http/Controllers/Admin/TelegramBroadcastController.php`
- `app/Services/TelegramBroadcastService.php` — `send()` / `schedule()` / `sendDue()` / `cancelSchedule()` / `dispatch()`
- `app/Repositories/TelegramBroadcastRepository.php` — `getDueScheduled()`
- `app/Console/Commands/SendScheduledBroadcastCommand.php` — `telegram:send-scheduled`，Kernel 每分鐘

### Request
- `app/Http/Requests/TelegramBroadcast/SendBroadcastRequest.php` — 圖片 10 張 / 5MB、`scheduled_at` 格式與 `after:now`

### View
- `resources/views/admin/telegram-broadcast/index.blade.php`
- `resources/views/admin/telegram-broadcast/partials/status-badge.blade.php` — 桌面表格與手機卡片共用

### 路由
- `routes/web.php` — prefix `telegram-broadcast`，全部掛 `can:telegram_chat.broadcast`

## 注意事項

- 權限沿用 `telegram_chat.broadcast`，**沒有另外新增 keyword**
- 前端 `localDatetimeValue()` 不能用 `toISOString()` 填 `datetime-local`：
  那會轉成 UTC，直接填會差一個時區，必須自己補零組字串
- 確認視窗用頁面內的 `#modal-broadcast-confirm`，不使用原生 `confirm()`（見 [[feedback-ui-style]]）
- 綁 confirm 的 OK 鈕前要先 `.off('click')`，否則連開兩次會觸發到上一次的 callback
