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
- 三處共用 `app/Http/Requests/TaskBoard/Concerns/HasAttachmentRules.php`：
  上限 20MB + 26 種可執行／腳本副檔名黑名單。**不要各寫各的**，否則會一鬆一緊
- 黑名單是必要的：檔案落在 `storage/app/public` 底下且對外可直接存取
- 非圖片附件用 `ImageUploadService::uploadKeepName()` 儲存（`upload()` 的 uniqid 命名會讓人看不出檔名）
- 前端 `attachmentName()` 會去掉 `時間戳_uniqid_` 前綴才顯示

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
- `app/Http/Requests/TaskBoard/StoreCommentRequest.php`
- `app/Http/Requests/TaskBoard/UpdateCommentRequest.php` — 只驗證 content（圖片不可異動）
- `app/Http/Requests/TaskBoard/UpdateChecklistRequest.php` — 只驗證 description
- `app/Http/Requests/TaskBoard/UploadEditorImageRequest.php` — 編輯器圖片（上限 5MB）
- `app/Http/Requests/TaskBoard/UploadEditorFileRequest.php` — 編輯器附件（上限 20MB）
- `app/Http/Requests/TaskBoard/Concerns/HasAttachmentRules.php` — 附件共用規則 trait
- `app/Http/Resources/TaskResource.php` — 含 preloadUsers 靜態快取、previous_status（從 latestArchivedActivity 取得）
- `app/Http/Resources/TaskCommentResource.php` — 含 `is_mine`（本人才顯示編輯鈕）、`is_edited`
- `app/Http/Resources/TaskActivityResource.php`

### View
- `resources/views/admin/task-board/index.blade.php`
- `public/css/task-content.css` — 描述內容樣式（清單分層、勾選清單），詳情面板與 TinyMCE 共用

### 路由
- `routes/web.php` — prefix `task-board`，所有 ajax 端點

## 常數
- `config/constants.php` — TASK.STATUS: PENDING=1, IN_PROGRESS=2, TESTING=3, IN_REVIEW=4, RESOLVED=5, ARCHIVED=6
- `config/constants.php` — TASK.PRIORITY: LOW=1, MEDIUM=2, HIGH=3, URGENT=4

## 注意事項
- `assignee_ids` JSON 欄位可能存整數或字串，查詢時需同時用 `whereJsonContains` 比對 int 和 string
- `LIST_COLUMNS` 需包含 `updated_at`，否則封存清單時間顯示為 1970/1/1
- 封存時 activity changes 記錄原始狀態，供封存清單顯示「原始狀態」欄位
- 留言編輯**不寫入活動紀錄**，與「新增留言也不記錄」的既有行為保持一致
- 新增任務 modal 的站台選擇器是收合式浮層（`.tb-station-panel` 用 `position: absolute`），
  展開時不能把底下欄位往下擠；詳情面板那組仍是常駐清單，兩者程式碼是分開的
- 詳情面板動態產生的站台清單（blade 約 1174 行）還有硬編中文「未選擇／系統：／站台：」，
  語系 key 都已存在（`station_unset`、`field_system`、`field_station`），待換掉
