@extends('layouts.app')

@section('title', trans('dashboard.page_title'))
@section('subtitle', trans('dashboard.subtitle'))

@section('content')

    @if(Auth::user()->isAdmin())
    {{-- ===== Admin Dashboard ===== --}}

    {{-- 帳號統計 --}}
    <div class="dash-cards">
        <div class="dash-card">
            <div class="dash-card__label">{{ trans('dashboard.total_cs') }}</div>
            <div class="dash-card__value">{{ $totalCs }}</div>
        </div>
        <div class="dash-card dash-card--green">
            <div class="dash-card__label">{{ trans('dashboard.status_normal') }}</div>
            <div class="dash-card__value">{{ $normalCs }}</div>
        </div>
        <div class="dash-card dash-card--yellow">
            <div class="dash-card__label">{{ trans('dashboard.status_lock') }}</div>
            <div class="dash-card__value">{{ $lockCs }}</div>
        </div>
        <div class="dash-card dash-card--red">
            <div class="dash-card__label">{{ trans('dashboard.status_deactivate') }}</div>
            <div class="dash-card__value">{{ $deactivateCs }}</div>
        </div>
    </div>

    {{-- 今日排班（依班別分組，顯示各班別有誰上班） --}}
    <div class="dash-section">
        <h2 class="dash-section__title">{{ trans('dashboard.today_shift') }} <span class="dash-section__date">{{ now()->format('m/d（D）') }}</span></h2>

        @if($todayByShift->isEmpty())
            <p class="dash-empty">{{ trans('dashboard.today_no_shift') }}</p>
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

    {{-- 本週概況（依員工班次數排名） --}}
    <div class="dash-section">
        <h2 class="dash-section__title">{{ trans('dashboard.week_overview') }} <span class="dash-section__date">{{ trans('dashboard.week_total') }} {{ $weekTotal }}</span></h2>

        @if($weekUserRanking->isEmpty())
            <p class="dash-empty">{{ trans('dashboard.my_no_shift') }}</p>
        @else
            <table>
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
                            <td>{{ $userName }}</td>
                            <td><span class="badge badge--active">{{ $count }} {{ trans('dashboard.week_shift_count') }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @else
    {{-- ===== 客服 Dashboard ===== --}}

    <div class="dash-section">
        <h2 class="dash-section__title">{{ trans('dashboard.today_shift') }} <span class="dash-section__date">{{ now()->format('m/d（D）') }}</span></h2>

        @if($todayByShift->isEmpty())
            <p class="dash-empty">{{ trans('dashboard.today_no_shift') }}</p>
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

    <div class="dash-section">
        <h2 class="dash-section__title">{{ trans('dashboard.my_week_shift') }} <span class="dash-section__date">{{ trans('dashboard.week_total') }} {{ $weekTotal }}</span></h2>

        @if($weekByDate->isEmpty())
            <p class="dash-empty">{{ trans('dashboard.my_no_shift') }}</p>
        @else
            <table>
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
                                <td><span class="badge badge--active">{{ trans('dashboard.allday') }}</span></td>
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
        @endif
    </div>
    @endif

@endsection
