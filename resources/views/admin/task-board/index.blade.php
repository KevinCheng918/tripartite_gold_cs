@extends('layouts.app')

@section('title', trans('task_board.page_title'))
@section('icon', 'columns')
@section('subtitle', trans('task_board.subtitle'))

@section('content')

    {{-- 任務描述內容樣式（清單分級、勾選清單）：詳情面板與 TinyMCE 編輯器內共用同一份 --}}
    <link rel="stylesheet" href="{{ asset('css/task-content.css') }}?v={{ filemtime(public_path('css/task-content.css')) }}">

    <style>
        .app-main__outer, .app-main__inner { overflow-x: hidden !important; }
        .taskboard-toolbar { background: #fff; }
        [data-theme="dark"] .taskboard-toolbar { background: #1a1a1a; color: #e0e0e0; }
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
        /* 封存清單 */
        [data-theme="dark"] #modal-archived-list .modal-content { background: #1e1e1e; color: #e0e0e0; }
        [data-theme="dark"] #modal-archived-list .modal-header { border-bottom-color: #333; }
        [data-theme="dark"] #modal-archived-list .table { color: #e0e0e0; }
        [data-theme="dark"] #modal-archived-list .table thead th { color: #ccc; border-bottom-color: #444; }
        [data-theme="dark"] #modal-archived-list .table td { border-bottom-color: #333; }
        [data-theme="dark"] #modal-archived-list .table-hover tbody tr:hover { background: rgba(255,255,255,0.05); }
        [data-theme="dark"] #modal-archived-list .form-select,
        [data-theme="dark"] #modal-archived-list .form-control { background: #2d2d2d; color: #e0e0e0; border-color: #444; }
        [data-theme="dark"] #modal-restore-confirm .modal-content { background: #1e1e1e; color: #e0e0e0; }
        /* 新增任務的站台下拉：清單浮在上層，不把 modal 內其他欄位往下擠 */
        .tb-station-picker { position: relative; }
        .tb-station-picker #task-station-toggle.is-open { border-color: #86b7fe; box-shadow: 0 0 0 0.25rem rgba(13,110,253,0.25); }
        .tb-station-panel {
            position: absolute; z-index: 5; top: calc(100% + 2px); left: 0; right: 0;
            background: #fff; border: 1px solid #dee2e6; border-radius: 0.25rem;
            box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15);
        }
        .tb-station-panel #task-station-list { max-height: 200px; overflow-y: auto; }
        .tb-station-panel .js-task-station-pick { font-size: 0.875rem; border-left: 0; border-right: 0; border-radius: 0; }
        [data-theme="dark"] .tb-station-panel { background: #2d2d2d; border-color: #444; }
        [data-theme="dark"] .tb-station-panel .js-task-station-pick { background: #2d2d2d; color: #e0e0e0; border-color: #444; }
        [data-theme="dark"] .tb-station-panel .js-task-station-pick:hover { background: #3a3a3a; }
        [data-theme="dark"] .tb-station-panel .js-task-station-pick.active { background: #0d6efd; color: #fff; }
        [data-theme="dark"] #task-station-toggle { background-color: #2d2d2d; color: #e0e0e0; border-color: #444; }
        @media (max-width: 767px) {
            #task-side-panel { width: 100% !important; }
        }
    </style>

    {{-- 篩選列 --}}
    <div class="mb-3 p-3 rounded shadow-sm taskboard-toolbar">
        <div class="d-flex gap-2 align-items-center mb-2">
            @if(Auth::user()->hasPermission('task_board.create'))
            <button class="btn btn-primary btn-sm" id="btn-open-create-task">
                <i class="fas fa-plus me-1"></i>{{ trans('task_board.action_create_task') }}
            </button>
            @endif
            <button class="btn btn-outline-secondary btn-sm" id="btn-open-archived">
                <i class="fas fa-archive me-1"></i>查看封存
            </button>
        </div>
        <div class="d-flex align-items-center flex-wrap gap-2" style="font-size:0.8125rem">
            <span class="text-muted">篩選</span>
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
            <span class="text-muted">|</span>
            <span class="text-muted">排序</span>
            <select id="filter-sort-field" class="form-select form-select-sm" style="width:auto">
                <option value="created">建立時間</option>
                <option value="updated">更新時間</option>
                <option value="priority">優先順序</option>
                <option value="due_date">到期日</option>
            </select>
            <select id="filter-sort-dir" class="form-select form-select-sm" style="width:auto">
                <option value="desc">新→舊</option>
                <option value="asc">舊→新</option>
            </select>
            <span class="text-muted">|</span>
            <input type="text" id="filter-keyword" class="form-control form-control-sm" style="width:130px" placeholder="搜尋標題...">
            <button class="btn btn-sm btn-outline-secondary" id="btn-filter">
                <i class="fas fa-search me-1"></i>搜尋
            </button>
            <span class="text-muted">|</span>
            <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox" id="filter-overdue">
                <label class="form-check-label" for="filter-overdue" style="font-size:0.8125rem">僅顯示已過期</label>
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
                        {{-- 站台數量多，用系統篩選 + 關鍵字搜尋，與編輯任務時的選擇方式一致 --}}
                        <div class="mb-3">
                            <label class="form-label">{{ trans('task_board.field_station') }}</label>
                            {{-- 收合式：預設只顯示目前選擇，點擊才展開篩選與清單 --}}
                            <div class="tb-station-picker">
                                <button type="button" class="form-select text-start" id="task-station-toggle">
                                    <span id="task-station-label">{{ trans('task_board.station_unset') }}</span>
                                </button>
                                <div class="tb-station-panel d-none" id="task-station-panel">
                                    <div class="row g-2 p-2">
                                        <div class="col">
                                            <select class="form-select form-select-sm" id="task-station-system">
                                                <option value="">{{ trans('task_board.all_systems') }}</option>
                                                @foreach($systems as $sys)
                                                    <option value="{{ $sys->id }}">{{ $sys->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col">
                                            <input type="text" class="form-control form-control-sm" id="task-station-search"
                                                   placeholder="{{ trans('task_board.station_search_ph') }}" autocomplete="off">
                                        </div>
                                    </div>
                                    <div id="task-station-list">
                                        <a href="javascript:void(0)" class="list-group-item list-group-item-action js-task-station-pick active"
                                           data-id="" data-label="{{ trans('task_board.station_unset') }}">{{ trans('task_board.station_unset') }}</a>
                                        @foreach($stations as $st)
                                            <a href="javascript:void(0)" class="list-group-item list-group-item-action js-task-station-pick"
                                               data-id="{{ $st->id }}" data-system="{{ $st->system_id }}" data-name="{{ strtolower($st->name) }}"
                                               data-label="{{ ($st->system ? $st->system->name : '-') }} / {{ $st->name }}">
                                                <span class="text-muted">{{ trans('task_board.field_system') }}：</span>{{ $st->system ? $st->system->name : '-' }}
                                                <span class="text-muted ms-2">{{ trans('task_board.field_station') }}：</span><strong>{{ $st->name }}</strong>
                                            </a>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                            <input type="hidden" id="task-station" value="">
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
                            <label class="form-label">{{ trans('task_board.field_attachment') }}</label>
                            <input type="file" class="form-control" id="task-images" multiple>
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

    <div class="modal fade" id="modal-archive-confirm" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-body text-center py-4">
                    <p class="mb-3">確定封存此任務？封存後可在異動紀錄中查看。</p>
                    <input type="hidden" id="delete-confirm-id">
                    <div class="d-flex justify-content-center gap-2">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                        <button type="button" class="btn btn-danger" id="btn-delete-confirm-ok">確定封存</button>
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
                    <p class="mb-3">確定封存此留言？</p>
                    <input type="hidden" id="delete-comment-confirm-id">
                    <input type="hidden" id="delete-comment-confirm-task-id">
                    <div class="d-flex justify-content-center gap-2">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                        <button type="button" class="btn btn-danger" id="btn-delete-comment-confirm-ok">確定封存</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- 封存清單 Modal --}}
    <div class="modal fade" id="modal-archived-list" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-archive me-2"></i>封存任務清單</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted mb-2" style="font-size:0.875rem">封存超過 30 天的任務將自動刪除</p>
                    <div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
                        <select id="archived-filter-project" class="form-select form-select-sm" style="width:auto">
                            <option value="">全部專案</option>
                        </select>
                        <select id="archived-filter-assignee" class="form-select form-select-sm" style="width:auto">
                            <option value="">全部人員</option>
                        </select>
                        <input type="date" id="archived-filter-date-start" class="form-control form-control-sm" style="width:auto" placeholder="起始日期">
                        <span class="text-muted">~</span>
                        <input type="date" id="archived-filter-date-end" class="form-control form-control-sm" style="width:auto" placeholder="結束日期">
                        <button class="btn btn-sm btn-outline-secondary" id="btn-archived-filter-reset" title="重置"><i class="fas fa-undo me-1"></i>重置</button>
                    </div>
                    <div id="archived-list-loading" class="text-center py-4" style="display:none">
                        <div class="spinner-border text-secondary" role="status"></div>
                    </div>
                    <div id="archived-list-empty" class="text-center py-4 text-muted" style="display:none">
                        <i class="fas fa-inbox fa-2x mb-2 d-block"></i>目前沒有封存任務
                    </div>
                    <div class="table-responsive" id="archived-list-table-wrap" style="display:none">
                        <table class="table table-hover table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style="width:90px;white-space:nowrap">專案</th>
                                    <th style="white-space:nowrap">標題</th>
                                    <th style="width:90px;white-space:nowrap">原始狀態</th>
                                    <th>指派人員</th>
                                    <th style="width:100px;white-space:nowrap">封存時間</th>
                                    <th style="width:80px;white-space:nowrap">剩餘天數</th>
                                    <th style="width:60px;white-space:nowrap">操作</th>
                                </tr>
                            </thead>
                            <tbody id="archived-list-body"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- 還原確認 Modal --}}
    <div class="modal fade" id="modal-restore-confirm" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-body text-center py-4">
                    <p class="mb-3">確定還原此任務？任務將回到「待處理」狀態。</p>
                    <input type="hidden" id="restore-confirm-id">
                    <div class="d-flex justify-content-center gap-2">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                        <button type="button" class="btn btn-success" id="btn-restore-confirm-ok">確定還原</button>
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
        var sortBy = $('#filter-sort-field').val() + '_' + $('#filter-sort-dir').val();
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

                var onlyOverdue = $('#filter-overdue').is(':checked');
                var today = new Date().toISOString().substring(0, 10);

                Object.keys(columns).forEach(function (key) {
                    var tasks = columns[key];
                    if (onlyOverdue) {
                        tasks = tasks.filter(function (t) { return t.due_date && t.due_date < today; });
                    }
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
                    actionsHtml += '<button class="btn btn-outline-secondary" id="btn-panel-archive" data-id="' + t.id + '"><i class="fas fa-archive me-1"></i>封存</button>';
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
                html += '<div class="col-3"><div class="side-prop js-prop-edit" data-field="project_id"><label style="font-size:0.75rem;color:#6c757d">專案</label><div style="font-size:0.9375rem"><i class="fas fa-folder me-1 text-muted"></i>' + t.project + '</div></div></div>';
                html += '<div class="col-3"><div class="side-prop js-prop-edit" data-field="station_id"><label style="font-size:0.75rem;color:#6c757d">站台</label><div style="font-size:0.9375rem"><i class="fas fa-server me-1 text-muted"></i>' + stationDisplay + '</div></div></div>';
                html += '<div class="col-6"><div class="side-prop js-prop-edit" data-field="assignee_ids"><label style="font-size:0.75rem;color:#6c757d">{{ trans("task_board.field_assignee") }}</label><div style="font-size:0.9375rem"><i class="fas fa-users me-1 text-muted"></i>' + assigneeDisplay + '</div></div></div>';
                html += '<div class="col-3"><div class="side-field" data-field="status" data-type="select"><label style="font-size:0.75rem;color:#6c757d">{{ trans("task_board.field_status") }}</label><div class="field-value" style="font-size:0.9375rem">' + (statusLabels[t.status] || '-') + '</div></div></div>';
                html += '<div class="col-3"><div class="side-field" data-field="priority" data-type="select"><label style="font-size:0.75rem;color:#6c757d">{{ trans("task_board.field_priority") }}</label><div class="field-value" style="font-size:0.9375rem">' + (priorityLabel[t.priority] || '-') + '</div></div></div>';
                html += '<div class="col-6"><div class="side-field" data-field="due_date" data-type="date"><label style="font-size:0.75rem;color:#6c757d">{{ trans("task_board.field_due_date") }}</label><div class="field-value" style="font-size:0.9375rem"><i class="fas fa-calendar me-1 text-muted"></i>' + (t.due_date || '<span class="text-muted">未設定</span>') + '</div></div></div>';
                html += '</div>';
                html += '</div>';


                // 描述（中間主要區域）
                // 描述不像其他欄位點一下就進編輯 —— 平常要留給勾選清單用，改由右上角的編輯鈕觸發
                html += '<div class="side-field" data-field="description" data-type="richtext">';
                html += '<div class="d-flex align-items-center justify-content-between">';
                html += '<label class="fw-bold mb-0">{{ trans("task_board.field_description") }}</label>';
                if (canUpdate) {
                    html += '<button type="button" class="btn btn-sm btn-outline-secondary js-edit-description">'
                        + '<i class="fas fa-pen me-1"></i>{{ trans("task_board.action_edit") }}</button>';
                }
                html += '</div>';
                html += '<div class="field-value tb-content" style="min-height:100px;cursor:default">'
                    + (t.description || '<span class="text-muted">{{ trans("task_board.description_empty") }}</span>')
                    + '</div></div>';

                // 附件（不限圖片；欄位仍沿用 images 這個舊名）
                if ((t.images && t.images.length > 0) || canUpdate) {
                    html += '<div class="mb-3">';
                    if (t.images && t.images.length > 0) {
                        html += '<div class="d-flex flex-wrap gap-2 mb-2 align-items-start">';
                        t.images.forEach(function (url) {
                            html += isImageUrl(url) ? attachmentThumbHtml(url) : attachmentFileHtml(url);
                        });
                        html += '</div>';
                    }
                    if (canUpdate) {
                        html += '<input type="file" class="form-control form-control-sm" id="panel-upload-images" multiple>';
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
                bindChecklist(taskId);
                bindPropEdit(t);
                loadComments(taskId);

                // 異動紀錄按鈕
                $('#btn-show-activities').on('click', function () {
                    $('#activity-modal-body').html('<p class="text-center py-3 text-muted">Loading...</p>');
                    showBsModal('modal-task-activities');
                    loadActivities(taskId);
                });

                $('#btn-panel-archive').on('click', function () {
                    var deleteId = $(this).data('id');
                    $('#delete-confirm-id').val(deleteId);
                    showBsModal('modal-archive-confirm');
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

    var CHECKLIST_PROGRESS_TEXT = @json(trans('task_board.checklist_progress'));

    /**
     * 依副檔名判斷附件能不能直接當圖片預覽
     *
     * @param {string} url
     * @returns {boolean}
     */
    function isImageUrl(url) {
        return /\.(jpe?g|png|gif|webp|bmp|svg)(\?|$)/i.test(String(url));
    }

    /**
     * 從附件網址取回可讀的檔名
     *
     * 上傳時檔名格式是「時間戳_uniqid_原始檔名」，顯示時把前綴去掉。
     * 舊資料沒有原始檔名（早期只存圖片），取到什麼就顯示什麼。
     *
     * @param {string} url
     * @returns {string}
     */
    function attachmentName(url) {
        var name = String(url).split('?')[0].split('/').pop();

        try { name = decodeURIComponent(name); } catch (e) { /* 保留原字串 */ }

        return name.replace(/^\d+_[a-z0-9]+_/i, '');
    }

    /**
     * @param {string} url
     * @returns {string}
     */
    function attachmentThumbHtml(url) {
        return '<a href="' + url + '" target="_blank" rel="noopener">'
            + '<img src="' + url + '" style="width:80px;height:80px;object-fit:cover;border-radius:0.375rem;border:1px solid #dee2e6"></a>';
    }

    /**
     * 非圖片附件顯示成可下載的檔案卡片
     *
     * @param {string} url
     * @returns {string}
     */
    function attachmentFileHtml(url) {
        return '<a href="' + url + '" target="_blank" rel="noopener" download '
            + 'class="d-inline-flex align-items-center gap-1 text-decoration-none border rounded px-2 py-1" '
            + 'style="font-size:0.8125rem;max-width:180px">'
            + '<i class="fas fa-paperclip text-muted"></i>'
            + '<span class="text-truncate">' + $('<span>').text(attachmentName(url)).html() + '</span></a>';
    }

    /**
     * 描述裡的勾選清單：顯示進度，並讓有編輯權限的人直接點方塊打勾
     *
     * @param {number} taskId
     */
    function bindChecklist(taskId) {
        var $desc = $('#side-panel-body').find('.side-field[data-field="description"] .field-value');
        if (!$desc.length) return;

        renderChecklistProgress($desc);

        if (!canUpdate) return;

        $desc.find('.tb-checklist li').off('click').on('click', function (e) {
            // 描述欄位整塊點下去會進入編輯模式，勾選不能讓事件冒上去
            e.stopPropagation();

            var $li = $(this);
            $li.attr('data-checked', $li.attr('data-checked') === '1' ? '0' : '1');

            renderChecklistProgress($desc);
            saveChecklist(taskId, $desc);
        });
    }

    /**
     * 進度標記放在欄位標題旁，不放進 .field-value —— 那裡的內容會被原樣存回描述
     *
     * @param {Object} $desc jQuery 物件
     */
    function renderChecklistProgress($desc) {
        var $label = $desc.closest('.side-field').find('label').first();
        $label.find('.js-checklist-progress').remove();

        var $items = $desc.find('.tb-checklist li');
        if (!$items.length) return;

        var text = CHECKLIST_PROGRESS_TEXT
            .split(':done').join($items.filter('[data-checked="1"]').length)
            .split(':total').join($items.length);

        $label.append('<span class="js-checklist-progress badge bg-secondary ms-2" style="font-weight:400">' + text + '</span>');
    }

    /**
     * 勾選後立即寫回，不必按儲存。走專用端點避免灌爆活動紀錄。
     *
     * @param {number} taskId
     * @param {Object} $desc jQuery 物件
     */
    function saveChecklist(taskId, $desc) {
        var description = $desc.html();

        // 同步面板暫存的資料，否則之後進編輯模式會載到舊的描述、把勾選蓋掉
        var t = $('#side-panel-body').data('task');
        if (t) { t.description = description; }

        $.ajax({
            url: '/admin/task-board/ajax-update-checklist/' + taskId,
            method: 'PUT',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            contentType: 'application/json',
            data: JSON.stringify({ description: description }),
            error: function (xhr) {
                showMsg((xhr.responseJSON && xhr.responseJSON.message) || @json(trans('task_board.msg.checklist_update_failed')));
            }
        });
    }

    function bindInlineEdit() {
        if (!canUpdate) return;

        var t = $('#side-panel-body').data('task');

        // 描述改由右上角的編輯鈕進入編輯模式，內容區平常留給勾選清單
        $('#side-panel-body').find('.js-edit-description').off('click').on('click', function (e) {
            e.stopPropagation();
            startFieldEdit($(this).closest('.side-field'), t);
        });

        $('#side-panel-body').find('.side-field[data-field]').off('click').on('click', function () {
            var $field = $(this);
            if ($field.data('type') === 'richtext') return;
            startFieldEdit($field, t);
        });
    }

    /**
     * 進入單一欄位的編輯狀態
     *
     * @param {Object} $field jQuery 物件（.side-field）
     * @param {Object} t      面板目前的任務資料
     */
    function startFieldEdit($field, t) {
        (function () {
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
                    inputEl = '<input type="date" class="form-control form-control-sm" style="min-width:130px" value="' + val + '">';
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
                $valueDiv.html(inputEl + '<div class="d-flex gap-2 mt-1">' + editConfirmBtn + editCancelBtn + '</div>');
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
        })();
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

    var actionLabels = { created: '建立任務', updated: '更新內容', moved: '移動狀態', archived: '封存任務', restored: '還原任務', deleted: '刪除任務' };

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

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /**
     * 留言相關 ajax 的錯誤訊息：優先取後端 message，其次取驗證錯誤
     */
    function getCommentErrorMsg(xhr) {
        var body = xhr.responseJSON || {};
        if (body.errors) {
            var first = Object.keys(body.errors)[0];
            if (first && body.errors[first].length) {
                return body.errors[first][0];
            }
        }

        return body.message || @json(trans('task_board.msg.comment_update_failed'));
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
                    html += '<div class="comment-item" data-comment-id="' + c.id + '">';
                    html += '<div class="d-flex justify-content-between align-items-center"><strong style="font-size:0.875rem">' + escapeHtml(c.user) + '</strong><div>';
                    html += '<small class="text-muted me-2">' + c.created_at;
                    if (c.is_edited) {
                        html += '（' + @json(trans('task_board.comment_edited')) + '）';
                    }
                    html += '</small>';
                    // 只有本人能編輯自己的留言
                    if (c.is_mine) {
                        html += '<button class="btn btn-sm btn-outline-secondary js-edit-comment me-1" data-id="' + c.id + '" title="' + @json(trans('task_board.action_edit_comment')) + '"><i class="fas fa-pen"></i></button>';
                    }
                    if (canDeleteComment) {
                        html += '<button class="btn btn-sm btn-outline-secondary js-delete-comment" data-id="' + c.id + '" title="刪除留言"><i class="fas fa-trash text-danger"></i></button>';
                    }
                    html += '</div></div>';
                    html += '<div class="js-comment-content" style="font-size:0.875rem;white-space:pre-wrap;margin-top:0.25rem">' + escapeHtml(c.content) + '</div>';
                    // 編輯區（預設隱藏，圖片不可異動）
                    html += '<div class="js-comment-edit d-none mt-1">';
                    html += '<textarea class="form-control form-control-sm js-comment-edit-input" rows="3" maxlength="2000" style="font-size:0.875rem">' + escapeHtml(c.content) + '</textarea>';
                    html += '<div class="d-flex justify-content-end align-items-center gap-1 mt-1">';
                    if (c.images && c.images.length > 0) {
                        html += '<small class="text-muted me-auto" style="font-size:0.75rem">' + @json(trans('task_board.comment_image_locked')) + '</small>';
                    }
                    html += '<button type="button" class="btn btn-sm btn-secondary js-comment-edit-cancel">' + @json(trans('task_board.action_cancel')) + '</button>';
                    html += '<button type="button" class="btn btn-sm btn-primary js-comment-edit-save" data-id="' + c.id + '">' + @json(trans('task_board.action_save')) + '</button>';
                    html += '</div></div>';
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

                // 進入編輯：切換成文字框，圖片維持原樣不動
                $('.js-edit-comment').off('click').on('click', function () {
                    var $item = $(this).closest('.comment-item');
                    $item.find('.js-comment-content').addClass('d-none');
                    $item.find('.js-comment-edit').removeClass('d-none');
                    $item.find('.js-comment-edit-input').focus();
                });

                // 取消編輯：文字框還原成原內容
                $('.js-comment-edit-cancel').off('click').on('click', function () {
                    var $item = $(this).closest('.comment-item');
                    $item.find('.js-comment-edit-input').val($item.find('.js-comment-content').text());
                    $item.find('.js-comment-edit').addClass('d-none');
                    $item.find('.js-comment-content').removeClass('d-none');
                });

                // 儲存編輯
                $('.js-comment-edit-save').off('click').on('click', function () {
                    var $btn = $(this);
                    var $item = $btn.closest('.comment-item');
                    var content = $item.find('.js-comment-edit-input').val().trim();
                    if (!content) {
                        showMsg(@json(trans('task_board.msg.comment_content_required')));
                        return;
                    }

                    $btn.prop('disabled', true);
                    $.ajax({
                        url: '/admin/task-board/ajax-update-comment/' + $btn.data('id'),
                        method: 'PUT',
                        headers: { 'X-CSRF-TOKEN': csrfToken },
                        contentType: 'application/json',
                        data: JSON.stringify({ content: content }),
                        success: function () {
                            $btn.prop('disabled', false);
                            loadComments(taskId);
                            showMsg(@json(trans('task_board.msg.comment_updated')));
                        },
                        error: function (xhr) {
                            $btn.prop('disabled', false);
                            showMsg(getCommentErrorMsg(xhr));
                        }
                    });
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
            url: '/admin/task-board/ajax-archive-task/' + id,
            method: 'PUT',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            success: function (body) {
                hideBsModal(document.getElementById('modal-archive-confirm'));
                closePanel();
                setTimeout(function () { showMsg(body.message); loadBoard(); }, 400);
                $btn.prop('disabled', false);
            },
            error: function (xhr) {
                hideBsModal(document.getElementById('modal-archive-confirm'));
                setTimeout(function () { showMsg((xhr.responseJSON && xhr.responseJSON.message) || '刪除失敗'); }, 400);
                $btn.prop('disabled', false);
            }
        });
    });

    // 封存清單
    var archivedAllData = [];

    $('#btn-open-archived').on('click', function () {
        loadArchivedList();
        showBsModal('modal-archived-list');
    });

    function loadArchivedList() {
        $('#archived-list-loading').show();
        $('#archived-list-empty, #archived-list-table-wrap').hide();
        $.ajax({
            url: '/admin/task-board/ajax-archived-list',
            method: 'GET',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            success: function (res) {
                $('#archived-list-loading').hide();
                archivedAllData = res.data || res;
                buildArchivedFilterOptions(archivedAllData);
                renderArchivedList(archivedAllData);
            },
            error: function () {
                $('#archived-list-loading').hide();
                showMsg('載入封存清單失敗');
            }
        });
    }

    function renderArchivedList(list) {
        $('#archived-list-empty, #archived-list-table-wrap').hide();
        if (!list.length) {
            $('#archived-list-empty').show();
            return;
        }
        var html = '';
        list.forEach(function (t) {
            var updatedAt = new Date(t.updated_at);
            var now = new Date();
            var diffDays = Math.ceil((now - updatedAt) / (1000 * 60 * 60 * 24));
            var remaining = 30 - diffDays;
            if (remaining < 0) remaining = 0;
            var badgeClass = remaining <= 7 ? 'bg-danger' : (remaining <= 14 ? 'bg-warning text-dark' : 'bg-secondary');
            var prevStatus = t.previous_status ? (statusLabels[t.previous_status] || '-') : '-';
            var assigneeNames = (t.assignees && t.assignees.length)
                ? t.assignees.map(function (a) { return $('<span>').text(a.nickname).html(); }).join(', ')
                : '-';
            html += '<tr>';
            html += '<td style="white-space:nowrap"><span class="badge bg-info text-dark" style="font-size:0.75rem">' + (t.project ? $('<span>').text(t.project).html() : '-') + '</span></td>';
            html += '<td style="white-space:nowrap">' + $('<span>').text(t.title).html() + '</td>';
            html += '<td style="white-space:nowrap"><span class="badge bg-secondary" style="font-size:0.75rem">' + prevStatus + '</span></td>';
            html += '<td style="font-size:0.875rem">' + assigneeNames + '</td>';
            html += '<td style="white-space:nowrap">' + updatedAt.toLocaleDateString('zh-TW') + '</td>';
            html += '<td style="white-space:nowrap"><span class="badge ' + badgeClass + '">' + remaining + ' 天</span></td>';
            html += '<td style="white-space:nowrap"><button class="btn btn-sm btn-outline-secondary js-restore-task" data-id="' + t.id + '" title="還原"><i class="fas fa-undo me-1"></i>還原</button></td>';
            html += '</tr>';
        });
        $('#archived-list-body').html(html);
        $('#archived-list-table-wrap').show();
    }

    function buildArchivedFilterOptions(list) {
        var projects = {};
        var assignees = {};
        list.forEach(function (t) {
            if (t.project) projects[t.project] = true;
            (t.assignees || []).forEach(function (a) {
                if (a.nickname) assignees[a.nickname] = true;
            });
        });
        var $proj = $('#archived-filter-project').empty().append($('<option>').val('').text('全部專案'));
        Object.keys(projects).sort().forEach(function (name) {
            $proj.append($('<option>').val(name).text(name));
        });
        var $asgn = $('#archived-filter-assignee').empty().append($('<option>').val('').text('全部人員'));
        Object.keys(assignees).sort().forEach(function (name) {
            $asgn.append($('<option>').val(name).text(name));
        });
    }

    function filterArchivedList() {
        var project = $('#archived-filter-project').val();
        var assignee = $('#archived-filter-assignee').val();
        var dateStart = $('#archived-filter-date-start').val();
        var dateEnd = $('#archived-filter-date-end').val();
        var filtered = archivedAllData.filter(function (t) {
            if (project && t.project !== project) return false;
            if (assignee) {
                var match = (t.assignees || []).some(function (a) { return a.nickname === assignee; });
                if (!match) return false;
            }
            if (dateStart || dateEnd) {
                var d = t.updated_at ? t.updated_at.substring(0, 10) : '';
                if (dateStart && d < dateStart) return false;
                if (dateEnd && d > dateEnd) return false;
            }
            return true;
        });
        renderArchivedList(filtered);
    }

    $('#archived-filter-project, #archived-filter-assignee').on('change', filterArchivedList);
    $('#archived-filter-date-start, #archived-filter-date-end').on('change', filterArchivedList);
    $('#btn-archived-filter-reset').on('click', function () {
        var $btn = $(this);
        $btn.addClass('active');
        $('#archived-filter-project, #archived-filter-assignee').val('');
        $('#archived-filter-date-start, #archived-filter-date-end').val('');
        renderArchivedList(archivedAllData);
        setTimeout(function () { $btn.removeClass('active'); }, 200);
    });

    $(document).on('click', '.js-restore-task', function () {
        var id = $(this).data('id');
        $('#restore-confirm-id').val(id);
        hideBsModal(document.getElementById('modal-archived-list'));
        setTimeout(function () { showBsModal('modal-restore-confirm'); }, 400);
    });

    $('#btn-restore-confirm-ok').on('click', function () {
        var id = $('#restore-confirm-id').val();
        var $btn = $(this);
        $btn.prop('disabled', true);
        $.ajax({
            url: '/admin/task-board/ajax-restore-task/' + id,
            method: 'PUT',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            success: function (body) {
                hideBsModal(document.getElementById('modal-restore-confirm'));
                setTimeout(function () { showMsg(body.message); loadBoard(); }, 400);
                $btn.prop('disabled', false);
            },
            error: function (xhr) {
                hideBsModal(document.getElementById('modal-restore-confirm'));
                setTimeout(function () { showMsg((xhr.responseJSON && xhr.responseJSON.message) || '還原失敗'); }, 400);
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
            var removeBtn = '<button type="button" class="btn-close position-absolute top-0 end-0 bg-white rounded-circle"'
                + ' style="font-size:0.5rem;padding:0.25rem" data-remove="' + idx + '"></button>';

            // 非圖片沒有縮圖可看，改顯示檔名卡片
            if (file.type.indexOf('image/') !== 0) {
                $container.append(
                    '<div class="position-relative border rounded d-flex align-items-center gap-1 px-2 py-1"'
                    + ' style="max-width:180px;height:80px;font-size:0.8125rem">'
                    + '<i class="fas fa-paperclip text-muted"></i>'
                    + '<span class="text-truncate">' + $('<span>').text(file.name).html() + '</span>'
                    + removeBtn + '</div>'
                );

                return;
            }

            var reader = new FileReader();
            reader.onload = function (e) {
                $container.append(
                    '<div class="position-relative" style="width:80px;height:80px">'
                    + '<img src="' + e.target.result + '" style="width:80px;height:80px;object-fit:cover;border-radius:0.375rem;border:1px solid #dee2e6">'
                    + removeBtn + '</div>'
                );
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

    // 站台清單：系統篩選 + 關鍵字搜尋（form reset 不會清掉這些，開啟時要自己重設）
    function filterTaskStations() {
        var kw = ($('#task-station-search').val() || '').toLowerCase();
        var sysId = $('#task-station-system').val();

        $('#task-station-list .js-task-station-pick').each(function () {
            var $item = $(this);

            // 「未選擇」永遠留著，否則篩到沒結果時就無法取消選擇
            if (!$item.data('id')) { $item.show(); return; }

            var nameMatch = !kw || ($item.data('name') || '').indexOf(kw) !== -1;
            var sysMatch = !sysId || $item.data('system') == sysId;
            $item.toggle(nameMatch && sysMatch);
        });
    }

    $('#task-station-search').on('input', filterTaskStations);
    $('#task-station-system').on('change', filterTaskStations);

    /**
     * 展開／收合站台清單
     *
     * @param {boolean} open
     */
    function toggleTaskStationPanel(open) {
        $('#task-station-panel').toggleClass('d-none', !open);
        $('#task-station-toggle').toggleClass('is-open', open);
        if (open) $('#task-station-search').trigger('focus');
    }

    $('#task-station-toggle').on('click', function () {
        toggleTaskStationPanel($('#task-station-panel').hasClass('d-none'));
    });

    // 點選單以外的地方就收起來，避免蓋住底下的欄位
    $(document).on('click', function (e) {
        if ($(e.target).closest('.tb-station-picker').length) return;
        toggleTaskStationPanel(false);
    });

    $('#task-station-list').on('click', '.js-task-station-pick', function (e) {
        e.preventDefault();
        $('#task-station-list .js-task-station-pick').removeClass('active');
        $(this).addClass('active');
        $('#task-station').val($(this).data('id') || '');
        $('#task-station-label').text($(this).data('label'));
        toggleTaskStationPanel(false);
    });

    /**
     * 站台選擇重設回「未選擇」並清掉篩選條件
     */
    function resetTaskStationPicker() {
        $('#task-station').val('');
        $('#task-station-search').val('');
        $('#task-station-system').val('');
        $('#task-station-list .js-task-station-pick').removeClass('active').show();
        $('#task-station-list .js-task-station-pick').filter(function () {
            return !$(this).data('id');
        }).addClass('active');
        $('#task-station-label').text('{{ trans("task_board.station_unset") }}');
        toggleTaskStationPanel(false);
    }

    $('#btn-open-create-task').on('click', function () {
        $('#task-id').val('');
        $('#form-task')[0].reset();
        $('#pri-medium').prop('checked', true);
        $('#modal-task-title').text('{{ trans("task_board.action_create_task") }}');
        taskImageFiles = [];
        $('#task-image-previews').empty();
        resetTaskStationPicker();
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



    // 排序 localStorage 記憶
    var savedField = localStorage.getItem('taskBoardSortField');
    var savedDir = localStorage.getItem('taskBoardSortDir');
    if (savedField && $('#filter-sort-field option[value="' + savedField + '"]').length) {
        $('#filter-sort-field').val(savedField);
    }
    if (savedDir && $('#filter-sort-dir option[value="' + savedDir + '"]').length) {
        $('#filter-sort-dir').val(savedDir);
    }
    $('#filter-sort-field, #filter-sort-dir').on('change', function () {
        localStorage.setItem('taskBoardSortField', $('#filter-sort-field').val());
        localStorage.setItem('taskBoardSortDir', $('#filter-sort-dir').val());
        loadBoard();
    });

    // 篩選
    $('#btn-filter').on('click', function () { loadBoard(); });
    $('#filter-keyword').on('keypress', function (e) { if (e.which === 13) loadBoard(); });
    $('#filter-overdue').on('change', function () { loadBoard(); });

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
// 描述內容樣式來自 public/css/task-content.css，編輯器與詳情面板共用同一份
var TASK_CONTENT_CSS_URL = '{{ asset('css/task-content.css') }}?v={{ filemtime(public_path('css/task-content.css')) }}';

var CHECKLIST_TOOLTIP = @json(trans('task_board.checklist_tooltip'));
var CHECKLIST_PLACEHOLDER = @json(trans('task_board.checklist_placeholder'));
var FILE_TOOLTIP = @json(trans('task_board.file_tooltip'));
var FILE_UPLOAD_FAILED = @json(trans('task_board.msg.file_upload_failed'));

/**
 * 從圖片對話框選檔上傳，成功後把網址回填給 TinyMCE
 *
 * @param {File}     file
 * @param {Function} callback TinyMCE file_picker 的回呼
 */
function uploadEditorImage(file, callback) {
    var fd = new FormData();
    fd.append('file', file);

    var xhr = new XMLHttpRequest();
    xhr.withCredentials = true;
    xhr.open('POST', '{{ route('admin.task-board.ajax-upload-editor-image') }}');
    xhr.setRequestHeader('X-CSRF-TOKEN', '{{ csrf_token() }}');

    xhr.onload = function () {
        var body = {};
        try { body = JSON.parse(xhr.responseText); } catch (e) { body = {}; }

        if (xhr.status < 200 || xhr.status >= 300) {
            notifyEditorError(uploadErrorMessage(body));

            return;
        }

        callback(body.location, { alt: file.name });
    };

    xhr.onerror = function () { notifyEditorError(FILE_UPLOAD_FAILED); };
    xhr.send(fd);
}

/**
 * 取出上傳失敗的原因：優先顯示欄位層級的驗證錯誤
 *
 * @param {Object} body 後端回應
 * @returns {string}
 */
function uploadErrorMessage(body) {
    if (body.errors && body.errors.file && body.errors.file.length) {
        return body.errors.file[0];
    }

    return body.message || FILE_UPLOAD_FAILED;
}

/**
 * 用編輯器內的通知列顯示錯誤（專案規範是不用 alert）
 *
 * @param {string} message
 */
function notifyEditorError(message) {
    var editor = tinymce.activeEditor;
    if (editor && editor.notificationManager) {
        editor.notificationManager.open({ text: message, type: 'error' });

        return;
    }

    showMsg(message);
}

/**
 * 上傳附件並在游標處插入下載連結
 *
 * @param {File}   file
 * @param {Object} editor TinyMCE 實例
 */
function uploadEditorFile(file, editor) {
    var fd = new FormData();
    fd.append('file', file);

    var xhr = new XMLHttpRequest();
    xhr.withCredentials = true;
    xhr.open('POST', '{{ route('admin.task-board.ajax-upload-editor-file') }}');
    xhr.setRequestHeader('X-CSRF-TOKEN', '{{ csrf_token() }}');

    xhr.onload = function () {
        var body = {};
        try { body = JSON.parse(xhr.responseText); } catch (e) { body = {}; }

        if (xhr.status < 200 || xhr.status >= 300) {
            // 驗證錯誤（檔案過大、類型不允許）要讓使用者看到原因
            editor.notificationManager.open({ text: uploadErrorMessage(body), type: 'error' });

            return;
        }

        editor.insertContent(
            '<p><a href="' + body.location + '" target="_blank" rel="noopener">'
            + '<i class="fas fa-paperclip"></i> ' + editor.dom.encode(body.name)
            + '</a></p>'
        );
    };

    xhr.onerror = function () {
        editor.notificationManager.open({ text: FILE_UPLOAD_FAILED, type: 'error' });
    };

    xhr.send(fd);
}

function getTinyConfig(overrides) {
    var dark = document.documentElement.getAttribute('data-theme') === 'dark';
    var config = {
        base_url: '{{ $tinyBase }}',
        suffix: '.min',
        license_key: 'gpl',
        skin: dark ? 'oxide-dark' : 'oxide',
        height: 300,
        menubar: false,
        plugins: ['autolink', 'link', 'lists', 'image', 'table', 'code'],
        toolbar: 'undo redo | fontfamily fontsize | bold italic underline strikethrough | forecolor backcolor | alignleft aligncenter alignright | bullist numlist tbchecklist | link image tbfile table | code',
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
        // images_upload_handler 只負責「貼上／拖曳」進來的圖片；
        // 點工具列圖片按鈕開的對話框要能選本機檔案，得另外給 file_picker_callback，
        // 否則那裡只有一個網址輸入框，看起來就像不能上傳。
        file_picker_types: 'image',
        file_picker_callback: function (callback, value, meta) {
            if (meta.filetype !== 'image') { return; }

            var input = document.createElement('input');
            input.type = 'file';
            input.accept = 'image/*';
            input.onchange = function () {
                if (!this.files.length) { return; }
                uploadEditorImage(this.files[0], callback);
            };
            input.click();
        },
        // 確保勾選清單的 data-checked 不會被 TinyMCE 的 schema 濾掉，否則存檔後勾選狀態會消失
        extended_valid_elements: 'li[data-checked|class|style|id],ul[class|style|id]',
        relative_urls: false,
        convert_urls: false,
        branding: false,
        promotion: false,
        // 勾選清單：用 data-checked 屬性而非 <input type="checkbox">，
        // input 在 TinyMCE 裡不好編輯，也不會隨 HTML 一起存回勾選狀態
        setup: function (editor) {
            editor.ui.registry.addButton('tbchecklist', {
                icon: 'checkmark',
                tooltip: CHECKLIST_TOOLTIP,
                onAction: function () {
                    editor.insertContent(
                        '<ul class="tb-checklist">' +
                        '<li data-checked="0">' + CHECKLIST_PLACEHOLDER + '</li>' +
                        '</ul>'
                    );
                }
            });

            // 圖片走 TinyMCE 內建的 image 上傳，這顆負責圖片以外的附件
            editor.ui.registry.addButton('tbfile', {
                icon: 'upload',
                tooltip: FILE_TOOLTIP,
                onAction: function () {
                    var input = document.createElement('input');
                    input.type = 'file';
                    input.onchange = function () {
                        if (this.files.length) { uploadEditorFile(this.files[0], editor); }
                    };
                    input.click();
                }
            });
        },
        content_css: [dark ? 'dark' : 'default', TASK_CONTENT_CSS_URL],
        content_style: 'body { font-family: "PingFang TC", "Microsoft JhengHei", Arial, sans-serif; font-size: 14px; line-height: 1.6; '
            + (dark ? 'background: #2d2d2d; color: #e0e0e0;' : '') + ' }'
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
