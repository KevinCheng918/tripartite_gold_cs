@extends('layouts.app')

@section('title', trans('vm.page_title'))
@section('icon', 'hdd')
@section('subtitle', trans('vm.subtitle'))

@section('content')

    {{-- Tab 切換 --}}
    <ul class="nav nav-tabs mb-3">
        @if(Auth::user()->hasPermission('vm.view'))
        <li class="nav-item">
            <button class="nav-link {{ Auth::user()->hasPermission('vm.view') ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#tab-vm-servers">
                <i class="fas fa-server me-1"></i>{{ trans('vm.tab_servers') }}
            </button>
        </li>
        @endif
        @if(Auth::user()->hasPermission('vm.billing_view'))
        <li class="nav-item">
            <button class="nav-link {{ !Auth::user()->hasPermission('vm.view') ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#tab-vm-billing">
                <i class="fas fa-file-invoice-dollar me-1"></i>{{ trans('vm.tab_billing') }}
            </button>
        </li>
        @endif
    </ul>

    <div class="tab-content">
        {{-- 虛擬機列表 Tab --}}
        @if(Auth::user()->hasPermission('vm.view'))
        <div class="tab-pane fade {{ Auth::user()->hasPermission('vm.view') ? 'show active' : '' }}" id="tab-vm-servers">
            {{-- 搜尋區 --}}
            <div class="main-card mb-3 card">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <div class="d-flex gap-2">
                        @if(Auth::user()->hasPermission('vm.create'))
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-vm">
                                <i class="fas fa-plus me-1"></i>{{ trans('vm.action_create') }}
                            </button>
                        @endif
                    </div>
                    <a href="javascript:void(0)" class="text-muted text-decoration-none" data-bs-toggle="collapse" data-bs-target="#vm-search-collapse" aria-expanded="true">
                        — 折疊 —
                    </a>
                </div>
                <div class="collapse show" id="vm-search-collapse">
                    <div class="card-body pt-3">
                        <div class="row g-3 mb-3">
                            <div class="col-md-3 col-6">
                                <label class="form-label fw-bold">系統：</label>
                                <select id="vm-search-system" class="form-select">
                                    <option value="">全部</option>
                                    @foreach($systems as $sys)
                                        <option value="{{ $sys->id }}">{{ $sys->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3 col-6">
                                <label class="form-label fw-bold">{{ trans('vm.field_hostname') }}：</label>
                                <input type="text" class="form-control" id="vm-search-hostname" placeholder="{{ trans('vm.field_hostname') }}">
                            </div>
                            <div class="col-md-3 col-6">
                                <label class="form-label fw-bold">{{ trans('vm.field_internal_ip') }}：</label>
                                <input type="text" class="form-control" id="vm-search-internal-ip" placeholder="{{ trans('vm.field_internal_ip') }}">
                            </div>
                            <div class="col-md-3 col-6">
                                <label class="form-label fw-bold">{{ trans('vm.field_external_ip') }}：</label>
                                <input type="text" class="form-control" id="vm-search-external-ip" placeholder="{{ trans('vm.field_external_ip') }}">
                            </div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-3 col-6">
                                <label class="form-label fw-bold">{{ trans('vm.field_power') }}：</label>
                                <select id="vm-search-power" class="form-select">
                                    <option value="">全部</option>
                                    <option value="1">{{ trans('vm.power_on') }}</option>
                                    <option value="0">{{ trans('vm.power_off') }}</option>
                                </select>
                            </div>
                            <div class="col-md-3 col-6">
                                <label class="form-label fw-bold">{{ trans('vm.field_status') }}：</label>
                                <select id="vm-search-status" class="form-select">
                                    <option value="">全部</option>
                                    <option value="1">{{ trans('vm.status_active') }}</option>
                                    <option value="0">{{ trans('vm.status_disabled') }}</option>
                                </select>
                            </div>
                        </div>
                        <div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-outline-secondary" id="btn-vm-reset">重置</button>
                            <button type="button" class="btn btn-primary" id="btn-vm-search">
                                <i class="fas fa-search me-1"></i>搜尋
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div id="vm-stats" class="mb-3"></div>

            <div id="vm-server-list">
                <p class="text-muted text-center py-4">Loading...</p>
            </div>
        </div>

        @endif

        {{-- 帳務紀錄 Tab --}}
        @if(Auth::user()->hasPermission('vm.billing_view'))
        <div class="tab-pane fade {{ !Auth::user()->hasPermission('vm.view') ? 'show active' : '' }}" id="tab-vm-billing">
            <div class="main-card mb-3 card">
                <div class="card-body">
                    <div class="row g-3 align-items-end">
                        <div class="col-auto">
                            <label class="form-label fw-bold">{{ trans('vm.field_month') }}</label>
                            <input type="month" id="billing-month" class="form-control" value="{{ date('Y-m') }}">
                        </div>
                        <div class="col-auto">
                            <label class="form-label fw-bold">{{ trans('vm.field_paid') }}</label>
                            <select id="billing-filter" class="form-select">
                                <option value="">{{ trans('vm.filter_all') }}</option>
                                <option value="0">{{ trans('vm.filter_unpaid') }}</option>
                                <option value="2">{{ trans('vm.filter_pending') }}</option>
                                <option value="1">{{ trans('vm.filter_paid') }}</option>
                                <option value="overdue">{{ trans('vm.filter_overdue') }}</option>
                            </select>
                        </div>
                        <div class="col-auto">
                            <button class="btn btn-primary" id="btn-search-billing">
                                <i class="fas fa-search me-1"></i>搜尋
                            </button>
                        </div>
                        @if(Auth::user()->hasPermission('vm.billing_approve'))
                        <div class="col-auto">
                            <button class="btn btn-outline-secondary" id="btn-generate-billing">
                                <i class="fas fa-cog me-1"></i>{{ trans('vm.action_generate') }}
                            </button>
                        </div>
                        @endif
                    </div>
                </div>
            </div>

            <div id="vm-billing-list">
                <p class="text-muted text-center py-4">Loading...</p>
            </div>
        </div>
        @endif
    </div>

    {{-- 新增/編輯 VM Modal --}}
    <div class="modal fade" id="modal-vm" tabindex="-1">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ trans('vm.action_create') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="form-vm">
                        <input type="hidden" id="vm-id">
                        <div class="mb-3">
                            <label class="form-label">{{ trans('vm.field_station') }}</label>
                            <select id="vm-station" class="form-select" name="station_id" required>
                                <option value="">選擇站台</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('vm.field_hostname') }}</label>
                            <input id="vm-hostname" type="text" class="form-control" name="hostname" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('vm.field_internal_ip') }}</label>
                            <input id="vm-internal-ip" type="text" class="form-control" name="internal_ip" placeholder="192.168.x.x">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('vm.field_external_ip') }}</label>
                            <input id="vm-external-ip" type="text" class="form-control" name="external_ip" placeholder="xxx.xxx.xxx.xxx">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('vm.field_model_type') }}</label>
                            <input id="vm-model-type" type="text" class="form-control" name="model_type" placeholder="例：AWS t3.medium">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('vm.field_spec') }}</label>
                            <input id="vm-spec" type="text" class="form-control" name="spec" required placeholder="例：2C4G 50GB">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('vm.field_monthly_fee') }}</label>
                            <input id="vm-fee" type="number" class="form-control js-fee-input" name="monthly_fee" required step="0.01" min="0">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('vm.field_vpn_fee') }}</label>
                            <input id="vm-vpn-fee" type="number" class="form-control js-fee-input" name="vpn_fee" step="0.01" min="0" value="0">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('vm.field_google_fee') }}</label>
                            <input id="vm-google-fee" type="number" class="form-control js-fee-input" name="google_fee" step="0.01" min="0" value="0">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">{{ trans('vm.field_total_fee') }}</label>
                            <div id="vm-total-fee" class="form-control-plaintext fw-bold" style="font-size:1.125rem">0.00</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('vm.field_billing_day') }}</label>
                            <input id="vm-billing-day" type="number" class="form-control" name="billing_day" required min="1" max="28" value="1">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('vm.field_note') }}</label>
                            <input id="vm-note" type="text" class="form-control" name="note">
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

    {{-- 上傳繳款證明 Modal --}}
    <div class="modal fade" id="modal-upload-proof" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ trans('vm.action_upload_proof') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="form-upload-proof" enctype="multipart/form-data">
                        <input type="hidden" id="upload-billing-id">
                        <div class="mb-3">
                            <label class="form-label">選擇圖片</label>
                            <input id="proof-file" type="file" class="form-control" accept="image/*" required>
                        </div>
                        <div id="proof-preview" class="mb-3" style="display:none">
                            <img id="proof-preview-img" style="max-width:100%;border-radius:0.375rem" alt="preview">
                        </div>
                        <div class="text-end">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                            <button type="submit" class="btn btn-primary">上傳</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- 查看繳款證明 Modal --}}
    <div class="modal fade" id="modal-view-proof" tabindex="-1">
        <div class="modal-dialog modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ trans('vm.action_view_proof') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <img id="view-proof-img" style="max-width:100%;border-radius:0.375rem" alt="proof">
                </div>
            </div>
        </div>
    </div>

    {{-- 二次確認 Modal --}}
    <div class="modal fade" id="modal-vm-confirm" tabindex="-1">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-vm-confirm-title"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="modal-vm-confirm-body"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary" id="btn-vm-confirm-ok">確認</button>
                </div>
            </div>
        </div>
    </div>

    {{-- 訊息 Modal --}}
    <div class="modal fade" id="modal-vm-msg" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-body text-center py-4">
                    <p id="modal-vm-msg-text" class="mb-3"></p>
                    <div class="text-end">
                        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

@endsection

@section('scripts')
<script>
$(function () {
    var csrfToken = $('meta[name="csrf-token"]').attr('content');
    var canUpdate = {{ Auth::user()->hasPermission('vm.update') ? 'true' : 'false' }};
    var canUpload = {{ Auth::user()->hasPermission('vm.billing_upload') ? 'true' : 'false' }};
    var canApprove = {{ Auth::user()->hasPermission('vm.billing_approve') ? 'true' : 'false' }};
    var isAdmin = {{ Auth::user()->isAdmin() ? 'true' : 'false' }};

    function showMessage(msg) {
        $('#modal-vm-msg-text').text(msg);
        var hasBackdrop = document.querySelectorAll('.modal-backdrop').length > 0;
        if (hasBackdrop) {
            setTimeout(function () { showBsModal('modal-vm-msg'); }, 400);
        } else {
            showBsModal('modal-vm-msg');
        }
    }

    // ---------------------------------------------------------------
    //  VM 列表
    // ---------------------------------------------------------------

    function loadServers() {
        var params = 'per_page=100';
        var systemId = $('#vm-search-system').val();
        var hostname = $('#vm-search-hostname').val();
        var internalIp = $('#vm-search-internal-ip').val();
        var externalIp = $('#vm-search-external-ip').val();
        var power = $('#vm-search-power').val();
        var status = $('#vm-search-status').val();
        if (systemId) { params += '&system_id=' + systemId; }
        if (hostname) { params += '&hostname=' + encodeURIComponent(hostname); }
        if (internalIp) { params += '&internal_ip=' + encodeURIComponent(internalIp); }
        if (externalIp) { params += '&external_ip=' + encodeURIComponent(externalIp); }
        if (power !== '') { params += '&power_status=' + power; }
        if (status !== '') { params += '&status=' + status; }

        $.ajax({
            url: '/admin/vm/ajax-list?' + params,
            headers: { 'X-CSRF-TOKEN': csrfToken },
            success: function (body) { renderServers(body.data || []); }
        });
    }

    function renderServers(servers) {
        if (servers.length === 0) {
            $('#vm-stats').html('');
            $('#vm-server-list').html('<div class="text-center text-muted py-4">暫無資料</div>');
            return;
        }

        // 統計
        var cardColors = ['#6f42c1', '#0d9488', '#2563eb', '#e85d04', '#d63384', '#0ea5e9'];
        var systemStats = {};
        var totalOn = 0, totalOff = 0, totalAmount = 0;
        servers.forEach(function (vm) {
            var sysName = vm.station && vm.station.system ? vm.station.system : '未分類';
            if (!systemStats[sysName]) { systemStats[sysName] = { on: 0, off: 0, amount: 0 }; }
            if (vm.power_status === 1) { systemStats[sysName].on++; totalOn++; }
            else { systemStats[sysName].off++; totalOff++; }
            systemStats[sysName].amount += parseFloat(vm.total_fee || 0);
            totalAmount += parseFloat(vm.total_fee || 0);
        });

        var statsHtml = '<div class="main-card mb-3 card">' +
            '<div class="card-header py-2"><strong><i class="fas fa-chart-bar me-2 text-muted"></i>虛擬機總覽</strong>' +
            '<small class="text-muted ms-2">即時掌握各系統運作狀態</small></div>' +
            '<div class="card-body py-3"><div class="row g-3">';

        var colorIdx = 0;
        Object.keys(systemStats).forEach(function (name) {
            var s = systemStats[name];
            var color = cardColors[colorIdx % cardColors.length];
            var initials = name.substring(0, 2).toUpperCase();
            colorIdx++;
            statsHtml +=
                '<div class="col">' +
                '<div class="border rounded-3 p-3 d-flex align-items-center gap-3" style="border-left:4px solid ' + color + ' !important">' +
                '<div class="rounded-circle d-flex align-items-center justify-content-center fw-bold text-white" ' +
                'style="width:40px;height:40px;min-width:40px;background:' + color + ';font-size:0.8125rem">' + initials + '</div>' +
                '<div class="flex-fill">' +
                '<div class="fw-bold">' + name + '</div>' +
                '<div class="d-flex gap-3 mt-1">' +
                '<span class="badge bg-success">▶ 開機 ' + s.on + '</span>' +
                '<span class="badge bg-danger">■ 關機 ' + s.off + '</span>' +
                '</div></div>' +
                '<div class="text-end">' +
                '<div class="fw-bold" style="font-size:1.25rem;color:' + color + '">' + s.amount.toFixed(2) + '</div>' +
                '<small class="text-muted">總費用</small>' +
                '</div></div></div>';
        });

        // 總計
        statsHtml +=
            '<div class="col">' +
            '<div class="border rounded-3 p-3 d-flex align-items-center gap-3" style="border-left:4px solid #0d9488 !important;background:#f8f9fa">' +
            '<div class="rounded-circle d-flex align-items-center justify-content-center fw-bold" ' +
            'style="width:40px;height:40px;min-width:40px;background:#e9ecef;color:#495057;font-size:0.875rem"><i class="fas fa-server"></i></div>' +
            '<div class="flex-fill">' +
            '<div class="fw-bold">總計 ' + (totalOn + totalOff) + ' 台</div>' +
            '<div class="d-flex gap-3 mt-1">' +
            '<span class="badge bg-success">▶ 開機 ' + totalOn + '</span>' +
            '<span class="badge bg-danger">■ 關機 ' + totalOff + '</span>' +
            '</div></div>' +
            '<div class="text-end">' +
            '<div class="fw-bold" style="font-size:1.25rem;color:#0d9488">' + totalAmount.toFixed(2) + '</div>' +
            '<small class="text-muted">總費用</small>' +
            '</div></div></div>';

        statsHtml += '</div></div></div>';
        $('#vm-stats').html(statsHtml);

        // 桌面版表格
        var tableHtml =
            '<div class="main-card mb-3 card d-none d-md-block"><div class="card-body p-0"><div class="table-responsive">' +
            '<table class="table table-hover table-striped align-middle mb-0"><thead class="table-light"><tr>' +
            '<th>#</th>' +
            '<th>系統</th>' +
            '<th>' + '{{ trans("vm.field_station") }}' + '</th>' +
            '<th>' + '{{ trans("vm.field_hostname") }}' + '</th>' +
            '<th>' + '{{ trans("vm.field_model_type") }}' + '</th>' +
            '<th>' + '{{ trans("vm.field_spec") }}' + '</th>' +
            '<th>' + '{{ trans("vm.field_internal_ip") }}' + '</th>' +
            '<th>' + '{{ trans("vm.field_external_ip") }}' + '</th>' +
            '<th>' + '{{ trans("vm.field_total_fee") }}' + '</th>' +
            '<th>' + '{{ trans("vm.field_power") }}' + '</th>' +
            '<th>' + '{{ trans("vm.field_status") }}' + '</th>' +
            '<th>操作</th>' +
            '</tr></thead><tbody>';

        // 手機版卡片
        var cardsHtml = '<div class="d-md-none">';

        servers.forEach(function (vm, idx) {
            var stationName = vm.station ? vm.station.name : '-';
            var systemName = vm.station && vm.station.system ? vm.station.system : '-';
            var powerBadge = vm.power_status === 1
                ? '<span class="badge bg-success">{{ trans("vm.power_on") }}</span>'
                : '<span class="badge bg-danger">{{ trans("vm.power_off") }}</span>';
            var statusBadge = vm.status === 1
                ? '<span class="badge bg-success">{{ trans("vm.status_active") }}</span>'
                : '<span class="badge bg-danger">{{ trans("vm.status_disabled") }}</span>';
            var actions = '';
            if (canUpdate) {
                actions =
                    '<button class="btn btn-sm btn-outline-secondary js-edit-vm" ' +
                    'data-id="' + vm.id + '" data-station-id="' + (vm.station_id || '') + '" ' +
                    'data-hostname="' + vm.hostname + '" data-spec="' + vm.spec + '" ' +
                    'data-internal-ip="' + (vm.internal_ip || '') + '" data-external-ip="' + (vm.external_ip || '') + '" ' +
                    'data-model-type="' + (vm.model_type || '') + '" data-fee="' + vm.monthly_fee + '" ' +
                    'data-vpn-fee="' + (vm.vpn_fee || 0) + '" data-google-fee="' + (vm.google_fee || 0) + '" ' +
                    'data-billing-day="' + vm.billing_day + '" data-note="' + (vm.note || '') + '" ' +
                    'data-status="' + vm.status + '">' +
                    '<i class="fas fa-edit me-1"></i>{{ trans("vm.action_edit") }}</button> ' +
                    '<button class="btn btn-sm btn-outline-secondary js-toggle-power" data-id="' + vm.id + '">' +
                    '<i class="fas fa-power-off me-1"></i>' + (vm.power_status === 1 ? '關機' : '開機') + '</button>';
            }

            tableHtml +=
                '<tr>' +
                '<td>' + (idx + 1) + '</td>' +
                '<td>' + systemName + '</td>' +
                '<td>' + stationName + '</td>' +
                '<td><strong>' + vm.hostname + '</strong></td>' +
                '<td>' + (vm.model_type || '-') + '</td>' +
                '<td>' + vm.spec + '</td>' +
                '<td>' + (vm.internal_ip || '-') + '</td>' +
                '<td>' + (vm.external_ip || '-') + '</td>' +
                '<td><strong>' + vm.total_fee + '</strong></td>' +
                '<td>' + powerBadge + '</td>' +
                '<td>' + statusBadge + '</td>' +
                '<td>' + actions + '</td>' +
                '</tr>';

            cardsHtml +=
                '<div class="card mb-2 shadow-sm"><div class="card-body py-3">' +
                '<div class="d-flex justify-content-between align-items-start mb-2">' +
                '<div><strong style="font-size:1.0625rem">' + vm.hostname + '</strong>' +
                '<div class="text-muted" style="font-size:0.8125rem">' + systemName + ' / ' + stationName + '</div></div>' +
                '<div class="d-flex gap-1">' + powerBadge + statusBadge + '</div></div>' +
                '<div class="d-flex justify-content-between mb-1" style="font-size:0.875rem"><span class="text-muted">機型</span><span>' + (vm.model_type || '-') + '</span></div>' +
                '<div class="d-flex justify-content-between mb-1" style="font-size:0.875rem"><span class="text-muted">規格</span><span>' + vm.spec + '</span></div>' +
                '<div class="d-flex justify-content-between mb-1" style="font-size:0.875rem"><span class="text-muted">內網 IP</span><span>' + (vm.internal_ip || '-') + '</span></div>' +
                '<div class="d-flex justify-content-between mb-1" style="font-size:0.875rem"><span class="text-muted">外網 IP</span><span>' + (vm.external_ip || '-') + '</span></div>' +
                '<div class="d-flex justify-content-between mb-1" style="font-size:0.875rem"><span class="text-muted">總金額</span><strong>' + vm.total_fee + '</strong></div>' +
                '<div class="d-flex justify-content-between mb-2" style="font-size:0.875rem"><span class="text-muted">帳單日</span><span>每月 ' + vm.billing_day + ' 日</span></div>' +
                '<div class="d-flex gap-1 flex-wrap">' + actions + '</div>' +
                '</div></div>';
        });

        tableHtml += '</tbody></table></div></div></div>';
        cardsHtml += '</div>';

        $('#vm-server-list').html(tableHtml + cardsHtml);
        bindServerEvents();
    }

    function bindServerEvents() {
        $('.js-edit-vm').off('click').on('click', function () {
            var $btn = $(this);
            var stationId = $btn.data('station-id');
            loadStations(function () {
                $('#vm-id').val($btn.data('id'));
                $('#vm-station').val(stationId);
                $('#vm-hostname').val($btn.data('hostname'));
                $('#vm-internal-ip').val($btn.data('internal-ip'));
                $('#vm-external-ip').val($btn.data('external-ip'));
                $('#vm-model-type').val($btn.data('model-type'));
                $('#vm-spec').val($btn.data('spec'));
                $('#vm-fee').val($btn.data('fee'));
                $('#vm-vpn-fee').val($btn.data('vpn-fee') || 0);
                $('#vm-google-fee').val($btn.data('google-fee') || 0);
                updateTotalFee();
                $('#vm-billing-day').val($btn.data('billing-day'));
                $('#vm-note').val($btn.data('note'));
                $('#modal-vm .modal-title').text('{{ trans("vm.action_edit") }}');
                showBsModal('modal-vm');
            });
        });

        $('.js-toggle-power').off('click').on('click', function () {
            var $btn = $(this);
            $btn.prop('disabled', true);
            $.ajax({
                url: '/admin/vm/ajax-toggle-power/' + $btn.data('id'),
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken },
                contentType: 'application/json',
                success: function () { loadServers(); },
                error: function (xhr) {
                    $btn.prop('disabled', false);
                    showMessage((xhr.responseJSON && xhr.responseJSON.message) || '操作失敗');
                }
            });
        });
    }

    // 載入站台選單
    function loadStations(callback) {
        $.ajax({
            url: '/admin/stations/ajax-list?per_page=200',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            success: function (body) {
                var list = body.data || [];
                var html = '<option value="">選擇站台</option>';
                list.forEach(function (s) {
                    html += '<option value="' + s.id + '">' + s.name + '</option>';
                });
                $('#vm-station').html(html);
                if (callback) { callback(); }
            }
        });
    }

    // 新增 Modal 打開時清空
    $('[data-bs-target="#modal-vm"]').on('click', function () {
        $('#vm-id').val('');
        $('#form-vm')[0].reset();
        $('#modal-vm .modal-title').text('{{ trans("vm.action_create") }}');
        loadStations();
    });

    // 新增/編輯提交
    $('#form-vm').on('submit', function (e) {
        e.preventDefault();
        var id = $('#vm-id').val();
        var url = id ? '/admin/vm/ajax-update/' + id : '/admin/vm/ajax-store';
        var method = id ? 'PUT' : 'POST';

        $.ajax({
            url: url, method: method,
            headers: { 'X-CSRF-TOKEN': csrfToken },
            contentType: 'application/json',
            data: JSON.stringify({
                station_id: parseInt($('#vm-station').val(), 10),
                hostname: $('#vm-hostname').val(),
                internal_ip: $('#vm-internal-ip').val(),
                external_ip: $('#vm-external-ip').val(),
                model_type: $('#vm-model-type').val(),
                spec: $('#vm-spec').val(),
                monthly_fee: parseFloat($('#vm-fee').val()),
                vpn_fee: parseFloat($('#vm-vpn-fee').val()) || 0,
                google_fee: parseFloat($('#vm-google-fee').val()) || 0,
                billing_day: parseInt($('#vm-billing-day').val(), 10),
                note: $('#vm-note').val(),
            }),
            success: function () {
                hideBsModal(document.getElementById('modal-vm'));
                loadServers();
            },
            error: function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.message) || '操作失敗';
                showMessage(msg);
            }
        });
    });

    // ---------------------------------------------------------------
    //  帳務紀錄
    // ---------------------------------------------------------------

    function loadBillings() {
        var month = $('#billing-month').val();
        var filter = $('#billing-filter').val();
        var params = 'per_page=100';
        if (month) { params += '&billing_month=' + month; }
        if (filter === 'overdue') {
            params += '&overdue=true';
        } else if (filter !== '') {
            params += '&paid=' + filter;
        }

        $.ajax({
            url: '/admin/vm/ajax-billing?' + params,
            headers: { 'X-CSRF-TOKEN': csrfToken },
            success: function (body) { renderBillings(body.data || []); }
        });
    }

    function renderBillings(billings) {
        if (billings.length === 0) {
            $('#vm-billing-list').html('<div class="text-center text-muted py-4">暫無資料</div>');
            return;
        }

        var tableHtml =
            '<div class="main-card mb-3 card d-none d-md-block"><div class="card-body p-0"><div class="table-responsive">' +
            '<table class="table table-hover table-striped align-middle mb-0"><thead class="table-light"><tr>' +
            '<th>#</th>' +
            '<th>{{ trans("vm.field_station") }}</th>' +
            '<th>{{ trans("vm.field_month") }}</th>' +
            '<th>{{ trans("vm.field_amount") }}</th>' +
            '<th>{{ trans("vm.field_due_date") }}</th>' +
            '<th>{{ trans("vm.field_paid") }}</th>' +
            '<th>{{ trans("vm.field_overdue_days") }}</th>' +
            '<th>操作</th>' +
            '</tr></thead><tbody>';

        var cardsHtml = '<div class="d-md-none">';

        billings.forEach(function (b, idx) {
            var vmLabel = b.vm_server ? b.vm_server.hostname : '-';
            var stationName = b.vm_server && b.vm_server.station ? b.vm_server.station.name : '-';
            var paidBadge = '';
            if (b.paid === 1) {
                paidBadge = '<span class="badge bg-success">{{ trans("vm.paid_yes") }}</span>';
            } else if (b.paid === 2) {
                paidBadge = '<span class="badge bg-primary">{{ trans("vm.paid_pending") }}</span>';
            } else {
                paidBadge = '<span class="badge bg-warning text-dark">{{ trans("vm.paid_no") }}</span>';
            }
            var overdueText = '';
            if (b.paid === 0 && b.overdue_days > 0) {
                overdueText = '<span class="text-danger fw-bold">' + b.overdue_days + ' 天</span>';
                paidBadge = '<span class="badge bg-danger">{{ trans("vm.overdue") }}</span>';
            } else {
                overdueText = '-';
            }
            var systemId = b.vm_server && b.vm_server.station ? b.vm_server.station.system_id : '';
            var hasTelegram = b.vm_server && b.vm_server.station && b.vm_server.station.telegram_group_id;

            var actions = '';
            // 複製文案 + 發送（所有狀態皆可）
            if (systemId) {
                actions += '<button class="btn btn-sm btn-outline-secondary js-copy-billing"' +
                    ' data-system-id="' + systemId + '"' +
                    ' data-station="' + stationName + '"' +
                    ' data-amount="' + b.amount + '"' +
                    ' data-month="' + b.billing_month + '"' +
                    ' data-due-date="' + (b.due_date || '') + '">' +
                    '<i class="fas fa-copy me-1"></i>{{ trans("payment_config.action_copy") }}</button> ';
                if (hasTelegram) {
                    actions += '<button class="btn btn-sm btn-outline-secondary js-send-billing"' +
                        ' data-system-id="' + systemId + '"' +
                        ' data-station="' + stationName + '"' +
                        ' data-group-id="' + b.vm_server.station.telegram_group_id + '"' +
                        ' data-amount="' + b.amount + '"' +
                        ' data-month="' + b.billing_month + '"' +
                        ' data-due-date="' + (b.due_date || '') + '">' +
                        '<i class="fas fa-paper-plane me-1"></i>{{ trans("payment_config.action_send") }}</button> ';
                }
            }
            // 未收款：有上傳權限可上傳證明，有審核權限可直接標記
            if (b.paid === 0) {
                if (canUpload) {
                    actions += '<button class="btn btn-sm btn-outline-secondary js-upload-proof" data-id="' + b.id + '">' +
                        '<i class="fas fa-upload me-1"></i>{{ trans("vm.action_upload_proof") }}</button> ';
                }
                if (canApprove) {
                    actions += '<button class="btn btn-sm btn-primary js-mark-paid" data-id="' + b.id + '"' +
                        ' data-station="' + stationName + '" data-vm="' + vmLabel + '"' +
                        ' data-month="' + b.billing_month + '" data-amount="' + b.amount + '"' +
                        ' data-proof="' + (b.proof_image || '') + '">' +
                        '<i class="fas fa-check me-1"></i>{{ trans("vm.action_mark_paid") }}</button>';
                }
            }
            // 待審核：可查看證明，有審核權限可審核
            if (b.paid === 2) {
                if (b.proof_image) {
                    actions += '<button class="btn btn-sm btn-outline-secondary js-view-proof" data-img="' + b.proof_image + '">' +
                        '<i class="fas fa-image me-1"></i>{{ trans("vm.action_view_proof") }}</button> ';
                }
                if (canApprove) {
                    actions += '<button class="btn btn-sm btn-primary js-approve-paid" data-id="' + b.id + '"' +
                        ' data-station="' + stationName + '" data-vm="' + vmLabel + '"' +
                        ' data-month="' + b.billing_month + '" data-amount="' + b.amount + '"' +
                        ' data-proof="' + (b.proof_image || '') + '">' +
                        '<i class="fas fa-check-double me-1"></i>{{ trans("vm.action_approve") }}</button>';
                }
            }

            tableHtml +=
                '<tr>' +
                '<td>' + (idx + 1) + '</td>' +
                '<td>' + stationName + '</td>' +
                '<td>' + b.billing_month + '</td>' +
                '<td>' + b.amount + '</td>' +
                '<td>' + (b.due_date || '-') + '</td>' +
                '<td>' + paidBadge + '</td>' +
                '<td>' + overdueText + '</td>' +
                '<td>' + actions + '</td>' +
                '</tr>';

            cardsHtml +=
                '<div class="card mb-2 shadow-sm"><div class="card-body py-3">' +
                '<div class="d-flex justify-content-between align-items-start mb-2">' +
                '<div><strong style="font-size:1.0625rem">' + stationName + '</strong></div>' +
                paidBadge + '</div>' +
                '<div class="d-flex justify-content-between mb-1" style="font-size:0.875rem"><span class="text-muted">月份</span><span>' + b.billing_month + '</span></div>' +
                '<div class="d-flex justify-content-between mb-1" style="font-size:0.875rem"><span class="text-muted">金額</span><strong>' + b.amount + '</strong></div>' +
                '<div class="d-flex justify-content-between mb-1" style="font-size:0.875rem"><span class="text-muted">應收日</span><span>' + (b.due_date || '-') + '</span></div>' +
                (b.paid === 0 && b.overdue_days > 0 ? '<div class="d-flex justify-content-between mb-2" style="font-size:0.875rem"><span class="text-muted">逾期</span><span class="text-danger fw-bold">' + b.overdue_days + ' 天</span></div>' : '') +
                '<div class="d-flex gap-1">' + actions + '</div>' +
                '</div></div>';
        });

        tableHtml += '</tbody></table></div></div></div>';
        cardsHtml += '</div>';

        $('#vm-billing-list').html(tableHtml + cardsHtml);
        bindBillingEvents();
    }

    // 二次確認
    var pendingVmAction = null;

    function buildConfirmHtml(title, detail) {
        var html = '<div class="text-center">' +
            '<p><strong>' + title + '</strong></p>' +
            '<table class="table table-sm mt-2 text-center"><tbody>' +
            '<tr><th style="width:80px">站台</th><td>' + (detail.station || '-') + '</td></tr>' +
            '<tr><th>月份</th><td>' + (detail.month || '-') + '</td></tr>' +
            '<tr><th>金額</th><td><strong>' + (detail.amount || '-') + '</strong></td></tr>' +
            '</tbody></table>';
        if (detail.proof) {
            html += '<div class="mt-2"><p class="text-muted mb-1">繳款證明：</p>' +
                '<img src="' + detail.proof + '" style="max-width:100%;border-radius:0.375rem" alt="proof"></div>';
        }
        html += '</div>';
        return html;
    }

    function showConfirm(title, detail, onConfirm) {
        $('#modal-vm-confirm-title').text('操作確認');
        $('#modal-vm-confirm-body').html(buildConfirmHtml(title, detail));
        pendingVmAction = onConfirm;
        showBsModal('modal-vm-confirm');
    }

    $('#btn-vm-confirm-ok').on('click', function () {
        hideBsModal(document.getElementById('modal-vm-confirm'));
        if (pendingVmAction) {
            setTimeout(function () {
                pendingVmAction();
                pendingVmAction = null;
            }, 350);
        }
    });

    function bindBillingEvents() {
        // 直接標記已收（二次確認）
        $('.js-mark-paid').off('click').on('click', function () {
            var $btn = $(this);
            var id = $btn.data('id');
            var detail = {
                station: $btn.data('station'),
                vm: $btn.data('vm'),
                month: $btn.data('month'),
                amount: $btn.data('amount'),
                proof: $btn.data('proof') || ''
            };
            showConfirm('確定要標記此帳單為已收款？', detail, function () {
                $.ajax({
                    url: '/admin/vm/ajax-mark-paid/' + id,
                    method: 'PUT',
                    headers: { 'X-CSRF-TOKEN': csrfToken },
                    contentType: 'application/json',
                    success: function () {
                        showMessage('{{ trans("vm.msg.marked_paid") }}');
                        loadBillings();
                    },
                    error: function (xhr) {
                        showMessage((xhr.responseJSON && xhr.responseJSON.message) || '操作失敗');
                    }
                });
            });
        });

        // 上傳繳款證明
        $('.js-upload-proof').off('click').on('click', function () {
            $('#upload-billing-id').val($(this).data('id'));
            $('#proof-file').val('');
            $('#proof-preview').hide();
            showBsModal('modal-upload-proof');
        });

        // 查看繳款證明
        $('.js-view-proof').off('click').on('click', function () {
            $('#view-proof-img').attr('src', $(this).data('img'));
            showBsModal('modal-view-proof');
        });

        // 審核通過（二次確認）
        $('.js-approve-paid').off('click').on('click', function () {
            var $btn = $(this);
            var id = $btn.data('id');
            var detail = {
                station: $btn.data('station'),
                vm: $btn.data('vm'),
                month: $btn.data('month'),
                amount: $btn.data('amount'),
                proof: $btn.data('proof') || ''
            };
            showConfirm('確定要審核通過此繳款？', detail, function () {
                $.ajax({
                    url: '/admin/vm/ajax-approve-paid/' + id,
                    method: 'PUT',
                    headers: { 'X-CSRF-TOKEN': csrfToken },
                    contentType: 'application/json',
                    success: function () {
                        showMessage('{{ trans("vm.msg.approved") }}');
                        loadBillings();
                    },
                    error: function (xhr) {
                        showMessage((xhr.responseJSON && xhr.responseJSON.message) || '操作失敗');
                    }
                });
            });
        });

        // 複製文案
        $('.js-copy-billing').off('click').on('click', function () {
            var $btn = $(this);
            var systemId = $btn.data('system-id');
            var station = $btn.data('station');
            var amount = $btn.data('amount');
            var month = $btn.data('month');
            var dueDate = $btn.data('due-date') || '';

            $.ajax({
                url: '/admin/payment-config/ajax-by-system?system_id=' + systemId,
                headers: { 'X-CSRF-TOKEN': csrfToken },
                success: function (configs) {
                    if (!configs || configs.length === 0) {
                        showMessage('{{ trans("payment_config.msg.no_config") }}');
                        return;
                    }
                    var config = configs[0];
                    var template = config.template || config.content;
                    var text = template
                        .replace(/\{station\}/g, station)
                        .replace(/\{amount\}/g, amount)
                        .replace(/\{month\}/g, month)
                        .replace(/\{due_date\}/g, dueDate)
                        .replace(/\{content\}/g, config.content);

                    if (navigator.clipboard) {
                        navigator.clipboard.writeText(text).then(function () {
                            showMessage('{{ trans("payment_config.msg.copied") }}');
                        });
                    } else {
                        var ta = document.createElement('textarea');
                        ta.value = text;
                        ta.style.position = 'fixed';
                        ta.style.opacity = '0';
                        document.body.appendChild(ta);
                        ta.select();
                        document.execCommand('copy');
                        document.body.removeChild(ta);
                        showMessage('{{ trans("payment_config.msg.copied") }}');
                    }
                }
            });
        });

        // 發送通知到 Telegram（含圖片）
        var sendingBilling = false;
        $('.js-send-billing').off('click').on('click', function () {
            if (sendingBilling) { return; }
            sendingBilling = true;
            var $btn = $(this);
            var originalHtml = $btn.html();
            $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i>發送中...');
            // 鎖定所有發送按鈕
            $('.js-send-billing').prop('disabled', true);

            $.ajax({
                url: '/admin/vm/ajax-send-payment-notice',
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken },
                contentType: 'application/json',
                data: JSON.stringify({
                    group_id: $btn.data('group-id'),
                    system_id: $btn.data('system-id'),
                    station: $btn.data('station'),
                    amount: String($btn.data('amount')),
                    month: $btn.data('month'),
                    due_date: $btn.data('due-date') || '',
                }),
                success: function () {
                    $btn.html('<i class="fas fa-check me-1"></i>已發送');
                    setTimeout(function () {
                        sendingBilling = false;
                        $btn.prop('disabled', false).html(originalHtml);
                        $('.js-send-billing').prop('disabled', false);
                    }, 2000);
                    showMessage('{{ trans("payment_config.msg.sent") }}');
                },
                error: function (xhr) {
                    sendingBilling = false;
                    $btn.prop('disabled', false).html(originalHtml);
                    $('.js-send-billing').prop('disabled', false);
                    showMessage((xhr.responseJSON && xhr.responseJSON.message) || '{{ trans("payment_config.msg.send_failed") }}');
                }
            });
        });
    }

    // 搜尋帳單
    $('#btn-search-billing').on('click', function () { loadBillings(); });

    // 產生帳單
    function doGenerateBilling(month, forceUpdate) {
        var $btn = $('#btn-generate-billing');
        $btn.prop('disabled', true);

        $.ajax({
            url: '/admin/vm/ajax-generate-billing',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            contentType: 'application/json',
            data: JSON.stringify({ month: month, force_update: forceUpdate || false }),
            success: function (body) {
                $btn.prop('disabled', false);

                // 有金額差異需要確認
                if (body.mismatches && body.mismatches.length > 0 && !forceUpdate) {
                    var html = '<div class="text-center"><p><strong>以下帳單金額與目前費用不同，是否更新？</strong></p></div>' +
                        '<table class="table table-sm mt-2"><thead><tr>' +
                        '<th>站台</th><th>主機</th><th>原金額</th><th>新金額</th>' +
                        '</tr></thead><tbody>';
                    body.mismatches.forEach(function (m) {
                        html += '<tr>' +
                            '<td>' + m.station + '</td>' +
                            '<td>' + m.hostname + '</td>' +
                            '<td class="text-danger">' + m.old_amount + '</td>' +
                            '<td class="text-success">' + m.new_amount + '</td>' +
                            '</tr>';
                    });
                    html += '</tbody></table>';

                    if (body.generated > 0) {
                        html += '<p class="text-muted mt-2">已新增 ' + body.generated + ' 筆帳單</p>';
                    }

                    $('#modal-vm-confirm-title').text('金額差異確認');
                    $('#modal-vm-confirm-body').html(html);
                    pendingVmAction = function () {
                        doGenerateBilling(month, true);
                    };
                    showBsModal('modal-vm-confirm');
                    return;
                }

                var msg = body.message || '完成';
                if (body.updated > 0) {
                    msg += '，已更新 ' + body.updated + ' 筆金額';
                }
                showMessage(msg);
                loadBillings();
            },
            error: function (xhr) {
                $btn.prop('disabled', false);
                showMessage((xhr.responseJSON && xhr.responseJSON.message) || '產生失敗');
            }
        });
    }

    $('#btn-generate-billing').on('click', function () {
        var month = $('#billing-month').val();
        doGenerateBilling(month, false);
    });

    // 上傳證明 — 圖片預覽
    $('#proof-file').on('change', function () {
        var file = this.files[0];
        if (file) {
            var reader = new FileReader();
            reader.onload = function (e) {
                $('#proof-preview-img').attr('src', e.target.result);
                $('#proof-preview').show();
            };
            reader.readAsDataURL(file);
        } else {
            $('#proof-preview').hide();
        }
    });

    // 上傳證明 — 提交
    $('#form-upload-proof').on('submit', function (e) {
        e.preventDefault();
        var billingId = $('#upload-billing-id').val();
        var fileInput = document.getElementById('proof-file');
        if (!fileInput.files[0]) { return; }

        var formData = new FormData();
        formData.append('proof', fileInput.files[0]);

        $.ajax({
            url: '/admin/vm/ajax-upload-proof/' + billingId,
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            data: formData,
            processData: false,
            contentType: false,
            success: function () {
                hideBsModal(document.getElementById('modal-upload-proof'));
                showMessage('{{ trans("vm.msg.proof_uploaded") }}');
                loadBillings();
            },
            error: function (xhr) {
                showMessage((xhr.responseJSON && xhr.responseJSON.message) || '上傳失敗');
            }
        });
    });

    // Tab 切換時載入資料
    $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function (e) {
        var target = $(e.target).data('bs-target');
        if (target === '#tab-vm-billing') { loadBillings(); }
    });

    // 搜尋 / 重置
    $('#btn-vm-search').on('click', function () { loadServers(); });
    $('#btn-vm-reset').on('click', function () {
        $('#vm-search-system').val('');
        $('#vm-search-hostname').val('');
        $('#vm-search-internal-ip').val('');
        $('#vm-search-external-ip').val('');
        $('#vm-search-power').val('');
        $('#vm-search-status').val('');
        loadServers();
    });

    // 折疊文字切換
    var $vmCollapse = $('#vm-search-collapse');
    var $vmToggle = $('[data-bs-target="#vm-search-collapse"]');
    $vmCollapse.on('show.bs.collapse', function () { $vmToggle.text('— 折疊 —'); });
    $vmCollapse.on('hide.bs.collapse', function () { $vmToggle.text('— 展開 —'); });

    // 即時計算總金額
    function updateTotalFee() {
        var fee = parseFloat($('#vm-fee').val()) || 0;
        var vpn = parseFloat($('#vm-vpn-fee').val()) || 0;
        var google = parseFloat($('#vm-google-fee').val()) || 0;
        $('#vm-total-fee').text((fee + vpn + google).toFixed(2));
    }
    $(document).on('input', '.js-fee-input', updateTotalFee);

    // 初始載入
    @if(Auth::user()->hasPermission('vm.view'))
    loadServers();
    @elseif(Auth::user()->hasPermission('vm.billing_view'))
    loadBillings();
    @endif
});
</script>
@endsection
