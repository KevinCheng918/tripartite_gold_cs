@extends('layouts.app')

@section('title', trans('attendance.detail_title'))
@section('icon', 'clock')
@section('subtitle', trans('attendance.detail_subtitle'))

@section('content')

    <div class="mb-3 d-flex align-items-center gap-2">
        <a href="{{ route('admin.attendance.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i>{{ trans('attendance.back_to_report') }}
        </a>
        <form method="GET" class="d-inline-flex align-items-center gap-2">
            <input type="text" name="month" id="detail-month-picker" class="form-control form-control-sm" value="{{ $yearMonth }}" readonly style="width:140px;cursor:pointer">
        </form>
    </div>

    {{-- 統計卡片 --}}
    @php
        $totalDays = $records->count();
        $normalDays = $records->where('status', 1)->count();
        $lateCnt = $records->whereIn('status', [2, 4])->count();
        $earlyCnt = $records->whereIn('status', [3, 4])->count();
        $absentCnt = $records->where('status', 5)->count();
        $lateMin = $records->sum('late_minutes');
        $earlyMin = $records->sum('early_leave_minutes');
        $otMin = $records->sum('overtime_minutes');
    @endphp
    <div class="row row-cols-3 row-cols-md-6 g-3 mb-3">
        <div class="col">
            <div class="card shadow-sm text-center py-3">
                <div class="small text-muted">{{ trans('attendance.field_total_days') }}</div>
                <div class="h4 mb-0 fw-light">{{ $totalDays }}</div>
                <div class="small text-muted">&nbsp;</div>
            </div>
        </div>
        <div class="col">
            <div class="card shadow-sm text-center py-3" style="border-left:3px solid #198754">
                <div class="small text-muted">{{ trans('attendance.field_normal_days') }}</div>
                <div class="h4 mb-0 fw-light text-success">{{ $normalDays }}</div>
                <div class="small text-muted">&nbsp;</div>
            </div>
        </div>
        <div class="col">
            <div class="card shadow-sm text-center py-3" style="border-left:3px solid #dc3545">
                <div class="small text-muted">{{ trans('attendance.field_late_count') }}</div>
                <div class="h4 mb-0 fw-light text-danger">{{ $lateCnt }}</div>
                <div class="small text-muted">{{ $lateMin }} {{ trans('attendance.unit_minutes') }}</div>
            </div>
        </div>
        <div class="col">
            <div class="card shadow-sm text-center py-3" style="border-left:3px solid #ffc107">
                <div class="small text-muted">{{ trans('attendance.field_early_count') }}</div>
                <div class="h4 mb-0 fw-light text-warning">{{ $earlyCnt }}</div>
                <div class="small text-muted">{{ $earlyMin }} {{ trans('attendance.unit_minutes') }}</div>
            </div>
        </div>
        <div class="col">
            <div class="card shadow-sm text-center py-3" style="border-left:3px solid #dc3545">
                <div class="small text-muted">{{ trans('attendance.field_absent_count') }}</div>
                <div class="h4 mb-0 fw-light text-danger">{{ $absentCnt }}</div>
                <div class="small text-muted">&nbsp;</div>
            </div>
        </div>
        <div class="col-md-2 col-4">
            <div class="card shadow-sm text-center py-3" style="border-left:3px solid #198754">
                <div class="small text-muted">{{ trans('attendance.field_overtime_total') }}</div>
                <div class="h4 mb-0 fw-light text-success">{{ $otMin }}</div>
                <div class="small text-muted">{{ trans('attendance.unit_minutes') }}</div>
            </div>
        </div>
    </div>

    {{-- 每日明細 --}}
    <div class="main-card mb-3 card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>{{ trans('attendance.field_date') }}</th>
                            <th>{{ trans('attendance.field_clock_in') }}</th>
                            <th>{{ trans('attendance.field_clock_out') }}</th>
                            <th>{{ trans('attendance.field_late') }}</th>
                            <th>{{ trans('attendance.field_early_leave') }}</th>
                            <th>{{ trans('attendance.field_overtime') }}</th>
                            <th>{{ trans('attendance.field_status') }}</th>
                            <th>{{ trans('attendance.field_ip') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($records as $r)
                            <tr>
                                <td>{{ $r->date->format('Y-m-d') }}</td>
                                <td>{{ $r->clock_in ? $r->clock_in->format('H:i:s') : '-' }}</td>
                                <td>{{ $r->clock_out ? $r->clock_out->format('H:i:s') : '-' }}</td>
                                <td>
                                    @if($r->late_minutes > 0)
                                        <span class="text-danger fw-bold">{{ $r->late_minutes }} {{ trans('attendance.unit_minutes') }}</span>
                                    @else - @endif
                                </td>
                                <td>
                                    @if($r->early_leave_minutes > 0)
                                        <span class="text-danger fw-bold">{{ $r->early_leave_minutes }} {{ trans('attendance.unit_minutes') }}</span>
                                    @else - @endif
                                </td>
                                <td>
                                    @if($r->overtime_minutes > 0)
                                        <span class="text-success fw-bold">{{ $r->overtime_minutes }} {{ trans('attendance.unit_minutes') }}</span>
                                    @else - @endif
                                </td>
                                <td>
                                    @switch($r->status)
                                        @case(0) <span class="badge bg-warning text-dark">{{ trans('attendance.status_incomplete') }}</span> @break
                                        @case(1) <span class="badge bg-success">{{ trans('attendance.status_normal') }}</span> @break
                                        @case(2) <span class="badge bg-danger">{{ trans('attendance.status_late') }}</span> @break
                                        @case(3) <span class="badge bg-warning text-dark">{{ trans('attendance.status_early_leave') }}</span> @break
                                        @case(4) <span class="badge bg-danger">{{ trans('attendance.status_late_early') }}</span> @break
                                        @case(5) <span class="badge bg-danger">{{ trans('attendance.status_absent') }}</span> @break
                                    @endswitch
                                </td>
                                <td><small class="text-muted">{{ $r->clock_in_ip ?? '-' }}</small></td>
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
    </div>

@endsection

@section('scripts')
<script src="{{ asset('vendor/flatpickr/flatpickr.min.js') }}"></script>
<script src="{{ asset('vendor/flatpickr/zh-tw.js') }}"></script>
<script src="{{ asset('vendor/flatpickr/monthSelect.js') }}"></script>
<script>
flatpickr.localize(flatpickr.l10ns.zh_tw);
flatpickr('#detail-month-picker', {
    plugins: [new monthSelectPlugin({ shorthand: true, dateFormat: 'Y-m', altFormat: 'Y-m' })],
    disableMobile: true,
    onChange: function (selectedDates) {
        if (selectedDates.length === 0) { return; }
        var d = selectedDates[0];
        var month = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
        window.location.href = '?month=' + month;
    }
});
</script>
@endsection
