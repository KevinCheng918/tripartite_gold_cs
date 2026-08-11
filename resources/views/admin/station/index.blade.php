@extends('layouts.app')

@section('title', trans('station.page_title'))
@section('icon', 'server')
@section('subtitle', trans('station.subtitle'))

@section('content')

    <div class="main-card mb-3 card">
        {{-- 頂部：新增按鈕 + 折疊 --}}
        <div class="card-header d-flex align-items-center justify-content-between">
            <div>
                @if(Auth::user()->hasPermission('station.create'))
                    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modal-station">
                        <i class="fas fa-plus me-1"></i>{{ trans('station.action_create') }}
                    </button>
                @endif
            </div>
            <a href="javascript:void(0)" class="text-muted text-decoration-none" data-bs-toggle="collapse" data-bs-target="#station-search-collapse" aria-expanded="true">
                — 折疊 —
            </a>
        </div>

        {{-- 搜尋區（可折疊） --}}
        <div class="collapse show" id="station-search-collapse">
        <div class="card-body pt-3">
            <form method="GET">
                <div class="row g-3 mb-3">
                    <div class="col-md-3 col-6">
                        <label class="form-label fw-bold">{{ trans('station.field_name') }}：</label>
                        <input type="text" class="form-control" name="keyword" value="{{ $filters['keyword'] ?? '' }}" placeholder="{{ trans('station.field_name') }}">
                    </div>
                    <div class="col-md-3 col-6">
                        <label class="form-label fw-bold">域名：</label>
                        <input type="text" class="form-control" name="domain" value="{{ $filters['domain'] ?? '' }}" placeholder="域名">
                    </div>
                    <div class="col-md-3 col-6">
                        <label class="form-label fw-bold">系統：</label>
                        <select name="system_id" class="form-select">
                            <option value="">全部</option>
                            @foreach($systems as $sys)
                                <option value="{{ $sys->id }}" {{ ($filters['system_id'] ?? '') == $sys->id ? 'selected' : '' }}>{{ $sys->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3 col-6">
                        <label class="form-label fw-bold">{{ trans('station.field_status') }}：</label>
                        <select name="status" class="form-select">
                            <option value="">全部</option>
                            <option value="1" {{ ($filters['status'] ?? '') === '1' ? 'selected' : '' }}>{{ trans('station.status_active') }}</option>
                            <option value="2" {{ ($filters['status'] ?? '') === '2' ? 'selected' : '' }}>{{ trans('station.status_frozen') }}</option>
                            <option value="0" {{ ($filters['status'] ?? '') === '0' ? 'selected' : '' }}>{{ trans('station.status_disabled') }}</option>
                        </select>
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-3 col-6">
                        <label class="form-label fw-bold">點數 ≥：</label>
                        <input type="number" class="form-control" name="credits_min" value="{{ $filters['credits_min'] ?? '' }}" placeholder="最小值" step="0.01">
                    </div>
                    <div class="col-md-3 col-6">
                        <label class="form-label fw-bold">點數 ≤：</label>
                        <input type="number" class="form-control" name="credits_max" value="{{ $filters['credits_max'] ?? '' }}" placeholder="最大值" step="0.01">
                    </div>
                    <div class="col-md-3 col-6">
                        <label class="form-label fw-bold">商城：</label>
                        <select name="support_shop" class="form-select">
                            <option value="">全部</option>
                            <option value="true" {{ ($filters['support_shop'] ?? '') === 'true' ? 'selected' : '' }}>啟用</option>
                            <option value="false" {{ ($filters['support_shop'] ?? '') === 'false' ? 'selected' : '' }}>未啟用</option>
                        </select>
                    </div>
                    <div class="col-md-3 col-6">
                        <label class="form-label fw-bold">跑分員：</label>
                        <select name="score_runner" class="form-select">
                            <option value="">全部</option>
                            <option value="true" {{ ($filters['score_runner'] ?? '') === 'true' ? 'selected' : '' }}>啟用</option>
                            <option value="false" {{ ($filters['score_runner'] ?? '') === 'false' ? 'selected' : '' }}>未啟用</option>
                        </select>
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-3 col-6">
                        <label class="form-label fw-bold">每頁：</label>
                        <select name="per_page" class="form-select">
                            @foreach([15, 30, 50, 100] as $n)
                                <option value="{{ $n }}" {{ ($filters['per_page'] ?? 15) == $n ? 'selected' : '' }}>{{ $n }} 筆</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <a href="{{ route('admin.stations.index') }}" class="btn btn-outline-secondary">重置</a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search me-1"></i>搜尋
                    </button>
                </div>
            </form>
        </div>
        </div>{{-- end collapse --}}
    </div>

    {{-- 站台列表 --}}
    {{-- 桌面版：表格 --}}
    <div class="main-card mb-3 card d-none d-md-block">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>系統</th>
                            <th>{{ trans('station.field_name') }}</th>
                            <th>{{ trans('station.field_credits') }}</th>
                            <th>費率（收/付）</th>
                            <th>{{ trans('station.field_status') }}</th>
                            <th>同步</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($stations as $station)
                            @php
                                $settings = $station->settings ?? [];
                                $depositRate = isset($settings['system_rate']) ? number_format($settings['system_rate'] * 100, 2) . '%' : '-';
                                if (empty($settings['withdraw'])) {
                                    $withdrawRate = '未開啟';
                                } elseif (empty($settings['withdraw_withholding_system'])) {
                                    $withdrawRate = '不收費';
                                } elseif (isset($settings['system_rate_withdraw'])) {
                                    $withdrawRate = number_format($settings['system_rate_withdraw'] * 100, 2) . '%';
                                } else {
                                    $withdrawRate = '-';
                                }
                            @endphp
                            <tr>
                                <td>{{ $loop->iteration }}</td>
                                <td>{{ $station->system ? $station->system->name : '-' }}</td>
                                <td>
                                    <strong>{{ $station->name }}</strong>
                                    @if(filled($station->domain))
                                        <br><small class="text-muted">{{ $station->domain }}</small>
                                    @endif
                                </td>
                                <td><strong>{{ number_format($station->credits, 2) }}</strong></td>
                                <td>{{ $depositRate }} / {{ $withdrawRate }}</td>
                                <td>
                                    @if($station->status == 1)
                                        <span class="badge bg-success">{{ trans('station.status_active') }}</span>
                                    @elseif($station->status == 2)
                                        <span class="badge bg-warning text-dark">{{ trans('station.status_frozen') }}</span>
                                    @else
                                        <span class="badge bg-danger">{{ trans('station.status_disabled') }}</span>
                                    @endif
                                </td>
                                <td><small class="text-muted">{{ $station->synced_at ? $station->synced_at->format('m/d H:i') : '-' }}</small></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-secondary js-station-detail" data-id="{{ $station->id }}">
                                        <i class="fas fa-info-circle me-1"></i>詳細
                                    </button>
                                    @if(Auth::user()->hasPermission('station.update'))
                                        <button class="btn btn-sm btn-outline-secondary js-edit-station"
                                                data-id="{{ $station->id }}"
                                                data-name="{{ $station->name }}"
                                                data-domain="{{ $station->domain }}"
                                                data-system-id="{{ $station->system_id }}"
                                                data-api-url="{{ $station->api_url }}"
                                                data-api-key="{{ $station->api_key }}"
                                                data-telegram-chat-id="{{ $station->telegram_chat_id }}"
                                                data-note="{{ $station->note }}">
                                            <i class="fas fa-edit me-1"></i>編輯
                                        </button>
                                        <button class="btn btn-sm btn-outline-secondary js-sync-credits" data-id="{{ $station->id }}">
                                            <i class="fas fa-sync-alt me-1"></i>同步
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">暫無資料</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($stations->hasPages())
            <div class="card-footer">{{ $stations->withQueryString()->links() }}</div>
        @endif
    </div>

    {{-- 手機版：卡片 --}}
    <div class="d-md-none">
        @forelse($stations as $station)
            @php
                $settings = $station->settings ?? [];
                $depositRate = isset($settings['system_rate']) ? number_format($settings['system_rate'] * 100, 2) . '%' : '-';
                if (empty($settings['withdraw'])) {
                    $withdrawRate = '未開啟';
                } elseif (empty($settings['withdraw_withholding_system'])) {
                    $withdrawRate = '不收費';
                } elseif (isset($settings['system_rate_withdraw'])) {
                    $withdrawRate = number_format($settings['system_rate_withdraw'] * 100, 2) . '%';
                } else {
                    $withdrawRate = '-';
                }
            @endphp
            <div class="card mb-2 shadow-sm">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <strong style="font-size:1.0625rem">{{ $station->name }}</strong>
                            <div class="text-muted" style="font-size:0.8125rem">{{ $station->system ? $station->system->name : '-' }}</div>
                        </div>
                        @if($station->status == 1)
                            <span class="badge bg-success">{{ trans('station.status_active') }}</span>
                        @elseif($station->status == 2)
                            <span class="badge bg-warning text-dark">{{ trans('station.status_frozen') }}</span>
                        @else
                            <span class="badge bg-danger">{{ trans('station.status_disabled') }}</span>
                        @endif
                    </div>
                    @if(filled($station->domain))
                        <div class="d-flex justify-content-between mb-1" style="font-size:0.875rem">
                            <span class="text-muted">域名</span>
                            <span>{{ $station->domain }}</span>
                        </div>
                    @endif
                    <div class="d-flex justify-content-between mb-1" style="font-size:0.875rem">
                        <span class="text-muted">{{ trans('station.field_credits') }}</span>
                        <strong>{{ number_format($station->credits, 2) }}</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2" style="font-size:0.875rem">
                        <span class="text-muted">費率（收/付）</span>
                        <span>{{ $depositRate }} / {{ $withdrawRate }}</span>
                    </div>
                    <div class="d-flex gap-1 flex-wrap">
                        <button class="btn btn-sm btn-outline-secondary js-station-detail" data-id="{{ $station->id }}">
                            <i class="fas fa-info-circle me-1"></i>詳細
                        </button>
                        @if(Auth::user()->hasPermission('station.update'))
                            <button class="btn btn-sm btn-outline-secondary js-edit-station"
                                    data-id="{{ $station->id }}"
                                    data-name="{{ $station->name }}"
                                    data-domain="{{ $station->domain }}"
                                    data-system-id="{{ $station->system_id }}"
                                    data-api-url="{{ $station->api_url }}"
                                    data-api-key="{{ $station->api_key }}"
                                    data-telegram-chat-id="{{ $station->telegram_chat_id }}"
                                    data-note="{{ $station->note }}">
                                <i class="fas fa-edit me-1"></i>編輯
                            </button>
                            <button class="btn btn-sm btn-outline-secondary js-sync-credits" data-id="{{ $station->id }}">
                                <i class="fas fa-sync-alt me-1"></i>同步
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="text-center text-muted py-4">暫無資料</div>
        @endforelse
        @if($stations->hasPages())
            <div class="mt-2">{{ $stations->withQueryString()->links() }}</div>
        @endif
    </div>

    {{-- 新增/編輯站台 Modal --}}
    <div class="modal fade" id="modal-station" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ trans('station.action_create') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="form-station">
                        <input type="hidden" id="station-id">
                        <div class="mb-3">
                            <label class="form-label">{{ trans('station.field_system') }}</label>
                            <select id="station-system" name="system_id" class="form-select">
                                @foreach($systems as $sys)
                                    <option value="{{ $sys->id }}">{{ $sys->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('station.field_name') }}</label>
                            <input id="station-name" type="text" class="form-control" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('station.field_domain') }}</label>
                            <input id="station-domain" type="text" class="form-control" name="domain">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('station.field_api_url') }}</label>
                            <input id="station-api-url" type="text" class="form-control" name="api_url" placeholder="https://...">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('station.field_api_key') }}</label>
                            <input id="station-api-key" type="text" class="form-control" name="api_key">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('station.field_telegram_chat_id') }}</label>
                            <input id="station-telegram-chat-id" type="text" class="form-control" name="telegram_chat_id" placeholder="-100xxxxxxxxxx">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('station.field_note') }}</label>
                            <input id="station-note" type="text" class="form-control" name="note">
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

    {{-- 詳細資訊 Modal --}}
    <div class="modal fade" id="modal-station-detail" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">站台詳細資訊</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="station-detail-body"></div>
            </div>
        </div>
    </div>

    {{-- 訊息 Modal --}}
    <div class="modal fade" id="modal-station-msg" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-body text-center py-4">
                    <p id="modal-station-msg-text" class="mb-3"></p>
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

    // 折疊/展開文字切換
    var $collapseEl = $('#station-search-collapse');
    var $toggleLink = $('[data-bs-target="#station-search-collapse"]');
    $collapseEl.on('show.bs.collapse', function () { $toggleLink.text('— 折疊 —'); });
    $collapseEl.on('hide.bs.collapse', function () { $toggleLink.text('— 展開 —'); });

    function showMessage(msg) {
        $('#modal-station-msg-text').text(msg);
        showBsModal('modal-station-msg');
    }

    // 編輯站台
    $('.js-edit-station').on('click', function () {
        var $btn = $(this);
        $('#station-id').val($btn.data('id'));
        $('#station-name').val($btn.data('name'));
        $('#station-domain').val($btn.data('domain'));
        $('#station-system').val($btn.data('system-id'));
        $('#station-api-url').val($btn.data('api-url'));
        $('#station-api-key').val($btn.data('api-key'));
        $('#station-telegram-chat-id').val($btn.data('telegram-chat-id'));
        $('#station-note').val($btn.data('note'));
        $('#modal-station .modal-title').text('{{ trans("station.action_edit") }}');
        showBsModal('modal-station');
    });

    // 新增/編輯提交
    $('#form-station').on('submit', function (e) {
        e.preventDefault();
        var id = $('#station-id').val();
        var url = id ? '/admin/stations/ajax-update/' + id : '/admin/stations/ajax-store';
        var method = id ? 'PUT' : 'POST';

        $.ajax({
            url: url, method: method,
            headers: { 'X-CSRF-TOKEN': csrfToken },
            contentType: 'application/json',
            data: JSON.stringify({
                system_id: $('#station-system').val(),
                name: $('#station-name').val(),
                domain: $('#station-domain').val(),
                api_url: $('#station-api-url').val(),
                api_key: $('#station-api-key').val(),
                telegram_chat_id: $('#station-telegram-chat-id').val(),
                note: $('#station-note').val(),
            }),
            success: function () { location.reload(); },
            error: function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.message) || '操作失敗';
                showMessage(msg);
            }
        });
    });

    // 新增 Modal 打開時清空
    $('[data-bs-target="#modal-station"]').on('click', function () {
        $('#station-id').val('');
        $('#form-station')[0].reset();
        $('#modal-station .modal-title').text('{{ trans("station.action_create") }}');
    });

    // 同步點數
    $('.js-sync-credits').on('click', function () {
        var id = $(this).data('id');
        var $btn = $(this);
        $btn.prop('disabled', true);

        $.ajax({
            url: '/admin/stations/ajax-sync-credits/' + id,
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            contentType: 'application/json',
            success: function () { location.reload(); },
            error: function (xhr) {
                $btn.prop('disabled', false);
                showMessage((xhr.responseJSON && xhr.responseJSON.message) || '同步失敗');
            }
        });
    });

    // 詳細資訊
    $('.js-station-detail').on('click', function () {
        var id = $(this).data('id');
        $('#station-detail-body').html('<p class="text-center py-3">Loading...</p>');
        showBsModal('modal-station-detail');

        $.ajax({
            url: '/admin/stations/ajax-list?per_page=100',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            success: function (body) {
                var list = body.data || [];
                var station = list.filter(function (s) { return s.id === id; })[0];
                if (!station) { $('#station-detail-body').html('<p>找不到</p>'); return; }

                var s = station.settings || {};
                var on = '<span class="badge bg-success">啟用</span>';
                var off = '<span class="badge bg-danger">未啟用</span>';

                var html = '<table class="table table-sm">';
                html += '<tr><th>名稱</th><td>' + station.name + '</td></tr>';
                html += '<tr><th>域名</th><td>' + (station.domain || '-') + '</td></tr>';
                html += '<tr><th>系統</th><td>' + (station.system ? station.system.name : '-') + '</td></tr>';
                html += '<tr><th>點數</th><td><strong>' + station.credits + '</strong></td></tr>';
                html += '<tr><th>代收費率</th><td>' + (s.system_rate ? (s.system_rate * 100).toFixed(2) + '%' : '-') + '</td></tr>';
                var withdrawRateText = '-';
                if (!s.withdraw) {
                    withdrawRateText = '未開啟';
                } else if (!s.withdraw_withholding_system) {
                    withdrawRateText = '不收費';
                } else if (s.system_rate_withdraw) {
                    withdrawRateText = (s.system_rate_withdraw * 100).toFixed(2) + '%';
                }
                html += '<tr><th>代付費率</th><td>' + withdrawRateText + '</td></tr>';
                html += '<tr><th>同系統轉單代收費率</th><td>' + (s.self_system_rate ? (s.self_system_rate * 100).toFixed(2) + '%' : '0%') + '</td></tr>';
                html += '<tr><th>同系統轉單代付費率</th><td>' + (s.self_system_rate_withdraw ? (s.self_system_rate_withdraw * 100).toFixed(2) + '%' : '0%') + '</td></tr>';
                html += '<tr><th>USDT 代收</th><td>' + (s.usdt_deposit ? on : off) + '</td></tr>';
                html += '<tr><th>ATM 代收</th><td>' + (s.atm_deposit ? on : off) + '</td></tr>';
                html += '<tr><th>超商代收</th><td>' + (s.cvs_deposit ? on : off) + '</td></tr>';
                html += '<tr><th>信用卡</th><td>' + (s.cc_deposit ? on : off) + '</td></tr>';
                html += '<tr><th>QR 代收</th><td>' + (s.qr_deposit ? on : off) + '</td></tr>';
                html += '<tr><th>代付</th><td>' + (s.withdraw ? on : off) + '</td></tr>';
                html += '<tr><th>商城</th><td>' + (s.support_shop ? on : off) + '</td></tr>';
                html += '<tr><th>跑分員</th><td>' + (s.score_runner ? on : off) + '</td></tr>';
                html += '</table>';

                $('#station-detail-body').html(html);
            }
        });
    });
});
</script>
@endsection
