@extends('layouts.app')

@section('title', trans('report.page_title'))
@section('icon', 'chart-bar')
@section('subtitle', trans('report.subtitle'))

@section('content')

    @php
        $canAttendance = Auth::user()->hasPermission('report.attendance');
        $canRemind = Auth::user()->hasPermission('report.remind');

        /*
         * 日期快捷鈕。key 是 window.DateRange 的方法名（common.js），
         * value 是 common.date_range 的語系 key —— 跟補點紀錄用同一組，
         * 「上週」怎麼算只有一份定義。
         */
        $dateRanges = [
            'today'     => 'today',
            'yesterday' => 'yesterday',
            'thisWeek'  => 'this_week',
            'lastWeek'  => 'last_week',
            'thisMonth' => 'this_month',
            'lastMonth' => 'last_month',
        ];
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
                    <div class="card-body">
                        {{-- 期間：起訖日期 + 快捷鈕，版面比照補點紀錄 --}}
                        <div class="row g-3 mb-3">
                            <div class="col-md-3 col-6">
                                <label class="form-label fw-bold" for="att-date-from">{{ trans('report.date_from') }}：</label>
                                <input type="date" class="form-control" id="att-date-from">
                            </div>
                            <div class="col-md-3 col-6">
                                <label class="form-label fw-bold" for="att-date-to">{{ trans('report.date_to') }}：</label>
                                <input type="date" class="form-control" id="att-date-to">
                            </div>
                            <div class="col-md-3 col-6 d-flex align-items-end">
                                <button type="button" class="btn btn-primary w-100 js-att-search">
                                    <i class="fas fa-search me-1"></i>{{ trans('report.search') }}
                                </button>
                            </div>
                            {{-- gap-2 而不是 gap-1：六顆小按鈕排在一起，0.25rem 會黏成一條 --}}
                            <div class="col-12 mt-2">
                                <div class="d-flex flex-wrap gap-2">
                                    @foreach($dateRanges as $range => $langKey)
                                        <button type="button" class="btn btn-sm btn-outline-secondary js-att-range"
                                                data-range="{{ $range }}">{{ trans("common.date_range.{$langKey}") }}</button>
                                    @endforeach
                                </div>
                            </div>
                        </div>

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
                    <div class="card-body">
                        <div class="row g-3 mb-3">
                            <div class="col-md-3 col-6">
                                <label class="form-label fw-bold" for="rmd-date-from">{{ trans('report.date_from') }}：</label>
                                <input type="date" class="form-control" id="rmd-date-from">
                            </div>
                            <div class="col-md-3 col-6">
                                <label class="form-label fw-bold" for="rmd-date-to">{{ trans('report.date_to') }}：</label>
                                <input type="date" class="form-control" id="rmd-date-to">
                            </div>
                            <div class="col-md-3 col-6 d-flex align-items-end">
                                <button type="button" class="btn btn-primary w-100 js-rmd-search">
                                    <i class="fas fa-search me-1"></i>{{ trans('report.search') }}
                                </button>
                            </div>
                            <div class="col-12 mt-2">
                                <div class="d-flex flex-wrap gap-2">
                                    @foreach($dateRanges as $range => $langKey)
                                        <button type="button" class="btn btn-sm btn-outline-secondary js-rmd-range"
                                                data-range="{{ $range }}">{{ trans("common.date_range.{$langKey}") }}</button>
                                    @endforeach
                                </div>
                            </div>
                        </div>

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
