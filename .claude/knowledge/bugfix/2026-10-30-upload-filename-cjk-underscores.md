# 上傳的中文檔名變成一串底線

**日期**：2026-10-30（v3.50）
**回報**：「任務版，檔案上傳的檔案名稱，現在都會變成 `_____________日期時間`」

## 症狀

上傳中文檔名的附件，顯示出來的檔名變成一串底線，中間夾著看起來像日期的數字：

| 上傳的檔名 | 顯示出來 |
|---|---|
| `營運報表.xlsx` | `____________.xlsx` |
| `2026年10月對帳單.pdf` | `2026___10____________.pdf` |
| `日本語ファイル.txt` | `_____________________.txt` |

數字與英文活得下來、中文全變底線 —— 所以回報的人看到的是
「一串底線加日期時間」。

## 原因：`preg_replace` 沒有 `/u`，所以是逐 **byte** 處理

`ImageUploadService::uploadKeepName()`：

```php
$safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $file->getClientOriginalName());
```

⚠ **沒有 `/u` 旗標**，PCRE 就把字串當成一串單位元組來處理。
一個中文字在 UTF-8 是 **3 個 byte**，每個 byte 都不在 `a-zA-Z0-9._-` 裡面，
於是**一個字變成三個底線**。

這也解釋了為什麼數字會留下來：`2026` 與 `10` 本來就是 ASCII。

## 修法

```php
$safe = preg_replace('/[^\p{L}\p{N}._-]/u', '_', $originalName);
```

⚠ **兩個地方都要改，缺一個都沒用**：

1. 加 `/u` —— 不加的話 `\p{L}` 這類 Unicode 屬性根本不會被當成屬性
2. 字元類別換成 `\p{L}\p{N}`（任何語言的文字與數字）——
   只加 `/u` 但維持 `a-zA-Z0-9` 的話，中文還是會變底線，
   只是從「一字三個」變成「一字一個」

⚠ **路徑攻擊照樣擋得住**：`/`、`\`、`:` 都不是文字也不是數字，
一樣會被換成底線（`../../etc/passwd` → `.._.._etc_passwd`）。

## ⚠ 放寬字元類別**新帶出來**的風險：檔名長度

這是改完才會踩到的坑，所以一起處理了：

- ext4 單一檔名上限 **255 byte**
- 中文一個字佔 **3 byte**
- 前面還要接 `time() . '_' . uniqid() . '_'`（25 byte）

以前只留 ASCII，要 255 個字才會超過；現在 **85 個中文字就滿了**，
而超過的話 `putFileAs()` 直接丟例外 —— 使用者看到「上傳失敗」，
完全看不出原因是檔名太長。

所以加了 `FILENAME_MAX_BYTES = 200` 的截斷：

⚠ **用 `mb_strcut()` 不是 `mb_substr()`**：要砍的是 **byte 數**
（檔案系統的上限是 byte），`mb_substr()` 數的是字元。
`mb_strcut()` 會自己避開切在多位元組字元中間。

⚠ **副檔名要留著**（它決定開啟方式），所以只砍主檔名那一段。
用 `strrpos`／`substr` 找最後一個點是安全的：UTF-8 的續接位元組一定
`>= 0x80`，不可能等於 `.`（`0x2E`），所以不會切在字元中間。

## 影響範圍：五處

`uploadKeepName()`／`uploadMultipleKeepName()` 的呼叫端：

| 位置 | 用途 |
|---|---|
| `TaskBoardController::ajaxStoreTask()` | 任務附件（新增）|
| `TaskBoardController::ajaxUpdateTask()` | 任務附件（更新）|
| `TaskBoardController::ajaxUploadEditorFile()` | 任務描述內嵌的檔案 |
| `TaskBoardController::ajaxAddComment()` | 留言附件 |
| `TelegramChatController` / `TelegramBroadcastService` | Telegram 聊天與廣播的檔案 |

一次全部受益。

## ⚠ 既有檔案修不回來

已經存下來的檔名就是那個樣子 —— 要改得同時改 DB 裡的路徑參照與磁碟上的
實體檔案，而且**不可逆**。這次只修「之後上傳的」，版本紀錄裡也寫明了。

## 順帶一提：內嵌檔案的連結文字本來就是對的

`ajaxUploadEditorFile()` 回傳的 `name` 是
`$file->getClientOriginalName()`（**原始**檔名），所以描述裡那個
`<a>📎 檔名</a>` 一直都顯示正確的中文 —— 壞掉的只有「從儲存路徑反推檔名」
的那條路（前端的 `attachmentName()`）。

## 相關檔案

| 檔案 | 說明 |
|---|---|
| `app/Services/ImageUploadService.php` | 新增 private `safeFileName()` 與 `FILENAME_MAX_BYTES`；`uploadKeepName()` 改用它 |
