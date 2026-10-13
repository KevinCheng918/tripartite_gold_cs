# 公告建檔（群發公告 → 公告管理）

> **狀態：已實作，待跑 migration。**

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
code           string(32)    公告編號，**人工輸入**、不可重複（2026-10-13 追加）
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

## 需求方的決定（2026-10-13）

| 問題 | 回答 |
|---|---|
| 選了建檔公告還能改嗎 | **可以改**（範本模式，改動不回寫） |
| 刪公告時歷史紀錄怎麼辦 | **不受影響**（複製路徑、不刪實體檔） |
| 要不要另一個權限 | **要**，`telegram_chat.broadcast_template` |

權限切法：**清單**綁 `broadcast`（能發送的人要看得到下拉選項），
**增刪改**綁 `broadcast_template`（改公版會影響之後每一個人發出去的內容）。

## ⚠ 安全：附件只收「索引」，絕對不收路徑

附件最後會走 `sendDocument($chatId, $diskPath, $name)`，而**那支吃的是本地絕對路徑**。

所以前端一律只送 `template_id` ＋ **要留哪幾個的索引**，路徑由後端拿 id 去
資料庫查。收路徑的話，送一個 `../../.env` 就能把資料庫密碼發給所有客人。

這條規則在兩個地方都適用：

| 動作 | 前端送 | 後端做 |
|---|---|---|
| 發送時帶範本附件 | `template_id` + `keep_image_indexes[]` | 從 DB 的範本挑出那幾個 |
| 編輯範本時保留附件 | `keep_image_indexes[]` | 同上（`pickTemplateAttachments()` 共用） |

⚠ 第一版的編輯功能我寫成「前端送回 `{path, name}` 物件」，理由是「路徑來自
後端剛給的那份資料」—— **那是錯的**，攻擊者直接改 request 就好。改成索引。

⚠ **沒送索引＝一個都不帶**，不是「全部帶走」。FormData 表達不出空陣列
（`append(name, '')` 是空字串，過不了 `array` 驗證），所以語意定成這樣，
由前端負責在選範本／開編輯視窗時就把全部索引裝進去。

## 公告編號（2026-10-13 追加）

需求方選擇**人工輸入**（而不是系統自動流水號），**不發給客人**。

⚠ **DB `nullable`、表單必填**。既有的公告沒有編號，欄位設 NOT NULL 會讓
migration 直接失敗，或被迫塞一個假值進去。DB 寬、表單嚴，既有那幾筆
維持空白（列表顯示「-」）等人去補。

⚠ **加 unique**：編號的用途就是唯一識別，重複的編號等於沒有編號。
MySQL 的 unique 索引允許多個 NULL，所以既有的空白不會互相衝突。

⚠ 更新時的唯一性要 `Rule::unique(...)->ignore($this->route('template'))`，
不然光改標題也會被自己的編號擋下來。

下拉選項是「編號　標題」—— 挑公告時那串編號比標題更好認。

## 其他實作細節

- 標題**不會發給客人**，只在後台辨識用 —— 介面上要講明，否則會有人把
  「【重要】」寫進標題以為客人看得到。
- 更新用 **POST 不是 PUT**：要收 multipart，而 PHP 不解析 PUT 的 multipart body。
- `broadcast_template.image_urls` / `file_urls` 跟 `telegram_broadcast` **同形狀**，
  發送時整個陣列搬過去，`sendPhoto` / `sendDocument` 那條路完全沒動。

## 待辦

- [ ] `php artisan migrate`（新表 `broadcast_template`）
- [ ] 勾權限 `telegram_chat.broadcast_template` 給該建檔的人
- [ ] 刪掉的範本會留下孤兒檔案，之後可以補一支清理指令

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
