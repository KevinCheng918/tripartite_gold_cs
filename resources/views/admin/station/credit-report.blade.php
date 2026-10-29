@extends('layouts.app')

@section('title', trans('station.credit_report.page_title'))
@section('icon', 'chart-line')
@section('subtitle', $station->name)

@section('content')

    @php
        /*
         * 日期快捷鈕。key 是 window.DateRange 的方法名（common.js），
         * value 是 common.date_range 的語系 key —— 跟補點紀錄、報表頁同一組，
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

    <div id="credit-report-app"
         {{-- JSON_HEX_APOS：屬性用單引號包，JSON 內的單引號會提早結束屬性 --}}
         data-i18n='@json(trans("station.credit_report"), JSON_HEX_APOS | JSON_HEX_QUOT)'
         data-source-labels='@json($sourceLabels, JSON_HEX_APOS | JSON_HEX_QUOT)'
         data-url="{{ route('admin.stations.ajax-credit-report', $station->id) }}">

        <div class="main-card mb-3 card">
            <div class="card-body">
                <div class="row g-2 mb-3">
                    <div class="col-md-3 col-6">
                        <label class="form-label fw-bold" for="cr-date-from">{{ trans('station.credit_report.date_from') }}：</label>
                        <input type="date" class="form-control" id="cr-date-from">
                    </div>
                    <div class="col-md-3 col-6">
                        <label class="form-label fw-bold" for="cr-date-to">{{ trans('station.credit_report.date_to') }}：</label>
                        <input type="date" class="form-control" id="cr-date-to">
                    </div>
                    <div class="col-md-3 col-6 d-flex align-items-end">
                        <button type="button" class="btn btn-primary w-100" id="cr-search">
                            <i class="fas fa-search me-1"></i>{{ trans('station.credit_report.search') }}
                        </button>
                    </div>
                    {{-- gap-2 而不是 gap-1：六顆小按鈕排在一起，0.25rem 會黏成一條 --}}
                    <div class="col-12 mt-2">
                        <div class="d-flex flex-wrap gap-2">
                            @foreach($dateRanges as $range => $langKey)
                                <button type="button" class="btn btn-sm btn-outline-secondary js-cr-range"
                                        data-range="{{ $range }}">{{ trans("common.date_range.{$langKey}") }}</button>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="row g-2 mb-3" id="cr-summary"></div>

                {{-- Chart.js 需要固定高度的容器，不然 responsive 會一路長高 --}}
                <div style="position:relative;height:360px">
                    <canvas id="cr-chart"></canvas>
                </div>

                <p class="text-muted mb-0 mt-3" style="font-size:0.875rem">
                    {{ trans('station.credit_report.chart_hint') }}
                </p>
            </div>
        </div>

        {{-- 同步失敗的那幾筆：曲線上是斷點，這裡才列得出來 --}}
        <div class="main-card mb-3 card" id="cr-failure-card" style="display:none">
            <div class="card-body">
                <h5 class="card-title text-warning">
                    <i class="fas fa-exclamation-triangle me-1"></i>{{ trans('station.credit_report.failure_title') }}
                </h5>
                <p class="text-muted" style="font-size:0.875rem">{{ trans('station.credit_report.failure_hint') }}</p>
                <div class="table-responsive" id="cr-failures"></div>
            </div>
        </div>

        {{-- 訊息一律走 modal，不用 alert --}}
        <div class="modal fade" id="modal-cr-msg" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-body"></div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

@endsection

{{-- ⚠ layout 用的是 @yield('scripts') 不是 @stack，寫成 @push 會整段不出現 --}}
@section('scripts')
    {{-- 跟儀表板的匯率圖同一個版本，不另外引一套 --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    {{-- ⚠ `?v=filemtime` 是這個專案所有 JS/CSS 的慣例，**不可以漏**：
         漏掉的話改完 JS 使用者的瀏覽器還是跑舊的那一份，而且畫面有一半
         是新的（語系從後端渲染進 data-i18n）、一半是舊的，很難判斷。
         2026-10-30 報的「報表沒顯示跟上一點的差值」就是漏了這個。 --}}
    <script src="{{ asset('js/station-credit-report.js') }}?v={{ filemtime(public_path('js/station-credit-report.js')) }}"></script>
@endsection
