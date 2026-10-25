@extends('layouts.app')

@section('title', trans('attendance.detail_title'))
@section('icon', 'clock')
@section('subtitle', (isset($targetUser) ? $targetUser->nickname . ' — ' : '') . trans('attendance.detail_subtitle'))

@section('content')

    <div class="mb-3 d-flex align-items-center gap-2">
        {{-- 這一頁現在只從報表頁的列點進來，所以返回也回報表頁 --}}
        <a href="{{ route('admin.report.index') }}" class="btn btn-outline-secondary btn-sm">
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

    @php
        // 建立補打卡 lookup: date => [type1, type2]
        $amendLookup = [];
        foreach ($amendments ?? [] as $a) {
            $dateKey = $a->date->format('Y-m-d');
            if (!isset($amendLookup[$dateKey])) { $amendLookup[$dateKey] = []; }
            $amendLookup[$dateKey][] = $a->type;
        }
    @endphp

    {{-- 每日明細 --}}
    <div class="main-card mb-3 card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle data-table">
                    <thead class="thead-gold">
                        <tr>
                            <th>{{ trans('attendance.field_date') }}</th>
                            <th>{{ trans('attendance.field_shift') }}</th>
                            <th>{{ trans('attendance.field_clock_in') }}</th>
                            <th>{{ trans('attendance.field_late') }}</th>
                            <th>{{ trans('attendance.field_early_clock_in') }}</th>
                            <th>{{ trans('attendance.field_clock_out') }}</th>
                            <th>{{ trans('attendance.field_early_leave') }}</th>
                            <th>{{ trans('attendance.field_late_clock_out') }}</th>
                            <th>{{ trans('attendance.field_status') }}</th>
                            <th>{{ trans('attendance.field_leave') }}</th>
                            <th>{{ trans('attendance.field_ip') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($timeline as $row)
                            @php
                                /*
                                 * ⚠ 跑的是**時間軸**不是出勤紀錄：每一天都有一列，
                                 * 沒有紀錄的那天 `$r` 會是 null（見
                                 * AttendanceService::getMonthlyTimeline）。
                                 *
                                 * 最常見的就是請整天假 —— 那天本來就不會有出勤紀錄，
                                 * 以前整列直接消失，看起來像漏掉了一天。
                                 */
                                $r = $row['record'];
                                $rowDate = $row['date'];
                                $leave = $row['leave'];
                            @endphp

                            @if(blank($r))
                                {{-- 那天沒有出勤紀錄：請假，或本來就沒班 --}}
                                <tr class="text-muted">
                                    <td>{{ \App\Presenters\DatePresenter::withWeekday($rowDate) }}</td>
                                    <td>-</td>
                                    <td>-</td>
                                    <td>-</td>
                                    <td>-</td>
                                    <td>-</td>
                                    <td>-</td>
                                    <td>-</td>
                                    <td>
                                        @if(filled($leave))
                                            <span class="badge bg-info">{{ trans('attendance.status_on_leave') }}</span>
                                        @else
                                            {{--
                                                ⚠ **只有固定班才有「休假」**（需求方 2026-10-26）。
                                                輪班的人沒有固定的休假日，也不放國定假日 ——
                                                那天沒有紀錄只代表「這天沒排到班」。
                                                一律標「休假」會讓人以為他那天本來就該休。
                                            --}}
                                            @if(filled($targetUser) && $targetUser->isFixedWorkMode())
                                                <span class="badge bg-secondary">{{ trans('attendance.status_day_off') }}</span>
                                            @else
                                                <span class="badge bg-light text-muted">{{ trans('attendance.status_no_shift') }}</span>
                                            @endif
                                        @endif
                                    </td>
                                    <td>
                                        @if(filled($leave))
                                            @if($leave['is_full_day'] === 1)
                                                <span class="badge bg-info">{{ trans('attendance.leave_full_day') }}</span>
                                            @else
                                                <span class="badge bg-info">{{ substr($leave['start_time'], 0, 5) }} ~ {{ substr($leave['end_time'], 0, 5) }}</span>
                                            @endif
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td>-</td>
                                </tr>
                                @continue
                            @endif

                            @php
                                /*
                                 * 那天本來幾點上下班。輪班來自排班、固定班來自帳號設定，
                                 * 兩種都由 Controller 算好（AttendanceService::workTimesForRecords）——
                                 * 這裡不要再從 $r->assignment->shift 取，固定班沒有那個關聯。
                                 */
                                $work = $workTimes[$r->date->format('Y-m-d')] ?? null;
                                $shiftLabel = '-';

                                if (filled($work)) {
                                    $shiftLabel = Arr::get($work, 'is_fixed')
                                        ? trans('shift.fixed_shift_name') . ' ' . substr(Arr::get($work, 'start'), 0, 5) . '–' . substr(Arr::get($work, 'end'), 0, 5)
                                        : (filled($r->assignment) && filled($r->assignment->shift) ? $r->assignment->shift->display_name : '-');
                                }
                            @endphp
                            <tr>
                                <td>{{ \App\Presenters\DatePresenter::withWeekday($r->date) }}</td>
                                <td>{{ $shiftLabel }}</td>
                                <td>
                                    {{ $r->clock_in ? $r->clock_in->format('H:i:s') : '-' }}
                                    @if(in_array(1, $amendLookup[$r->date->format('Y-m-d')] ?? []))
                                        <span class="badge bg-primary" style="font-size:0.625rem">補</span>
                                    @endif
                                </td>
                                <td>
                                    @if($r->late_minutes > 0)
                                        <span class="text-danger fw-bold">{{ $r->late_minutes }} {{ trans('attendance.unit_minutes') }}</span>
                                    @else - @endif
                                </td>
                                <td>
                                    @php
                                        // 標準上班時間已經由 $work 給了（含固定班），不必再認 assignment
                                        $earlyClockIn = 0;
                                        if (filled($r->clock_in) && filled($work)) {
                                            $clockInMin = $r->clock_in->hour * 60 + $r->clock_in->minute;
                                            $earlyClockIn = max((int) Arr::get($work, 'start_minutes') - $clockInMin, 0);
                                        }
                                    @endphp
                                    @if($earlyClockIn > 0)
                                        <span class="text-success fw-bold">{{ $earlyClockIn }} {{ trans('attendance.unit_minutes') }}</span>
                                    @else - @endif
                                </td>
                                <td>
                                    {{ $r->clock_out ? $r->clock_out->format('H:i:s') : '-' }}
                                    @if(in_array(2, $amendLookup[$r->date->format('Y-m-d')] ?? []))
                                        <span class="badge bg-primary" style="font-size:0.625rem">補</span>
                                    @endif
                                </td>
                                <td>
                                    @if($r->early_leave_minutes > 0)
                                        <span class="text-danger fw-bold">{{ $r->early_leave_minutes }} {{ trans('attendance.unit_minutes') }}</span>
                                    @else - @endif
                                </td>
                                <td>
                                    @php
                                        /*
                                         * `end_minutes` 已經把跨日加好了（到午夜的 00:00 算 1440、
                                         * 固定班的 06:00 算 1800），這裡不必再認哪種寫法代表跨日。
                                         */
                                        $lateClockOut = 0;
                                        if (filled($r->clock_out) && filled($work)) {
                                            $clockOutMin = $r->clock_out->hour * 60 + $r->clock_out->minute;
                                            if ($r->clock_out->format('Y-m-d') !== $r->date->format('Y-m-d')) {
                                                $clockOutMin += 1440;
                                            }
                                            $lateClockOut = max($clockOutMin - (int) Arr::get($work, 'end_minutes'), 0);
                                        }
                                    @endphp
                                    @if($lateClockOut > 0)
                                        <span class="text-success fw-bold">{{ $lateClockOut }} {{ trans('attendance.unit_minutes') }}</span>
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
                                <td>
                                    @if(filled($r->leave_info))
                                        @if($r->leave_info['is_full_day'] === 1)
                                            <span class="badge bg-info">{{ trans('attendance.leave_full_day') }}</span>
                                        @else
                                            {{-- 砍掉秒數：請假時間的秒一律是 00，印出來只是讓欄位變寬 --}}
                                            <span class="badge bg-info">{{ substr($r->leave_info['start_time'], 0, 5) }} ~ {{ substr($r->leave_info['end_time'], 0, 5) }}</span>
                                        @endif
                                    @else
                                        -
                                    @endif
                                </td>
                                <td><small class="text-muted">{{ $r->clock_in_ip ?? '-' }}</small></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="text-center text-muted py-4">暫無資料</td>
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
