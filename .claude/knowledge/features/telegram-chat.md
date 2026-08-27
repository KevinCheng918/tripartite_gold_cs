# 客服對話窗（Telegram 整合）

## 現況

已完成，持續迭代。

## 需求（已確認）

- **串接方式**：使用 Telegram Bot 或一個固定帳號，串接回覆到 Telegram 群組
- **群組訊息身份**：Telegram 群組中永遠顯示為同一個客服帳號（不區分哪位客服回覆）
- **接單機制**：由當班人員接收處理需求
- **多客服**：不限制同時在線客服數量
- **對話紀錄**：需保留，TTL 7 天後刪除
- **未回覆告警**：
  - 週一至週五：客人問題超過 5 分鐘未回覆 → 告警
  - 週六、週日：客人問題超過 30 分鐘未回覆 → 告警

## 已實作功能

| 功能 | 說明 |
|------|------|
| 文字收發 | Webhook 收訊 + 後台回覆 |
| 圖片收發 | 收：下載 Telegram 圖片/貼圖到 storage；發：上傳圖片後透過 Bot API 傳送 |
| 貼上／拖曳截圖 | 聊天視窗內 Ctrl+V 貼上，或把檔案直接拖進聊天欄（見下方「貼上／拖曳截圖」）|
| 快速回覆選單 | 客服選類別 → 問題 → 預覽答案，可填入輸入框微調或直接送出（見下方「快速回覆選單」）|
| 貼圖相容 | 動態/影片貼圖自動取用 thumbnail 靜態縮圖 |
| 表情回應 | 收：處理 message_reaction webhook，合併計數存入 reactions JSON；發：透過 setMessageReaction Bot API，前端提供 6 個快速 emoji 選擇器 |
| 值班自動指派 | 依排班自動指派當前值班客服 |
| 即時推送 | Pusher/WebSocket broadcasting |
| Web Push PWA | 收到 Telegram 訊息時推播通知到所有已訂閱客服的瀏覽器，支援 PWA 離線通知 |
| 未回覆告警 | 超時未回覆告警（工作日 5 分鐘、假日 30 分鐘） |

## 貼上／拖曳截圖（2026-08-27）

實作在 `public/js/telegram-chat/input.js`，後端沿用既有的 `ajax-send-image`，**無後端異動**。
貼上與拖曳共用同一組 `pendingImages` 待傳送佇列與 `addPendingImage()` 驗證。

### 設計取捨

- **貼上／拖曳都不直接送出**，先進「待傳送」預覽區，按發送鈕／Enter 才真的送到客戶群組。
  訊息一旦送到 Telegram 就收不回來，誤貼的代價太高。
- `paste` 事件綁在 `document` 上，截圖後不必先點輸入框就能 Ctrl+V；
  但若焦點在其他 `input`/`textarea`/`contenteditable`（例如群組搜尋框）則不攔截。
- 剪貼簿沒有圖片時完全不 `preventDefault()`，純文字貼上維持瀏覽器預設行為。
- **切換群組時 `pendingImages` 會清空**（`showInput()` 開頭），避免截圖誤送到別的對話。
- 多張截圖**序列送出**而非平行，確保客戶端看到的順序與貼上順序一致；
  文字只掛在第一張當 caption。失敗時已送出的不重送，只保留失敗那張與其後未送的。

### 拖曳的兩層判斷

拖放刻意分成兩個範圍，避免拖歪就把使用者帶離頁面：

| 函式 | 範圍 | 作用 |
|------|------|------|
| `inChatPage()` | 整個 `#telegram-chat-app` | 一律 `preventDefault()`，**只為阻止瀏覽器直接開啟該檔案** |
| `canDropHere()` | 右側 `.app-inner-layout__content` | 真正接受截圖，並顯示提示遮罩 |

- `dragenter`/`dragleave` 會在子元素之間反覆觸發，用 `dragDepth` 計數避免遮罩閃爍；
  拖出瀏覽器視窗時 `relatedTarget` 為 `null`，直接歸零收掉遮罩，另有 `dragend` 兜底。
- 遮罩 `#tg-drop-overlay` 必須是 `pointer-events:none`，否則它會自己吃掉 `dragleave`/`drop`。
- 拖入多個檔案時，圖片照收，只有**全部都不是圖片**才顯示 `msg.drop_invalid` 提示
  （非圖片檔請走既有的「文件區」功能傳送）。

### 注意事項

- 剪貼簿圖片常沒有檔名或叫 `blob`，前端 `namedImage()` 會補 `screenshot_<ts>.<ext>`；
  否則後端 `putFileAs()` 會存出沒有副檔名的檔案，Telegram 端可能無法正確辨識。
  拖曳進來的檔案有真實檔名（如 Mac 的「螢幕截圖 2026-08-27 上午11.22.33.png」）會保留原名，
  後端 `preg_replace('/[^a-zA-Z0-9._-]/', '_', ...)` 會把中文與空白轉成底線。
- 前端 `MAX_IMAGE_BYTES` 必須與後端 `ajaxSendImage` 的 `max:5120`（KB）一致，改一邊要改兩邊。
- 錯誤提示走輸入區上方的行內紅字（`#tg-input-error`），不是 `alert`。
  註：`sendReply()` 的文字送出失敗仍是既有的 `alert`，尚未一併改掉。

## 快速回覆選單（2026-08-27）

取代原本掛在 Telegram 上的問答機器人 —— 改由**客服在後台選好答案再送出**，
而不是讓客戶自己點 inline_keyboard。

**題庫由客服在後台自行維護（存 DB），不寫死在 config。**

| 檔案 | 角色 |
|------|------|
| `quick_reply_category` / `quick_reply_item` 兩張表 | 線上題庫本體，含 `sort`、`status` |
| `config/quick_reply.php` | **只是 `QuickReplySeeder` 的初始資料來源**，改它不影響線上內容 |
| `QuickReplyService::getForChat()` | 聊天視窗選單（只回啟用中的） |
| `QuickReplyService::getForManage()` | 管理頁（含停用項目） |
| `QuickReplyController` | 後台 CRUD + 上下移，`quick_reply.view` / `quick_reply.edit` |
| `TelegramChatController::ajaxQuickReplies()` | `GET ajax-quick-replies`，需 `telegram_chat.reply` |
| `public/js/telegram-chat/quick-reply.js` | 聊天視窗三層 Modal：類別 → 問題 → 答案預覽 |
| `public/js/quick-reply-admin.js` | 後台管理頁 |

### 為什麼 chat 端的 key 要加前綴

`getForChat()` 回傳的 key 是 `c{id}` / `i{id}` 而不是純數字 ——
**JS 物件的純數字 key 會被引擎自動依數值排序**，會蓋掉我們排好的 `sort` 順序。

### 圖片一律 multipart 上傳，不要傳 URL 給 Telegram

`sendPhoto` / `sendMediaGroup` 原本是把 `Storage::disk('public')->url(...)` 產生的網址
交給 Telegram，**由 Telegram 反過來抓我們的伺服器**。開發環境 `APP_URL=http://localhost`，
Telegram 抓不到，會回：

```
400 Bad Request: wrong HTTP URL specified
```

現在兩支都會先用 `resolveLocalPath()` 判斷是不是本站 storage 的網址，
是的話改用 multipart 直接把檔案上傳給 Telegram（`sendMediaGroup` 用 `attach://欄位名` 對應），
外部網址才維持原本交給 Telegram 自行抓取的做法。

這樣**不再依賴 `APP_URL` 是否對外可達** —— 正式環境也不必為了傳圖把 storage 目錄公開。
`sendDocument` 本來就是 multipart，所以文件一直傳得出去、只有圖片會失敗。

### 輸入區版面

輸入區是**兩列**結構（`showInput()` 產生）：

```
#tg-pending-images   ← 待傳送截圖縮圖
#tg-input-error      ← 行內錯誤提示
#tg-input-tools      ← 圖片／文件／快速回覆，圖示 + 文字說明
.d-flex              ← textarea + 圓形送出鈕
```

功能鈕原本是同列的純圖示圓鈕，手機版會把輸入框擠到只剩一小截；
改成獨立一列後輸入框可佔滿整列，文字說明在手機版也看得到（純圖示沒有 hover 可用）。

### 其他注意事項

- 類別底下還有問答時**不允許刪除**（`deleteCategory()` 回 `false` → 422），避免一次誤刪整批題目。
- 上下移是與相鄰一筆交換 `sort`，兩筆寫入包在 `DB::transaction()` 內。
- 整個類別若沒有任何啟用中的問答，聊天視窗選單就不顯示該類別，免得點進去是空的。

### 設計取捨

- **答案一定先預覽**，再由客服選「填入輸入框」（可改字）或「直接送出」。
  訊息送到客戶群組收不回來，不做「點一下就送」。
- 送出沿用既有 `ajax-reply`，**沒有新的送出端點**；客服本來就能自由打字送出任意內容，
  由前端帶答案文字不構成新的風險面。
- 選單資料前端**只載入一次**後快取在 `quick-reply.js` 的 `data` 變數，切換對話不重抓。
- 附搜尋框，跨類別同時比對「問題」與「答案」內容 —— 題庫有 60+ 題，只靠分類翻找太慢。

### 題庫轉換時的調整（原始資料來自使用者提供的機器人設定）

| 項目 | 處理 |
|------|------|
| `keywords.not_private` | 原為 bot 拒絕私訊的自動回覆，改放進「其他」類別供客服手動送出 |
| `back_root` 返回按鈕 | 移除，後台 Modal 自己有返回鈕 |
| `api_block` 重複 | 原始資料中「如何開啟黑名單阻擋」與「付款人電話…必填」的 `callback_data` 都是 `api_block`，後者改 key 為 `api_block_required`，兩題目前**共用同一段答案**，待確認是否要分開 |
| `order_distribute` | 原始資料有答案但 keyboard 漏了按鈕，已補上「派單功能設定」 |
| `agent_balance_holiday` | 原文「系統後gi台」為筆誤，改為「系統後台」 |

## 待釐清

- （已全部釐清）
