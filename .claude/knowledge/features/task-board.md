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
- `app/Http/Resources/TaskResource.php` — 含 preloadUsers 靜態快取、previous_status（從 latestArchivedActivity 取得）
- `app/Http/Resources/TaskCommentResource.php`
- `app/Http/Resources/TaskActivityResource.php`

### View
- `resources/views/admin/task-board/index.blade.php`

### 路由
- `routes/web.php` — prefix `task-board`，所有 ajax 端點

## 常數
- `config/constants.php` — TASK.STATUS: PENDING=1, IN_PROGRESS=2, TESTING=3, IN_REVIEW=4, RESOLVED=5, ARCHIVED=6
- `config/constants.php` — TASK.PRIORITY: LOW=1, MEDIUM=2, HIGH=3, URGENT=4

## 注意事項
- `assignee_ids` JSON 欄位可能存整數或字串，查詢時需同時用 `whereJsonContains` 比對 int 和 string
- `LIST_COLUMNS` 需包含 `updated_at`，否則封存清單時間顯示為 1970/1/1
- 封存時 activity changes 記錄原始狀態，供封存清單顯示「原始狀態」欄位
