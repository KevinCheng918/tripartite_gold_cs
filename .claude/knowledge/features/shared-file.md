# 文件區（Shared File）

## 現況

已完成，持續迭代。

## 功能概述

兩個分頁：**共用文件**（需 `shared_file.view`）與**個人文件**（所有登入者皆可用自己的）。

- 資料夾（`shared_folder`）→ 檔案（`shared_file`）
- `type` 區分 `shared` / `personal`；個人資料夾以 `user_id` 標示擁有者
- 管理者可從下拉切換查看其他人的個人文件
- 檔案可在 Telegram 對話視窗直接選來傳送（見 [[telegram-chat]]）

## 子資料夾（2026-09-07）

`shared_folder` 新增 `parent_id`（nullable，自我參照 FK）。既有資料夾 `parent_id` 為 null，即最上層。

### 遞迴刪除寫在 Service，不靠 DB cascade

> FK 用 `nullOnDelete` 而非 `cascadeOnDelete` —— **MySQL 自我參照的 FK 串接刪除行為不可靠**。
> 實際的遞迴刪除在 `SharedFileService::deleteFolder()`：
> 先用 `getDescendantIds()` 取整棵子樹，**先刪硬碟實體檔再刪 DB**
> （順序反了就查不到 `file_path`，硬碟會留下孤兒檔），最後由下往上刪資料夾。

`getDescendantIds()` 逐層往下查而非遞迴 SQL —— MySQL 5.7 沒有 CTE。

### 子資料夾強制沿用父層的 type / user_id

否則會出現「共用資料夾底下掛著個人資料夾」這種矛盾結構。
`createFolder()` 有指定 `parent_id` 時，`type` 與 `user_id` 一律取自父層。

> ⚠️ `findFolder()` 的 `select()` **必須含 `type` 和 `user_id`**，
> 少一欄就會建出歸屬錯誤的子資料夾（同 [[telegram-chat]] 補點通知那個坑）。

### 刪除確認要講清楚影響範圍

刪父層會把整棵子樹一起帶走且不可逆，因此確認視窗會加上
「（含 N 個子資料夾及其檔案）」。N 由前端從已載入的樹算出，不另打 API。

### Telegram 選檔顯示完整路徑

有了子資料夾之後，只顯示末端名稱會讓不同層的同名資料夾分不出來，
`getFilesForTelegram()` 的 `folder_name` 改為 `父 / 子 / 孫`。
組路徑時有 20 層上限，資料若因故成環才不會跑不完。

### 未實作

**搬移資料夾**。沒有這個功能就不可能產生循環父子關係，
所以程式沒有防呆；日後若要加搬移，**必須先擋「不能搬到自己的後代底下」**。

## JS / CSS 已抽離（2026-09-07）

blade 原本 550 行，內嵌 `<style>` 與 `<script>`，已拆成：

- `public/css/shared-file.css`
- `public/js/shared-file.js`
- `resources/lang/{tw,cn,en}/shared_file.php`（原本 JS 內有 19 處硬編中文）

權限與語系透過 `#shared-file-app` 的 `data-*` 傳給 JS，
與 [[telegram-chat]]、[[quick-reply]] 同一套做法。

> ⚠️ **`<link>` 不能放 `@section('css')`**：layout 的 `@yield('css')` 位在
> `<style>` 標籤**內部**（是給頁面塞 CSS 規則用的）。
> 放 `<link>` 進去等於無效內容，整份樣式表不會載入 ——
> 症狀是選中／hover／分隔線全部消失。
> 正確做法與 task-board 一致：`<link>` 放在 `@section('content')` 開頭。

## 檔案預覽（2026-09-07）

點檔名開 Modal 預覽。支援圖片、PDF、影片、音訊、純文字，其餘明講「無法預覽，請下載」。

- **SVG 用 `<img>` 而非內嵌**：SVG 可夾帶 `<script>`，內嵌會執行，放 `<img>` 不會
- **純文字先看 `content-length`**，超過 1MB 就不抓，否則開一個大 log 會把瀏覽器卡死
- Modal 關閉時清空內容，不然影片／音訊會在背景繼續播
- 不放下載鈕：檔案列表那列本來就有一顆

> ⚠️ **內容元素不能自帶 `max-height` / `overflow`**：
> `modal-dialog-scrollable` 的 modal-body 已經是捲動容器，
> 再包一層會變成兩個容器搶同一個手勢 —— **手機上完全滑不動**。
> iOS 另需 `-webkit-overflow-scrolling: touch` 才有慣性捲動。

> ⚠️ **不要用 `modal-dialog-centered`**：`min-height` 會把 dialog 撐成全高，
> 看起來像上下多一個框。全專案的慣例是 `modal-dialog-scrollable`。

**PDF 在手機一律改用「在新分頁開啟」** —— iOS Safari 的 iframe 內嵌 PDF
只顯示第一頁且無法捲動，這是系統限制，改 CSS 沒用。

## 搬移檔案（2026-09-07）

`ajax-move-file`，**只改 `folder_id`，實體檔不動** ——
檔案以亂數檔名存在同一個目錄下，路徑本來就不帶資料夾資訊。

### 權限要檢查來源與目標兩邊

> ⚠️ 只驗一邊會有兩個漏洞：把**別人的個人檔案搬到自己的資料夾**、
> 或把私人檔案丟進共用區。

`canAccessFolder($user, $folder, $permission)` 統一判斷
（管理者放行、共用區看權限 keyword、個人區看是不是自己的）。
上傳、搬移、刪除三處原本各寫一份幾乎相同的判斷，已收斂到這支。

> 順帶修掉：`ajaxDeleteFile` 原本寫成 `$folder && ...`，
> **資料夾取不到時反而直接放行**。改為取不到就拒絕。

### 選資料夾的介面

第一版把所有資料夾攤平列出（帶完整路徑），使用者反映混淆 ——
清單同時兼具「選取」與「導覽」兩種語意，點下去會發生什麼不明確。

現在比照檔案總管：**清單只負責瀏覽，動作固定在底部一顆按鈕**，
目標永遠是目前瀏覽到的那一層，按鈕文字直接寫出資料夾名稱（`移到「456」`）。

- 停用狀態會說明原因（還沒選資料夾／已經在這個資料夾），不讓按鈕默默消失
- 開啟時顯示「目前位置」，並直接進到檔案所在的那一區
- 檔案原本的資料夾在清單中標記徽章

## 上傳限制

`UploadFileRequest` 使用共用的 `BlocksExecutableUploads` trait
（黑名單在 `config('rules.UPLOAD_BLOCKED_EXTENSIONS')`，與任務看板、Telegram 傳檔、群發公告同一份）。
上限 20MB。

## 架構檔案

- `app/Models/SharedFolder.php` — `parent()` / `children()`
- `app/Models/SharedFile.php`
- `app/Http/Controllers/Admin/SharedFileController.php`
- `app/Services/SharedFileService.php` — `createFolder()` / `deleteFolder()` / `getDeleteImpact()`
- `app/Repositories/SharedFileRepository.php` — `getDescendantIds()` / `getFilesByFolders()` / `deleteFoldersByIds()`
- `database/migrations/2026_08_24_000001_create_shared_file_tables.php`
- `database/migrations/2026_09_07_000003_add_parent_id_to_shared_folder_table.php`

## 注意事項

- 權限 keyword：`shared_file.view` / `upload` / `delete`
- 共用區要有 `shared_file.upload` 才能建資料夾，個人區一律可以
- 上傳的 `original_name` 存的是使用者當下的檔名，**不做任何加工**。
  若使用者上傳的是先前從 Telegram 下載的檔案，檔名本來就帶
  `時間戳_uniqid_` 前綴，那是來源檔名而非程式的問題
