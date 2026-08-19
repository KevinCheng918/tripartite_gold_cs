@extends('layouts.app')

@section('title', '內勤管理')
@section('icon', 'id-card')
@section('subtitle', '人員到職與設備管理')

@section('content')

    <style>
        [data-theme="dark"] .modal-content .form-control { background: #2d2d2d; color: #e0e0e0; border-color: #444; }
        [data-theme="dark"] input[type="date"]::-webkit-calendar-picker-indicator { filter: invert(1); }
    </style>

    {{-- Tab 切換 --}}
    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-staff">
                <i class="fas fa-users me-1"></i>人員管理
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-equipment">
                <i class="fas fa-laptop me-1"></i>設備管理
            </button>
        </li>
    </ul>

    <div class="tab-content">
        {{-- 人員管理 Tab --}}
        <div class="tab-pane fade show active" id="tab-staff">
            <div class="main-card mb-3 card">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <div></div>
                    <a href="javascript:void(0)" class="text-muted text-decoration-none" data-bs-toggle="collapse" data-bs-target="#staff-search-collapse" aria-expanded="true" id="staff-collapse-toggle">
                        — 折疊 —
                    </a>
                </div>
                <div class="collapse show" id="staff-search-collapse">
                <div class="card-body pt-3">
                    <div class="row g-3 mb-3">
                        <div class="col-md-3 col-6">
                            <label class="form-label fw-bold">帳號：</label>
                            <input type="text" class="form-control" id="staff-search-account" placeholder="帳號">
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label fw-bold">暱稱：</label>
                            <input type="text" class="form-control" id="staff-search-name" placeholder="暱稱">
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label fw-bold">身份：</label>
                            <select class="form-select" id="staff-search-level">
                                <option value="">全部</option>
                                {!! \App\Presenters\UserPresenter::levelOptions([0]) !!}
                            </select>
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label fw-bold">年資篩選：</label>
                            <div class="input-group">
                                <select class="form-select" id="staff-search-tenure-op" style="max-width:90px">
                                    <option value="">不限</option>
                                    <option value="gte">≥</option>
                                    <option value="lte">≤</option>
                                </select>
                                <input type="number" class="form-control" id="staff-search-tenure-years" placeholder="年" min="0" step="1">
                                <span class="input-group-text">年</span>
                                <input type="number" class="form-control" id="staff-search-tenure-months" placeholder="月" min="0" max="11" step="1">
                                <span class="input-group-text">月</span>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-outline-secondary" id="btn-staff-reset">重置</button>
                        <button type="button" class="btn btn-primary" id="btn-staff-search">
                            <i class="fas fa-search me-1"></i>搜尋
                        </button>
                    </div>
                </div>
                </div>{{-- end collapse --}}
            </div>
            <div class="row g-3 mb-3" id="staff-stats"></div>
            <div class="main-card mb-3 card">
                <div class="card-body p-0">
                    <div class="table-responsive d-none d-md-block">
                        <table class="table table-hover table-striped align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>帳號</th>
                                    <th>暱稱</th>
                                    <th>身份</th>
                                    <th>到職日</th>
                                    <th>離職日</th>
                                    <th>年資</th>
                                    @if(Auth::user()->hasPermission('staff_manage.edit'))
                                    <th>操作</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody id="staff-table-body">
                                <tr><td colspan="8" class="text-center text-muted py-4">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="d-md-none" id="staff-card-list">
                        <p class="text-muted text-center py-4">Loading...</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- 設備管理 Tab --}}
        <div class="tab-pane fade" id="tab-equipment">
            <div class="main-card mb-3 card">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <div class="d-flex gap-2">
                        @if(Auth::user()->hasPermission('staff_manage.edit'))
                        <button class="btn btn-primary" id="btn-open-add-eq">
                            <i class="fas fa-plus me-1"></i>新增設備
                        </button>
                        @endif
                    </div>
                    <a href="javascript:void(0)" class="text-muted text-decoration-none" data-bs-toggle="collapse" data-bs-target="#eq-search-collapse" aria-expanded="true" id="eq-collapse-toggle">
                        — 折疊 —
                    </a>
                </div>
                <div class="collapse show" id="eq-search-collapse">
                <div class="card-body pt-3">
                    <div class="row g-3 mb-3">
                        <div class="col-md-3 col-6">
                            <label class="form-label fw-bold">帳號：</label>
                            <input type="text" class="form-control" id="eq-search-account" placeholder="帳號">
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label fw-bold">暱稱：</label>
                            <input type="text" class="form-control" id="eq-search-name" placeholder="暱稱">
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label fw-bold">設備名稱：</label>
                            <input type="text" class="form-control" id="eq-search-eq" placeholder="設備名稱">
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label fw-bold">編號：</label>
                            <input type="text" class="form-control" id="eq-search-serial" placeholder="編號">
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-3 col-6">
                            <label class="form-label fw-bold">使用年限：</label>
                            <div class="input-group">
                                <select class="form-select" id="eq-search-duration-op" style="max-width:90px">
                                    <option value="">不限</option>
                                    <option value="gte">≥</option>
                                    <option value="lte">≤</option>
                                </select>
                                <input type="number" class="form-control" id="eq-search-duration-years" placeholder="年" min="0" step="1">
                                <span class="input-group-text">年</span>
                                <input type="number" class="form-control" id="eq-search-duration-months" placeholder="月" min="0" max="11" step="1">
                                <span class="input-group-text">月</span>
                            </div>
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label fw-bold">狀態：</label>
                            <select class="form-select" id="eq-search-status">
                                <option value="">全部</option>
                                <option value="in_use">使用中</option>
                                <option value="returned">已退還</option>
                                <option value="not_received">未領取</option>
                            </select>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-outline-secondary" id="btn-eq-reset">重置</button>
                        <button type="button" class="btn btn-primary" id="btn-eq-search">
                            <i class="fas fa-search me-1"></i>搜尋
                        </button>
                    </div>
                </div>
                </div>{{-- end collapse --}}
            </div>
            <div class="row g-3 mb-3" id="eq-stats"></div>
            <div class="main-card mb-3 card">
                <div class="card-body p-0">
                    <div class="table-responsive d-none d-md-block">
                        <table class="table table-hover table-striped align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>員工</th>
                                    <th>設備名稱</th>
                                    <th>型號</th>
                                    <th>編號</th>
                                    <th>領用日期</th>
                                    <th>退還日期</th>
                                    <th>使用時長</th>
                                    <th>狀態</th>
                                    @if(Auth::user()->hasPermission('staff_manage.edit'))
                                    <th>操作</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody id="eq-table-body">
                                <tr><td colspan="10" class="text-center text-muted py-4">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="d-md-none" id="eq-card-list">
                        <p class="text-muted text-center py-4">Loading...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- 編輯人員 Modal --}}
    <div class="modal fade" id="modal-staff-edit" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="staff-edit-title">編輯人員</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="form-staff-edit">
                        <input type="hidden" id="staff-edit-id">
                        <div class="mb-3">
                            <label class="form-label">到職日</label>
                            <input id="staff-hired-at" type="date" class="form-control">
                            <div id="staff-tenure-hint" class="mt-1 fw-bold" style="font-size:0.9375rem;color:#a67c00"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">離職日</label>
                            <input id="staff-resigned-at" type="date" class="form-control">
                        </div>
                        <div class="d-flex justify-content-between">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                            <button type="submit" class="btn btn-primary">儲存</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- 編輯設備 Modal --}}
    <div class="modal fade" id="modal-eq-edit" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="eq-edit-title">設備管理</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="form-eq-edit">
                        <input type="hidden" id="eq-edit-id">
                        <div id="staff-equipments-list"></div>
                        <button type="button" class="btn btn-sm btn-outline-secondary mt-1 mb-3" id="btn-staff-add-eq">
                            <i class="fas fa-plus me-1"></i>新增設備
                        </button>
                        <div class="d-flex justify-content-between">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                            <button type="submit" class="btn btn-primary">儲存</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- 新增設備 Modal --}}
    <div class="modal fade" id="modal-add-eq" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">新增設備</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="form-add-eq">
                        <div class="mb-3">
                            <label class="form-label">領用人 <span class="text-danger">*</span></label>
                            <select id="add-eq-user" class="form-select" required>
                                <option value="">請選擇</option>
                                @foreach($staffList as $u)
                                    <option value="{{ $u->id }}">{{ $u->nickname }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">設備名稱 <span class="text-danger">*</span></label>
                            <input id="add-eq-name" type="text" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">型號</label>
                            <input id="add-eq-model" type="text" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">編號</label>
                            <input id="add-eq-serial" type="text" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">領用日期</label>
                            <input id="add-eq-received-at" type="date" class="form-control">
                        </div>
                        <div class="d-flex justify-content-between">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                            <button type="submit" class="btn btn-primary">新增</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- 歸還確認 Modal --}}
    <div class="modal fade" id="modal-eq-return-confirm" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-body text-center py-4">
                    <p id="eq-return-confirm-text" class="mb-3"></p>
                    <input type="hidden" id="eq-return-user-id">
                    <input type="hidden" id="eq-return-eq-idx">
                    <div class="d-flex justify-content-between">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                        <button type="button" class="btn btn-primary" id="btn-eq-return-ok">確定歸還</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- 訊息 Modal --}}
    <div class="modal fade" id="modal-staff-msg" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-body text-center py-4">
                    <p id="modal-staff-msg-text" class="mb-3"></p>
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
                </div>
            </div>
        </div>
    </div>

@endsection

@section('scripts')
<script>
$(function () {
    var csrfToken = $('meta[name="csrf-token"]').attr('content');
    var canEdit = {{ Auth::user()->hasPermission('staff_manage.edit') ? 'true' : 'false' }};
    var levelMap = @json(collect(config('constants.USER.LEVEL'))->mapWithKeys(function ($v, $k) { return [$v => trans('account.level_' . strtolower($k))]; })->except([config('constants.USER.LEVEL.ADMIN')]));
    var levelColorMap = { 0: 'bg-danger', 1: 'bg-dark', 2: 'bg-primary', 3: 'bg-info', 4: 'bg-secondary' };
    function levelBadge(lv) { return '<span class="badge ' + (levelColorMap[lv] || 'bg-secondary') + '">' + (levelMap[lv] || '-') + '</span>'; }

    function showMsg(msg) {
        $('#modal-staff-msg-text').text(msg);
        showBsModal('modal-staff-msg');
    }

    var staffData = [];

    function loadList() {
        $.ajax({
            url: '/admin/staff-manage/ajax-list',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            success: function (body) {
                staffData = body.data || body;
                renderStaffStats(staffData);
                renderEqStats(staffData);
                renderStaffTable(staffData);
                renderStaffCards(staffData);
                renderEqTable(staffData);
                renderEqCards(staffData);
            }
        });
    }

    // ---------------------------------------------------------------
    //  人員管理 Tab
    // ---------------------------------------------------------------

    // 人員統計
    function renderStaffStats(list) {
        var total = list.length;
        var byLevel = {};
        list.forEach(function (s) { byLevel[s.level] = (byLevel[s.level] || 0) + 1; });
        var onJob = list.filter(function (s) { return !s.resigned_at; }).length;
        var resigned = total - onJob;

        // 身份卡片
        var levelItems = '';
        Object.keys(levelMap).forEach(function (lv) {
            levelItems += '<div class="flex-fill text-center"><div class="mb-1">' + levelBadge(parseInt(lv, 10)) + '</div><div class="fw-bold" style="font-size:1.25rem">' + (byLevel[lv] || 0) + '</div></div>';
        });

        var html = '<div class="col"><div class="card shadow-sm">';
        html += '<div class="card-header py-2 d-flex justify-content-center" style="background:#e9ecef"><strong style="font-size:1.0625rem"><i class="fas fa-users me-2"></i>身份</strong></div>';
        html += '<div class="card-body py-2"><div class="d-flex flex-wrap">' + levelItems + '</div></div>';
        html += '</div></div>';

        // 狀態卡片
        html += '<div class="col"><div class="card shadow-sm">';
        html += '<div class="card-header py-2 d-flex justify-content-center" style="background:#e9ecef"><strong style="font-size:1.0625rem"><i class="fas fa-toggle-on me-2"></i>狀態</strong></div>';
        html += '<div class="card-body py-2"><div class="d-flex flex-wrap text-center">';
        html += '<div class="flex-fill"><div class="mb-1"><span class="badge bg-success">在職</span></div><div class="fw-bold" style="font-size:1.25rem">' + onJob + '</div></div>';
        html += '<div class="flex-fill"><div class="mb-1"><span class="badge bg-secondary">離職</span></div><div class="fw-bold" style="font-size:1.25rem">' + resigned + '</div></div>';
        html += '</div></div></div></div>';

        // 總計
        html += '<div class="col-auto d-flex"><div class="card shadow-sm d-flex justify-content-center" style="min-width:100px">';
        html += '<div class="card-header py-2 d-flex justify-content-center" style="background:#e9ecef"><strong style="font-size:1.0625rem">總計</strong></div>';
        html += '<div class="card-body py-2 text-center"><div class="fw-bold" style="font-size:1.25rem">' + total + '</div><small class="text-muted">人</small></div>';
        html += '</div></div>';

        $('#staff-stats').html(html);
    }

    // 設備統計
    function renderEqStats(list) {
        var total = 0, inUse = 0, returned = 0, notReceived = 0;
        list.forEach(function (s) {
            if (!s.equipments) return;
            s.equipments.forEach(function (eq) {
                total++;
                if (eq.returned_at) { returned++; }
                else if (eq.received_at) { inUse++; }
                else { notReceived++; }
            });
        });

        var html = '<div class="col"><div class="card shadow-sm">';
        html += '<div class="card-header py-2 d-flex justify-content-center" style="background:#e9ecef"><strong style="font-size:1.0625rem"><i class="fas fa-laptop me-2"></i>設備狀態</strong></div>';
        html += '<div class="card-body py-2"><div class="d-flex flex-wrap text-center">';
        html += '<div class="flex-fill"><div class="mb-1"><span class="badge bg-success">使用中</span></div><div class="fw-bold" style="font-size:1.25rem">' + inUse + '</div></div>';
        html += '<div class="flex-fill"><div class="mb-1"><span class="badge bg-secondary">已退還</span></div><div class="fw-bold" style="font-size:1.25rem">' + returned + '</div></div>';
        html += '<div class="flex-fill"><div class="mb-1"><span class="badge bg-warning text-dark">未領取</span></div><div class="fw-bold" style="font-size:1.25rem">' + notReceived + '</div></div>';
        html += '</div></div></div></div>';

        html += '<div class="col-auto d-flex"><div class="card shadow-sm d-flex justify-content-center" style="min-width:100px">';
        html += '<div class="card-header py-2 d-flex justify-content-center" style="background:#e9ecef"><strong style="font-size:1.0625rem">總計</strong></div>';
        html += '<div class="card-body py-2 text-center"><div class="fw-bold" style="font-size:1.25rem">' + total + '</div><small class="text-muted">件</small></div>';
        html += '</div></div>';

        $('#eq-stats').html(html);
    }

    function calcTenureMonths(hiredAt) {
        if (!hiredAt) return -1;
        var hired = new Date(hiredAt + 'T00:00:00');
        var now = new Date();
        now.setHours(0, 0, 0, 0);
        if (hired > now) return -1;
        return (now.getFullYear() - hired.getFullYear()) * 12 + (now.getMonth() - hired.getMonth());
    }

    function filterStaffData(list) {
        var acct = ($('#staff-search-account').val() || '').toLowerCase();
        var name = ($('#staff-search-name').val() || '').toLowerCase();
        var level = $('#staff-search-level').val();
        var tenureOp = $('#staff-search-tenure-op').val();
        var tenureYears = parseInt($('#staff-search-tenure-years').val(), 10) || 0;
        var tenureMonths = parseInt($('#staff-search-tenure-months').val(), 10) || 0;
        var tenureThreshold = tenureYears * 12 + tenureMonths;

        return list.filter(function (s) {
            if (acct && (s.account || '').toLowerCase().indexOf(acct) === -1) return false;
            if (name && (s.nickname || '').toLowerCase().indexOf(name) === -1) return false;
            if (level && s.level !== parseInt(level, 10)) return false;
            if (tenureOp) {
                var months = calcTenureMonths(s.hired_at);
                if (months < 0) return false;
                if (tenureOp === 'gte' && months < tenureThreshold) return false;
                if (tenureOp === 'lte' && months > tenureThreshold) return false;
            }
            return true;
        });
    }

    function renderStaffTable(list) {
        list = filterStaffData(list);
        var $tbody = $('#staff-table-body');
        if (!list.length) { $tbody.html('<tr><td colspan="8" class="text-center text-muted py-4">暫無資料</td></tr>'); return; }
        var html = '';
        list.forEach(function (s, idx) {
            html += '<tr>';
            html += '<td>' + (idx + 1) + '</td>';
            html += '<td>' + s.account + '</td>';
            html += '<td><strong>' + s.nickname + '</strong></td>';
            html += '<td>' + levelBadge(s.level) + '</td>';
            html += '<td>' + (s.hired_at || '<span class="text-muted">未設定</span>') + '</td>';
            html += '<td>' + (s.resigned_at ? s.resigned_at : '<span class="badge bg-success">在職中</span>') + '</td>';
            html += '<td>' + (s.tenure || '-') + '</td>';
            if (canEdit) {
                html += '<td><button class="btn btn-sm btn-outline-secondary js-staff-edit" data-id="' + s.id + '" data-nickname="' + s.nickname + '" data-hired="' + (s.hired_at || '') + '" data-resigned="' + (s.resigned_at || '') + '"><i class="fas fa-edit me-1"></i>編輯</button></td>';
            }
            html += '</tr>';
        });
        $tbody.html(html);
        bindStaffEdit();
    }

    function renderStaffCards(list) {
        list = filterStaffData(list);
        var $c = $('#staff-card-list');
        if (!list.length) { $c.html('<p class="text-muted text-center py-4">暫無資料</p>'); return; }
        var html = '';
        list.forEach(function (s) {
            html += '<div class="card mb-2 shadow-sm"><div class="card-body py-3">';
            html += '<div class="d-flex justify-content-between align-items-start mb-2"><div><strong style="font-size:1.0625rem">' + s.nickname + '</strong><div class="text-muted" style="font-size:0.8125rem">' + s.account + '</div></div>';
            if (canEdit) {
                html += '<button class="btn btn-sm btn-outline-secondary js-staff-edit" data-id="' + s.id + '" data-nickname="' + s.nickname + '" data-hired="' + (s.hired_at || '') + '" data-resigned="' + (s.resigned_at || '') + '"><i class="fas fa-edit"></i></button>';
            }
            html += '</div>';
            html += '<div class="d-flex justify-content-between mb-1" style="font-size:0.875rem"><span class="text-muted">到職日</span><span>' + (s.hired_at || '未設定') + '</span></div>';
            html += '<div class="d-flex justify-content-between mb-1" style="font-size:0.875rem"><span class="text-muted">離職日</span><span>' + (s.resigned_at || '<span class="badge bg-success">在職中</span>') + '</span></div>';
            html += '<div class="d-flex justify-content-between mb-1" style="font-size:0.875rem"><span class="text-muted">年資</span><span>' + (s.tenure || '-') + '</span></div>';
            html += '</div></div>';
        });
        $c.html(html);
        bindStaffEdit();
    }

    function bindStaffEdit() {
        $('.js-staff-edit').off('click').on('click', function () {
            var $btn = $(this);
            $('#staff-edit-id').val($btn.data('id'));
            $('#staff-edit-title').text('編輯 - ' + $btn.data('nickname'));
            var hired = $btn.data('hired') || '';
            $('#staff-hired-at').val(hired);
            $('#staff-resigned-at').val($btn.data('resigned') || '');
            calcTenure(hired);
            showBsModal('modal-staff-edit');
        });
    }

    function calcTenure(dateStr) {
        var $el = $('#staff-tenure-hint');
        if (!dateStr) { $el.text(''); return; }
        var hired = new Date(dateStr + 'T00:00:00');
        var now = new Date();
        now.setHours(0, 0, 0, 0);
        if (hired > now) { $el.text('尚未到職'); return; }
        var y = now.getFullYear() - hired.getFullYear();
        var m = now.getMonth() - hired.getMonth();
        var d = now.getDate() - hired.getDate();
        if (d < 0) { m--; d += new Date(now.getFullYear(), now.getMonth(), 0).getDate(); }
        if (m < 0) { y--; m += 12; }
        var parts = [];
        if (y > 0) parts.push(y + ' 年');
        if (m > 0) parts.push(m + ' 個月');
        if (d > 0) parts.push(d + ' 天');
        $el.text(parts.length ? '年資：' + parts.join(' ') : '今天到職');
    }
    $('#staff-hired-at').on('change', function () { calcTenure($(this).val()); });

    $('#form-staff-edit').on('submit', function (e) {
        e.preventDefault();
        $.ajax({
            url: '/admin/staff-manage/ajax-update/' + $('#staff-edit-id').val(),
            method: 'PUT',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            contentType: 'application/json',
            data: JSON.stringify({ hired_at: $('#staff-hired-at').val() || null, resigned_at: $('#staff-resigned-at').val() || null }),
            success: function (body) {
                hideBsModal(document.getElementById('modal-staff-edit'));
                setTimeout(function () { showMsg(body.message || '已更新'); loadList(); }, 400);
            },
            error: function (xhr) { showMsg((xhr.responseJSON && xhr.responseJSON.message) || '更新失敗'); }
        });
    });

    // ---------------------------------------------------------------
    //  設備管理 Tab
    // ---------------------------------------------------------------

    function calcEqDuration(receivedAt, returnedAt) {
        if (!receivedAt) { return ''; }
        var start = new Date(receivedAt + 'T00:00:00');
        var end = returnedAt ? new Date(returnedAt + 'T00:00:00') : new Date();
        end.setHours(0, 0, 0, 0);
        if (end < start) { return ''; }
        var y = end.getFullYear() - start.getFullYear();
        var m = end.getMonth() - start.getMonth();
        var d = end.getDate() - start.getDate();
        if (d < 0) { m--; d += new Date(end.getFullYear(), end.getMonth(), 0).getDate(); }
        if (m < 0) { y--; m += 12; }
        var parts = [];
        if (y > 0) parts.push(y + '年');
        if (m > 0) parts.push(m + '個月');
        parts.push(d + '天');
        return (returnedAt ? '' : '已') + parts.join(' ');
    }

    function eqStatusBadge(eq) {
        if (eq.returned_at) return '<span class="badge bg-secondary">已退還</span>';
        if (eq.received_at) return '<span class="badge bg-success">使用中</span>';
        return '<span class="badge bg-warning text-dark">未領取</span>';
    }

    function calcEqDurationMonths(receivedAt, returnedAt) {
        if (!receivedAt) return -1;
        var start = new Date(receivedAt + 'T00:00:00');
        var end = returnedAt ? new Date(returnedAt + 'T00:00:00') : new Date();
        end.setHours(0, 0, 0, 0);
        if (end < start) return -1;
        return (end.getFullYear() - start.getFullYear()) * 12 + (end.getMonth() - start.getMonth());
    }

    function filterEqData(list) {
        var fAccount = ($('#eq-search-account').val() || '').toLowerCase();
        var fName = ($('#eq-search-name').val() || '').toLowerCase();
        var fEq = ($('#eq-search-eq').val() || '').toLowerCase();
        var fSerial = ($('#eq-search-serial').val() || '').toLowerCase();
        var fDurationOp = $('#eq-search-duration-op').val();
        var fDurationYears = parseInt($('#eq-search-duration-years').val(), 10) || 0;
        var fDurationMonths = parseInt($('#eq-search-duration-months').val(), 10) || 0;
        var fDurationThreshold = fDurationYears * 12 + fDurationMonths;
        var fStatus = $('#eq-search-status').val();

        var result = [];
        list.forEach(function (s) {
            if (!s.equipments || !s.equipments.length) return;
            s.equipments.forEach(function (eq, eqIdx) {
                if (fAccount && (s.account || '').toLowerCase().indexOf(fAccount) === -1) return;
                if (fName && (s.nickname || '').toLowerCase().indexOf(fName) === -1) return;
                if (fEq && (eq.name || '').toLowerCase().indexOf(fEq) === -1) return;
                if (fSerial && (eq.serial || '').toLowerCase().indexOf(fSerial) === -1) return;
                if (fStatus) {
                    if (fStatus === 'in_use' && (!eq.received_at || eq.returned_at)) return;
                    if (fStatus === 'returned' && !eq.returned_at) return;
                    if (fStatus === 'not_received' && eq.received_at) return;
                }
                if (fDurationOp) {
                    var months = calcEqDurationMonths(eq.received_at, eq.returned_at);
                    if (months < 0) return;
                    if (fDurationOp === 'gte' && months < fDurationThreshold) return;
                    if (fDurationOp === 'lte' && months > fDurationThreshold) return;
                }
                result.push({ user: s, eq: eq, eqIdx: eqIdx });
            });
        });
        return result;
    }

    function renderEqTable(list) {
        var filtered = filterEqData(list);
        var $tbody = $('#eq-table-body');
        var colCount = canEdit ? 10 : 9;

        if (!filtered.length) { $tbody.html('<tr><td colspan="' + colCount + '" class="text-center text-muted py-4">暫無設備</td></tr>'); return; }

        var html = '';
        filtered.forEach(function (item, idx) {
            var eq = item.eq;
            html += '<tr>';
            html += '<td>' + (idx + 1) + '</td>';
            html += '<td><strong>' + item.user.nickname + '</strong></td>';
            html += '<td>' + (eq.name || '-') + '</td>';
            html += '<td>' + (eq.model || '-') + '</td>';
            html += '<td>' + (eq.serial || '-') + '</td>';
            html += '<td>' + (eq.received_at || '-') + '</td>';
            html += '<td>' + (eq.returned_at || '-') + '</td>';
            html += '<td>' + calcEqDuration(eq.received_at, eq.returned_at) + '</td>';
            html += '<td>' + eqStatusBadge(eq) + '</td>';
            if (canEdit) {
                html += '<td>';
                if (eq.received_at && !eq.returned_at) {
                    html += '<button class="btn btn-sm btn-outline-secondary js-eq-return" data-user-id="' + item.user.id + '" data-eq-idx="' + item.eqIdx + '" data-eq-name="' + (item.eq.name || '設備') + '" data-user-name="' + item.user.nickname + '"><i class="fas fa-undo me-1"></i>歸還</button>';
                }
                html += '</td>';
            }
            html += '</tr>';
        });
        $tbody.html(html);
        bindEqReturn();
    }

    function renderEqCards(list) {
        var filtered = filterEqData(list);
        var $c = $('#eq-card-list');

        if (!filtered.length) { $c.html('<p class="text-muted text-center py-4">暫無設備</p>'); return; }

        var html = '';
        filtered.forEach(function (item) {
            var eq = item.eq;
            html += '<div class="card mb-2 shadow-sm"><div class="card-body py-3">';
            html += '<div class="d-flex justify-content-between align-items-start mb-2"><strong>' + item.user.nickname + '</strong>' + eqStatusBadge(eq) + '</div>';
            html += '<div class="d-flex justify-content-between mb-1" style="font-size:0.875rem"><span class="text-muted">設備</span><span>' + (eq.name || '-') + '</span></div>';
            html += '<div class="d-flex justify-content-between mb-1" style="font-size:0.875rem"><span class="text-muted">型號</span><span>' + (eq.model || '-') + '</span></div>';
            html += '<div class="d-flex justify-content-between mb-1" style="font-size:0.875rem"><span class="text-muted">編號</span><span>' + (eq.serial || '-') + '</span></div>';
            if (eq.received_at) html += '<div class="d-flex justify-content-between mb-1" style="font-size:0.875rem"><span class="text-muted">領用</span><span>' + eq.received_at + '</span></div>';
            if (eq.returned_at) html += '<div class="d-flex justify-content-between mb-1" style="font-size:0.875rem"><span class="text-muted">退還</span><span>' + eq.returned_at + '</span></div>';
            var dur = calcEqDuration(eq.received_at, eq.returned_at);
            if (dur) html += '<div class="d-flex justify-content-between mb-1" style="font-size:0.875rem"><span class="text-muted">時長</span><span>' + dur + '</span></div>';
            if (canEdit && eq.received_at && !eq.returned_at) {
                html += '<div class="mt-2"><button class="btn btn-sm btn-outline-secondary js-eq-return" data-user-id="' + item.user.id + '" data-eq-idx="' + item.eqIdx + '"><i class="fas fa-undo me-1"></i>歸還</button></div>';
            }
            html += '</div></div>';
        });
        $c.html(html);
        bindEqReturn();
    }

    function bindEqReturn() {
        $('.js-eq-return').off('click').on('click', function () {
            var userId = $(this).data('user-id');
            var eqIdx = $(this).data('eq-idx');
            var eqName = $(this).data('eq-name') || '設備';
            var userName = $(this).data('user-name') || '';

            $('#eq-return-user-id').val(userId);
            $('#eq-return-eq-idx').val(eqIdx);
            var target = null;
            staffData.forEach(function (s) { if (s.id === parseInt(userId, 10)) { target = s; } });
            var eq = (target && target.equipments) ? target.equipments[eqIdx] : {};

            var confirmHtml = '<p><strong>確定歸還此設備？</strong></p>';
            confirmHtml += '<table class="table table-sm text-start mt-2"><tbody>';
            confirmHtml += '<tr><th style="width:80px">員工</th><td>' + userName + '</td></tr>';
            confirmHtml += '<tr><th>設備</th><td>' + eqName + '</td></tr>';
            if (eq.model) confirmHtml += '<tr><th>型號</th><td>' + eq.model + '</td></tr>';
            if (eq.serial) confirmHtml += '<tr><th>編號</th><td>' + eq.serial + '</td></tr>';
            if (eq.received_at) confirmHtml += '<tr><th>領用日期</th><td>' + eq.received_at + '</td></tr>';
            confirmHtml += '<tr><th>歸還日期</th><td><strong>' + new Date().toISOString().substring(0, 10) + '</strong>（今天）</td></tr>';
            var dur = calcEqDuration(eq.received_at, null);
            if (dur) confirmHtml += '<tr><th>使用時長</th><td>' + dur + '</td></tr>';
            confirmHtml += '</tbody></table>';
            $('#eq-return-confirm-text').html(confirmHtml);
            showBsModal('modal-eq-return-confirm');
        });
    }

    $('#btn-eq-return-ok').on('click', function () {
        var userId = parseInt($('#eq-return-user-id').val(), 10);
        var eqIdx = parseInt($('#eq-return-eq-idx').val(), 10);
        var $btn = $(this);
        $btn.prop('disabled', true);

        var target = null;
        staffData.forEach(function (s) { if (s.id === userId) { target = s; } });
        if (!target) { $btn.prop('disabled', false); return; }
        var eqs = JSON.parse(JSON.stringify(target.equipments || []));
        if (eqs[eqIdx]) {
            eqs[eqIdx].returned_at = new Date().toISOString().substring(0, 10);
        }

        $.ajax({
            url: '/admin/staff-manage/ajax-update/' + userId,
            method: 'PUT',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            contentType: 'application/json',
            data: JSON.stringify({ equipments: eqs }),
            success: function (body) {
                hideBsModal(document.getElementById('modal-eq-return-confirm'));
                setTimeout(function () { showMsg(body.message || '已歸還'); loadList(); }, 400);
                $btn.prop('disabled', false);
            },
            error: function (xhr) {
                hideBsModal(document.getElementById('modal-eq-return-confirm'));
                setTimeout(function () { showMsg((xhr.responseJSON && xhr.responseJSON.message) || '操作失敗'); }, 400);
                $btn.prop('disabled', false);
            }
        });
    });

    function renderEquipments(list) {
        var $c = $('#staff-equipments-list');
        $c.empty();
        if (!list.length) { $c.html('<p class="text-muted mb-0" style="font-size:0.875rem">尚無設備</p>'); return; }
        list.forEach(function (eq, idx) {
            var duration = calcEqDuration(eq.received_at, eq.returned_at);
            var html = '<div class="border rounded p-2 mb-2 position-relative js-eq-item">';
            html += '<button type="button" class="btn-close position-absolute top-0 end-0 m-1 js-remove-eq" data-idx="' + idx + '" style="font-size:0.5rem"></button>';
            html += '<div class="row g-2 mb-1">';
            html += '<div class="col-md-4"><input type="text" class="form-control form-control-sm js-eq-name" placeholder="設備名稱" value="' + (eq.name || '').replace(/"/g, '&quot;') + '"></div>';
            html += '<div class="col-md-4"><input type="text" class="form-control form-control-sm js-eq-model" placeholder="型號" value="' + (eq.model || '').replace(/"/g, '&quot;') + '"></div>';
            html += '<div class="col-md-4"><input type="text" class="form-control form-control-sm js-eq-serial" placeholder="編號" value="' + (eq.serial || '').replace(/"/g, '&quot;') + '"></div>';
            html += '</div>';
            html += '<div class="row g-2">';
            html += '<div class="col-md-4"><label style="font-size:0.75rem" class="text-muted">領用日期</label><input type="date" class="form-control form-control-sm js-eq-received-at" value="' + (eq.received_at || '') + '"></div>';
            html += '<div class="col-md-4"><label style="font-size:0.75rem" class="text-muted">退還日期</label><input type="date" class="form-control form-control-sm js-eq-returned-at" value="' + (eq.returned_at || '') + '"></div>';
            html += '<div class="col-md-4 d-flex align-items-end"><span class="text-muted" style="font-size:0.8125rem;padding-bottom:0.375rem">' + duration + '</span></div>';
            html += '</div></div>';
            $c.append(html);
        });
    }

    $('#btn-staff-add-eq').on('click', function () {
        var cur = getEqData();
        cur.push({ name: '', model: '', serial: '', received_at: '', returned_at: '' });
        renderEquipments(cur);
    });

    $(document).on('click', '.js-remove-eq', function () {
        var cur = getEqData();
        cur.splice(parseInt($(this).data('idx'), 10), 1);
        renderEquipments(cur);
    });

    function getEqData() {
        var list = [];
        $('.js-eq-item').each(function () {
            list.push({
                name: $(this).find('.js-eq-name').val(),
                model: $(this).find('.js-eq-model').val(),
                serial: $(this).find('.js-eq-serial').val(),
                received_at: $(this).find('.js-eq-received-at').val() || null,
                returned_at: $(this).find('.js-eq-returned-at').val() || null
            });
        });
        return list;
    }

    $('#form-eq-edit').on('submit', function (e) {
        e.preventDefault();
        $.ajax({
            url: '/admin/staff-manage/ajax-update/' + $('#eq-edit-id').val(),
            method: 'PUT',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            contentType: 'application/json',
            data: JSON.stringify({ equipments: getEqData() }),
            success: function (body) {
                hideBsModal(document.getElementById('modal-eq-edit'));
                setTimeout(function () { showMsg(body.message || '已更新'); loadList(); }, 400);
            },
            error: function (xhr) { showMsg((xhr.responseJSON && xhr.responseJSON.message) || '更新失敗'); }
        });
    });

    // 新增設備
    $('#btn-open-add-eq').on('click', function () {
        $('#form-add-eq')[0].reset();
        showBsModal('modal-add-eq');
    });

    $('#form-add-eq').on('submit', function (e) {
        e.preventDefault();
        var userId = $('#add-eq-user').val();
        if (!userId) { showMsg('請選擇領用人'); return; }

        // 找到該用戶現有設備，加入新的
        var target = null;
        staffData.forEach(function (s) { if (s.id === parseInt(userId, 10)) { target = s; } });
        var existing = (target && target.equipments) ? JSON.parse(JSON.stringify(target.equipments)) : [];
        existing.push({
            name: $('#add-eq-name').val(),
            model: $('#add-eq-model').val() || null,
            serial: $('#add-eq-serial').val() || null,
            received_at: $('#add-eq-received-at').val() || null,
            returned_at: null
        });

        $.ajax({
            url: '/admin/staff-manage/ajax-update/' + userId,
            method: 'PUT',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            contentType: 'application/json',
            data: JSON.stringify({ equipments: existing }),
            success: function (body) {
                hideBsModal(document.getElementById('modal-add-eq'));
                setTimeout(function () { showMsg(body.message || '已新增'); loadList(); }, 400);
            },
            error: function (xhr) { showMsg((xhr.responseJSON && xhr.responseJSON.message) || '新增失敗'); }
        });
    });

    // 人員搜尋
    $('#btn-staff-search').on('click', function () { renderStaffTable(staffData); renderStaffCards(staffData); });
    $('#btn-staff-reset').on('click', function () {
        $('#staff-search-account, #staff-search-name, #staff-search-tenure-years, #staff-search-tenure-months').val('');
        $('#staff-search-level, #staff-search-tenure-op').val('');
        renderStaffTable(staffData); renderStaffCards(staffData);
    });
    var $staffCollapse = $('#staff-search-collapse');
    var $staffToggle = $('#staff-collapse-toggle');
    $staffCollapse.on('show.bs.collapse', function () { $staffToggle.text('— 折疊 —'); });
    $staffCollapse.on('hide.bs.collapse', function () { $staffToggle.text('— 展開 —'); });

    // 折疊切換
    var $eqCollapse = $('#eq-search-collapse');
    var $eqToggle = $('#eq-collapse-toggle');
    $eqCollapse.on('show.bs.collapse', function () { $eqToggle.text('— 折疊 —'); });
    $eqCollapse.on('hide.bs.collapse', function () { $eqToggle.text('— 展開 —'); });

    // 搜尋與重置
    $('#btn-eq-search').on('click', function () { renderEqTable(staffData); renderEqCards(staffData); });
    $('#btn-eq-reset').on('click', function () {
        $('#eq-search-account, #eq-search-name, #eq-search-eq, #eq-search-serial, #eq-search-duration-years, #eq-search-duration-months').val('');
        $('#eq-search-duration-op, #eq-search-status').val('');
        renderEqTable(staffData); renderEqCards(staffData);
    });
    // 切到設備 Tab 時刷新
    $('button[data-bs-target="#tab-equipment"]').on('shown.bs.tab', function () {
        renderEqTable(staffData); renderEqCards(staffData);
    });

    loadList();
});
</script>
@endsection
