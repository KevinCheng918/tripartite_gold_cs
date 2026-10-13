# 公告建檔（群發公告 → 公告管理）

> **狀態：設計稿，等需求方確認。** 底下「待確認」回完再動工。

## 需求（2026-10-13）

> 「我要在群發公告這邊做一個 tab，叫做公告管理，我可以上傳附件和公告內容，
> 然後輸入標題，再發送公告時，我可以選擇我要自己輸入公告內容、圖片，
> 還是選擇已經建檔的公告」

## 現況

群發公告頁現在有兩個分頁：

| 分頁 | 內容 |
|---|---|
| 發送公告 | 內容（≤4096 字）、圖片（≤10 張／每張 5MB）、檔案（≤10 個／每個 50MB）、對象（全部／指定群組）、預約時間 |
| 歷史紀錄 | 發過什麼、發給誰、成功失敗數 |

附件走 `ImageUploadService::uploadKeepName($file, 'broadcast')`，
`telegram_broadcast.image_urls` / `file_urls` 存的是 **`[{path, name}]` 相對路徑**
（不是網址 —— `sendDocument` 要的是本地絕對路徑，留網址還得反推）。

**公告本身沒有標題欄位**，現在只有內容。

## 設計

### 新分頁「公告管理」

```
群發公告
├─ 發送公告
├─ 公告管理   ← 新增
└─ 歷史紀錄
```

列表欄位：標題、內容摘要、附件數、建立者、更新時間、操作（編輯／刪除）。

### 新表 `broadcast_template`

```
title          string(100)   標題（這張表才有，發送紀錄沿用現況不存標題）
content        text          內容
image_urls     json          [{path, name}]，跟 telegram_broadcast 同一個形狀
file_urls      json          同上
created_by     FK user  nullOnDelete
timestamps
```

⚠ `image_urls` / `file_urls` 刻意跟 `telegram_broadcast` **同形狀** ——
發送時直接把陣列搬過去，不必轉換，`sendPhoto` / `sendDocument` 那條路完全不動。

### 發送分頁加「來源」選擇

```
公告來源：  ( ) 自己輸入     (•) 選擇已建檔的公告
                            [ 下拉：選一則公告 ▾ ]
```

選了之後把標題底下的內容與附件帶進表單。

## 待確認

1. **選了建檔公告之後，內容還可以改嗎？**
   - **A（我的建議）：可以改。** 把它當「範本」—— 帶進表單後照常編輯，
     送出的是當下表單裡的東西。公告改了不影響已經發出去的。
   - B：唯讀，直接照建檔的送。好處是「發出去的一定等於建檔的」，
     代價是臨時要加一句話就得回去改公告（而那會影響下次所有人）。

2. **附件怎麼處理？**（跟第 1 題連動）
   - **A（我的建議）：帶進表單後可以再加、也可以移除**，送出時以表單為準。
   - B：固定用公告的附件，不能動。

3. **公告被刪掉時，之前用它發出去的歷史紀錄怎麼辦？**
   - **A（我的建議）：歷史紀錄不受影響。** 發送時把附件路徑**複製**進
     `telegram_broadcast`（現在就是這個形狀），兩邊各自獨立；
     刪公告只刪 `broadcast_template` 那一列，**不刪實體檔**
     （實體檔可能還被歷史紀錄引用）。
     代價是刪掉的公告留下孤兒檔案 —— 可以之後補一支清理指令。
   - B：刪公告連實體檔一起刪 → 歷史紀錄的附件會變成死連結。

4. **「公告管理」要不要另一個權限？**
   現在整頁是 `telegram_chat.broadcast`。
   - **A（我的建議）：沿用同一個。** 能群發的人本來就能決定發什麼內容，
     多一個 keyword 只是多一個要記得勾的東西。
   - B：另開 `broadcast.template_manage`，分開「能發」與「能改公版」。

5. **公告要不要「停用」而不是刪除？**
   季節性的公告（例如年節公告）明年還要用，刪掉可惜、留著又會讓下拉變長。
   - **A（我的建議）：這次不做。** 先看實際累積幾則，真的太長再加。
   - B：現在就加一個啟用開關。

## 檔案（預估）

**新增**
```
database/migrations/..._create_broadcast_template_table.php
app/Models/BroadcastTemplate.php
app/Repositories/BroadcastTemplateRepository.php
app/Http/Requests/TelegramBroadcast/StoreTemplateRequest.php
app/Http/Requests/TelegramBroadcast/UpdateTemplateRequest.php
app/Http/Resources/BroadcastTemplateResource.php
```

**修改**
```
app/Services/TelegramBroadcastService.php          範本的 CRUD
app/Http/Controllers/Admin/TelegramBroadcastController.php
routes/web.php
resources/views/admin/telegram-broadcast/index.blade.php   新分頁 + 編輯 modal
public/js/broadcast.js
resources/lang/{tw,cn,en}/broadcast.php
```

## 相關

- [[broadcast]] —— 群發公告本體
- [[telegram-upload-limit]] —— 附件 50MB 是 Bot API 的硬上限
