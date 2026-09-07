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

> **`resolveLocalPath()` 只比對 path，不比對 host。**
> 同一份檔案的網址可能帶不同 host —— `APP_URL` 是 `localhost`，但 `asset()` 會用當下請求的
> host（`tripartite_gold_cs` 或正式網域）。第一版拿完整網址前綴去比對，
> VM 繳款通知的圖片就因此被誤判成外部網址、又走回讓 Telegram 反向抓取的老路而 400。
> 比對後仍有 `file_exists()` 把關，本地沒有對應檔案就退回 URL 模式。

這樣**不再依賴 `APP_URL` 是否對外可達** —— 正式環境也不必為了傳圖把 storage 目錄公開。
`sendDocument` 本來就是 multipart，所以文件一直傳得出去、只有圖片會失敗。

### 超長／超寬截圖會被 Telegram 退件

Telegram photo 的限制：**寬 + 高 ≤ 10000**，且**長短邊比例 ≤ 20:1**，
超過會回 `400 PHOTO_INVALID_DIMENSIONS`。整頁長截圖、超寬的設定畫面截圖很容易踩到。

`sendPhoto()` 會先用 `isValidPhotoDimensions()`（`getimagesize()`，不需要 GD）檢查，
不符規格就**自動改用 `sendDocument`** —— 顯示成檔案，但至少送得出去，而且原圖不會被 Telegram 壓縮。
判斷不了尺寸時照原流程送，交給 Telegram 決定。

### 系統自動發出的通知要傳 `markReplied = false`

`sendReply()` 預設會呼叫 `markMessagesReplied()`，把該群組**所有未回覆的客戶訊息標記為已回覆** ——
未回覆告警（工作日 5 分鐘／假日 30 分鐘）就是靠這個標記。

所以**系統自動發出的通知必須傳 `$markReplied = false`**，否則客戶還在等的提問會被誤判成已處理、
告警不會觸發。客服在聊天視窗手動回覆才該用預設的 `true`。

```php
// 補點審核通過的自動通知（CreditTopupService::notifyTelegram）
$this->telegramChatService->sendReply($groupId, $message, $userId, $name, null, false);
```

目前傳 `false` 的呼叫端：`CreditTopupService`（補點通知）、
`VmController::ajaxSendPaymentNotice`（VM 繳款通知）。

### 補點審核結果通知

站台補點／扣點審核通過、且主站 API 回 `code === 1` 時，
`CreditTopupService::approve()` 會把主站回傳的 `msg` 送到 `station.telegram_group_id` 對應的對話。

- 通知在 **`DB::transaction` 之外**執行 —— Telegram 是外部呼叫，不該把交易撐在那裡等
- 站台沒綁 Telegram 群組、或主站沒回 `msg` 就略過
- 通知失敗只記 log 不往外拋：**點數已經加扣完成了**，不能因為通知失敗而讓補點紀錄看起來失敗
- 訊息結尾會補一句提醒（`config('constants.STATION.TOPUP_NOTIFY_FOOTER')`）。
  這句**刻意不放語系檔** —— 語系會跟著客服後台的語言跑，
  客服切成英文時客戶就會收到英文結尾，但主站回的 `msg` 一直是中文。
  放 config 也讓它清成空字串就能關掉，不必改程式

> ⚠️ **關聯上的 `select()` 是個陷阱**：`CreditTopup::station()` 為了符合「SELECT 指定欄位」
> 的規範，只撈固定幾個欄位。第一版漏了 `telegram_group_id`，
> 導致 `$station->telegram_group_id` 恆為 `null` —— 站台明明綁好了，通知卻一直被略過，
> 而且 log 顯示的是「站台未綁定 Telegram 群組」，反而把人引導到錯誤方向。
>
> **透過關聯讀新欄位前，先確認該關聯的 `select()` 有含這一欄。**
> 專案多處關聯都有 select（如 `TelegramMessage::user()` 只取 `id`、`nickname`）。

### 送失敗就不要留下「已送出」的紀錄

`sendReply()` 原本不檢查 Bot API 的回傳值，Telegram 明明退件了，
訊息還是照樣寫進 `telegram_message` 並顯示在後台，**客服會誤以為回覆成功、客戶其實沒收到**。
現在回傳 null 就記 log 並丟 `RuntimeException`，由 Controller 轉成 500 讓前端顯示錯誤。

### 輸入區版面

輸入區是**兩列**結構（`showInput()` 產生）：

```
#tg-pending-images   ← 待傳送附件（圖片縮圖／檔案卡片）
#tg-upload-progress  ← 傳送中標示 + 進度條
#tg-input-error      ← 行內錯誤提示
#tg-input-tools      ← 檔案／文件／快速回覆，圖示 + 文字說明
.d-flex              ← textarea + 圓形送出鈕
```

功能鈕原本是同列的純圖示圓鈕，手機版會把輸入框擠到只剩一小截；
改成獨立一列後輸入框可佔滿整列，文字說明在手機版也看得到（純圖示沒有 hover 可用）。

### 表情符號選單（2026-09-07）

`public/js/telegram-chat/emoji.js`，與 `reactions.js` 是**兩件不同的事**：
後者是對「已收到的訊息」按表情回應，前者是把表情插進要送出的訊息。

- 按鈕嵌在**輸入框內側右下角**（Telegram／LINE 的做法），不放功能鈕那列。
  輸入框 `padding-right` 留白，文字才不會壓到圖示；用 `bottom` 定位而非垂直置中，
  輸入框長高時圖示才會一直待在右下角
- 選單用 `position: fixed`（同 reactions.js）—— 輸入區在聊天容器底部且有 overflow，
  absolute 會被裁掉。預設開在按鈕上方，空間不足才翻到下方
- 插入到**游標位置**而非句尾，插完手動 `dispatchEvent(new Event('input'))`，
  否則輸入框自動長高的邏輯不會跑
- 選完不關閉選單（可連續挑），點外面或再按一次才關
- `showInput()` 會重建輸入區，切換群組時要呼叫 `T.closeEmojiPicker()`，
  否則殘留的選單會指向已消失的按鈕

> ⚠️ 分類標題的語系鍵是 `T.i18n['emoji_cat_' + key]` **動態組出來的**，
> grep `emoji_cat_face` 在 JS 裡找不到任何字面。
> 做「未使用語系鍵」清理時不要把它們刪掉（語系檔已加註解）。

### 傳送一般檔案（2026-09-07）

- 迴紋針按鈕拿掉 `accept="image/*"`，圖片與一般檔案都從這裡選，選完依 MIME 分流：
  圖片走 `ajax-send-image`（`sendPhoto`，客戶端看得到縮圖），其他走 `ajax-send-file`（`sendDocument`）
- 拖曳也收一般檔案；待送區 `pendingFiles` 同時裝兩種，
  圖片顯示縮圖、檔案顯示「圖示 + 檔名 + 大小」卡片
- 上限不同：圖片 5MB（`MAX_IMAGE_BYTES`）、檔案 50MB（Telegram Bot API 的上傳天花板）。
  **前端先擋一次**，否則得等整個檔案傳完才收到 422
- `TelegramChatService::sendFileReply()` 與 `sendDocumentFromSharedFile()` 是兩支：
  後者從文件區挑既有檔案，前者收的是當下上傳的。兩支都要檢查 Bot API 的 `ok`
- 副檔名黑名單移到 `config('rules.UPLOAD_BLOCKED_EXTENSIONS')`，
  與任務看板附件共用同一份 —— 原本各存一份，改一邊漏一邊就會一鬆一緊

### 對話署名（2026-09-07）

`user.telegram_nickname`（管理者在**帳號管理**設定，不是內勤管理）。
送出時由 `TelegramChatService::appendSignature()` 在結尾接上 ` -暱稱`。

- 三個送出點都會經過：`sendReply()`、`sendFileReply()`、`sendDocumentFromSharedFile()`
- 署名在**送出前**加上，寫進 `content` 的內容才會與客戶看到的一致
- 未設定就原樣送出，等於逐一帳號開啟
- 空內容（只傳圖片）也會署名，否則客戶不知道是誰傳的

> ⚠️ `AccountService::update()` 是**白名單制**，新欄位沒加進去就會被靜默丟掉 ——
> 症狀是畫面顯示「已更新」但 DB 沒變。用 `array_key_exists` 而非 `filled` 判斷，
> 才能把暱稱清空。

> ⚠️ **檔案卡片的標籤不能用 `content`**：加了署名之後 content 從空字串變成 `-Ａ`，
> 會把檔名蓋掉。卡片一律讀 `media_name`。

### 傳送檔案的預覽視窗

按鈕選檔後不直接送出，先開 `modal-tg-send-file` 確認 ——
選錯檔案送到客戶群組是收不回來的。圖片給縮圖，其他檔案只給檔名與大小
（pdf、doc 在這裡也預覽不了，硬塞一個框只是佔空間）。

> 貼上與拖曳仍走**待送區**（`pendingFiles`）那條路：縮圖在輸入框上方、
> 打完字按發送一起送出，且支援多檔。兩條路徑刻意不同，改動時不要誤以為重複。

### 訊息編輯與「刪除同步做不到」（2026-09-07）

> ⚠️ **Bot API 沒有訊息刪除事件**。客人在 Telegram 按「收回／刪除」，
> Bot 收不到任何通知 —— update 類型裡就是沒有這種事件，
> 也沒有任何 API 能查詢歷史訊息或某則訊息是否還存在。
> **「客人刪除 → 後台自動移除」無法實作**，不要再嘗試。
>
> `getUpdates` 也不是出路：webhook 啟用時呼叫它會直接回 **409 Conflict**
> （兩者互斥），而且它只回傳未消化的新事件、不是聊天歷史，同樣沒有刪除事件。

`edited_message` 則是**收得到的**，已由 `handleEditedMessage()` 處理。

> ⚠️ 編輯事件的 payload key 是 `edited_message` **不是** `message`。
> 沒有獨立分支的話會被 `handleIncomingMessage()` 當成空訊息直接 return —— 靜默失效。

- 同步內容、記 `edited_at`、前端標示「已編輯」
- 讀 `text` 或 `caption`（改圖說也會觸發）
- 內容變更後 `replied` 重設為 false，避免客服看到已回覆就略過

> ⚠️ **不能用 `updated_at > created_at` 判斷已編輯**：
> `markMessagesReplied()` 是批次 `update()`，Eloquent 會連帶更新 `updated_at`，
> 所有訊息都會被誤判。所以另開 `edited_at` 欄位。

### setWebhook 的 allowed_updates

> ⚠️ 不指定 `allowed_updates` 時 Telegram 用預設清單，
> 而預設**不含 `message_reaction`** —— 表情回應功能會完全收不到事件。
> `TelegramSetWebhookCommand` 現在明確宣告
> `['message', 'edited_message', 'message_reaction']`。
> **改完要重跑 `php artisan telegram:set-webhook` 才生效。**

### webhook 支援的媒體類型（2026-09-07）

> ⚠️ **沒列到的類型會讓整則訊息消失**：`handleMessage()` 判斷完媒體後有一行
> `if (!filled($text) && !filled($mediaType)) return;` ——
> 客人只傳影片沒打字時，`mediaType` 是 null 就直接 return，
> **連訊息紀錄都不會留**。第一版只處理 photo / sticker / document，
> 客人傳影片就完全收不到。

目前支援（`parseOtherMedia()`）：

| Telegram key | 內部 media_type |
|---|---|
| `photo` / `sticker` / `document` | 同名（各自獨立分支） |
| `video` / `animation` / `video_note` | `video` |
| `voice` / `audio` | `audio` |

> `voice` **只用於按住錄音的訊息**（固定 ogg/opus）。
> 使用者當附件傳的 `.wav`、`.mp4` 會走 `document`，
> 因此 document 分支再依 `mime_type` 用 `documentMediaType()` 判斷 ——
> 是可播放的音訊／影片就改成 audio / video，讓它能在對話裡直接播。
>
> 白名單只收 `<audio>` / `<video>` 普遍支援的格式。
> flac、wmv 這類**故意留在 document**：給一個永遠播不出聲音的空播放器，
> 比直接給下載連結更糟。

日後要再擴充（如 `location`、`contact`、`poll`）記得**同時**確認：
Repository 的 select、`TelegramMessageResource`、Pusher payload、
前端 `messages.js` 的渲染分支、`MEDIA_LABELS`。

### 20MB 下載上限

Bot API 的 `getFile` 只能抓 20MB 以內。超過時 `downloadTelegramFile()` 回 null，
但**訊息仍會寫進資料庫**（`media_url` 為 null、content 是 `[影片]` 這類標籤）——
客服至少要知道客人傳了東西。

`downloadTelegramFile()` 的副檔名 fallback **不能一律用 jpg**，
那會讓影片存成 `.jpg` 而播不出來；依前綴給 mp4 / oga / mp3 / bin。

### 媒體替代文字放 config 不放語系

`config('constants.TELEGRAM.MEDIA_LABELS')`。這些字串會**寫進 `content` 欄位**
成為訊息紀錄的一部分，跟著客服當下的語系跑會讓同一筆訊息在不同人眼中不一樣。
（同 [[broadcast]] 補點通知結尾的理由）

### 附件下載要還原原始檔名（2026-09-07）

> ⚠️ **原始檔名無法從網址反推**：`uploadKeepName()` 存檔時會加時間戳前綴，
> 且把非 ASCII 換成底線 —— `YONGXIN接口V3.9.pdf` 落地就變成
> `1788756999_6a9e..._YONGXIN______V3.9.pdf`，**中文在那一刻就沒了**。
> 客戶傳進來的檔案更極端，本地存成 `document_<時間戳>_<亂數>.pdf`，
> 原始檔名完全不在路徑裡。
>
> 因此 `telegram_message` 加了 `media_name` 欄位，四個寫入點都要帶：
> inbound webhook（`document.file_name`）、`sendFileReply()`、
> `sendDocumentFromSharedFile()`、群發公告的 `sendFilesTo()`。
>
> `TelegramRepository` 的 select 與 `TelegramMessageResource`、
> 三處 Pusher 廣播 payload 也都要加，漏掉就恆為 null。

前端 `download="<media_name>"`，舊資料沒有這欄才退回 `fileNameFromUrl()`。
**既有訊息的原始檔名已無法回填**。

### 上傳進度條

`uploadAttachment()` 用 **XMLHttpRequest 而非 fetch** —— fetch 沒有上傳進度事件，
拿不到百分比。大檔案要傳好幾秒，沒有回饋客服會以為當掉而重複按送出。

三個入口（按鈕選檔、貼上、拖曳）共用同一支 `uploadAttachment()`，進度顯示才會一致。

`fileNameFromUrl()`（messages.js）**必須去掉 `時間戳_uniqid_` 前綴**：
`ImageUploadService::uploadKeepName()` 存檔會加這段前綴，
沒有 caption 的檔案訊息會 fallback 用網址取檔名，不去前綴就會顯示成
`1757212345_66dd4f8e2a1b3_報表.xlsx`。任務看板的 `attachmentName()` 用同一套正則。

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
