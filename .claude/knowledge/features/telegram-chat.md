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
| 貼上截圖 | 聊天視窗內直接 Ctrl+V 貼上截圖，免存檔再選檔（見下方「貼上截圖」）|
| 貼圖相容 | 動態/影片貼圖自動取用 thumbnail 靜態縮圖 |
| 表情回應 | 收：處理 message_reaction webhook，合併計數存入 reactions JSON；發：透過 setMessageReaction Bot API，前端提供 6 個快速 emoji 選擇器 |
| 值班自動指派 | 依排班自動指派當前值班客服 |
| 即時推送 | Pusher/WebSocket broadcasting |
| Web Push PWA | 收到 Telegram 訊息時推播通知到所有已訂閱客服的瀏覽器，支援 PWA 離線通知 |
| 未回覆告警 | 超時未回覆告警（工作日 5 分鐘、假日 30 分鐘） |

## 貼上截圖（2026-08-27）

實作在 `public/js/telegram-chat/input.js`，後端沿用既有的 `ajax-send-image`，**無後端異動**。

### 設計取捨

- **貼上不直接送出**，先進「待傳送」預覽區，按發送鈕／Enter 才真的送到客戶群組。
  訊息一旦送到 Telegram 就收不回來，誤貼的代價太高。
- `paste` 事件綁在 `document` 上，截圖後不必先點輸入框就能 Ctrl+V；
  但若焦點在其他 `input`/`textarea`/`contenteditable`（例如群組搜尋框）則不攔截。
- 剪貼簿沒有圖片時完全不 `preventDefault()`，純文字貼上維持瀏覽器預設行為。
- **切換群組時 `pendingImages` 會清空**（`showInput()` 開頭），避免截圖誤送到別的對話。
- 多張截圖**序列送出**而非平行，確保客戶端看到的順序與貼上順序一致；
  文字只掛在第一張當 caption。失敗時已送出的不重送，只保留失敗那張與其後未送的。

### 注意事項

- 剪貼簿圖片常沒有檔名或叫 `blob`，前端 `namedImage()` 會補 `screenshot_<ts>.<ext>`；
  否則後端 `putFileAs()` 會存出沒有副檔名的檔案，Telegram 端可能無法正確辨識。
- 前端 `MAX_IMAGE_BYTES` 必須與後端 `ajaxSendImage` 的 `max:5120`（KB）一致，改一邊要改兩邊。
- 錯誤提示走輸入區上方的行內紅字（`#tg-input-error`），不是 `alert`。
  註：`sendReply()` 的文字送出失敗仍是既有的 `alert`，尚未一併改掉。

## 待釐清

- （已全部釐清）
