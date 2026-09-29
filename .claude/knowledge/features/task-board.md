# 任務看板（Task Board）

## 現況

已完成，持續迭代。

## 功能概述

Kanban 風格任務看板，五欄分組：待處理、進行中、測試中、審核中、已解決。

### 核心功能
- 拖曳移動任務（SortableJS）
- Notion 風格側邊面板：屬性網格、TinyMCE 描述、留言（含圖片/emoji）、圖片附件
- 屬性 inline 編輯（狀態/優先/到期日）與 Modal 編輯（專案/站台/指派人員）
- 站台選擇器（系統篩選 + 文字搜尋 + 金黃色高亮）
- 多人指派（assignee_ids JSON 欄位）
- 活動紀錄（欄位級 diff 追蹤，分頁顯示）

### 留言編輯（2026-08-27）
- **只有本人能編輯自己的留言**，不另設權限 keyword；管理者刪除他人留言仍走既有的 `task_board.delete_comment`
- **只能改文字，圖片維持原樣**（編輯區會提示「圖片無法編輯」）；要改圖只能刪掉重發
- 雙層把關：`TaskCommentResource` 的 `is_mine` 決定前端是否顯示編輯鈕，
  `ajaxUpdateComment()` 再比對 `$comment->user_id !== Auth::id()` 回 403
- 「已編輯」標記用 `updated_at->gt(created_at)` 判斷，**不需要額外欄位或 migration**
  （`getComments()` 的 select 必須含 `updated_at`，否則判斷永遠是 false）
- 留言內容渲染改為 `escapeHtml()` 後插入 —— 原本 `c.content` 直接串進 HTML，有 XSS 風險

### 描述勾選清單（2026-09-04）
- 勾選框存在描述 HTML 裡（`<input type="checkbox">`），**沒有獨立資料表**
- 打勾走專用端點 `ajax-update-checklist`，只覆寫 description，**不寫活動紀錄**
  （每打一個勾就記一筆會把活動紀錄灌爆）
- 權限沿用 `task_board.update`，不另設 keyword；沒權限時前端不綁事件、後端 middleware 再擋一次
- 進度文字（`已完成 :done / :total`）渲染在**欄位標題旁**，不能放進 `.field-value`
  —— 那塊的 innerHTML 會被原樣存回 description，放進去會把進度字串寫進資料庫
- 描述預設為唯讀（可打勾），按右上角「編輯」才開 TinyMCE，避免誤改內容
- 清單分層樣式在 `public/css/task-content.css`，**詳情面板與 TinyMCE 共用同一份**
  （TinyMCE 用 `content_css` 載入，改樣式只要改這個檔）

### 附件上傳（2026-09-04）
- 任務附件不限圖片；`images` 欄位名稱沿用舊名，實際收各類檔案
- **四處**共用 `app/Http/Requests/TaskBoard/Concerns/HasAttachmentRules.php`：
  新增任務、更新任務、描述編輯器上傳、**留言**。
  上限 20MB + 26 種可執行／腳本副檔名黑名單。**不要各寫各的**，否則會一鬆一緊
- 黑名單是必要的：檔案落在 `storage/app/public` 底下且對外可直接存取
- 非圖片附件用 `ImageUploadService::uploadKeepName()` 儲存（`upload()` 的 uniqid 命名會讓人看不出檔名）
- 前端 `attachmentName()` 會去掉 `時間戳_uniqid_` 前綴才顯示

### 附件刪除（2026-09-09）

縮圖／檔案卡片右上角的紅色 `×`，確認彈窗會顯示檔名避免刪錯。
`TaskBoardService::deleteAttachment()` 同時移除 `images` 的參照與實體檔案。

| 決定 | 原因 |
|------|------|
| **傳 URL 而非陣列索引** | 索引在多人同時操作時會錯位 —— 你要刪第 2 個，別人剛上傳新檔案，就會刪到別人的 |
| **後端用 `asset("storage/{$path}")` 產 URL 來比對**，不剝 URL 前綴 | `APP_URL` 或子目錄部署變動時剝前綴會對不上；用同一套產生規則比對才不會有落差 |
| 權限沿用 `task_board.update` | 與上傳同一個，能上傳就能刪。無權限時**不顯示**按鈕而非 disable |
| 實體檔案刪除失敗只記 log 不擋流程 | 檔案可能早被手動清掉；DB 參照移除才是使用者看得到的結果 |
| 會寫活動紀錄（附件 → 已刪除） | 與其他操作一致，可追溯 |

**尚未支援**：留言的附件只能連同整則留言刪除，無法單獨刪某一個
（渲染時 `attachmentFileHtml(url, false)` 刻意傳 `canDelete = false`）。

### 留言附件不限圖片（2026-09-09）

留言原本只收圖片（`image|max:5120` + `accept="image/*"`），已改為與任務附件一致：

- `StoreCommentRequest` 改用 `HasAttachmentRules`，20MB + 副檔名黑名單
- 上傳改用 `uploadMultipleKeepName()`。原本的 `uploadMultiple()` 是 uniqid 命名，
  圖片沒差，但**檔案下載後會完全看不出是什麼**
- 顯示沿用 `isImageUrl()` 分流：圖片縮圖、非圖片走 `attachmentFileHtml()`

送出前的預覽（`renderCommentImagePreviews()`）有個容易改壞的地方：
**必須先同步依序放好卡片，縮圖再非同步塞進 `<img>`**。
若照 `FileReader.onload` 的順序 `append`，載入快的小圖會插到前面，
畫面順序就跟 `commentImageFiles` 的索引對不上，使用者會刪錯檔案。

### 長內容的溢出處理（2026-09-09）

註冊表路徑、網址這類**沒有空白可斷的長字串**會把整塊內容撐寬，
手機上就超出卡片範圍、內容被推到畫面外。四處都要處理：

| 位置 | 處理 |
|------|------|
| `.tb-content`（描述，`public/css/task-content.css`） | `overflow-wrap` + `word-break` |
| `.comment-text`（留言內文） | 同上；`pre-wrap` 只保留換行，**不會**斷長字串 |
| `.kanban-card`（看板卡片） | 同上，長標題會撐出欄外 |
| `#task-side-panel` | `overflow-x: hidden` 作為保險 |

**`<pre>` 程式碼區塊刻意不斷字**，改成 `overflow-x: auto` 自己橫向捲動 ——
在任意位置折行會改變程式碼的意思，複製出去可能是壞的。
表格同理（`display: block` + 自身捲動），圖片則是 `max-width: 100%`。

`task-content.css` 由詳情面板與 TinyMCE 編輯器共用（`content_css`），
改一次兩邊同時生效，不要在 blade 裡另寫一份。

### 附件的 dark mode

樣式在頁面 `<style>` 的 `.attachment-item` / `.attachment-thumb` /
`.attachment-file` / `.attachment-del`，**不要寫回 inline style** ——
inline 無法表達 `[data-theme="dark"]` 選擇器。

- 刪除鈕外框在 light 是 `#fff`，dark 必須改成 `#1e1e1e`（`#side-panel-inner`
  的實際底色）。沿用白框會在深色底上浮成一圈亮邊
- 檔案卡片在 dark 要自己給底色與文字色，否則是白底白字幾乎看不見
- 刪除鈕平常 `opacity: 0`，hover 附件才浮現；但**必須有
  `@media (hover: none) { opacity: 1 }`**，否則觸控裝置沒有 hover 會完全按不到

### 封存系統
- 任務封存（status = 6），不直接刪除
- 封存清單 Modal（modal-xl）：顯示專案、標題、原始狀態、指派人員、封存時間、剩餘天數
- 前端篩選：專案/人員/時間（從封存資料動態產生選項）
- 還原功能：封存任務回到「待處理」
- 自動清理：超過 30 天封存任務自動刪除

### 篩選
- 看板篩選：專案、人員（assignee_id + assignee_ids 同時查）、優先順序、關鍵字、排序
- 封存篩選：專案、指派人員、時間範圍（前端過濾）

## 架構檔案

### Model / Migration
- `app/Models/Task.php` — relations: project, station.system, assignee, creator, comments, latestArchivedActivity
- `app/Models/TaskComment.php`
- `app/Models/TaskActivity.php`
- `database/migrations/2026_08_19_*_create_task_table.php`
- `database/migrations/2026_08_20_*_create_task_comment_table.php`
- `database/migrations/2026_08_20_*_create_task_activity_table.php`

### Controller / Service / Repository
- `app/Http/Controllers/Admin/TaskBoardController.php`
- `app/Services/TaskBoardService.php`
- `app/Repositories/TaskRepository.php`

### Request / Resource
- `app/Http/Requests/TaskBoard/StoreTaskRequest.php`
- `app/Http/Requests/TaskBoard/UpdateTaskRequest.php`
- `app/Http/Requests/TaskBoard/MoveTaskRequest.php`
- `app/Http/Requests/TaskBoard/ReorderTaskRequest.php`
- `app/Http/Requests/TaskBoard/StoreProjectRequest.php`
- `app/Http/Requests/TaskBoard/StoreCommentRequest.php` — 留言附件不限圖片，共用 HasAttachmentRules
- `app/Http/Requests/TaskBoard/UpdateCommentRequest.php` — 只驗證 content（圖片不可異動）
- `app/Http/Requests/TaskBoard/UpdateChecklistRequest.php` — 只驗證 description
- `app/Http/Requests/TaskBoard/UploadEditorImageRequest.php` — 編輯器圖片（上限 5MB）
- `app/Http/Requests/TaskBoard/UploadEditorFileRequest.php` — 編輯器附件（上限 20MB）
- `app/Http/Requests/TaskBoard/DeleteAttachmentRequest.php` — 只驗證 url（刻意不用陣列索引）
- `app/Http/Requests/TaskBoard/Concerns/HasAttachmentRules.php` — 附件共用規則 trait
- `app/Http/Resources/TaskResource.php` — 含 preloadUsers 靜態快取、previous_status（從 latestArchivedActivity 取得）
- `app/Http/Resources/TaskCommentResource.php` — 含 `is_mine`（本人才顯示編輯鈕）、`is_edited`
- `app/Http/Resources/TaskActivityResource.php`

### View
- `resources/views/admin/task-board/index.blade.php`
- `public/css/task-board.css` — **看板的全部樣式**（2026-09-29 從 blade 的 inline `<style>` 搬出）
- `public/css/task-content.css` — 描述內容樣式（清單分層、勾選清單），詳情面板與 TinyMCE 共用

### 路由
- `routes/web.php` — prefix `task-board`，所有 ajax 端點

## 常數
- `config/constants.php` — TASK.STATUS: PENDING=1, IN_PROGRESS=2, TESTING=3, IN_REVIEW=4, RESOLVED=5, ARCHIVED=6
- `config/constants.php` — TASK.PRIORITY: LOW=1, MEDIUM=2, HIGH=3, URGENT=4

## 拖曳（SortableJS）

`initSortable()`。設定與 [[quick-reply]] 的題庫排序共用同一組原則：

- `forceFallback: true` —— 原生 HTML5 DnD 在**部分 Windows 環境整個失效**（Mac 正常），
  fallback 模式各平台一致。需搭配 `.sortable-fallback` 樣式
- 長按延遲用全域的 `isCoarsePointer()`（`public/js/common.js`）決定，
  **不要靠 `delayOnTouchOnly`** —— Windows 虛擬機常回報具備觸控能力，
  會讓滑鼠拖曳被當成觸控而拖不動
- 重新渲染前先 `destroy()` 舊實例，否則同容器疊出多個實例會互搶事件

### 拖曳時不要選到文字（2026-09-29）

桌機拖卡片會把卡片上的文字整片反白 —— `forceFallback` 配上桌機的 `delay: 0`，
等於按下去就開始拖，瀏覽器的文字選取同時也啟動了，兩件事撞在一起。
手機長按還會多跳一個系統的複製選單。

`.kanban-card` 加 `user-select: none` + `-webkit-touch-callout: none`
（作法同 [[quick-reply]] 的 `.js-qr-item`）。卡片上只有標題與標籤，
要複製內容點開右側面板就有。

### 拖到哪一欄，那一欄就框起來（2026-09-29）

`onMove` 把游標所在的 `.kanban-column` 加上 `is-drop-target`，
`onStart` 先標來源欄，`onEnd` 清掉。

- **`onMove` 不可以 `return false`** —— 那會被 SortableJS 當成「不允許放置」
- **只在換欄時才動 DOM**。`onMove` 在拖曳期間會連續觸發很多次，
  每次都把五欄全部 remove/add 是白做工
- 拖曳取消（放回原處、按 ESC）一樣會走 `onEnd`，所以高亮清在那裡就夠

外框下在 `.kanban-column`（框住整欄、含標題列），填色下在 `.card-list`。
三個踩過的點：

> ⚠️ **外框必須用 `outline`，不能用 inset `box-shadow`。**
> 欄位裡的 header 與 card-list 加起來就佔滿整欄，父層的 inset 陰影會畫在
> 子元素底下 —— 等於完全看不見。

> ⚠️ **`.kanban-board` 的 `padding: 6px` 是給外框用的，不要拿掉。**
> outline 畫在元素外緣，而外層 `.kanban-board-wrapper` 是 `overflow-x: auto`，
> 沒有這圈 padding 的話，第一欄與最後一欄的外框會被裁掉（或多撐出一截捲軸）。
> 6px 剛好容得下 `outline-offset: 2px` + `寬 3px`。

> ⚠️ **填色用 inset `box-shadow`，不要動 `background`。**
> 深色模式那條 `[data-theme="dark"] .card-list { background: … !important }`
> 會蓋掉任何 background；改用陰影就不必跟 `!important` 打架，
> 淺色深色也能共用同一組規則（只換顏色）。
> `inset 0 0 0 9999px` 等於整塊填色。

### 樣式全部在 `public/css/task-board.css`（2026-09-29）

原本 158 行 CSS 寫在 blade 的 inline `<style>` 裡，已整份搬出來。

> **搬成獨立檔，不要併進 `app.css`。**
> blade 裡的 `<link>` 位置跟原本的 `<style>` 一樣在 `@section('content')` 內，
> 所以**載入順序與特異性完全不變** —— 併進 head 載入的 `app.css` 反而會讓
> 它跟 `custom.css` 的既有規則重新比順序，風險無謂。
> 旁邊的 `task-content.css` 也是同一個模式。

拖曳高亮那幾條原本放在 `app.css`，一併移過來 ——
**任務看板的樣式只放這一個檔案**，不要再散到 `app.css` 或 blade 裡。

## 首屏載入（2026-09-29）

進頁面會「卡一下」卡片才出現 —— 卡片是進頁面後才用 ajax 撈的，在那之前
五欄完全空白，看起來像當掉。後端不是瓶頸（`getBoard()` 實測 12ms、6 次查詢，
eager loading 都有做），問題純粹在前端流程。

兩件事：

### 骨架卡

`partials/skeleton.blade.php`，五欄各放三張灰色佔位卡。

- **首屏那份是 blade 直接渲染的**，不必等 JS
- 換篩選條件、拖完重載時由 `showBoardSkeleton()` 補上 ——
  不換的話畫面會停在舊資料，使用者不知道新的還在路上
- `loadBoard()` 成功後整個 `html()` 覆蓋掉，不需要另外清

> ⚠️ **`$.ajax` 一定要有 `error` 分支。**
> 原本只有 `success`，加了骨架之後請求一失敗，骨架就會永遠閃在那裡，
> 看起來像永遠載不完。

### TinyMCE 改成 defer

`tinymce.min.js` 有 **422KB**，但只有開「新增任務」或編輯描述時才用得到。
原本是同步載入，瀏覽器會停下來等它下載完才繼續解析與繪製，
看板卡片也跟著晚一步，而且它還跟看板的 ajax 搶頻寬。

> ⚠️ **defer 的執行順序是「HTML 解析完之後」，所以後面那支 inline script
> 反而會先跑。** 這裡之所以安全，是因為所有 `tinymce.*` 都寫在函式或事件
> 回呼裡，沒有一處在頂層立即執行 —— 真要在頂層用到就得把 defer 拿掉。
> 使用者也不會「太早」點到：defer 保證在 `DOMContentLoaded` 之前執行完，
> 而按鈕要等頁面可互動才按得到。

## 注意事項
- `assignee_ids` JSON 欄位可能存整數或字串，查詢時需同時用 `whereJsonContains` 比對 int 和 string
- `LIST_COLUMNS` 需包含 `updated_at`，否則封存清單時間顯示為 1970/1/1
- 封存時 activity changes 記錄原始狀態，供封存清單顯示「原始狀態」欄位
- 留言編輯**不寫入活動紀錄**，與「新增留言也不記錄」的既有行為保持一致
- 新增任務 modal 的站台選擇器是收合式浮層（`.tb-station-panel` 用 `position: absolute`），
  展開時不能把底下欄位往下擠；詳情面板那組仍是常駐清單，兩者程式碼是分開的
- 詳情面板動態產生的站台清單（blade 約 1174 行）還有硬編中文「未選擇／系統：／站台：」，
  語系 key 都已存在（`station_unset`、`field_system`、`field_station`），待換掉
