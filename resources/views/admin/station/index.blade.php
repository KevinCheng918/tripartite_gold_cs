@extends('layouts.app')

@section('title', trans('station.page_title'))
@section('icon', 'server')
@section('subtitle', trans('station.subtitle'))

@section('content')

    <style>
        .searchable-dropdown .dropdown-item:hover { background: #f0f0f0; cursor: pointer; }
        [data-theme="dark"] .searchable-dropdown { background: #2d2d2d !important; border-color: #444 !important; }
        [data-theme="dark"] .searchable-dropdown .dropdown-item { color: #e0e0e0; }
        [data-theme="dark"] .searchable-dropdown .dropdown-item:hover { background: #3a3a3a; }
        /* 加點/扣點按鈕 dark mode */
        [data-theme="dark"] .btn-outline-success { color: #66bb6a; border-color: #4a8a4e; }
        [data-theme="dark"] .btn-outline-success:hover { background: rgba(102,187,106,0.15); }
        [data-theme="dark"] .btn-check:checked + .btn-outline-success { background: #2e7d32; color: #fff; border-color: #2e7d32; box-shadow: 0 0 0 3px rgba(46,125,50,0.4); }
        [data-theme="dark"] .btn-outline-danger { color: #ef5350; border-color: #8b3a3a; }
        [data-theme="dark"] .btn-outline-danger:hover { background: rgba(239,83,80,0.15); }
        [data-theme="dark"] .btn-check:checked + .btn-outline-danger { background: #c62828; color: #fff; border-color: #c62828; box-shadow: 0 0 0 3px rgba(198,40,40,0.4); }
    </style>

    @php
        $canStationView = Auth::user()->hasPermission('station.view');
        $canTopupView   = Auth::user()->hasPermission('station.topup_view');
        $firstTab       = $canStationView ? 'list' : ($canTopupView ? 'topup' : '');
    @endphp

    {{-- Tab 切換 --}}
    <ul class="nav nav-tabs mb-3">
        @if($canStationView)
        <li class="nav-item">
            <button class="nav-link {{ $firstTab === 'list' ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#tab-station-list">
                <i class="fas fa-server me-1"></i>站台列表
            </button>
        </li>
        @endif
        @if($canTopupView)
        <li class="nav-item">
            <button class="nav-link {{ $firstTab === 'topup' ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#tab-topup">
                <i class="fas fa-coins me-1"></i>{{ trans('station.tab_topup') }}
            </button>
        </li>
        @endif
    </ul>

    <div class="tab-content">
    {{-- 站台列表 Tab --}}
    @if($canStationView)
    <div class="tab-pane fade {{ $firstTab === 'list' ? 'show active' : '' }}" id="tab-station-list">

    <div class="main-card mb-3 card">
        {{-- 頂部：新增按鈕 + 折疊 --}}
        <div class="card-header d-flex align-items-center justify-content-between">
            <div class="d-flex gap-2">
                @if(Auth::user()->hasPermission('station.create'))
                    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modal-station">
                        <i class="fas fa-plus me-1"></i>{{ trans('station.action_create') }}
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="btn-open-system-mgmt">
                        <i class="fas fa-cogs me-1"></i>系統管理
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
    {{-- 系統狀態總覽 --}}
    <div class="main-card mb-3 card">
        <div class="card-header py-2">
            <strong><i class="fas fa-chart-bar me-2 text-muted"></i>系統狀態總覽</strong>
            <small class="text-muted ms-2">即時掌握各系統運作狀態</small>
        </div>
        <div class="card-body py-3">
            <div class="row g-3">
                @php
                    $cardColors = ['#6f42c1', '#0d9488', '#2563eb', '#e85d04', '#d63384', '#0ea5e9'];
                @endphp
                @foreach($systemStats['by_system'] as $idx => $stat)
                    @php
                        $initials = mb_strtoupper(mb_substr($stat['name'], 0, 2));
                        $color = $cardColors[$idx % count($cardColors)];
                    @endphp
                    <div class="col">
                        <a href="{{ route('admin.stations.index', ['system_id' => $stat['system_id'] ?? '']) }}" class="text-decoration-none">
                            <div class="border rounded-3 p-3 d-flex align-items-center gap-3" style="border-left:4px solid {{ $color }} !important;cursor:pointer;transition:box-shadow .2s"
                                 onmouseover="this.style.boxShadow='0 2px 8px rgba(0,0,0,0.1)'" onmouseout="this.style.boxShadow='none'">
                                <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold text-white"
                                     style="width:40px;height:40px;min-width:40px;background:{{ $color }};font-size:0.8125rem">{{ $initials }}</div>
                                <div>
                                    <div class="fw-bold" style="color:#212529">{{ $stat['name'] }} <i class="fas fa-chevron-right" style="font-size:0.5rem;color:#adb5bd"></i></div>
                                    <div class="d-flex gap-3 mt-1">
                                        <span class="badge bg-success">正常 {{ $stat['active'] }}</span>
                                        <span class="badge bg-warning text-dark">凍結 {{ $stat['frozen'] }}</span>
                                        <span class="badge bg-danger">關閉 {{ $stat['disabled'] }}</span>
                                    </div>
                                </div>
                            </div>
                        </a>
                    </div>
                @endforeach
                <div class="col">
                    <div class="border rounded-3 p-3 d-flex align-items-center gap-3" style="border-left:4px solid #0d9488 !important;background:#f8f9fa">
                        <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold"
                             style="width:40px;height:40px;min-width:40px;background:#e9ecef;color:#495057;font-size:0.875rem"><i class="fas fa-clipboard-list"></i></div>
                        <div>
                            <div class="fw-bold">總計 <strong>{{ $systemStats['total'] }} 站</strong></div>
                            <div class="d-flex gap-3 mt-1">
                                <span class="badge bg-success">正常 {{ $systemStats['active'] }}</span>
                                <span class="badge bg-warning text-dark">凍結 {{ $systemStats['frozen'] }}</span>
                                <span class="badge bg-danger">關閉 {{ $systemStats['disabled'] }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

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
                                if (empty($settings['withholding_system'])) {
                                    $depositRate = '不收費';
                                } elseif (isset($settings['system_rate'])) {
                                    $depositRate = number_format($settings['system_rate'] * 100, 2) . '%';
                                } else {
                                    $depositRate = '-';
                                }
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
                                                data-key-masked="{{ filled($station->api_key) ? Str::substr($station->api_key, 0, 4) . str_repeat('*', max(0, Str::length($station->api_key) - 8)) . Str::substr($station->api_key, -4) : '' }}"
                                                data-tg-masked="{{ $station->telegramGroup && filled($station->telegramGroup->chat_id) ? Str::substr((string)$station->telegramGroup->chat_id, 0, 4) . str_repeat('*', max(0, Str::length((string)$station->telegramGroup->chat_id) - 8)) . Str::substr((string)$station->telegramGroup->chat_id, -4) : '' }}"
                                                data-note="{{ $station->note }}">
                                            <i class="fas fa-edit me-1"></i>編輯
                                        </button>
                                        <button class="btn btn-sm btn-outline-secondary js-sync-credits" data-id="{{ $station->id }}">
                                            <i class="fas fa-sync-alt me-1"></i>同步
                                        </button>
                                        <button class="btn btn-sm btn-outline-secondary js-change-station-status"
                                                data-id="{{ $station->id }}"
                                                data-status="{{ $station->status }}">
                                            <i class="fas fa-exchange-alt me-1"></i>狀態
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
                if (empty($settings['withholding_system'])) {
                    $depositRate = '不收費';
                } elseif (isset($settings['system_rate'])) {
                    $depositRate = number_format($settings['system_rate'] * 100, 2) . '%';
                } else {
                    $depositRate = '-';
                }
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
                                    data-key-masked="{{ filled($station->api_key) ? Str::substr($station->api_key, 0, 4) . str_repeat('*', max(0, Str::length($station->api_key) - 8)) . Str::substr($station->api_key, -4) : '' }}"
                                    data-tg-masked="{{ $station->telegramGroup && filled($station->telegramGroup->chat_id) ? Str::substr((string)$station->telegramGroup->chat_id, 0, 4) . str_repeat('*', max(0, Str::length((string)$station->telegramGroup->chat_id) - 8)) . Str::substr((string)$station->telegramGroup->chat_id, -4) : '' }}"
                                    data-note="{{ $station->note }}">
                                <i class="fas fa-edit me-1"></i>編輯
                            </button>
                            <button class="btn btn-sm btn-outline-secondary js-sync-credits" data-id="{{ $station->id }}">
                                <i class="fas fa-sync-alt me-1"></i>同步
                            </button>
                            <button class="btn btn-sm btn-outline-secondary js-change-station-status"
                                    data-id="{{ $station->id }}"
                                    data-status="{{ $station->status }}">
                                <i class="fas fa-exchange-alt me-1"></i>狀態
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

    </div>{{-- end tab-station-list --}}
    @endif

    {{-- 補點紀錄 Tab --}}
    @if($canTopupView)
    <div class="tab-pane fade {{ $firstTab === 'topup' ? 'show active' : '' }}" id="tab-topup">
        <div class="main-card mb-3 card">
            <div class="card-header">
                <div class="row g-2 align-items-center">
                    @if(Auth::user()->hasPermission('station.topup_apply'))
                    <div class="col-auto">
                        <button type="button" class="btn btn-primary" id="btn-open-topup-apply">
                            <i class="fas fa-plus me-1"></i>{{ trans('station.topup_title') }}
                        </button>
                    </div>
                    @endif
                    <div class="col"></div>
                    <div class="col-12 col-md-auto">
                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <div class="searchable-select flex-grow-1" style="min-width:140px;position:relative">
                                <input type="text" class="form-control form-control-sm" id="topup-filter-station-search" placeholder="搜尋站台..." autocomplete="off">
                                <input type="hidden" id="topup-filter-station" value="">
                                <div class="searchable-dropdown" id="topup-filter-station-dropdown" style="display:none;position:absolute;z-index:1050;background:#fff;border:1px solid #dee2e6;border-radius:0.25rem;max-height:200px;overflow-y:auto;width:100%;box-shadow:0 2px 8px rgba(0,0,0,0.15)">
                                    <a href="javascript:void(0)" class="dropdown-item js-station-opt" data-id="" style="display:block;padding:0.35rem 0.75rem;font-size:0.875rem">全部站台</a>
                                    @foreach($allStations as $st)
                                        <a href="javascript:void(0)" class="dropdown-item js-station-opt" data-id="{{ $st->id }}" data-name="{{ $st->name }}" style="display:block;padding:0.35rem 0.75rem;font-size:0.875rem">{{ $st->name }}</a>
                                    @endforeach
                                </div>
                            </div>
                            <select id="topup-filter-status" class="form-select form-select-sm" style="width:auto">
                                <option value="">全部狀態</option>
                                <option value="0">{{ trans('station.topup_status_pending') }}</option>
                                <option value="1">{{ trans('station.topup_status_completed') }}</option>
                                <option value="2">{{ trans('station.topup_status_rejected') }}</option>
                                <option value="3">{{ trans('station.topup_status_failed') }}</option>
                            </select>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-topup-search">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- 桌面版：表格 --}}
        <div class="main-card mb-3 card d-none d-md-block">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped align-middle mb-0" style="white-space:nowrap">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>系統</th>
                                <th>{{ trans('station.topup_field_station') }}</th>
                                <th>{{ trans('station.topup_field_action') }}</th>
                                <th>{{ trans('station.topup_field_usdt') }}</th>
                                <th>{{ trans('station.topup_field_rate') }}</th>
                                <th>{{ trans('station.topup_field_amount') }}</th>
                                <th>{{ trans('station.topup_field_status') }}</th>
                                <th>{{ trans('station.topup_field_requester') }}</th>
                                <th>時間</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody id="topup-table-body">
                            <tr><td colspan="11" class="text-center text-muted py-4">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- 手機版：卡片 --}}
        <div class="d-md-none" id="topup-card-list">
            <p class="text-muted text-center py-4">Loading...</p>
        </div>
    </div>
    @endif
    </div>{{-- end tab-content --}}

    {{-- 申請補點/扣點 Modal --}}
    <div class="modal fade" id="modal-topup-apply" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ trans('station.topup_title') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="form-topup-apply">
                        <div class="mb-3">
                            <label class="form-label">{{ trans('station.topup_field_station') }} <span class="text-danger">*</span></label>
                            <div class="searchable-select" style="position:relative">
                                <input type="text" class="form-control" id="topup-station-search" placeholder="輸入搜尋站台..." autocomplete="off" required>
                                <input type="hidden" id="topup-station" value="">
                                <div class="searchable-dropdown" id="topup-station-dropdown" style="display:none;position:absolute;z-index:1060;background:#fff;border:1px solid #dee2e6;border-radius:0.25rem;max-height:200px;overflow-y:auto;width:100%;box-shadow:0 2px 8px rgba(0,0,0,0.15)">
                                    @foreach($allStations as $st)
                                        <a href="javascript:void(0)" class="dropdown-item js-topup-station-opt" data-id="{{ $st->id }}" data-name="{{ $st->name }}" style="display:block;padding:0.35rem 0.75rem;font-size:0.875rem">{{ $st->name }}</a>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('station.topup_field_action') }} <span class="text-danger">*</span></label>
                            <div class="d-flex gap-2">
                                <input type="radio" class="btn-check" name="topup_action" value="1" id="topup-action-add" autocomplete="off" checked>
                                <label class="btn btn-outline-success btn-lg flex-fill fw-bold" for="topup-action-add" style="font-size:1.125rem;padding:0.75rem 1rem">
                                    <i class="fas fa-plus-circle me-2"></i>{{ trans('station.topup_action_add') }}
                                </label>
                                <input type="radio" class="btn-check" name="topup_action" value="2" id="topup-action-deduct" autocomplete="off">
                                <label class="btn btn-outline-danger btn-lg flex-fill fw-bold" for="topup-action-deduct" style="font-size:1.125rem;padding:0.75rem 1rem">
                                    <i class="fas fa-minus-circle me-2"></i>{{ trans('station.topup_action_deduct') }}
                                </label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('station.topup_field_type') }} <span class="text-danger">*</span></label>
                            <select id="topup-credit-type" class="form-select" required>
                                <option value="credit">{{ trans('station.topup_credit') }}</option>
                                <option value="shop_credit">{{ trans('station.topup_shop_credit') }}</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('station.topup_field_usdt') }} <span class="text-danger">*</span></label>
                            <input id="topup-usdt" type="number" class="form-control" step="0.0001" min="0.0001" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('station.topup_field_rate') }} <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input id="topup-rate" type="number" class="form-control" step="0.0001" min="0.0001" required>
                                <button type="button" class="btn btn-outline-secondary" id="btn-fetch-rate">
                                    <i class="fas fa-sync-alt me-1"></i>即時匯率
                                </button>
                            </div>
                            <small class="text-muted" id="topup-rate-hint"></small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">{{ trans('station.topup_field_amount') }}</label>
                            <div id="topup-calc-amount" class="form-control-plaintext fw-bold" style="font-size:1.125rem">0.00</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('station.topup_field_note') }}</label>
                            <textarea id="topup-note" class="form-control" rows="2" maxlength="500"></textarea>
                        </div>
                        <div class="text-end">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                            <button type="submit" class="btn btn-primary">送出申請</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- 審核確認 Modal --}}
    <div class="modal fade" id="modal-topup-confirm" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-body text-center py-4">
                    <p id="topup-confirm-text" class="mb-3"></p>
                    <input type="hidden" id="topup-confirm-id">
                    <input type="hidden" id="topup-confirm-action">
                    <div class="d-flex justify-content-center gap-2">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                        <button type="button" class="btn btn-primary" id="btn-topup-confirm-ok">確認</button>
                    </div>
                </div>
            </div>
        </div>
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
                            <div class="input-group">
                                <input id="station-telegram-chat-id" type="text" class="form-control" name="telegram_chat_id" placeholder="-100xxxxxxxxxx">
                                <button type="button" class="btn btn-outline-secondary" id="btn-detect-group">
                                    <i class="fas fa-search me-1"></i>偵測
                                </button>
                            </div>
                            <div id="bot-group-list" class="mt-2" style="display:none"></div>
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

    {{-- 調整狀態 Modal --}}
    <div class="modal fade" id="modal-station-status" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">調整站台狀態</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="form-station-status">
                        <input type="hidden" id="status-station-id">
                        <div class="mb-3">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="station_status" value="1" id="st-status-active">
                                <label class="form-check-label" for="st-status-active">
                                    <strong>{{ trans('station.status_active') }}</strong>
                                </label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="station_status" value="2" id="st-status-frozen">
                                <label class="form-check-label" for="st-status-frozen">
                                    <strong>{{ trans('station.status_frozen') }}</strong>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="station_status" value="0" id="st-status-disabled">
                                <label class="form-check-label" for="st-status-disabled">
                                    <strong>{{ trans('station.status_disabled') }}</strong>
                                </label>
                            </div>
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

    {{-- 系統管理 Modal --}}
    <div class="modal fade" id="modal-system-mgmt" tabindex="-1">
        <div class="modal-dialog modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">系統管理</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    {{-- 新增系統 --}}
                    <div class="card mb-3">
                        <div class="card-header fw-bold">新增系統</div>
                        <div class="card-body">
                            <form id="form-add-system">
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-4">
                                        <label class="form-label">系統名稱 <span class="text-danger">*</span></label>
                                        <input id="new-system-name" type="text" class="form-control" required>
                                    </div>
                                    <div class="col-md-5">
                                        <label class="form-label">Bot Token</label>
                                        <input id="new-system-token" type="text" class="form-control" placeholder="選填">
                                    </div>
                                    <div class="col-md-3">
                                        <button type="submit" class="btn btn-primary w-100">
                                            <i class="fas fa-plus me-1"></i>新增
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    {{-- 現有系統列表 --}}
                    <div class="card">
                        <div class="card-header fw-bold">現有系統</div>
                        <div class="card-body p-0">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>系統名稱</th>
                                        <th>Bot Token</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody id="system-list">
                                    @foreach($systems as $sys)
                                        <tr data-id="{{ $sys->id }}">
                                            <td><strong>{{ $sys->name }}</strong></td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm js-sys-token"
                                                       value="{{ $sys->bot_token ? Str::substr($sys->bot_token, 0, 10) . '***' : '' }}"
                                                       data-original="{{ $sys->bot_token ? Str::substr($sys->bot_token, 0, 10) . '***' : '' }}"
                                                       placeholder="未設定">
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-primary js-save-system" data-id="{{ $sys->id }}">
                                                    <i class="fas fa-save me-1"></i>儲存
                                                </button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
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

    // 折疊/展開文字切換
    var $collapseEl = $('#station-search-collapse');
    var $toggleLink = $('[data-bs-target="#station-search-collapse"]');
    $collapseEl.on('show.bs.collapse', function () { $toggleLink.text('— 折疊 —'); });
    $collapseEl.on('hide.bs.collapse', function () { $toggleLink.text('— 展開 —'); });

    function showMessage(msg) {
        $('#modal-station-msg-text').text(msg);
        showBsModal('modal-station-msg');
    }

    // 編輯站台（敏感欄位顯示遮罩值）
    var editApiKeyMasked = '';
    var editChatIdMasked = '';

    $('.js-edit-station').on('click', function () {
        var $btn = $(this);
        editApiKeyMasked = $btn.data('key-masked') || '';
        editChatIdMasked = $btn.data('tg-masked') || '';

        $('#station-id').val($btn.data('id'));
        $('#station-name').val($btn.data('name'));
        $('#station-domain').val($btn.data('domain'));
        $('#station-system').val($btn.data('system-id'));
        $('#station-api-url').val($btn.data('api-url'));
        $('#station-api-key').val(editApiKeyMasked);
        $('#station-telegram-chat-id').val(editChatIdMasked);
        $('#station-note').val($btn.data('note'));
        $('#modal-station .modal-title').text('{{ trans("station.action_edit") }}');
        $('#bot-group-list').hide();
        showBsModal('modal-station');
    });

    // 新增/編輯提交
    $('#form-station').on('submit', function (e) {
        e.preventDefault();
        var id = $('#station-id').val();
        var url = id ? '/admin/stations/ajax-update/' + id : '/admin/stations/ajax-store';
        var method = id ? 'PUT' : 'POST';

        var apiKeyVal = $('#station-api-key').val();
        var chatIdVal = $('#station-telegram-chat-id').val();
        var payload = {
            system_id: $('#station-system').val(),
            name: $('#station-name').val(),
            domain: $('#station-domain').val(),
            api_url: $('#station-api-url').val(),
            note: $('#station-note').val(),
        };
        // 敏感欄位：只有被修改時才傳送（遮罩值不傳）
        if (apiKeyVal !== editApiKeyMasked) { payload.api_key = apiKeyVal; }
        if (chatIdVal !== editChatIdMasked) { payload.telegram_chat_id = chatIdVal; }
        // 新增時一律傳
        if (!id) {
            payload.api_key = apiKeyVal;
            payload.telegram_chat_id = chatIdVal;
        }

        $.ajax({
            url: url, method: method,
            headers: { 'X-CSRF-TOKEN': csrfToken },
            contentType: 'application/json',
            data: JSON.stringify(payload),
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

    // 偵測 Telegram Bot 群組
    $('#btn-detect-group').on('click', function () {
        var $btn = $(this);
        var $list = $('#bot-group-list');
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i>偵測中...');

        $.ajax({
            url: '/admin/stations/ajax-bot-groups',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            success: function (groups) {
                if (!groups || groups.length === 0) {
                    $list.html('<p class="text-muted mb-0">未找到群組</p>').show();
                    $btn.prop('disabled', false).html('<i class="fas fa-search me-1"></i>偵測');
                    return;
                }
                var html = '<div class="list-group">';
                groups.forEach(function (g) {
                    html += '<a href="javascript:void(0)" class="list-group-item list-group-item-action js-select-group" data-chat-id="' + g.chat_id + '">' +
                        '<strong>' + g.title + '</strong>' +
                        '<small class="text-muted ms-2">' + g.chat_id + '</small>' +
                        '</a>';
                });
                html += '</div>';
                $list.html(html).show();

                $list.find('.js-select-group').on('click', function () {
                    $('#station-telegram-chat-id').val($(this).data('chat-id'));
                    $list.hide();
                });

                $btn.prop('disabled', false).html('<i class="fas fa-search me-1"></i>偵測');
            },
            error: function (xhr) {
                $btn.prop('disabled', false).html('<i class="fas fa-search me-1"></i>偵測');
                showMessage((xhr.responseJSON && xhr.responseJSON.message) || '偵測失敗');
            }
        });
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
                var depositRateText = '-';
                if (!s.withholding_system) {
                    depositRateText = '不收費';
                } else if (s.system_rate) {
                    depositRateText = (s.system_rate * 100).toFixed(2) + '%';
                }
                html += '<tr><th>代收費率</th><td>' + depositRateText + '</td></tr>';
                var withdrawRateText = '-';
                if (!s.withdraw) {
                    withdrawRateText = '未開啟';
                } else if (!s.withdraw_withholding_system) {
                    withdrawRateText = '不收費';
                } else if (s.system_rate_withdraw) {
                    withdrawRateText = (s.system_rate_withdraw * 100).toFixed(2) + '%';
                }
                html += '<tr><th>代付費率</th><td>' + withdrawRateText + '</td></tr>';
                var selfDepositText = '-';
                if (!s.withholding_system) {
                    selfDepositText = '不收費';
                } else if (s.self_system_rate) {
                    selfDepositText = (s.self_system_rate * 100).toFixed(2) + '%';
                } else {
                    selfDepositText = '0%';
                }
                html += '<tr><th>同系統轉單代收費率</th><td>' + selfDepositText + '</td></tr>';
                var selfWithdrawText = '-';
                if (!s.withdraw) {
                    selfWithdrawText = '未開啟';
                } else if (!s.withdraw_withholding_system) {
                    selfWithdrawText = '不收費';
                } else if (s.self_system_rate_withdraw) {
                    selfWithdrawText = (s.self_system_rate_withdraw * 100).toFixed(2) + '%';
                } else {
                    selfWithdrawText = '0%';
                }
                html += '<tr><th>同系統轉單代付費率</th><td>' + selfWithdrawText + '</td></tr>';
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
    // 調整狀態
    $('.js-change-station-status').on('click', function () {
        var $btn = $(this);
        $('#status-station-id').val($btn.data('id'));
        $('input[name="station_status"][value="' + $btn.data('status') + '"]').prop('checked', true);
        showBsModal('modal-station-status');
    });

    $('#form-station-status').on('submit', function (e) {
        e.preventDefault();
        var id = $('#status-station-id').val();
        var status = parseInt($('input[name="station_status"]:checked').val(), 10);

        $.ajax({
            url: '/admin/stations/ajax-update/' + id,
            method: 'PUT',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            contentType: 'application/json',
            data: JSON.stringify({ status: status }),
            success: function () { location.reload(); },
            error: function (xhr) {
                showMessage((xhr.responseJSON && xhr.responseJSON.message) || '操作失敗');
            }
        });
    });

    // 系統管理
    $('#btn-open-system-mgmt').on('click', function () {
        showBsModal('modal-system-mgmt');
    });

    // 新增系統
    $('#form-add-system').on('submit', function (e) {
        e.preventDefault();
        var name = $('#new-system-name').val().trim();
        var token = $('#new-system-token').val().trim();
        if (!name) { return; }

        $.ajax({
            url: '/admin/stations/ajax-store-system',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            contentType: 'application/json',
            data: JSON.stringify({ name: name, bot_token: token || null }),
            success: function () { location.reload(); },
            error: function (xhr) {
                showMessage((xhr.responseJSON && xhr.responseJSON.message) || '新增失敗');
            }
        });
    });

    // 儲存系統 Bot Token
    $('.js-save-system').on('click', function () {
        var $btn = $(this);
        var id = $btn.data('id');
        var $input = $btn.closest('tr').find('.js-sys-token');
        var val = $input.val().trim();
        var original = $input.data('original');

        // 沒改就不送
        if (val === original) {
            showMessage('未修改');
            return;
        }

        $btn.prop('disabled', true);
        $.ajax({
            url: '/admin/stations/ajax-update-system/' + id,
            method: 'PUT',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            contentType: 'application/json',
            data: JSON.stringify({ bot_token: val || null }),
            success: function (body) {
                hideBsModal(document.getElementById('modal-system-mgmt'));
                setTimeout(function () {
                    showMessage(body.message || '已更新');
                    // OK 後 reload
                    $('#modal-station-msg').off('hidden.bs.modal.reload').on('hidden.bs.modal.reload', function () {
                        location.reload();
                    });
                }, 400);
            },
            error: function (xhr) {
                $btn.prop('disabled', false);
                hideBsModal(document.getElementById('modal-system-mgmt'));
                setTimeout(function () {
                    showMessage((xhr.responseJSON && xhr.responseJSON.message) || '更新失敗');
                }, 400);
            }
        });
    });

    // ---------------------------------------------------------------
    //  補點紀錄
    // ---------------------------------------------------------------

    var hasTopupView = {{ Auth::user()->hasPermission('station.topup_view') ? 'true' : 'false' }};
    var hasTopupApply = {{ Auth::user()->hasPermission('station.topup_apply') ? 'true' : 'false' }};
    var hasTopupApprove = {{ Auth::user()->hasPermission('station.topup_approve') ? 'true' : 'false' }};

    function topupStatusBadge(status) {
        var map = {
            0: '<span class="badge" style="background:rgba(255,193,7,0.15);color:#b8860b;border-radius:9999px;padding:0.3em 0.8em">{{ trans("station.topup_status_pending") }}</span>',
            1: '<span class="badge" style="background:rgba(25,135,84,0.15);color:#198754;border-radius:9999px;padding:0.3em 0.8em">{{ trans("station.topup_status_completed") }}</span>',
            2: '<span class="badge" style="background:rgba(220,53,69,0.15);color:#dc3545;border-radius:9999px;padding:0.3em 0.8em">{{ trans("station.topup_status_rejected") }}</span>',
            3: '<span class="badge" style="background:rgba(220,53,69,0.15);color:#dc3545;border-radius:9999px;padding:0.3em 0.8em">{{ trans("station.topup_status_failed") }}</span>'
        };
        return map[status] || '-';
    }

    function topupActionLabel(actionType, creditType) {
        var creditLabel = creditType === 'shop_credit' ? '商城點數' : '系統點數';
        return parseInt(actionType, 10) === 1
            ? '<span class="text-success"><i class="fas fa-plus-circle me-1"></i>加' + creditLabel + '</span>'
            : '<span class="text-danger"><i class="fas fa-minus-circle me-1"></i>扣' + creditLabel + '</span>';
    }

    function formatNum(val, maxDecimals) {
        var n = parseFloat(val);
        if (isNaN(n)) return '0';
        var fixed = n.toFixed(maxDecimals);
        // 移除尾部多餘的 0，如果小數點後全是 0 則連小數點也移除
        return fixed.replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
    }

    function loadTopupList() {
        if (!hasTopupView) return;
        var params = {};
        var stationId = $('#topup-filter-station').val();
        var status = $('#topup-filter-status').val();
        if (stationId) params.station_id = stationId;
        if (status !== '') params.status = status;

        $.ajax({
            url: '/admin/stations/ajax-topup-list',
            data: params,
            headers: { 'X-CSRF-TOKEN': csrfToken },
            success: function (body) {
                var list = body.data || body;
                renderTopupTable(list);
                renderTopupCards(list);
            }
        });
    }

    function renderTopupTable(list) {
        var $tbody = $('#topup-table-body');
        if (!list || list.length === 0) {
            $tbody.html('<tr><td colspan="11" class="text-center text-muted py-4">暫無資料</td></tr>');
            return;
        }
        var html = '';
        list.forEach(function (t, idx) {
            html += '<tr>';
            html += '<td>' + (idx + 1) + '</td>';
            html += '<td>' + (t.system || '-') + '</td>';
            html += '<td>' + t.station + '</td>';
            html += '<td>' + topupActionLabel(t.action_type, t.credit_type) + '</td>';
            html += '<td>' + t.usdt_amount + '</td>';
            html += '<td>' + t.exchange_rate + '</td>';
            html += '<td><strong>' + t.credit_amount + '</strong></td>';
            html += '<td>' + topupStatusBadge(t.status) + '</td>';
            html += '<td>' + t.requester + '</td>';
            html += '<td><small class="text-muted">' + t.created_at + '</small></td>';
            html += '<td>';
            if (parseInt(t.status, 10) === 0 && hasTopupApprove) {
                html += '<button class="btn btn-sm btn-outline-secondary js-topup-approve" data-id="' + t.id + '" data-station="' + t.station + '" data-amount="' + t.credit_amount + '" data-action="' + t.action_type + '">';
                html += '<i class="fas fa-check text-success me-1"></i>通過</button> ';
                html += '<button class="btn btn-sm btn-outline-secondary js-topup-reject" data-id="' + t.id + '">';
                html += '<i class="fas fa-times text-danger me-1"></i>拒絕</button>';
            }
            if (parseInt(t.status, 10) === 3 && hasTopupApprove) {
                html += '<button class="btn btn-sm btn-outline-secondary js-topup-approve" data-id="' + t.id + '" data-station="' + t.station + '" data-amount="' + t.credit_amount + '" data-action="' + t.action_type + '">';
                html += '<i class="fas fa-redo text-warning me-1"></i>重試</button>';
            }
            if (t.reviewer) {
                html += ' <small class="text-muted">審核：' + t.reviewer + '</small>';
            }
            if (t.note) {
                html += ' <button class="btn btn-sm btn-outline-secondary js-topup-note" data-note="' + t.note.replace(/"/g, '&quot;') + '"><i class="fas fa-sticky-note me-1"></i>備註</button>';
            }
            html += '</td>';
            html += '</tr>';
        });
        $tbody.html(html);
        bindTopupActions();
    }

    function renderTopupCards(list) {
        var $container = $('#topup-card-list');
        if (!list || list.length === 0) {
            $container.html('<p class="text-muted text-center py-4">暫無資料</p>');
            return;
        }
        var html = '';
        list.forEach(function (t) {
            html += '<div class="card mb-2 shadow-sm"><div class="card-body py-3">';
            html += '<div class="d-flex justify-content-between align-items-start mb-2">';
            html += '<div><small class="text-muted">' + (t.system || '-') + '</small><br><strong>' + t.station + '</strong> ' + topupActionLabel(t.action_type, t.credit_type) + '</div>';
            html += topupStatusBadge(t.status);
            html += '</div>';
            html += '<div class="d-flex justify-content-between mb-1" style="font-size:0.875rem"><span class="text-muted">USDT</span><span>' + t.usdt_amount + '</span></div>';
            html += '<div class="d-flex justify-content-between mb-1" style="font-size:0.875rem"><span class="text-muted">{{ trans("station.topup_field_rate") }}</span><span>' + t.exchange_rate + '</span></div>';
            html += '<div class="d-flex justify-content-between mb-1" style="font-size:0.875rem"><span class="text-muted">{{ trans("station.topup_field_amount") }}</span><strong>' + t.credit_amount + '</strong></div>';
            html += '<div class="d-flex justify-content-between mb-1" style="font-size:0.875rem"><span class="text-muted">申請人</span><span>' + t.requester + '</span></div>';
            html += '<div class="d-flex justify-content-between mb-2" style="font-size:0.8125rem"><span class="text-muted">' + t.created_at + '</span>';
            if (t.reviewer) html += '<span class="text-muted">審核：' + t.reviewer + '</span>';
            html += '</div>';
            if (t.note) html += '<div class="text-muted mb-2" style="font-size:0.8125rem"><i class="fas fa-sticky-note me-1"></i>' + t.note + '</div>';
            if (parseInt(t.status, 10) === 0 && hasTopupApprove) {
                html += '<div class="d-flex gap-1">';
                html += '<button class="btn btn-sm btn-outline-secondary js-topup-approve" data-id="' + t.id + '" data-station="' + t.station + '" data-amount="' + t.credit_amount + '" data-action="' + t.action_type + '"><i class="fas fa-check text-success me-1"></i>通過</button>';
                html += '<button class="btn btn-sm btn-outline-secondary js-topup-reject" data-id="' + t.id + '"><i class="fas fa-times text-danger me-1"></i>拒絕</button>';
                html += '</div>';
            }
            if (parseInt(t.status, 10) === 3 && hasTopupApprove) {
                html += '<button class="btn btn-sm btn-outline-secondary js-topup-approve" data-id="' + t.id + '" data-station="' + t.station + '" data-amount="' + t.credit_amount + '" data-action="' + t.action_type + '"><i class="fas fa-redo text-warning me-1"></i>重試</button>';
            }
            html += '</div></div>';
        });
        $container.html(html);
        bindTopupActions();
    }

    function bindTopupActions() {
        $('.js-topup-approve').off('click').on('click', function () {
            var $btn = $(this);
            var actionText = parseInt($btn.data('action'), 10) === 1 ? '{{ trans("station.topup_action_add") }}' : '{{ trans("station.topup_action_deduct") }}';
            $('#topup-confirm-id').val($btn.data('id'));
            $('#topup-confirm-action').val('approve');
            $('#topup-confirm-text').html('確定通過此筆<strong>' + actionText + '</strong>申請？<br>站台：' + $btn.data('station') + '<br>點數：' + $btn.data('amount'));
            showBsModal('modal-topup-confirm');
        });

        $('.js-topup-reject').off('click').on('click', function () {
            $('#topup-confirm-id').val($(this).data('id'));
            $('#topup-confirm-action').val('reject');
            $('#topup-confirm-text').html('確定<strong class="text-danger">拒絕</strong>此筆補點申請？');
            showBsModal('modal-topup-confirm');
        });

        $('.js-topup-note').off('click').on('click', function () {
            $('#modal-station-msg-text').text($(this).data('note'));
            showBsModal('modal-station-msg');
        });
    }

    // 審核確認送出
    $('#btn-topup-confirm-ok').on('click', function () {
        var id = $('#topup-confirm-id').val();
        var action = $('#topup-confirm-action').val();
        var url = action === 'approve'
            ? '/admin/stations/ajax-topup-approve/' + id
            : '/admin/stations/ajax-topup-reject/' + id;
        var $btn = $(this);
        $btn.prop('disabled', true);

        $.ajax({
            url: url,
            method: 'PUT',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            contentType: 'application/json',
            success: function (body) {
                hideBsModal(document.getElementById('modal-topup-confirm'));
                setTimeout(function () {
                    showMessage(body.message || '操作成功');
                    loadTopupList();
                }, 400);
                $btn.prop('disabled', false);
            },
            error: function (xhr) {
                hideBsModal(document.getElementById('modal-topup-confirm'));
                setTimeout(function () {
                    showMessage((xhr.responseJSON && xhr.responseJSON.message) || '操作失敗');
                }, 400);
                $btn.prop('disabled', false);
            }
        });
    });

    // 搜尋
    $('#btn-topup-search').on('click', function () { loadTopupList(); });

    // 打開申請 Modal
    $('#btn-open-topup-apply').on('click', function () {
        $('#form-topup-apply')[0].reset();
        $('#topup-station').val('');
        $('#topup-station-search').val('');
        $('#topup-calc-amount').text('0.00');
        $('#topup-rate-hint').text('');
        fetchUsdtRate();
        showBsModal('modal-topup-apply');
    });

    // 自動帶入 USDT 即時匯率
    function fetchUsdtRate() {
        var $btn = $('#btn-fetch-rate');
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i>取得中...');
        $.ajax({
            url: '/admin/dashboard/ajax-usdt-rate',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            success: function (body) {
                if (body && body.avg_rate) {
                    $('#topup-rate').val(parseFloat(body.avg_rate).toFixed(4));
                    $('#topup-rate-hint').text('4H 均價：' + body.avg_rate + '（即時：' + body.current_rate + '）');
                    calcTopupAmount();
                }
                $btn.prop('disabled', false).html('<i class="fas fa-sync-alt me-1"></i>即時匯率');
            },
            error: function () {
                $btn.prop('disabled', false).html('<i class="fas fa-sync-alt me-1"></i>即時匯率');
                showMessage('匯率取得失敗');
            }
        });
    }

    // 自動計算換算點數
    function calcTopupAmount() {
        var usdt = parseFloat($('#topup-usdt').val()) || 0;
        var rate = parseFloat($('#topup-rate').val()) || 0;
        var amount = (usdt * rate).toFixed(2);
        $('#topup-calc-amount').text(amount);
    }

    $('#topup-usdt, #topup-rate').on('input', function () { calcTopupAmount(); });

    // 取得即時匯率按鈕
    $('#btn-fetch-rate').on('click', function () { fetchUsdtRate(); });

    // 送出申請
    $('#form-topup-apply').on('submit', function (e) {
        e.preventDefault();
        var stationId = $('#topup-station').val();
        if (!stationId) {
            showMessage('請選擇站台');
            return;
        }
        var usdt = parseFloat($('#topup-usdt').val()) || 0;
        var rate = parseFloat($('#topup-rate').val()) || 0;
        if (usdt <= 0 || rate <= 0) {
            showMessage('請填入正確的 USDT 金額與匯率');
            return;
        }

        var payload = {
            station_id: parseInt(stationId, 10),
            action_type: parseInt($('input[name="topup_action"]:checked').val(), 10),
            credit_type: $('#topup-credit-type').val(),
            usdt_amount: usdt,
            exchange_rate: rate,
            credit_amount: parseFloat((usdt * rate).toFixed(2)),
            note: $('#topup-note').val() || null
        };

        $.ajax({
            url: '/admin/stations/ajax-topup-store',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            contentType: 'application/json',
            data: JSON.stringify(payload),
            success: function (body) {
                hideBsModal(document.getElementById('modal-topup-apply'));
                setTimeout(function () {
                    showMessage(body.message || '申請已送出');
                    loadTopupList();
                }, 400);
            },
            error: function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.message) || '申請失敗';
                if (xhr.responseJSON && xhr.responseJSON.errors) {
                    var errors = xhr.responseJSON.errors;
                    var errorMsgs = [];
                    Object.keys(errors).forEach(function (key) { errorMsgs.push(errors[key][0]); });
                    msg = errorMsgs.join('\n');
                }
                hideBsModal(document.getElementById('modal-topup-apply'));
                setTimeout(function () { showMessage(msg); }, 400);
            }
        });
    });

    // ---------------------------------------------------------------
    //  Searchable Select（站台搜尋下拉）
    // ---------------------------------------------------------------

    function initSearchableSelect(inputId, hiddenId, dropdownId, optSelector) {
        var $input = $('#' + inputId);
        var $hidden = $('#' + hiddenId);
        var $dropdown = $('#' + dropdownId);

        $input.on('focus', function () { $dropdown.show(); });
        $input.on('input', function () {
            var keyword = $(this).val().toLowerCase();
            $dropdown.find(optSelector).each(function () {
                var name = ($(this).data('name') || $(this).text()).toString().toLowerCase();
                $(this).toggle(name.indexOf(keyword) !== -1 || !keyword);
            });
            $dropdown.show();
            // 清除已選
            $hidden.val('');
        });

        $dropdown.on('click', optSelector, function () {
            var id = $(this).data('id');
            var name = $(this).data('name') || $(this).text();
            $hidden.val(id || '');
            $input.val(id ? name : '');
            $dropdown.hide();
        });

        // 點擊外部關閉
        $(document).on('mousedown', function (e) {
            if (!$(e.target).closest($input.parent()).length) {
                $dropdown.hide();
            }
        });
    }

    initSearchableSelect('topup-filter-station-search', 'topup-filter-station', 'topup-filter-station-dropdown', '.js-station-opt');
    initSearchableSelect('topup-station-search', 'topup-station', 'topup-station-dropdown', '.js-topup-station-opt');

    // 切到補點 Tab 時自動載入
    $('button[data-bs-target="#tab-topup"]').on('shown.bs.tab', function () {
        loadTopupList();
    });

    // 頁面載入時，若補點 Tab 為預設 active 則自動載入
    if ($('#tab-topup').hasClass('active')) {
        loadTopupList();
    }
});
</script>
@endsection
