@extends('layouts.app')

@section('title', trans('followup.page_title'))
@section('icon', 'hourglass-half')
@section('subtitle', trans('followup.subtitle'))

@section('content')

    {{-- 權限與語系由 data-* 帶給 public/js/followup.js --}}
    <div id="followup-app"
         {{-- JSON_HEX_APOS：屬性用單引號包，JSON 內的單引號（英文縮寫）會提早結束屬性 --}}
         data-i18n='@json(trans("followup"), JSON_HEX_APOS | JSON_HEX_QUOT)'
         data-sources='@json(config("constants.FOLLOWUP.SOURCE_LABEL"), JSON_HEX_APOS | JSON_HEX_QUOT)'
         data-statuses='@json(config("constants.FOLLOWUP.STATUS_LABEL"), JSON_HEX_APOS | JSON_HEX_QUOT)'
         data-customer-source="{{ config('constants.FOLLOWUP.SOURCE.CUSTOMER') }}"
         data-can-manage="{{ Auth::user()->hasPermission('followup.manage') ? '1' : '0' }}">

        <div class="main-card mb-3 card">
            <div class="card-body">
                <p class="text-muted" style="font-size:0.875rem">{{ trans('followup.desc') }}</p>

                {{-- 篩選 --}}
                <div class="row g-2 align-items-end">
                    <div class="col-md-3 col-6">
                        <label class="form-label" for="followup-filter-source">{{ trans('followup.filter_source') }}</label>
                        <select class="form-select" id="followup-filter-source"></select>
                    </div>
                    <div class="col-md-3 col-6">
                        <label class="form-label" for="followup-filter-status">{{ trans('followup.filter_status') }}</label>
                        <select class="form-select" id="followup-filter-status"></select>
                    </div>
                    {{-- 期間比的是開單日期（created_at）。
                         留白＝不限期間，所以一進來看得到全部 --}}
                    <div class="col-md-3 col-6">
                        <label class="form-label" for="followup-date-from">{{ trans('followup.filter_date_from') }}</label>
                        <input type="date" class="form-control" id="followup-date-from">
                    </div>
                    <div class="col-md-3 col-6">
                        <label class="form-label" for="followup-date-to">{{ trans('followup.filter_date_to') }}</label>
                        <input type="date" class="form-control" id="followup-date-to">
                    </div>

                    {{-- 快捷鈕走共用的 window.DateRange（common.js），
                         「本週」怎麼算全站只有一份定義 --}}
                    <div class="col-12 mt-2">
                        <div class="d-flex flex-wrap gap-2">
                            @foreach(['today' => 'today', 'yesterday' => 'yesterday', 'thisWeek' => 'this_week', 'lastWeek' => 'last_week', 'thisMonth' => 'this_month', 'lastMonth' => 'last_month'] as $range => $langKey)
                                <button type="button" class="btn btn-sm btn-outline-secondary js-followup-range"
                                        data-range="{{ $range }}">{{ trans("common.date_range.{$langKey}") }}</button>
                            @endforeach
                        </div>
                    </div>

                    <div class="col-12 mt-2">
                        <button type="button" class="btn btn-primary" id="followup-search">
                            <i class="fas fa-search me-1"></i>{{ trans('followup.filter_search') }}
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-transition" id="followup-reset">
                            {{ trans('followup.filter_reset') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="main-card mb-3 card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table data-table mb-0">
                        <thead>
                            <tr>
                                <th>{{ trans('followup.col_source') }}</th>
                                <th>{{ trans('followup.col_group') }}</th>
                                <th>{{ trans('followup.col_sender') }}</th>
                                <th>{{ trans('followup.col_text') }}</th>
                                <th>{{ trans('followup.col_waited') }}</th>
                                <th>{{ trans('followup.col_counts') }}</th>
                                <th>{{ trans('followup.col_status') }}</th>
                                <th>{{ trans('followup.col_decided') }}</th>
                                <th>{{ trans('followup.col_action') }}</th>
                            </tr>
                        </thead>
                        <tbody id="followup-rows"></tbody>
                    </table>
                </div>

                <div id="followup-pagination" class="mt-3"></div>
            </div>
        </div>
    </div>

    {{-- 確認 Modal（不用 confirm，比照全站做法） --}}
    <div class="modal fade" id="modal-followup-confirm" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body text-center py-4"></div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        {{ trans('followup.confirm_cancel') }}
                    </button>
                    <button type="button" class="btn btn-primary" id="followup-confirm-ok">
                        {{ trans('followup.confirm_ok') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- 訊息 Modal（不用 alert） --}}
    <div class="modal fade" id="modal-followup-msg" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body text-center py-4"></div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
                </div>
            </div>
        </div>
    </div>

@endsection

@section('scripts')
<script src="{{ asset('js/followup.js') }}?v={{ filemtime(public_path('js/followup.js')) }}"></script>
@endsection
