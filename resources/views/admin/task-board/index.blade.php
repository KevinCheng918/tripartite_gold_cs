@extends('layouts.app')

@section('title', trans('task_board.page_title'))
@section('icon', 'columns')
@section('subtitle', trans('task_board.subtitle'))

@section('content')

    <style>
        .app-main__outer, .app-main__inner { overflow-x: hidden !important; }
        .kanban-board-wrapper { overflow-x: auto; width: 100%; }
        .kanban-board { display: inline-flex; gap: 0.75rem; min-height: 65vh; min-width: 1500px; }
        .kanban-column { flex: 1; min-width: 0; display: flex; flex-direction: column; border-radius: 0.5rem; overflow: hidden; }
        .kanban-column .card-list { flex: 1; }
        .kanban-column .column-header { padding: 0.75rem 1rem; border-radius: 0.5rem 0.5rem 0 0; font-weight: bold; display: flex; justify-content: space-between; align-items: center; }
        .kanban-column .card-list { flex: 1; padding: 0.5rem; border-radius: 0 0 0.5rem 0.5rem; min-height: 100px; background: #f1f3f5; }
        .kanban-card { background: #fff; border-radius: 0.5rem; padding: 0.75rem; margin-bottom: 0.5rem; cursor: grab; box-shadow: 0 1px 3px rgba(0,0,0,0.08); transition: box-shadow 0.2s; border-left: 3px solid transparent; }
        .kanban-card:hover { box-shadow: 0 3px 8px rgba(0,0,0,0.12); }
        .kanban-card.sortable-ghost { opacity: 0.4; }
        .kanban-card .card-project { font-size: 0.75rem; color: #6c757d; margin-bottom: 0.25rem; }
        .kanban-card .card-title-text { font-weight: 600; font-size: 0.9375rem; margin-bottom: 0.5rem; }
        .kanban-card .card-meta { display: flex; justify-content: space-between; align-items: center; font-size: 0.8125rem; }
        .priority-low    { border-left-color: #6c757d !important; }
        .priority-medium { border-left-color: #0dcaf0 !important; }
        .priority-high   { border-left-color: #ffc107 !important; }
        .priority-urgent { border-left-color: #dc3545 !important; }
        .col-pending .column-header     { background: #e9ecef; color: #495057; }
        .col-in-progress .column-header { background: #cfe2ff; color: #084298; }
        .col-testing .column-header     { background: #e8daef; color: #6c3483; }
        .col-in-review .column-header   { background: #fff3cd; color: #664d03; }
        .col-resolved .column-header    { background: #d1e7dd; color: #0f5132; }
        .col-pending .card-list     { background: #f8f9fa; }
        .col-in-progress .card-list { background: #f0f6ff; }
        .col-testing .card-list     { background: #f5f0fa; }
        .col-in-review .card-list   { background: #fffcf0; }
        .col-resolved .card-list    { background: #f0faf4; }
        /* dark mode */
        [data-theme="dark"] .kanban-card { background: #2d2d2d; color: #e0e0e0; }
        [data-theme="dark"] .kanban-card .card-project { color: #999; }
        [data-theme="dark"] .card-list { background: #1e1e1e !important; }
        [data-theme="dark"] .col-pending .column-header     { background: #333; color: #ccc; }
        [data-theme="dark"] .col-in-progress .column-header { background: #1a3a5c; color: #8ec5fc; }
        [data-theme="dark"] .col-testing .column-header     { background: #2d1f3d; color: #bb86fc; }
        [data-theme="dark"] .col-in-review .column-header   { background: #4a3f1f; color: #ffd966; }
        [data-theme="dark"] .col-resolved .column-header    { background: #1a3a2a; color: #81c784; }
        /* 側邊面板 */
        #task-side-panel.open { transform: translateX(0) !important; }
        #side-panel-resize:hover { background: rgba(0,123,255,0.3); }
        #side-panel-inner { background: #fff; }
        [data-theme="dark"] #side-panel-inner { background: #1e1e1e; color: #e0e0e0; }
        [data-theme="dark"] #side-panel-inner .form-control,
        [data-theme="dark"] #side-panel-inner .form-select { background: #2d2d2d; color: #e0e0e0; border-color: #444; }
        [data-theme="dark"] #side-panel-inner .btn-outline-secondary { color: #ccc; border-color: #555; }
        [data-theme="dark"] #side-panel-inner .btn-outline-secondary:hover { background: #333; }
        [data-theme="dark"] #side-panel-inner .table { color: #e0e0e0; }
        [data-theme="dark"] #task-side-overlay { background: rgba(0,0,0,0.5); }
        .side-field { padding: 0.5rem 0; border-bottom: 1px solid #eee; }
        .side-field label { font-size: 0.8125rem; color: #6c757d; margin-bottom: 0.25rem; display: block; }
        .side-field .field-value { cursor: pointer; padding: 0.25rem 0.5rem; border-radius: 0.25rem; min-height: 1.75rem; }
        .side-field .field-value:hover { background: rgba(0,0,0,0.04); }
        [data-theme="dark"] .side-field { border-bottom-color: #333; }
        [data-theme="dark"] .side-field .field-value:hover { background: rgba(255,255,255,0.05); }
        .comment-item { padding: 0.5rem 0; border-bottom: 1px solid #f0f0f0; }
        .comment-item:last-child { border-bottom: none; }
        [data-theme="dark"] .comment-item { border-bottom-color: #333; }
        /* dark mode 優先順序按鈕 */
        [data-theme="dark"] .btn-check:checked + .btn-outline-secondary { background: #888 !important; color: #fff !important; border-color: #888 !important; box-shadow: 0 0 0 3px rgba(136,136,136,0.4) !important; }
        [data-theme="dark"] .btn-check:checked + .btn-outline-info { background: #0aa2c0 !important; color: #fff !important; border-color: #0aa2c0 !important; box-shadow: 0 0 0 3px rgba(10,162,192,0.4) !important; }
        [data-theme="dark"] .btn-check:checked + .btn-outline-warning { background: #b8860b !important; color: #fff !important; border-color: #b8860b !important; box-shadow: 0 0 0 3px rgba(184,134,11,0.4) !important; }
        [data-theme="dark"] .btn-check:checked + .btn-outline-danger { background: #c62828 !important; color: #fff !important; border-color: #c62828 !important; box-shadow: 0 0 0 3px rgba(198,40,40,0.4) !important; }
        /* emoji picker */
        .js-emoji-item:hover { background: rgba(0,0,0,0.08); }
        [data-theme="dark"] #comment-emoji-picker { background: #2d2d2d !important; border-color: #444 !important; }
        [data-theme="dark"] .js-emoji-item:hover { background: rgba(255,255,255,0.1); }
        /* 站台選擇金黃色 */
        .js-station-pick.active { background: linear-gradient(135deg,#d4af37,#a67c00) !important; color: #fff !important; border-color: #a67c00 !important; }
        .js-station-pick.active .text-muted { color: rgba(255,255,255,0.7) !important; }
        [data-theme="dark"] .js-station-pick { color: #e0e0e0; background: #2d2d2d; border-color: #444; }
        [data-theme="dark"] .js-station-pick:hover { background: #3a3a3a; }
        /* 屬性點擊 */
        .side-prop { cursor: pointer; padding: 0.375rem 0.5rem; border-radius: 0.25rem; }
        .side-prop:hover { background: rgba(0,0,0,0.04); }
        .side-prop label { display: block; cursor: pointer; }
        [data-theme="dark"] .side-prop:hover { background: rgba(255,255,255,0.05); }
        /* 描述 HTML 顯示 */
        .side-field .field-value img { max-width: 100%; height: auto; }
        @media (max-width: 767px) {
            #task-side-panel { width: 100% !important; }
        }
    </style>

    {{-- 篩選列 --}}
    <div class="main-card mb-3 card">
        <div class="card-header">
            <div class="row g-2 align-items-center">
                @if(Auth::user()->hasPermission('task_board.create'))
                <div class="col-auto">
                    <button class="btn btn-primary" id="btn-open-create-task">
                        <i class="fas fa-plus me-1"></i>{{ trans('task_board.action_create_task') }}
                    </button>
                </div>
                @endif
                @if(Auth::user()->hasPermission('task_board.manage_project'))
                <div class="col-auto">
                    <button class="btn btn-outline-secondary" id="btn-open-create-project">
                        <i class="fas fa-folder-plus me-1"></i>{{ trans('task_board.action_create_project') }}
                    </button>
                </div>
                @endif
                <div class="col"></div>
                <div class="col-12 col-md-auto">
                    <div class="d-flex gap-2 align-items-center flex-wrap">
                        <select id="filter-project" class="form-select form-select-sm" style="width:auto">
                            <option value="">全部專案</option>
                            @foreach($projects as $p)
                                <option value="{{ $p->id }}">{{ $p->name }}</option>
                            @endforeach
                        </select>
                        <select id="filter-assignee" class="form-select form-select-sm" style="width:auto">
                            <option value="">全部人員</option>
                            @foreach($assignees as $u)
                                <option value="{{ $u->id }}">{{ $u->nickname }}</option>
                            @endforeach
                        </select>
                        <select id="filter-priority" class="form-select form-select-sm" style="width:auto">
                            <option value="">全部優先</option>
                            <option value="1">{{ trans('task_board.priority_low') }}</option>
                            <option value="2">{{ trans('task_board.priority_medium') }}</option>
                            <option value="3">{{ trans('task_board.priority_high') }}</option>
                            <option value="4">{{ trans('task_board.priority_urgent') }}</option>
                        </select>
                        <select id="filter-sort" class="form-select form-select-sm" style="width:auto">
                            <option value="created_desc">建立時間 新→舊</option>
                            <option value="created_asc">建立時間 舊→新</option>
                            <option value="updated_desc">更新時間 新→舊</option>
                            <option value="updated_asc">更新時間 舊→新</option>
                            <option value="priority_desc">優先順序 高→低</option>
                            <option value="priority_asc">優先順序 低→高</option>
                        </select>
                        <input type="text" id="filter-keyword" class="form-control form-control-sm" style="width:140px" placeholder="搜尋標題...">
                        <button class="btn btn-sm btn-outline-secondary" id="btn-filter">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- 看板 --}}
    <div class="kanban-board-wrapper">
    <div class="kanban-board">
        <div class="kanban-column col-pending" data-status="1">
            <div class="column-header">
                <span><i class="fas fa-inbox me-2"></i>{{ trans('task_board.status_pending') }}</span>
                <span class="badge bg-secondary" id="count-pending">0</span>
            </div>
            <div class="card-list" id="list-pending"></div>
        </div>
        <div class="kanban-column col-in-progress" data-status="2">
            <div class="column-header">
                <span><i class="fas fa-spinner me-2"></i>{{ trans('task_board.status_in_progress') }}</span>
                <span class="badge bg-primary" id="count-in-progress">0</span>
            </div>
            <div class="card-list" id="list-in-progress"></div>
        </div>
        <div class="kanban-column col-testing" data-status="3">
            <div class="column-header">
                <span><i class="fas fa-vial me-2"></i>{{ trans('task_board.status_testing') }}</span>
                <span class="badge" style="background:#6f42c1" id="count-testing">0</span>
            </div>
            <div class="card-list" id="list-testing"></div>
        </div>
        <div class="kanban-column col-in-review" data-status="4">
            <div class="column-header">
                <span><i class="fas fa-search me-2"></i>{{ trans('task_board.status_in_review') }}</span>
                <span class="badge bg-warning text-dark" id="count-in-review">0</span>
            </div>
            <div class="card-list" id="list-in-review"></div>
        </div>
        <div class="kanban-column col-resolved" data-status="5">
            <div class="column-header">
                <span><i class="fas fa-check-circle me-2"></i>{{ trans('task_board.status_resolved') }}</span>
                <span class="badge bg-success" id="count-resolved">0</span>
            </div>
            <div class="card-list" id="list-resolved"></div>
        </div>
    </div>
    </div>{{-- end kanban-board-wrapper --}}

    {{-- 新增/編輯任務 Modal --}}
    <div class="modal fade" id="modal-task" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-task-title">{{ trans('task_board.action_create_task') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="form-task">
                        <input type="hidden" id="task-id">
                        <div class="mb-3">
                            <label class="form-label">{{ trans('task_board.field_project') }} <span class="text-danger">*</span></label>
                            <select id="task-project" class="form-select" required>
                                <option value="">請選擇專案</option>
                                @foreach($projects as $p)
                                    <option value="{{ $p->id }}">{{ $p->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">站台</label>
                            <select id="task-station" class="form-select">
                                <option value="">未選擇</option>
                                @foreach($stations as $st)
                                    <option value="{{ $st->id }}">{{ $st->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('task_board.field_title') }} <span class="text-danger">*</span></label>
                            <input type="text" id="task-title" class="form-control" required maxlength="200">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('task_board.field_description') }}</label>
                            <textarea id="task-description" class="tinymce-editor"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('task_board.field_priority') }}</label>
                            <div class="d-flex gap-2">
                                <input type="radio" class="btn-check" name="task_priority" value="1" id="pri-low" autocomplete="off">
                                <label class="btn btn-outline-secondary flex-fill" for="pri-low">{{ trans('task_board.priority_low') }}</label>
                                <input type="radio" class="btn-check" name="task_priority" value="2" id="pri-medium" autocomplete="off" checked>
                                <label class="btn btn-outline-info flex-fill" for="pri-medium">{{ trans('task_board.priority_medium') }}</label>
                                <input type="radio" class="btn-check" name="task_priority" value="3" id="pri-high" autocomplete="off">
                                <label class="btn btn-outline-warning flex-fill" for="pri-high">{{ trans('task_board.priority_high') }}</label>
                                <input type="radio" class="btn-check" name="task_priority" value="4" id="pri-urgent" autocomplete="off">
                                <label class="btn btn-outline-danger flex-fill" for="pri-urgent">{{ trans('task_board.priority_urgent') }}</label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('task_board.field_assignee') }}</label>
                            <div id="task-assignee-list" style="max-height:150px;overflow-y:auto;border:1px solid #dee2e6;border-radius:0.25rem;padding:0.5rem">
                                @foreach($assignees as $u)
                                    <div class="form-check">
                                        <input class="form-check-input js-assignee-check" type="checkbox" value="{{ $u->id }}" id="assignee-{{ $u->id }}">
                                        <label class="form-check-label" for="assignee-{{ $u->id }}">{{ $u->nickname }}</label>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('task_board.field_due_date') }}</label>
                            <input type="date" id="task-due-date" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">上傳圖片</label>
                            <input type="file" class="form-control" id="task-images" accept="image/*" multiple>
                            <div id="task-image-previews" class="d-flex flex-wrap gap-2 mt-2"></div>
                        </div>
                        <div class="text-end">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                            <button type="submit" class="btn btn-primary">確認</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- 側邊面板 --}}
    <div id="task-side-panel" style="display:none;position:fixed;top:60px;right:0;max-width:90%;height:calc(100vh - 60px);z-index:1050;overflow-y:auto;box-shadow:-4px 0 20px rgba(0,0,0,0.15);transition:transform 0.3s ease;transform:translateX(100%)">
        <div id="side-panel-resize" style="position:absolute;left:0;top:0;width:5px;height:100%;cursor:col-resize;z-index:2"></div>
        <div style="min-height:100%;padding:1.5rem" id="side-panel-inner">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div id="side-panel-project-badge"></div>
                <div class="d-flex gap-3 align-items-center" id="side-panel-actions"></div>
            </div>
            <div id="side-panel-body"></div>
        </div>
    </div>
    <div id="task-side-overlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;z-index:1049;background:rgba(0,0,0,0.3)"></div>

    {{-- 新增專案 Modal --}}
    <div class="modal fade" id="modal-project" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ trans('task_board.action_create_project') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="form-project">
                        <div class="mb-3">
                            <label class="form-label">專案名稱 <span class="text-danger">*</span></label>
                            <input type="text" id="project-name" class="form-control" required maxlength="100">
                        </div>
                        <div class="text-end">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                            <button type="submit" class="btn btn-primary">新增</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- 刪除確認 Modal --}}
    {{-- 屬性編輯 Modal --}}
    <div class="modal fade" id="modal-prop-edit" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="prop-edit-title">編輯</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="prop-edit-body"></div>
                <div class="modal-footer d-flex justify-content-between">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary" id="btn-prop-save">確認</button>
                </div>
            </div>
        </div>
    </div>

    {{-- 異動紀錄 Modal --}}
    <div class="modal fade" id="modal-task-activities" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-history me-2"></i>異動紀錄</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="activity-modal-body"></div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modal-delete-confirm" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-body text-center py-4">
                    <p class="mb-3">{{ trans('task_board.msg.confirm_delete') }}</p>
                    <input type="hidden" id="delete-confirm-id">
                    <div class="d-flex justify-content-center gap-2">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                        <button type="button" class="btn btn-danger" id="btn-delete-confirm-ok">確定刪除</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- 刪除留言確認 Modal --}}
    <div class="modal fade" id="modal-delete-comment-confirm" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-body text-center py-4">
                    <p class="mb-3">確定刪除此留言？</p>
                    <input type="hidden" id="delete-comment-confirm-id">
                    <input type="hidden" id="delete-comment-confirm-task-id">
                    <div class="d-flex justify-content-center gap-2">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                        <button type="button" class="btn btn-danger" id="btn-delete-comment-confirm-ok">確定刪除</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- 訊息 Modal --}}
    <div class="modal fade" id="modal-task-msg" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-body text-center py-4">
                    <p id="modal-task-msg-text" class="mb-3"></p>
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
                </div>
            </div>
        </div>
    </div>

@endsection

@section('scripts')
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script>
$(function () {
    var csrfToken = $('meta[name="csrf-token"]').attr('content');
    var canCreate = {{ Auth::user()->hasPermission('task_board.create') ? 'true' : 'false' }};
    var canUpdate = {{ Auth::user()->hasPermission('task_board.update') ? 'true' : 'false' }};
    var canDelete = {{ Auth::user()->hasPermission('task_board.delete') ? 'true' : 'false' }};
    var canDeleteComment = {{ Auth::user()->hasPermission('task_board.delete_comment') ? 'true' : 'false' }};

    function showMsg(msg) {
        $('#modal-task-msg-text').text(msg);
        showBsModal('modal-task-msg');
    }

    var priorityClass = { 1: 'priority-low', 2: 'priority-medium', 3: 'priority-high', 4: 'priority-urgent' };
    var priorityLabel = {
        1: '<span class="badge" style="background:rgba(108,117,125,0.15);color:#6c757d;border-radius:9999px;padding:0.2em 0.6em">{{ trans("task_board.priority_low") }}</span>',
        2: '<span class="badge" style="background:rgba(13,202,240,0.15);color:#0aa2c0;border-radius:9999px;padding:0.2em 0.6em">{{ trans("task_board.priority_medium") }}</span>',
        3: '<span class="badge" style="background:rgba(255,193,7,0.15);color:#b8860b;border-radius:9999px;padding:0.2em 0.6em">{{ trans("task_board.priority_high") }}</span>',
        4: '<span class="badge" style="background:rgba(220,53,69,0.15);color:#dc3545;border-radius:9999px;padding:0.2em 0.6em">{{ trans("task_board.priority_urgent") }}</span>'
    };

    var statusListMap = { 1: 'list-pending', 2: 'list-in-progress', 3: 'list-testing', 4: 'list-in-review', 5: 'list-resolved' };
    var statusCountMap = { 1: 'count-pending', 2: 'count-in-progress', 3: 'count-testing', 4: 'count-in-review', 5: 'count-resolved' };

    // 專案名稱取前兩字做縮寫
    var projectColors = ['#6f42c1', '#0d9488', '#2563eb', '#e85d04', '#d63384', '#0ea5e9'];
    var projectColorCache = {};
    var projectColorIdx = 0;
    function getProjectColor(name) {
        if (!projectColorCache[name]) {
            projectColorCache[name] = projectColors[projectColorIdx % projectColors.length];
            projectColorIdx++;
        }
        return projectColorCache[name];
    }

    function renderCard(task) {
        var pColor = getProjectColor(task.project);

        var html = '<div class="kanban-card ' + (priorityClass[task.priority] || '') + '" data-task-id="' + task.id + '">';
        // 頂部：專案 badge + 優先順序
        html += '<div class="d-flex justify-content-between align-items-center mb-2">';
        html += '<span class="badge" style="background:' + pColor + ';color:#fff;border-radius:9999px;padding:0.25em 0.6em;font-size:0.75rem">' + task.project + '</span>';
        html += (priorityLabel[task.priority] || '');
        html += '</div>';
        // 標題
        html += '<div class="card-title-text">' + task.title + '</div>';
        // 站台/系統
        if (task.station) {
            html += '<div style="font-size:0.75rem;color:#6c757d;margin-bottom:0.25rem"><i class="fas fa-server me-1"></i>' + (task.system ? task.system + ' / ' : '') + task.station + '</div>';
        }
        // 指派人員
        html += '<div style="font-size:0.8125rem;margin-bottom:0.25rem">';
        if (task.assignees && task.assignees.length > 0) {
            html += '<i class="fas fa-users me-1" style="color:#6c757d"></i>';
            var names = [];
            task.assignees.forEach(function (a) { names.push(a.nickname); });
            html += names.join(', ');
        } else {
            html += '<span class="text-muted">未指派</span>';
        }
        html += '</div>';
        // 到期日
        if (task.due_date) {
            var today = new Date();
            today.setHours(0, 0, 0, 0);
            var due = new Date(task.due_date + 'T00:00:00');
            var isMiddle = task.status >= 2 && task.status <= 4;
            var isToday = due.getTime() === today.getTime();
            var tomorrow = new Date(today);
            tomorrow.setDate(tomorrow.getDate() + 1);
            var isTomorrow = due.getTime() === tomorrow.getTime();
            var isOverdue = due < today && task.status !== 5;
            var dueStyle = 'color:#6c757d';
            var dueText = task.due_date;
            if (isToday && isMiddle) {
                dueStyle = 'color:#dc3545;font-weight:bold';
                dueText = '今日到期';
            } else if (isTomorrow && isMiddle) {
                dueStyle = 'color:#b8860b;font-weight:bold';
                dueText = '<i class="fas fa-exclamation-triangle me-1"></i>' + task.due_date + ' 明日到期';
            } else if (isOverdue) {
                dueStyle = 'color:#dc3545';
                dueText = task.due_date + ' <i class="fas fa-exclamation-triangle"></i>';
            }
            html += '<div style="font-size:0.75rem;' + dueStyle + '"><i class="fas fa-calendar me-1"></i>' + dueText + '</div>';
        } else if (task.status >= 2 && task.status <= 4) {
            html += '<div style="font-size:0.75rem;color:#dc3545"><i class="fas fa-exclamation-circle me-1"></i>未設定到期日</div>';
        }
        html += '</div>';
        return html;
    }

    function loadBoard() {
        var params = {};
        var projectId = $('#filter-project').val();
        var assigneeId = $('#filter-assignee').val();
        var priority = $('#filter-priority').val();
        var sortBy = $('#filter-sort').val();
        var keyword = $('#filter-keyword').val();
        if (projectId) params.project_id = projectId;
        if (assigneeId) params.assignee_id = assigneeId;
        if (priority) params.priority = priority;
        if (sortBy && sortBy !== 'sort_order') params.sort = sortBy;
        if (keyword) params.keyword = keyword;

        $.ajax({
            url: '/admin/task-board/ajax-board',
            data: params,
            headers: { 'X-CSRF-TOKEN': csrfToken },
            success: function (data) {
                var columns = {
                    pending: data.pending || [],
                    in_progress: data.in_progress || [],
                    testing: data.testing || [],
                    in_review: data.in_review || [],
                    resolved: data.resolved || []
                };

                var statusKeys = { pending: 1, in_progress: 2, testing: 3, in_review: 4, resolved: 5 };

                Object.keys(columns).forEach(function (key) {
                    var tasks = columns[key];
                    var listId = statusListMap[statusKeys[key]];
                    var countId = statusCountMap[statusKeys[key]];
                    var html = '';
                    tasks.forEach(function (t) { html += renderCard(t); });
                    $('#' + listId).html(html || '<p class="text-muted text-center py-3" style="font-size:0.8125rem">無任務</p>');
                    $('#' + countId).text(tasks.length);
                });

                bindCardClick();
                initSortable();
            }
        });
    }

    // 側邊面板寬度（localStorage 記憶）
    var panelWidth = parseInt(localStorage.getItem('taskPanelWidth'), 10) || 480;
    $('#task-side-panel').css('width', panelWidth + 'px');

    // 拖曳調整寬度
    (function () {
        var isResizing = false;
        var $panel = $('#task-side-panel');

        $('#side-panel-resize').on('mousedown', function (e) {
            isResizing = true;
            e.preventDefault();
            $('body').css({ cursor: 'col-resize', userSelect: 'none' });
        });

        $(document).on('mousemove', function (e) {
            if (!isResizing) return;
            var newWidth = window.innerWidth - e.clientX;
            if (newWidth < 360) newWidth = 360;
            if (newWidth > window.innerWidth * 0.9) newWidth = Math.floor(window.innerWidth * 0.9);
            $panel.css('width', newWidth + 'px');
        });

        $(document).on('mouseup', function () {
            if (!isResizing) return;
            isResizing = false;
            $('body').css({ cursor: '', userSelect: '' });
            panelWidth = parseInt($panel.css('width'), 10);
            localStorage.setItem('taskPanelWidth', panelWidth);
        });
    })();

    var currentTaskId = null;
    var statusLabels = { 1: '{{ trans("task_board.status_pending") }}', 2: '{{ trans("task_board.status_in_progress") }}', 3: '{{ trans("task_board.status_testing") }}', 4: '{{ trans("task_board.status_in_review") }}', 5: '{{ trans("task_board.status_resolved") }}' };

    function openPanel() {
        $('#task-side-panel').show();
        $('#task-side-overlay').show();
        setTimeout(function () { $('#task-side-panel').addClass('open'); }, 10);
    }
    function closePanel() {
        $('#task-side-panel').removeClass('open');
        setTimeout(function () { $('#task-side-panel').hide(); $('#task-side-overlay').hide(); }, 300);
        currentTaskId = null;
    }
    $('#task-side-overlay').on('click', closePanel);

    function inlineField(label, value, fieldName, type) {
        if (!canUpdate) {
            return '<div class="side-field"><label>' + label + '</label><div>' + (value || '-') + '</div></div>';
        }
        return '<div class="side-field" data-field="' + fieldName + '" data-type="' + (type || 'text') + '"><label>' + label + '</label><div class="field-value">' + (value || '<span class="text-muted">點擊編輯</span>') + '</div></div>';
    }

    function loadPanel(taskId) {
        currentTaskId = taskId;
        $('#side-panel-body').html('<p class="text-center py-3 text-muted">Loading...</p>');
        openPanel();

        $.ajax({
            url: '/admin/task-board/ajax-task/' + taskId,
            headers: { 'X-CSRF-TOKEN': csrfToken },
            success: function (body) {
                var t = body.data || body;
                var pColor = getProjectColor(t.project);

                // 頂部：狀態 + 優先順序 + 按鈕列
                var assigneeDisplay = '';
                if (t.assignees && t.assignees.length > 0) {
                    var aNames = [];
                    t.assignees.forEach(function (a) { aNames.push(a.nickname); });
                    assigneeDisplay = aNames.join(', ');
                } else {
                    assigneeDisplay = '<span class="text-muted">未指派</span>';
                }

                $('#side-panel-project-badge').html(
                    '<span class="badge" style="background:' + pColor + ';color:#fff;border-radius:9999px;padding:0.3em 0.8em">' + t.project + '</span> ' +
                    (statusLabels[t.status] ? '<span class="badge bg-secondary ms-1">' + statusLabels[t.status] + '</span>' : '') + ' ' +
                    (priorityLabel[t.priority] || '')
                );

                var actionsHtml = '<button class="btn" id="btn-show-activities" style="background:linear-gradient(135deg,#d4af37,#a67c00);color:#fff;border:none"><i class="fas fa-history me-1"></i>異動紀錄</button>';
                if (canDelete) {
                    actionsHtml += '<button class="btn btn-outline-secondary" id="btn-panel-delete" data-id="' + t.id + '"><i class="fas fa-trash text-danger me-1"></i>{{ trans("task_board.action_delete") }}</button>';
                }
                actionsHtml += '<button type="button" class="btn-close" id="btn-close-panel-inner"></button>';
                $('#side-panel-actions').html(actionsHtml);

                $('#btn-close-panel-inner').on('click', closePanel);

                var html = '';

                // 標題
                html += '<div class="side-field" data-field="title" data-type="text"><div class="field-value" style="font-size:1.375rem;font-weight:bold;padding:0.5rem 0">' + t.title + '</div></div>';

                // 屬性區（集中在上面，虛線邊框）
                html += '<div class="border rounded p-2 mb-3" style="border-style:dashed !important;background:rgba(0,0,0,0.015);font-size:0.8125rem">';
                var stationDisplay = t.station ? (t.system ? t.system + ' / ' : '') + t.station : '<span class="text-muted">未選擇</span>';

                html += '<div class="row g-1">';
                html += '<div class="col-md-4 col-6"><div class="side-prop js-prop-edit" data-field="project_id"><label style="font-size:0.6875rem;color:#6c757d">專案</label><div><i class="fas fa-folder me-1 text-muted"></i>' + t.project + '</div></div></div>';
                html += '<div class="col-md-4 col-6"><div class="side-prop js-prop-edit" data-field="station_id"><label style="font-size:0.6875rem;color:#6c757d">站台</label><div><i class="fas fa-server me-1 text-muted"></i>' + stationDisplay + '</div></div></div>';
                html += '<div class="col-md-4 col-6"><div class="side-prop js-prop-edit" data-field="assignee_ids"><label style="font-size:0.6875rem;color:#6c757d">{{ trans("task_board.field_assignee") }}</label><div><i class="fas fa-users me-1 text-muted"></i>' + assigneeDisplay + '</div></div></div>';
                html += '<div class="col-md-4 col-6"><div class="side-field" data-field="status" data-type="select"><label style="font-size:0.6875rem;color:#6c757d">{{ trans("task_board.field_status") }}</label><div class="field-value">' + (statusLabels[t.status] || '-') + '</div></div></div>';
                html += '<div class="col-md-4 col-6"><div class="side-field" data-field="priority" data-type="select"><label style="font-size:0.6875rem;color:#6c757d">{{ trans("task_board.field_priority") }}</label><div class="field-value">' + (priorityLabel[t.priority] || '-') + '</div></div></div>';
                html += '<div class="col-md-4 col-6"><div class="side-field" data-field="due_date" data-type="date"><label style="font-size:0.6875rem;color:#6c757d">{{ trans("task_board.field_due_date") }}</label><div class="field-value"><i class="fas fa-calendar me-1 text-muted"></i>' + (t.due_date || '<span class="text-muted">未設定</span>') + '</div></div></div>';
                html += '</div>';
                html += '</div>';


                // 描述（中間主要區域）
                html += '<div class="side-field" data-field="description" data-type="richtext"><label class="fw-bold">{{ trans("task_board.field_description") }}</label><div class="field-value" style="min-height:100px">' + (t.description || '<span class="text-muted">點擊新增描述...</span>') + '</div></div>';

                // 圖片
                if ((t.images && t.images.length > 0) || canUpdate) {
                    html += '<div class="mb-3">';
                    if (t.images && t.images.length > 0) {
                        html += '<div class="d-flex flex-wrap gap-2 mb-2">';
                        t.images.forEach(function (url) {
                            html += '<a href="' + url + '" target="_blank"><img src="' + url + '" style="width:80px;height:80px;object-fit:cover;border-radius:0.375rem;border:1px solid #dee2e6"></a>';
                        });
                        html += '</div>';
                    }
                    if (canUpdate) {
                        html += '<input type="file" class="form-control form-control-sm" id="panel-upload-images" accept="image/*" multiple>';
                    }
                    html += '</div>';
                }

                // 留言區（底部）
                html += '<hr><h6><i class="fas fa-comments me-1"></i>留言</h6>';
                html += '<div id="panel-comments"><p class="text-muted" style="font-size:0.8125rem">Loading...</p></div>';
                html += '<div class="mt-2"><textarea id="panel-comment-input" class="form-control" rows="2" placeholder="輸入留言..."></textarea>';
                html += '<div class="d-flex gap-2 align-items-center mt-1">';
                html += '<div class="position-relative"><button class="btn btn-sm btn-outline-secondary" id="btn-comment-emoji" type="button"><i class="fas fa-smile"></i></button>';
                html += '<div id="comment-emoji-picker" style="display:none;position:absolute;bottom:100%;left:0;z-index:1060;background:#fff;border:1px solid #dee2e6;border-radius:0.5rem;padding:0.5rem;width:280px;box-shadow:0 4px 12px rgba(0,0,0,0.15);margin-bottom:0.25rem">';
                html += '<div style="display:flex;flex-wrap:wrap;gap:4px;max-height:200px;overflow-y:auto" id="emoji-grid"></div></div></div>';
                html += '<label class="btn btn-sm btn-outline-secondary mb-0" for="panel-comment-images"><i class="fas fa-image me-1"></i>附圖</label>';
                html += '<input type="file" class="d-none" id="panel-comment-images" accept="image/*" multiple>';
                html += '<div id="panel-comment-image-previews" class="d-flex gap-1 flex-wrap"></div>';
                html += '<button class="btn btn-sm btn-primary ms-auto" id="btn-send-comment">送出</button>';
                html += '</div></div>';

                $('#side-panel-body').html(html);

                // 儲存原始資料供 inline edit
                $('#side-panel-body').data('task', t);

                bindInlineEdit();
                bindPropEdit(t);
                loadComments(taskId);

                // 異動紀錄按鈕
                $('#btn-show-activities').on('click', function () {
                    $('#activity-modal-body').html('<p class="text-center py-3 text-muted">Loading...</p>');
                    showBsModal('modal-task-activities');
                    loadActivities(taskId);
                });

                $('#btn-panel-delete').on('click', function () {
                    var deleteId = $(this).data('id');
                    $('#delete-confirm-id').val(deleteId);
                    showBsModal('modal-delete-confirm');
                });

                commentImageFiles = [];

                // 側邊面板圖片上傳
                $('#panel-upload-images').on('change', function () {
                    if (!this.files.length) return;
                    var formData = new FormData();
                    formData.append('_method', 'PUT');
                    for (var i = 0; i < this.files.length; i++) {
                        formData.append('images[]', this.files[i]);
                    }
                    $.ajax({
                        url: '/admin/task-board/ajax-update-task/' + taskId,
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': csrfToken },
                        processData: false,
                        contentType: false,
                        data: formData,
                        success: function () { loadPanel(taskId); loadBoard(); },
                        error: function (xhr) { showMsg((xhr.responseJSON && xhr.responseJSON.message) || '上傳失敗'); }
                    });
                });

                // Emoji picker
                var emojis = ['👍','❤️','😂','😮','😢','🙏','❤️‍🔥','👌','😁','🤗','🔥','🤔','👎','🥰','👏','🤯','🎉','🤩','🤮','💩','🕊️','🤡','🫣','😌','😍','🐳','🌚','🌭','💯','🤣','⚡','🍌','🏆','💔','🤨','🙂','🍓','🍾','💋','🖕','😈','😴','😭','🤓','👻','🤷','👀','🎃','🙈','😇','😱','🤝','✍️','🫡','🎅','🎄','☃️','🎆','🤪','🗿','🆒','💘','🙉','🦄','🥴','🙊','👾','🤷‍♂️'];
                var emojiHtml = '';
                emojis.forEach(function (e) {
                    emojiHtml += '<span class="js-emoji-item" style="cursor:pointer;font-size:1.25rem;padding:2px 4px;border-radius:4px;display:inline-block" data-emoji="' + e + '">' + e + '</span>';
                });
                $('#emoji-grid').html(emojiHtml);

                $('#btn-comment-emoji').on('click', function (e) {
                    e.stopPropagation();
                    $('#comment-emoji-picker').toggle();
                });

                $(document).on('click', '.js-emoji-item', function () {
                    var emoji = $(this).data('emoji');
                    var $input = $('#panel-comment-input');
                    var pos = $input[0].selectionStart || $input.val().length;
                    var val = $input.val();
                    $input.val(val.substring(0, pos) + emoji + val.substring(pos));
                    $input.focus();
                    $('#comment-emoji-picker').hide();
                });

                $(document).on('click', function (e) {
                    if (!$(e.target).closest('#comment-emoji-picker, #btn-comment-emoji').length) {
                        $('#comment-emoji-picker').hide();
                    }
                });

                $('#btn-send-comment').on('click', function () { sendComment(taskId); });
                $('#panel-comment-input').on('keydown', function (e) {
                    if (e.ctrlKey && e.which === 13) sendComment(taskId);
                });
            }
        });
    }

    var editConfirmBtn = '<button class="btn btn-outline-secondary js-edit-confirm" type="button" title="確認"><i class="fas fa-check text-success"></i></button>';
    var editCancelBtn = '<button class="btn btn-outline-secondary js-edit-cancel" type="button" title="取消" style="margin-left:2px"><i class="fas fa-times text-danger"></i></button>';

    function bindInlineEdit() {
        if (!canUpdate) return;

        var t = $('#side-panel-body').data('task');

        $('#side-panel-body').find('.side-field[data-field]').off('click').on('click', function () {
            var $field = $(this);
            if ($field.find('input, select, textarea').length) return;
            var fieldName = $field.data('field');
            var fieldType = $field.data('type');
            var $valueDiv = $field.find('.field-value');
            var originalHtml = $valueDiv.html();

            var inputEl = '';
            if (fieldType === 'richtext') {
                var val = t[fieldName] || '';
                var editorId = 'panel-editor-' + Date.now();
                $valueDiv.html('<textarea id="' + editorId + '">' + val + '</textarea><div class="d-flex gap-2 mt-1">' + editConfirmBtn + editCancelBtn + '</div>');
                tinymce.init(getTinyConfig({ selector: '#' + editorId, height: 250 }));

                $valueDiv.find('.js-edit-confirm').on('click', function (e) {
                    e.stopPropagation();
                    var editor = tinymce.get(editorId);
                    saveField(fieldName, editor ? editor.getContent() : '');
                    if (editor) editor.remove();
                });
                $valueDiv.find('.js-edit-cancel').on('click', function (e) {
                    e.stopPropagation();
                    var editor = tinymce.get(editorId);
                    if (editor) editor.remove();
                    $valueDiv.html(originalHtml);
                });
                return;
            } else if (fieldType === 'textarea') {
                var val = t[fieldName] || '';
                inputEl = '<textarea class="form-control" rows="4">' + val + '</textarea>';
                $valueDiv.html(inputEl + '<div class="d-flex gap-2 mt-1">' + editConfirmBtn + editCancelBtn + '</div>');
            } else {
                if (fieldType === 'date') {
                    var val = t[fieldName] || '';
                    inputEl = '<input type="date" class="form-control" value="' + val + '">';
                } else if (fieldType === 'select' && fieldName === 'status') {
                    inputEl = '<select class="form-select">';
                    [1,2,3,4,5].forEach(function (s) { inputEl += '<option value="' + s + '"' + (t.status === s ? ' selected' : '') + '>' + statusLabels[s] + '</option>'; });
                    inputEl += '</select>';
                } else if (fieldType === 'select' && fieldName === 'priority') {
                    var priorityNames = { 1: '{{ trans("task_board.priority_low") }}', 2: '{{ trans("task_board.priority_medium") }}', 3: '{{ trans("task_board.priority_high") }}', 4: '{{ trans("task_board.priority_urgent") }}' };
                    inputEl = '<select class="form-select">';
                    [1,2,3,4].forEach(function (p) { inputEl += '<option value="' + p + '"' + (t.priority === p ? ' selected' : '') + '>' + priorityNames[p] + '</option>'; });
                    inputEl += '</select>';
                } else if (fieldType === 'select' && fieldName === 'project_id') {
                    inputEl = '<select class="form-select">';
                    @foreach($projects as $p)
                    inputEl += '<option value="{{ $p->id }}"' + (t.project_id === {{ $p->id }} ? ' selected' : '') + '>{{ $p->name }}</option>';
                    @endforeach
                    inputEl += '</select>';
                } else if (fieldType === 'select' && fieldName === 'station_id') {
                inputEl = '<select class="form-select"><option value="">未選擇</option>';
                @foreach($stations as $st)
                inputEl += '<option value="{{ $st->id }}"' + (t.station_id === {{ $st->id }} ? ' selected' : '') + '>{{ $st->name }}</option>';
                @endforeach
                inputEl += '</select>';
            } else if (fieldType === 'multiselect' && fieldName === 'assignee_ids') {
                    var currentIds = t.assignee_ids || [];
                    var checkHtml = '<div style="max-height:150px;overflow-y:auto;border:1px solid #dee2e6;border-radius:0.25rem;padding:0.5rem">';
                    @foreach($assignees as $u)
                    checkHtml += '<div class="form-check"><input class="form-check-input js-panel-assignee" type="checkbox" value="{{ $u->id }}"' + (currentIds.indexOf({{ $u->id }}) !== -1 ? ' checked' : '') + '><label class="form-check-label">{{ $u->nickname }}</label></div>';
                    @endforeach
                    checkHtml += '</div>';
                    $valueDiv.html(checkHtml + '<div class="d-flex gap-2 mt-1">' + editConfirmBtn + editCancelBtn + '</div>');

                    $valueDiv.find('.js-edit-confirm').on('click', function (e) {
                        e.stopPropagation();
                        var selected = [];
                        $valueDiv.find('.js-panel-assignee:checked').each(function () { selected.push(parseInt($(this).val(), 10)); });
                        saveField('assignee_ids', selected);
                    });
                    $valueDiv.find('.js-edit-cancel').on('click', function (e) {
                        e.stopPropagation();
                        $valueDiv.html(originalHtml);
                    });
                    return;
                } else {
                    var val = t[fieldName] || '';
                    inputEl = '<input type="text" class="form-control" value="' + val.replace(/"/g, '&quot;') + '">';
                }
                $valueDiv.html('<div class="input-group">' + inputEl + editConfirmBtn + editCancelBtn + '</div>');
            }
            $valueDiv.find('input, textarea, select').first().focus();

            // 確認
            $valueDiv.find('.js-edit-confirm').on('click', function (e) {
                e.stopPropagation();
                var newVal;
                if (fieldType === 'textarea') {
                    newVal = $valueDiv.find('textarea').val();
                } else if (fieldType === 'select') {
                    newVal = $valueDiv.find('select').val();
                    if (fieldName === 'status' || fieldName === 'priority') newVal = parseInt(newVal, 10);
                    if (!newVal) newVal = null;
                } else if (fieldType === 'date') {
                    newVal = $valueDiv.find('input').val();
                } else {
                    newVal = $valueDiv.find('input').val();
                }
                saveField(fieldName, newVal);
            });

            // 取消
            $valueDiv.find('.js-edit-cancel').on('click', function (e) {
                e.stopPropagation();
                $valueDiv.html(originalHtml);
            });

            // Enter 確認（非 textarea）
            if (fieldType !== 'textarea') {
                $valueDiv.find('input, select').on('keypress', function (e) {
                    if (e.which === 13) $valueDiv.find('.js-edit-confirm').trigger('click');
                });
            }

            // Esc 取消
            $valueDiv.find('input, select, textarea').on('keydown', function (e) {
                if (e.which === 27) $valueDiv.find('.js-edit-cancel').trigger('click');
            });
        });
    }

    function saveField(fieldName, value) {
        if (!currentTaskId) return;
        var payload = {};
        payload[fieldName] = value;

        $.ajax({
            url: '/admin/task-board/ajax-update-task/' + currentTaskId,
            method: 'PUT',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            contentType: 'application/json',
            data: JSON.stringify(payload),
            success: function () {
                loadPanel(currentTaskId);
                loadBoard();
            },
            error: function (xhr) {
                showMsg((xhr.responseJSON && xhr.responseJSON.message) || '更新失敗');
            }
        });
    }

    // 屬性 Modal 編輯
    var propEditField = null;
    var propFieldLabels = { project_id: '專案', station_id: '站台', assignee_ids: '{{ trans("task_board.field_assignee") }}', status: '{{ trans("task_board.field_status") }}', priority: '{{ trans("task_board.field_priority") }}', due_date: '{{ trans("task_board.field_due_date") }}' };
    var priorityNames = { 1: '{{ trans("task_board.priority_low") }}', 2: '{{ trans("task_board.priority_medium") }}', 3: '{{ trans("task_board.priority_high") }}', 4: '{{ trans("task_board.priority_urgent") }}' };

    function bindPropEdit(t) {
        if (!canUpdate) return;
        $('.js-prop-edit').off('click').on('click', function () {
            propEditField = $(this).data('field');
            $('#prop-edit-title').text('編輯 - ' + (propFieldLabels[propEditField] || propEditField));
            var html = '';

            if (propEditField === 'project_id') {
                html = '<select class="form-select" id="prop-val">';
                @foreach($projects as $p)
                html += '<option value="{{ $p->id }}"' + (t.project_id === {{ $p->id }} ? ' selected' : '') + '>{{ $p->name }}</option>';
                @endforeach
                html += '</select>';
            } else if (propEditField === 'station_id') {
                html = '<div class="row g-2 mb-2">';
                html += '<div class="col"><select class="form-select form-select-sm" id="prop-station-system"><option value="">全部系統</option>';
                @foreach($systems as $sys)
                html += '<option value="{{ $sys->id }}">{{ $sys->name }}</option>';
                @endforeach
                html += '</select></div>';
                html += '<div class="col"><input type="text" class="form-control form-control-sm" id="prop-station-search" placeholder="搜尋站台..." autocomplete="off"></div>';
                html += '</div>';
                html += '<div id="prop-station-list" style="max-height:250px;overflow-y:auto;border:1px solid #dee2e6;border-radius:0.25rem">';
                html += '<a href="javascript:void(0)" class="list-group-item list-group-item-action js-station-pick" data-id="" style="font-size:0.875rem">未選擇</a>';
                @foreach($stations as $st)
                html += '<a href="javascript:void(0)" class="list-group-item list-group-item-action js-station-pick' + (t.station_id === {{ $st->id }} ? ' active' : '') + '" data-id="{{ $st->id }}" data-system="{{ $st->system_id }}" data-name="{{ strtolower($st->name) }}" style="font-size:0.875rem"><span class="text-muted">系統：</span>{{ $st->system ? $st->system->name : '-' }} <span class="text-muted ms-2">站台：</span><strong>{{ $st->name }}</strong></a>';
                @endforeach
                html += '</div>';
                html += '<input type="hidden" id="prop-val" value="' + (t.station_id || '') + '">';
            } else if (propEditField === 'status') {
                html = '<select class="form-select" id="prop-val">';
                [1,2,3,4,5].forEach(function (s) { html += '<option value="' + s + '"' + (t.status === s ? ' selected' : '') + '>' + statusLabels[s] + '</option>'; });
                html += '</select>';
            } else if (propEditField === 'priority') {
                html = '<select class="form-select" id="prop-val">';
                [1,2,3,4].forEach(function (p) { html += '<option value="' + p + '"' + (t.priority === p ? ' selected' : '') + '>' + priorityNames[p] + '</option>'; });
                html += '</select>';
            } else if (propEditField === 'due_date') {
                html = '<input type="date" class="form-control" id="prop-val" value="' + (t.due_date || '') + '">';
            } else if (propEditField === 'assignee_ids') {
                var currentIds = t.assignee_ids || [];
                html = '<div style="max-height:200px;overflow-y:auto;border:1px solid #dee2e6;border-radius:0.25rem;padding:0.5rem">';
                @foreach($assignees as $u)
                html += '<div class="form-check"><input class="form-check-input js-prop-assignee" type="checkbox" value="{{ $u->id }}"' + (currentIds.indexOf({{ $u->id }}) !== -1 ? ' checked' : '') + '><label class="form-check-label">{{ $u->nickname }}</label></div>';
                @endforeach
                html += '</div>';
            }

            $('#prop-edit-body').html(html);
            showBsModal('modal-prop-edit');

            // 站台搜尋 + 系統篩選
            if (propEditField === 'station_id') {
                function filterStations() {
                    var kw = ($('#prop-station-search').val() || '').toLowerCase();
                    var sysId = $('#prop-station-system').val();
                    $('.js-station-pick').each(function () {
                        if (!$(this).data('id')) { $(this).show(); return; }
                        var nameMatch = !kw || ($(this).data('name') || '').indexOf(kw) !== -1;
                        var sysMatch = !sysId || $(this).data('system') == sysId;
                        $(this).toggle(nameMatch && sysMatch);
                    });
                }
                $('#prop-station-search').on('input', filterStations);
                $('#prop-station-system').on('change', filterStations);

                $('#prop-station-list').on('click', '.js-station-pick', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    $('#prop-station-list .js-station-pick').removeClass('active');
                    $(this).addClass('active');
                    $('#prop-val').val($(this).data('id') || '');
                });
            }
        });
    }

    $('#btn-prop-save').on('click', function () {
        if (!propEditField || !currentTaskId) return;
        var val;

        if (propEditField === 'assignee_ids') {
            val = [];
            $('.js-prop-assignee:checked').each(function () { val.push(parseInt($(this).val(), 10)); });
        } else if (propEditField === 'status' || propEditField === 'priority' || propEditField === 'project_id') {
            val = parseInt($('#prop-val').val(), 10);
        } else if (propEditField === 'station_id') {
            val = $('#prop-val').val() || null;
        } else {
            val = $('#prop-val').val();
        }

        saveField(propEditField, val);
        hideBsModal(document.getElementById('modal-prop-edit'));
    });

    var actionLabels = { created: '建立任務', updated: '更新內容', moved: '移動狀態', deleted: '刪除任務' };

    var activityPageSize = 10;
    var activityAllData = [];
    var activityPage = 1;

    function loadActivities(taskId) {
        $.ajax({
            url: '/admin/task-board/ajax-activities/' + taskId,
            headers: { 'X-CSRF-TOKEN': csrfToken },
            success: function (body) {
                activityAllData = body.data || body;
                activityPage = 1;
                renderActivities();
            }
        });
    }

    function renderActivities() {
        var $container = $('#activity-modal-body');
        var list = activityAllData;
        if (!list || list.length === 0) {
            $container.html('<p class="text-muted text-center py-3">暫無紀錄</p>');
            return;
        }

        var totalPages = Math.ceil(list.length / activityPageSize);
        var start = (activityPage - 1) * activityPageSize;
        var pageData = list.slice(start, start + activityPageSize);

        var html = '<div class="table-responsive"><table class="table table-hover table-striped align-middle mb-0" style="font-size:0.9375rem;white-space:nowrap">';
        html += '<thead class="table-light"><tr class="text-center"><th style="width:70px">操作人</th><th style="width:80px">動作</th><th style="width:60px">欄位</th><th>修改前</th><th>修改後</th><th style="width:130px">時間</th></tr></thead><tbody class="text-center">';
        pageData.forEach(function (a) {
            var changeRows = [];
            if (a.changes) {
                Object.keys(a.changes).forEach(function (key) {
                    var c = a.changes[key];
                    if (typeof c === 'object' && c.from !== undefined) {
                        var fromVal = Array.isArray(c.from) ? c.from.join(', ') : (c.from || '-');
                        var toVal = Array.isArray(c.to) ? c.to.join(', ') : (c.to || '-');
                        if (key === '狀態') {
                            fromVal = statusLabels[c.from] || fromVal;
                            toVal = statusLabels[c.to] || toVal;
                        }
                        changeRows.push({ field: key, from: fromVal, to: toVal });
                    } else {
                        changeRows.push({ field: key, from: '-', to: c });
                    }
                });
            }
            if (changeRows.length === 0) {
                changeRows.push({ field: '-', from: '-', to: '-' });
            }
            changeRows.forEach(function (cr) {
                html += '<tr>';
                html += '<td class="text-center"><strong>' + a.user + '</strong></td>';
                html += '<td class="text-center">' + (actionLabels[a.action] || a.action) + '</td>';
                html += '<td class="text-center">' + cr.field + '</td>';
                html += '<td class="text-center"><span class="text-danger">' + cr.from + '</span></td>';
                html += '<td class="text-center"><span class="text-success">' + cr.to + '</span></td>';
                html += '<td class="text-center"><small class="text-muted">' + a.created_at + '</small></td>';
                html += '</tr>';
            });
        });
        html += '</tbody></table></div>';

        // 分頁
        if (totalPages > 1) {
            html += '<nav class="mt-3"><ul class="pagination justify-content-center mb-0">';
            html += '<li class="page-item' + (activityPage <= 1 ? ' disabled' : '') + '"><a class="page-link js-act-page" href="javascript:void(0)" data-page="' + (activityPage - 1) + '">‹</a></li>';
            for (var i = 1; i <= totalPages; i++) {
                html += '<li class="page-item' + (i === activityPage ? ' active' : '') + '"><a class="page-link js-act-page" href="javascript:void(0)" data-page="' + i + '">' + i + '</a></li>';
            }
            html += '<li class="page-item' + (activityPage >= totalPages ? ' disabled' : '') + '"><a class="page-link js-act-page" href="javascript:void(0)" data-page="' + (activityPage + 1) + '">›</a></li>';
            html += '</ul></nav>';
            html += '<div class="text-center text-muted mt-1" style="font-size:0.8125rem">共 ' + list.length + ' 筆，第 ' + activityPage + ' / ' + totalPages + ' 頁</div>';
        }

        $container.html(html);

        $('.js-act-page').on('click', function () {
            var p = parseInt($(this).data('page'), 10);
            if (p >= 1 && p <= totalPages) {
                activityPage = p;
                renderActivities();
            }
        });
    }

    function loadComments(taskId) {
        $.ajax({
            url: '/admin/task-board/ajax-comments/' + taskId,
            headers: { 'X-CSRF-TOKEN': csrfToken },
            success: function (body) {
                var list = body.data || body;
                if (!list || list.length === 0) {
                    $('#panel-comments').html('<p class="text-muted" style="font-size:0.8125rem">尚無留言</p>');
                    return;
                }
                var html = '';
                list.forEach(function (c) {
                    html += '<div class="comment-item">';
                    html += '<div class="d-flex justify-content-between align-items-center"><strong style="font-size:0.875rem">' + c.user + '</strong><div>';
                    html += '<small class="text-muted me-2">' + c.created_at + '</small>';
                    if (canDeleteComment) {
                        html += '<button class="btn btn-sm btn-outline-secondary js-delete-comment" data-id="' + c.id + '" title="刪除留言"><i class="fas fa-trash text-danger"></i></button>';
                    }
                    html += '</div></div>';
                    html += '<div style="font-size:0.875rem;white-space:pre-wrap;margin-top:0.25rem">' + c.content + '</div>';
                    if (c.images && c.images.length > 0) {
                        html += '<div class="d-flex flex-wrap gap-1 mt-1">';
                        c.images.forEach(function (url) {
                            html += '<a href="' + url + '" target="_blank"><img src="' + url + '" style="width:60px;height:60px;object-fit:cover;border-radius:0.25rem"></a>';
                        });
                        html += '</div>';
                    }
                    html += '</div>';
                });
                $('#panel-comments').html(html);

                // 綁定刪除留言
                $('.js-delete-comment').off('click').on('click', function () {
                    $('#delete-comment-confirm-id').val($(this).data('id'));
                    $('#delete-comment-confirm-task-id').val(taskId);
                    showBsModal('modal-delete-comment-confirm');
                });
            }
        });
    }

    var commentImageFiles = [];

    $(document).on('change', '#panel-comment-images', function () {
        for (var i = 0; i < this.files.length; i++) {
            commentImageFiles.push(this.files[i]);
        }
        this.value = '';
        renderCommentImagePreviews();
    });

    function renderCommentImagePreviews() {
        var $container = $('#panel-comment-image-previews');
        $container.empty();
        commentImageFiles.forEach(function (file, idx) {
            var reader = new FileReader();
            reader.onload = function (e) {
                $container.append('<div class="position-relative" style="width:40px;height:40px"><img src="' + e.target.result + '" style="width:40px;height:40px;object-fit:cover;border-radius:0.25rem"><button type="button" class="btn-close position-absolute top-0 end-0" style="font-size:0.4rem;padding:0.15rem;background:#fff;border-radius:50%" data-rm-comment-img="' + idx + '"></button></div>');
            };
            reader.readAsDataURL(file);
        });
    }

    $(document).on('click', '[data-rm-comment-img]', function () {
        commentImageFiles.splice(parseInt($(this).data('rm-comment-img'), 10), 1);
        renderCommentImagePreviews();
    });

    function sendComment(taskId) {
        var content = $('#panel-comment-input').val().trim();
        if (!content && commentImageFiles.length === 0) return;

        var formData = new FormData();
        formData.append('content', content || '(圖片)');
        commentImageFiles.forEach(function (file) { formData.append('images[]', file); });

        $.ajax({
            url: '/admin/task-board/ajax-store-comment/' + taskId,
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            processData: false,
            contentType: false,
            data: formData,
            success: function () {
                $('#panel-comment-input').val('');
                commentImageFiles = [];
                $('#panel-comment-image-previews').empty();
                loadComments(taskId);
            },
            error: function (xhr) {
                showMsg((xhr.responseJSON && xhr.responseJSON.message) || '留言失敗');
            }
        });
    }

    function bindCardClick() {
        $('.kanban-card').off('click').on('click', function () {
            loadPanel($(this).data('task-id'));
        });
    }

    // SortableJS — 拖曳
    var sortableInstances = [];
    function initSortable() {
        sortableInstances.forEach(function (s) { s.destroy(); });
        sortableInstances = [];

        if (!canUpdate) return;

        $('.card-list').each(function () {
            var el = this;
            var s = Sortable.create(el, {
                group: 'tasks',
                animation: 150,
                ghostClass: 'sortable-ghost',
                delay: 500,
                delayOnTouchOnly: true,
                touchStartThreshold: 5,
                onEnd: function (evt) {
                    var taskId = $(evt.item).data('task-id');
                    var newStatus = parseInt($(evt.to).closest('.kanban-column').data('status'), 10);
                    var newIndex = evt.newIndex;

                    // 清除目標欄位的「無任務」佔位文字
                    $(evt.to).find('p.text-muted').remove();

                    // 來源欄位如果已無卡片，補上佔位文字
                    if ($(evt.from).find('.kanban-card').length === 0) {
                        $(evt.from).html('<p class="text-muted text-center py-3" style="font-size:0.8125rem">無任務</p>');
                    }

                    $.ajax({
                        url: '/admin/task-board/ajax-move-task/' + taskId,
                        method: 'PUT',
                        headers: { 'X-CSRF-TOKEN': csrfToken },
                        contentType: 'application/json',
                        data: JSON.stringify({ status: newStatus, sort_order: newIndex }),
                        success: function () { loadBoard(); },
                        error: function () { loadBoard(); }
                    });

                    // 更新計數
                    $('.kanban-column').each(function () {
                        var st = $(this).data('status');
                        var count = $(this).find('.kanban-card').length;
                        $('#' + statusCountMap[st]).text(count);
                    });
                }
            });
            sortableInstances.push(s);
        });
    }

    // 刪除確認
    // 刪除留言確認
    $('#btn-delete-comment-confirm-ok').on('click', function () {
        var commentId = $('#delete-comment-confirm-id').val();
        var taskId = $('#delete-comment-confirm-task-id').val();
        var $btn = $(this);
        $btn.prop('disabled', true);
        $.ajax({
            url: '/admin/task-board/ajax-delete-comment/' + commentId,
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            success: function () {
                hideBsModal(document.getElementById('modal-delete-comment-confirm'));
                setTimeout(function () { loadComments(parseInt(taskId, 10)); }, 400);
                $btn.prop('disabled', false);
            },
            error: function (xhr) {
                hideBsModal(document.getElementById('modal-delete-comment-confirm'));
                setTimeout(function () { showMsg((xhr.responseJSON && xhr.responseJSON.message) || '刪除失敗'); }, 400);
                $btn.prop('disabled', false);
            }
        });
    });

    // 刪除任務確認
    $('#btn-delete-confirm-ok').on('click', function () {
        var id = $('#delete-confirm-id').val();
        var $btn = $(this);
        $btn.prop('disabled', true);
        $.ajax({
            url: '/admin/task-board/ajax-delete-task/' + id,
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            success: function (body) {
                hideBsModal(document.getElementById('modal-delete-confirm'));
                closePanel();
                setTimeout(function () { showMsg(body.message); loadBoard(); }, 400);
                $btn.prop('disabled', false);
            },
            error: function (xhr) {
                hideBsModal(document.getElementById('modal-delete-confirm'));
                setTimeout(function () { showMsg((xhr.responseJSON && xhr.responseJSON.message) || '刪除失敗'); }, 400);
                $btn.prop('disabled', false);
            }
        });
    });

    // 新增任務
    // 圖片上傳（新增 Modal）
    var taskImageFiles = [];

    function renderTaskImagePreviews() {
        var $container = $('#task-image-previews');
        $container.empty();
        taskImageFiles.forEach(function (file, idx) {
            var reader = new FileReader();
            reader.onload = function (e) {
                var html = '<div class="position-relative" style="width:80px;height:80px">';
                html += '<img src="' + e.target.result + '" style="width:80px;height:80px;object-fit:cover;border-radius:0.375rem;border:1px solid #dee2e6">';
                html += '<button type="button" class="btn-close position-absolute top-0 end-0 bg-white rounded-circle" style="font-size:0.5rem;padding:0.25rem" data-remove="' + idx + '"></button>';
                html += '</div>';
                $container.append(html);
            };
            reader.readAsDataURL(file);
        });
    }

    $('#task-images').on('change', function () {
        for (var i = 0; i < this.files.length; i++) {
            taskImageFiles.push(this.files[i]);
        }
        this.value = '';
        renderTaskImagePreviews();
    });

    $(document).on('click', '#task-image-previews .btn-close', function () {
        taskImageFiles.splice(parseInt($(this).data('remove'), 10), 1);
        renderTaskImagePreviews();
    });

    $('#btn-open-create-task').on('click', function () {
        $('#task-id').val('');
        $('#form-task')[0].reset();
        $('#pri-medium').prop('checked', true);
        $('#modal-task-title').text('{{ trans("task_board.action_create_task") }}');
        taskImageFiles = [];
        $('#task-image-previews').empty();
        showBsModal('modal-task');
    });

    $('#form-task').on('submit', function (e) {
        e.preventDefault();
        var id = $('#task-id').val();
        var url = id ? '/admin/task-board/ajax-update-task/' + id : '/admin/task-board/ajax-store-task';
        var method = id ? 'PUT' : 'POST';

        var formData = new FormData();
        formData.append('project_id', $('#task-project').val());
        if ($('#task-station').val()) formData.append('station_id', $('#task-station').val());
        formData.append('title', $('#task-title').val());
        var descContent = tinymce.get('task-description') ? tinymce.get('task-description').getContent() : $('#task-description').val();
        if (descContent) formData.append('description', descContent);
        formData.append('priority', $('input[name="task_priority"]:checked').val());
        $('.js-assignee-check:checked').each(function () { formData.append('assignee_ids[]', $(this).val()); });
        if ($('#task-due-date').val()) formData.append('due_date', $('#task-due-date').val());
        taskImageFiles.forEach(function (file) { formData.append('images[]', file); });
        if (id) formData.append('_method', 'PUT');

        $.ajax({
            url: url, method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            processData: false,
            contentType: false,
            data: formData,
            success: function (body) {
                hideBsModal(document.getElementById('modal-task'));
                setTimeout(function () { showMsg(body.message); loadBoard(); }, 400);
            },
            error: function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.message) || '操作失敗';
                if (xhr.responseJSON && xhr.responseJSON.errors) {
                    var errors = xhr.responseJSON.errors;
                    var arr = [];
                    Object.keys(errors).forEach(function (k) { arr.push(errors[k][0]); });
                    msg = arr.join('\n');
                }
                showMsg(msg);
            }
        });
    });

    // 新增專案
    $('#btn-open-create-project').on('click', function () {
        $('#form-project')[0].reset();
        showBsModal('modal-project');
    });

    $('#form-project').on('submit', function (e) {
        e.preventDefault();
        var name = $('#project-name').val().trim();
        if (!name) return;

        $.ajax({
            url: '/admin/task-board/ajax-store-project',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            contentType: 'application/json',
            data: JSON.stringify({ name: name }),
            success: function () { location.reload(); },
            error: function (xhr) {
                showMsg((xhr.responseJSON && xhr.responseJSON.message) || '新增失敗');
            }
        });
    });

    // 排序 localStorage 記憶
    var savedSort = localStorage.getItem('taskBoardSort');
    if (savedSort && $('#filter-sort option[value="' + savedSort + '"]').length) {
        $('#filter-sort').val(savedSort);
    }
    $('#filter-sort').on('change', function () {
        localStorage.setItem('taskBoardSort', $(this).val());
        loadBoard();
    });

    // 篩選
    $('#btn-filter').on('click', function () { loadBoard(); });
    $('#filter-keyword').on('keypress', function (e) { if (e.which === 13) loadBoard(); });

    // 初始載入
    loadBoard();
});
</script>

@php
    $tinyBase = asset('js/tinymce/js/tinymce');
    $uploadUrl = route('admin.task-board.ajax-upload-editor-image');
    $csrf = csrf_token();
@endphp
<script src="{{ $tinyBase }}/tinymce.min.js" referrerpolicy="origin"></script>
<script>
function getTinyConfig(overrides) {
    var dark = document.documentElement.getAttribute('data-theme') === 'dark';
    var config = {
        base_url: '{{ $tinyBase }}',
        suffix: '.min',
        license_key: 'gpl',
        skin: dark ? 'oxide-dark' : 'oxide',
        content_css: dark ? 'dark' : 'default',
        height: 300,
        menubar: false,
        plugins: ['autolink', 'link', 'lists', 'image', 'table', 'code'],
        toolbar: 'undo redo | fontfamily fontsize | bold italic underline strikethrough | forecolor backcolor | alignleft aligncenter alignright | bullist numlist | link image table | code',
        font_family_formats:
            '系統預設=inherit;' +
            '微軟正黑體=Microsoft JhengHei,PingFang TC,Heiti TC,sans-serif;' +
            '思源黑體=Noto Sans TC,Noto Sans CJK TC,Microsoft JhengHei,PingFang TC,sans-serif;' +
            'Arial=Arial,Helvetica,sans-serif;' +
            'Times New Roman=Times New Roman,Times,serif;',
        font_size_formats: '12px 14px 16px 18px 20px 24px 28px 32px 36px 48px',
        toolbar_mode: 'sliding',
        images_upload_url: '{{ $uploadUrl }}',
        images_upload_credentials: true,
        automatic_uploads: true,
        images_upload_handler: function (blobInfo, progress) {
            return new Promise(function (resolve, reject) {
                var xhr = new XMLHttpRequest();
                xhr.withCredentials = true;
                xhr.open('POST', '{{ $uploadUrl }}');
                xhr.setRequestHeader('X-CSRF-TOKEN', '{{ $csrf }}');
                xhr.upload.onprogress = function (e) { progress(e.loaded / e.total * 100); };
                xhr.onload = function () {
                    if (xhr.status < 200 || xhr.status >= 300) { reject({ message: 'HTTP Error: ' + xhr.status, remove: true }); return; }
                    try { var json = JSON.parse(xhr.responseText); resolve(json.location); } catch (e) { reject('Invalid JSON'); }
                };
                xhr.onerror = function () { reject('Upload failed'); };
                var fd = new FormData();
                fd.append('file', blobInfo.blob(), blobInfo.filename());
                xhr.send(fd);
            });
        },
        paste_data_images: true,
        relative_urls: false,
        convert_urls: false,
        branding: false,
        promotion: false,
        content_style: 'body { font-family: "PingFang TC", "Microsoft JhengHei", Arial, sans-serif; font-size: 14px; line-height: 1.6; ' + (dark ? 'background: #2d2d2d; color: #e0e0e0;' : '') + ' }'
    };
    if (overrides) {
        for (var key in overrides) { config[key] = overrides[key]; }
    }
    return config;
}

// Modal 打開時初始化 TinyMCE
$('#modal-task').on('shown.bs.modal', function () {
    if (!tinymce.get('task-description')) {
        tinymce.init(getTinyConfig({ selector: '#task-description' }));
    }
});
$('#modal-task').on('hidden.bs.modal', function () {
    var editor = tinymce.get('task-description');
    if (editor) editor.remove();
});
</script>
@endsection
