@extends('layouts.app')

@section('title', trans('dashboard.page_title'))
@section('subtitle', trans('dashboard.subtitle'))
@section('icon', 'tachometer-alt')

@section('content')

    {{-- USDT 匯率（有權限即可看） --}}
    @if(Auth::user()->hasPermission('dashboard.usdt_rate'))
    {{-- USDT 匯率 --}}
    <div class="main-card mb-4 card">
        <div class="card-header d-flex justify-content-between align-items-center py-2">
            <strong><i class="fas fa-chart-line me-2 text-muted"></i>USDT/TWD 匯率</strong>
            <div class="d-flex align-items-center gap-2">
                <small class="text-muted" id="rate-updated-at"></small>
                <button class="btn btn-sm btn-outline-secondary" id="btn-refresh-rate" title="重新整理">
                    <i class="fas fa-sync-alt"></i>
                </button>
            </div>
        </div>
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-4 mb-3 mb-md-0">
                    <div class="text-center">
                        <div class="text-muted mb-1">即時匯率</div>
                        <div id="rate-current" style="font-size:2rem;font-weight:700;color:#a67c00">-</div>
                    </div>
                    <div class="d-flex justify-content-center gap-3 mt-2">
                        <div class="text-center">
                            <small class="text-muted">4H 最高</small>
                            <div id="rate-high-4h" class="fw-bold text-danger">-</div>
                        </div>
                        <div class="text-center">
                            <small class="text-muted">4H 均價</small>
                            <div id="rate-avg" class="fw-bold">-</div>
                        </div>
                        <div class="text-center">
                            <small class="text-muted">4H 最低</small>
                            <div id="rate-low-4h" class="fw-bold text-success">-</div>
                        </div>
                    </div>
                    <div class="d-flex justify-content-center gap-3 mt-2">
                        <div class="text-center">
                            <small class="text-muted">24H 最高</small>
                            <div id="rate-high-day" class="fw-bold text-danger">-</div>
                        </div>
                        <div class="text-center">
                            <small class="text-muted">24H 最低</small>
                            <div id="rate-low-day" class="fw-bold text-success">-</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-8">
                    <canvas id="rate-chart" height="280"></canvas>
                </div>
            </div>
        </div>
    </div>
    @endif

    @if(Auth::user()->hasPermission('shift.view'))
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

    @endif {{-- end shift.view for admin --}}

    @if(Auth::user()->hasPermission('shift.view') && !Auth::user()->isAdmin())
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
                                <tr>
                                    <td>{{ $info['date']->format('m/d（D）') }}</td>
                                    @if($info['is_allday'])
                                        <td><span class="badge bg-success">{{ trans('dashboard.allday') }}</span></td>
                                        <td>-</td>
                                    @else
                                        <td>
                                            @foreach($info['items'] as $a)
                                                @if($a->shift)
                                                    <span class="badge bg-secondary me-1">{{ $a->shift->display_name }}</span>
                                                @endif
                                            @endforeach
                                        </td>
                                        <td>
                                            @foreach($info['items'] as $a)
                                                @if($a->shift)
                                                    <div>{{ $a->shift->start_time }} - {{ $a->shift->end_time }}</div>
                                                @endif
                                            @endforeach
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
    @endif {{-- end shift.view for cs --}}

@endsection

@section('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
$(function () {
    var csrfToken = $('meta[name="csrf-token"]').attr('content');
    var rateChart = null;

    function loadRate() {
        var $btn = $('#btn-refresh-rate');
        $btn.find('i').addClass('fa-spin');
        $btn.prop('disabled', true);

        $.ajax({
            url: '/admin/dashboard/ajax-usdt-rate',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            success: function (data) {
                $btn.find('i').removeClass('fa-spin');
                $btn.prop('disabled', false);

                $('#rate-current').text(data.current_rate ? data.current_rate.toFixed(3) : '-');
                $('#rate-avg').text(data.avg_rate ? data.avg_rate.toFixed(3) : '-');
                $('#rate-high-4h').text(data.high_4h ? data.high_4h.toFixed(3) : '-');
                $('#rate-low-4h').text(data.low_4h ? data.low_4h.toFixed(3) : '-');
                $('#rate-high-day').text(data.high_day ? data.high_day.toFixed(3) : '-');
                $('#rate-low-day').text(data.low_day ? data.low_day.toFixed(3) : '-');
                $('#rate-updated-at').text(data.updated_at || '');

                rateInfo = data;
                renderChart(data.chart || []);
            },
            error: function (xhr) {
                $btn.find('i').removeClass('fa-spin');
                $btn.prop('disabled', false);
                var msg = (xhr.responseJSON && xhr.responseJSON.message) || '取得失敗';
                $('#rate-current').text(msg).css('font-size', '1rem');
            }
        });
    }

    var rateInfo = {};

    function makeLine(labels, value, label, color, dash) {
        return {
            label: label,
            data: labels.map(function () { return value; }),
            borderColor: color,
            borderWidth: 1.5,
            borderDash: dash || [],
            pointRadius: 0,
            pointHoverRadius: 0,
            fill: false,
            tension: 0,
        };
    }

    function renderChart(chartData) {
        var labels = chartData.map(function (d) { return d.time; });
        var prices = chartData.map(function (d) { return d.price; });

        var ctx = document.getElementById('rate-chart');
        if (!ctx) { return; }

        if (rateChart) { rateChart.destroy(); }

        var datasets = [{
            label: '即時匯率',
            data: prices,
            borderColor: '#a67c00',
            backgroundColor: 'rgba(166,124,0,0.1)',
            fill: true,
            tension: 0.3,
            pointRadius: 2,
            pointHoverRadius: 5,
            borderWidth: 2,
        }];

        // 水平標記線
        if (rateInfo.high_4h) { datasets.push(makeLine(labels, rateInfo.high_4h, '4H 最高', '#dc3545', [])); }
        if (rateInfo.low_4h) { datasets.push(makeLine(labels, rateInfo.low_4h, '4H 最低', '#198754', [])); }
        if (rateInfo.high_day) { datasets.push(makeLine(labels, rateInfo.high_day, '24H 最高', '#e85d04', [6, 4])); }
        if (rateInfo.low_day) { datasets.push(makeLine(labels, rateInfo.low_day, '24H 最低', '#0ea5e9', [6, 4])); }
        if (rateInfo.avg_rate) { datasets.push(makeLine(labels, rateInfo.avg_rate, '4H 均價', '#6f42c1', [2, 2])); }

        rateChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom',
                        labels: { boxWidth: 20, font: { size: 11 } }
                    },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) { return ctx.dataset.label + ' ' + ctx.parsed.y.toFixed(3); }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 11 }, maxTicksLimit: 8 }
                    },
                    y: {
                        grid: { color: 'rgba(0,0,0,0.05)' },
                        ticks: {
                            font: { size: 11 },
                            callback: function (v) { return v.toFixed(3); }
                        }
                    }
                }
            }
        });
    }

    $('#btn-refresh-rate').on('click', function () { loadRate(); });

    // 初始載入
    loadRate();
});
</script>
@endsection
