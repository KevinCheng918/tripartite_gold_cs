@extends('layouts.app')

@section('title', trans('task_board.page_title'))
@section('icon', 'columns')
@section('subtitle', trans('task_board.subtitle'))

@section('content')

    <style>
        .kanban-board { display: flex; gap: 1rem; overflow-x: auto; min-height: 70vh; padding-bottom: 1rem; }
        .kanban-column { flex: 1; min-width: 280px; max-width: 350px; display: flex; flex-direction: column; }
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
        .col-in-review .column-header   { background: #fff3cd; color: #664d03; }
        .col-resolved .column-header    { background: #d1e7dd; color: #0f5132; }
        .col-pending .card-list     { background: #f8f9fa; }
        .col-in-progress .card-list { background: #f0f6ff; }
        .col-in-review .card-list   { background: #fffcf0; }
        .col-resolved .card-list    { background: #f0faf4; }
        /* dark mode */
        [data-theme="dark"] .kanban-card { background: #2d2d2d; color: #e0e0e0; }
        [data-theme="dark"] .kanban-card .card-project { color: #999; }
        [data-theme="dark"] .card-list { background: #1e1e1e !important; }
        [data-theme="dark"] .col-pending .column-header     { background: #333; color: #ccc; }
        [data-theme="dark"] .col-in-progress .column-header { background: #1a3a5c; color: #8ec5fc; }
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
        @media (max-width: 767px) {
            #task-side-panel { width: 100% !important; }
        }
        @media (max-width: 767px) {
            .kanban-board { flex-direction: column; }
            .kanban-column { max-width: 100%; min-width: 100%; }
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
        <div class="kanban-column col-in-review" data-status="3">
            <div class="column-header">
                <span><i class="fas fa-search me-2"></i>{{ trans('task_board.status_in_review') }}</span>
                <span class="badge bg-warning text-dark" id="count-in-review">0</span>
            </div>
            <div class="card-list" id="list-in-review"></div>
        </div>
        <div class="kanban-column col-resolved" data-status="4">
            <div class="column-header">
                <span><i class="fas fa-check-circle me-2"></i>{{ trans('task_board.status_resolved') }}</span>
                <span class="badge bg-success" id="count-resolved">0</span>
            </div>
            <div class="card-list" id="list-resolved"></div>
        </div>
    </div>

    {{-- 新增/編輯任務 Modal --}}
    <div class="modal fade" id="modal-task" tabindex="-1">
        <div class="modal-dialog modal-dialog-scrollable">
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
                            <textarea id="task-description" class="form-control" rows="4"></textarea>
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
                            <select id="task-assignee" class="form-select">
                                <option value="">未指派</option>
                                @foreach($assignees as $u)
                                    <option value="{{ $u->id }}">{{ $u->nickname }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('task_board.field_due_date') }}</label>
                            <input type="date" id="task-due-date" class="form-control">
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
                <h5 class="mb-0" id="side-panel-project-badge"></h5>
                <button type="button" class="btn-close" id="btn-close-panel"></button>
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

    var statusListMap = { 1: 'list-pending', 2: 'list-in-progress', 3: 'list-in-review', 4: 'list-resolved' };
    var statusCountMap = { 1: 'count-pending', 2: 'count-in-progress', 3: 'count-in-review', 4: 'count-resolved' };

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
        var initials = task.project.substring(0, 2);

        var html = '<div class="kanban-card ' + (priorityClass[task.priority] || '') + '" data-task-id="' + task.id + '">';
        // 頂部：專案 badge + 優先順序
        html += '<div class="d-flex justify-content-between align-items-center mb-2">';
        html += '<span class="badge" style="background:' + pColor + ';color:#fff;border-radius:9999px;padding:0.25em 0.6em;font-size:0.75rem">' + initials + '</span>';
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
        html += task.assignee ? '<i class="fas fa-user me-1" style="color:#6c757d"></i>' + task.assignee : '<span class="text-muted">未指派</span>';
        html += '</div>';
        // 到期日
        if (task.due_date) {
            var today = new Date();
            today.setHours(0, 0, 0, 0);
            var due = new Date(task.due_date + 'T00:00:00');
            var isToday = due.getTime() === today.getTime();
            var isOverdue = due < today && task.status !== 4;
            var dueStyle = 'color:#6c757d';
            var dueText = task.due_date;
            if (isToday) {
                dueStyle = 'color:#dc3545;font-weight:bold';
                dueText = '今日到期';
            } else if (isOverdue) {
                dueStyle = 'color:#dc3545';
                dueText = task.due_date + ' <i class="fas fa-exclamation-triangle"></i>';
            }
            html += '<div style="font-size:0.75rem;' + dueStyle + '"><i class="fas fa-calendar me-1"></i>' + dueText + '</div>';
        }
        html += '</div>';
        return html;
    }

    function loadBoard() {
        var params = {};
        var projectId = $('#filter-project').val();
        var assigneeId = $('#filter-assignee').val();
        var keyword = $('#filter-keyword').val();
        if (projectId) params.project_id = projectId;
        if (assigneeId) params.assignee_id = assigneeId;
        if (keyword) params.keyword = keyword;

        $.ajax({
            url: '/admin/task-board/ajax-board',
            data: params,
            headers: { 'X-CSRF-TOKEN': csrfToken },
            success: function (data) {
                var columns = {
                    pending: data.pending || [],
                    in_progress: data.in_progress || [],
                    in_review: data.in_review || [],
                    resolved: data.resolved || []
                };

                var statusKeys = { pending: 1, in_progress: 2, in_review: 3, resolved: 4 };

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
    var statusLabels = { 1: '{{ trans("task_board.status_pending") }}', 2: '{{ trans("task_board.status_in_progress") }}', 3: '{{ trans("task_board.status_in_review") }}', 4: '{{ trans("task_board.status_resolved") }}' };

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
    $('#btn-close-panel').on('click', closePanel);
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

                $('#side-panel-project-badge').html('<span class="badge" style="background:' + pColor + ';color:#fff;border-radius:9999px;padding:0.3em 0.8em">' + t.project + '</span>');

                var html = '';
                // 標題（可編輯）
                html += '<div class="side-field" data-field="title" data-type="text"><label>{{ trans("task_board.field_title") }}</label><div class="field-value" style="font-size:1.25rem;font-weight:bold">' + t.title + '</div></div>';
                // 站台
                var stationDisplay = t.station ? (t.system ? t.system + ' / ' : '') + t.station : '<span class="text-muted">未選擇</span>';
                html += '<div class="side-field" data-field="station_id" data-type="select"><label>站台</label><div class="field-value">' + stationDisplay + '</div></div>';
                // 狀態
                html += '<div class="side-field" data-field="status" data-type="select"><label>{{ trans("task_board.field_status") }}</label><div class="field-value">' + (statusLabels[t.status] || '-') + '</div></div>';
                // 優先順序
                html += '<div class="side-field" data-field="priority" data-type="select"><label>{{ trans("task_board.field_priority") }}</label><div class="field-value">' + (priorityLabel[t.priority] || '-') + '</div></div>';
                // 指派人員
                html += '<div class="side-field" data-field="assignee_id" data-type="select"><label>{{ trans("task_board.field_assignee") }}</label><div class="field-value">' + (t.assignee || '<span class="text-muted">未指派</span>') + '</div></div>';
                // 到期日
                html += '<div class="side-field" data-field="due_date" data-type="date"><label>{{ trans("task_board.field_due_date") }}</label><div class="field-value">' + (t.due_date || '<span class="text-muted">未設定</span>') + '</div></div>';
                // 建立者 + 時間（不可編輯）
                html += '<div class="side-field"><label>{{ trans("task_board.field_creator") }}</label><div>' + t.creator + ' · ' + t.created_at + '</div></div>';
                // 描述
                html += '<div class="side-field" data-field="description" data-type="textarea"><label>{{ trans("task_board.field_description") }}</label><div class="field-value" style="white-space:pre-wrap;min-height:60px">' + (t.description || '<span class="text-muted">點擊新增描述...</span>') + '</div></div>';

                // 操作按鈕
                if (canDelete) {
                    html += '<div class="mt-3"><button class="btn btn-sm btn-outline-secondary" id="btn-panel-delete" data-id="' + t.id + '"><i class="fas fa-trash text-danger me-1"></i>{{ trans("task_board.action_delete") }}</button></div>';
                }

                // 留言區
                html += '<hr><h6><i class="fas fa-comments me-1"></i>留言</h6>';
                html += '<div id="panel-comments"><p class="text-muted" style="font-size:0.8125rem">Loading...</p></div>';
                html += '<div class="mt-2"><textarea id="panel-comment-input" class="form-control" rows="2" placeholder="輸入留言..."></textarea>';
                html += '<button class="btn btn-sm btn-primary mt-1" id="btn-send-comment">送出</button></div>';

                $('#side-panel-body').html(html);

                // 儲存原始資料供 inline edit
                $('#side-panel-body').data('task', t);

                bindInlineEdit();
                loadComments(taskId);

                $('#btn-panel-delete').on('click', function () {
                    var deleteId = $(this).data('id');
                    $('#delete-confirm-id').val(deleteId);
                    showBsModal('modal-delete-confirm');
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
            if (fieldType === 'textarea') {
                var val = t[fieldName] || '';
                inputEl = '<textarea class="form-control" rows="4">' + val + '</textarea>';
                $valueDiv.html(inputEl + '<div class="d-flex gap-2 mt-1">' + editConfirmBtn + editCancelBtn + '</div>');
            } else {
                if (fieldType === 'date') {
                    var val = t[fieldName] || '';
                    inputEl = '<input type="date" class="form-control" value="' + val + '">';
                } else if (fieldType === 'select' && fieldName === 'status') {
                    inputEl = '<select class="form-select">';
                    [1,2,3,4].forEach(function (s) { inputEl += '<option value="' + s + '"' + (t.status === s ? ' selected' : '') + '>' + statusLabels[s] + '</option>'; });
                    inputEl += '</select>';
                } else if (fieldType === 'select' && fieldName === 'priority') {
                    var priorityNames = { 1: '{{ trans("task_board.priority_low") }}', 2: '{{ trans("task_board.priority_medium") }}', 3: '{{ trans("task_board.priority_high") }}', 4: '{{ trans("task_board.priority_urgent") }}' };
                    inputEl = '<select class="form-select">';
                    [1,2,3,4].forEach(function (p) { inputEl += '<option value="' + p + '"' + (t.priority === p ? ' selected' : '') + '>' + priorityNames[p] + '</option>'; });
                    inputEl += '</select>';
                } else if (fieldType === 'select' && fieldName === 'station_id') {
                inputEl = '<select class="form-select"><option value="">未選擇</option>';
                @foreach($stations as $st)
                inputEl += '<option value="{{ $st->id }}"' + (t.station_id === {{ $st->id }} ? ' selected' : '') + '>{{ $st->name }}</option>';
                @endforeach
                inputEl += '</select>';
            } else if (fieldType === 'select' && fieldName === 'assignee_id') {
                    inputEl = '<select class="form-select"><option value="">未指派</option>';
                    @foreach($assignees as $u)
                    inputEl += '<option value="{{ $u->id }}"' + (t.assignee_id === {{ $u->id }} ? ' selected' : '') + '>{{ $u->nickname }}</option>';
                    @endforeach
                    inputEl += '</select>';
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
                    if (fieldName === 'assignee_id' && !newVal) newVal = null;
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
                    html += '<div class="d-flex justify-content-between"><strong style="font-size:0.875rem">' + c.user + '</strong><small class="text-muted">' + c.created_at + '</small></div>';
                    html += '<div style="font-size:0.875rem;white-space:pre-wrap;margin-top:0.25rem">' + c.content + '</div>';
                    html += '</div>';
                });
                $('#panel-comments').html(html);
            }
        });
    }

    function sendComment(taskId) {
        var content = $('#panel-comment-input').val().trim();
        if (!content) return;

        $.ajax({
            url: '/admin/task-board/ajax-store-comment/' + taskId,
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            contentType: 'application/json',
            data: JSON.stringify({ content: content }),
            success: function () {
                $('#panel-comment-input').val('');
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
    $('#btn-open-create-task').on('click', function () {
        $('#task-id').val('');
        $('#form-task')[0].reset();
        $('#pri-medium').prop('checked', true);
        $('#modal-task-title').text('{{ trans("task_board.action_create_task") }}');
        showBsModal('modal-task');
    });

    $('#form-task').on('submit', function (e) {
        e.preventDefault();
        var id = $('#task-id').val();
        var url = id ? '/admin/task-board/ajax-update-task/' + id : '/admin/task-board/ajax-store-task';
        var method = id ? 'PUT' : 'POST';

        var payload = {
            project_id: parseInt($('#task-project').val(), 10),
            station_id: $('#task-station').val() || null,
            title: $('#task-title').val(),
            description: $('#task-description').val() || null,
            priority: parseInt($('input[name="task_priority"]:checked').val(), 10),
            assignee_id: $('#task-assignee').val() || null,
            due_date: $('#task-due-date').val() || null
        };

        $.ajax({
            url: url, method: method,
            headers: { 'X-CSRF-TOKEN': csrfToken },
            contentType: 'application/json',
            data: JSON.stringify(payload),
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

    // 篩選
    $('#btn-filter').on('click', function () { loadBoard(); });
    $('#filter-keyword').on('keypress', function (e) { if (e.which === 13) loadBoard(); });

    // 初始載入
    loadBoard();
});
</script>
@endsection
