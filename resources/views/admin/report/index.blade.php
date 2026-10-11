@extends('layouts.app')

@section('title', trans('report.page_title'))
@section('icon', 'chart-bar')
@section('subtitle', trans('report.subtitle'))

@section('content')

    @php
        $canAttendance = Auth::user()->hasPermission('report.attendance');
        $canRemind = Auth::user()->hasPermission('report.remind');
    @endphp

    {{-- 權限與語系由 data-* 帶給 public/js/report.js --}}
    <div id="report-app"
         {{-- JSON_HEX_APOS：屬性用單引號包，JSON 內的單引號（英文縮寫）會提早結束屬性 --}}
         data-i18n='@json(trans("report"), JSON_HEX_APOS | JSON_HEX_QUOT)'
         data-can-attendance="{{ $canAttendance ? '1' : '0' }}"
         data-can-remind="{{ $canRemind ? '1' : '0' }}"
         {{-- 報表列要連到出勤明細，那一頁由 attendance.report 管；
              只勾 report.attendance 的人看得到彙總但點不進去 --}}
         data-can-detail="{{ Auth::user()->hasPermission('attendance.report') ? '1' : '0' }}">

        <ul class="nav nav-tabs mb-3" role="tablist">
            @if($canAttendance)
            <li class="nav-item">
                <a class="nav-link active" data-bs-toggle="tab" href="#tab-report-attendance" role="tab">
                    <i class="fas fa-user-clock me-1"></i>{{ trans('report.tab_attendance') }}
                </a>
            </li>
            @endif
            @if($canRemind)
            <li class="nav-item">
                <a class="nav-link {{ $canAttendance ? '' : 'active' }}" data-bs-toggle="tab" href="#tab-report-remind" role="tab">
                    <i class="fas fa-bell me-1"></i>{{ trans('report.tab_remind') }}
                </a>
            </li>
            @endif
        </ul>

        <div class="tab-content">
            @if($canAttendance)
            <div class="tab-pane fade show active" id="tab-report-attendance" role="tabpanel">
                <div class="main-card mb-3 card">
                    <div class="card-header d-flex flex-wrap align-items-center gap-2">
                        {{-- 期間切換。打卡報表沒有「日」—— 一天的出勤看打卡出勤頁就好 --}}
                        <div class="btn-group btn-group-sm period-switch" role="group" aria-label="{{ trans('report.page_title') }}">
                            <button type="button" class="btn btn-outline-secondary btn-icon btn-transition active js-att-type" data-type="weekly">
                                {{ trans('report.period_weekly') }}
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-icon btn-transition js-att-type" data-type="monthly">
                                {{ trans('report.period_monthly') }}
                            </button>
                        </div>
                        <div class="row-actions ms-auto">
                            <div class="btn-group btn-group-sm" role="group" aria-label="{{ trans('report.prev') }}">
                                <button type="button" class="btn btn-outline-secondary btn-icon btn-transition js-att-prev">
                                    <i class="fas fa-chevron-left btn-icon-wrapper"></i>{{ trans('report.prev') }}
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-icon btn-transition js-att-next">
                                    {{ trans('report.next') }}<i class="fas fa-chevron-right ms-1"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <h5 class="card-title" id="att-range"></h5>
                        <div class="table-responsive" id="att-table"></div>
                        <p class="text-muted mb-0 mt-2" style="font-size:0.875rem" id="att-hint"></p>
                    </div>
                </div>
            </div>
            @endif

            @if($canRemind)
            <div class="tab-pane fade {{ $canAttendance ? '' : 'show active' }}" id="tab-report-remind" role="tabpanel">
                <div class="main-card mb-3 card">
                    <div class="card-header d-flex flex-wrap align-items-center gap-2">
                        <div class="btn-group btn-group-sm period-switch" role="group" aria-label="{{ trans('report.page_title') }}">
                            <button type="button" class="btn btn-outline-secondary btn-icon btn-transition active js-rmd-type" data-type="daily">
                                {{ trans('report.period_daily') }}
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-icon btn-transition js-rmd-type" data-type="weekly">
                                {{ trans('report.period_weekly') }}
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-icon btn-transition js-rmd-type" data-type="monthly">
                                {{ trans('report.period_monthly') }}
                            </button>
                        </div>
                        <div class="row-actions ms-auto">
                            <div class="btn-group btn-group-sm" role="group" aria-label="{{ trans('report.prev') }}">
                                <button type="button" class="btn btn-outline-secondary btn-icon btn-transition js-rmd-prev">
                                    <i class="fas fa-chevron-left btn-icon-wrapper"></i>{{ trans('report.prev') }}
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-icon btn-transition js-rmd-next">
                                    {{ trans('report.next') }}<i class="fas fa-chevron-right ms-1"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <h5 class="card-title" id="rmd-range"></h5>
                        <p class="mb-3" id="rmd-summary"></p>
                        <div id="rmd-body"></div>
                    </div>
                </div>
            </div>
            @endif
        </div>
    </div>

@endsection

@section('scripts')
<script src="{{ asset('js/report.js') }}?v={{ filemtime(public_path('js/report.js')) }}"></script>
@endsection
