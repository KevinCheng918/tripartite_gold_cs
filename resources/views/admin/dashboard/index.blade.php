@extends('layouts.app')

@section('title', trans('dashboard.page_title'))
@section('subtitle', trans('dashboard.subtitle'))
@section('icon', 'tachometer-alt')

@section('content')

    @if(Auth::user()->isAdmin())
    {{-- ===== Admin Dashboard ===== --}}

    {{-- 帳號統計 --}}
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="card widget-content bg-white shadow-sm">
                <div class="widget-content-wrapper">
                    <div class="widget-content-left">
                        <div class="widget-heading text-muted">{{ trans('dashboard.total_cs') }}</div>
                        <div class="widget-numbers text-dark">{{ $totalCs }}</div>
                    </div>
                    <div class="widget-content-right">
                        <div class="widget-numbers text-primary"><i class="fas fa-users"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card widget-content bg-white shadow-sm" style="border-left: 3px solid #198754;">
                <div class="widget-content-wrapper">
                    <div class="widget-content-left">
                        <div class="widget-heading text-muted">{{ trans('dashboard.status_normal') }}</div>
                        <div class="widget-numbers text-success">{{ $normalCs }}</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card widget-content bg-white shadow-sm" style="border-left: 3px solid #ffc107;">
                <div class="widget-content-wrapper">
                    <div class="widget-content-left">
                        <div class="widget-heading text-muted">{{ trans('dashboard.status_lock') }}</div>
                        <div class="widget-numbers text-warning">{{ $lockCs }}</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card widget-content bg-white shadow-sm" style="border-left: 3px solid #dc3545;">
                <div class="widget-content-wrapper">
                    <div class="widget-content-left">
                        <div class="widget-heading text-muted">{{ trans('dashboard.status_deactivate') }}</div>
                        <div class="widget-numbers text-danger">{{ $deactivateCs }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- 今日排班 --}}
    <div class="main-card mb-3 card">
        <div class="card-header">
            <i class="header-icon fas fa-calendar-day me-2 text-muted"></i>
            {{ trans('dashboard.today_shift') }}
            <span class="ms-2 badge bg-secondary">{{ now()->format('m/d（D）') }}</span>
        </div>
        <div class="card-body">
            @if($todayByShift->isEmpty())
                <p class="text-muted mb-0">{{ trans('dashboard.today_no_shift') }}</p>
            @else
                <div class="dash-today-shifts">
                    @foreach($todayByShift as $shiftName => $info)
                        @php
                            $isActive = false;
                            if ($info['shift']) {
                                $nowMinutes = now()->hour * 60 + now()->minute;
                                $startParts = explode(':', $info['shift']->start_time);
                                $endParts = explode(':', $info['shift']->end_time);
                                $startMin = (int)$startParts[0] * 60 + (int)$startParts[1];
                                $endMin = (int)$endParts[0] * 60 + (int)$endParts[1];

                                if ($endMin > $startMin) {
                                    $isActive = ($nowMinutes >= $startMin && $nowMinutes < $endMin);
                                } elseif ($endMin <= $startMin) {
                                    $isActive = ($nowMinutes >= $startMin || $nowMinutes < $endMin);
                                }
                            }
                        @endphp
                        <div class="dash-shift-group {{ $isActive ? 'dash-shift-group--active' : '' }}">
                            @if($isActive)
                                <span class="dash-shift-group__badge">{{ trans('dashboard.now_on_duty') }}</span>
                            @endif
                            <div class="dash-shift-group__name">{{ $shiftName }}</div>
                            <div class="dash-shift-group__time">
                                @if($info['shift'])
                                    {{ $info['shift']->start_time }} - {{ $info['shift']->end_time }}
                                @endif
                            </div>
                            <div class="dash-shift-group__users">
                                @foreach($info['users'] as $userName)
                                    <span class="dash-user-chip">{{ $userName }}</span>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- 本週概況 --}}
    <div class="main-card mb-3 card">
        <div class="card-header">
            <i class="header-icon fas fa-chart-bar me-2 text-muted"></i>
            {{ trans('dashboard.week_overview') }}
            <span class="ms-2 badge bg-secondary">{{ trans('dashboard.week_total') }} {{ $weekTotal }}</span>
        </div>
        <div class="card-body p-0">
            @if($weekUserRanking->isEmpty())
                <p class="text-muted p-3 mb-0">{{ trans('dashboard.my_no_shift') }}</p>
            @else
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>{{ trans('dashboard.rank') }}</th>
                                <th>{{ trans('dashboard.field_user') }}</th>
                                <th>{{ trans('dashboard.field_shift_count') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($weekUserRanking as $userName => $count)
                                <tr>
                                    <td>{{ $loop->iteration }}</td>
                                    <td><strong>{{ $userName }}</strong></td>
                                    <td>
                                        <span class="fw-bold" style="font-size:1.125rem">{{ $count }}</span>
                                        <small class="text-muted ms-1">{{ trans('dashboard.week_shift_count') }}</small>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    @else
    {{-- ===== 客服 Dashboard ===== --}}

    <div class="main-card mb-3 card">
        <div class="card-header">
            <i class="header-icon fas fa-calendar-day me-2 text-muted"></i>
            {{ trans('dashboard.today_shift') }}
            <span class="ms-2 badge bg-secondary">{{ now()->format('m/d（D）') }}</span>
        </div>
        <div class="card-body">
            @if($todayByShift->isEmpty())
                <p class="text-muted mb-0">{{ trans('dashboard.today_no_shift') }}</p>
            @else
                <div class="dash-today-shifts">
                    @foreach($todayByShift as $shiftName => $info)
                        @php
                            $isActive = false;
                            if ($info['shift']) {
                                $nowMinutes = now()->hour * 60 + now()->minute;
                                $startParts = explode(':', $info['shift']->start_time);
                                $endParts = explode(':', $info['shift']->end_time);
                                $startMin = (int)$startParts[0] * 60 + (int)$startParts[1];
                                $endMin = (int)$endParts[0] * 60 + (int)$endParts[1];

                                if ($endMin > $startMin) {
                                    $isActive = ($nowMinutes >= $startMin && $nowMinutes < $endMin);
                                } elseif ($endMin <= $startMin) {
                                    $isActive = ($nowMinutes >= $startMin || $nowMinutes < $endMin);
                                }
                            }
                        @endphp
                        <div class="dash-shift-group {{ $isActive ? 'dash-shift-group--active' : '' }}">
                            @if($isActive)
                                <span class="dash-shift-group__badge">{{ trans('dashboard.now_on_duty') }}</span>
                            @endif
                            <div class="dash-shift-group__name">{{ $shiftName }}</div>
                            <div class="dash-shift-group__time">
                                @if($info['shift'])
                                    {{ $info['shift']->start_time }} - {{ $info['shift']->end_time }}
                                @endif
                            </div>
                            <div class="dash-shift-group__users">
                                @foreach($info['users'] as $userName)
                                    <span class="dash-user-chip">{{ $userName }}</span>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div class="main-card mb-3 card">
        <div class="card-header">
            <i class="header-icon fas fa-list-ol me-2 text-muted"></i>
            {{ trans('dashboard.my_week_shift') }}
            <span class="ms-2 badge bg-secondary">{{ trans('dashboard.week_total') }} {{ $weekTotal }}</span>
        </div>
        <div class="card-body p-0">
            @if($weekByDate->isEmpty())
                <p class="text-muted p-3 mb-0">{{ trans('dashboard.my_no_shift') }}</p>
            @else
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>{{ trans('dashboard.field_date') }}</th>
                                <th>{{ trans('dashboard.field_shift') }}</th>
                                <th>{{ trans('dashboard.field_time') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($weekByDate as $dateKey => $info)
                                @if($info['is_allday'])
                                    <tr>
                                        <td>{{ $info['date']->format('m/d（D）') }}</td>
                                        <td><span class="badge bg-success">{{ trans('dashboard.allday') }}</span></td>
                                        <td>-</td>
                                    </tr>
                                @else
                                    @foreach($info['items'] as $a)
                                        <tr>
                                            <td>{{ $a->date->format('m/d（D）') }}</td>
                                            <td>{{ $a->shift ? $a->shift->display_name : '-' }}</td>
                                            <td>
                                                @if($a->shift)
                                                    {{ $a->shift->start_time }} - {{ $a->shift->end_time }}
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
    @endif

@endsection
