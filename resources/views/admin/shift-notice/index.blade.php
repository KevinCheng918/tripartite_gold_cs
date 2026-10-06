@extends('layouts.app')

@section('title', trans('shift_notice.page_title'))
@section('icon', 'paper-plane')
@section('subtitle', trans('shift_notice.subtitle'))

@section('content')

    {{-- 初始資料隨頁面送出，避免載入時先空一拍 --}}
    {{-- ⚠️ data 屬性用單引號包，JSON 內的單引號會提早結束屬性、讓前端解析失敗，
         所以一律用 JSON_HEX_APOS 轉義 --}}
    <div id="shift-notice-app"
         data-i18n='@json(trans("shift_notice"), JSON_HEX_APOS | JSON_HEX_QUOT)'
         data-initial='@json($initialSettings, JSON_HEX_APOS | JSON_HEX_QUOT)'
         data-can-manage="{{ Auth::user()->hasPermission('shift_notice.manage') ? '1' : '0' }}">

        <div class="row">
            <div class="col-lg-7">
                {{-- 完整班表收件人 --}}
                <div class="main-card mb-3 card">
                    <div class="card-body">
                        <h5 class="card-title">{{ trans('shift_notice.manager_title') }}</h5>
                        <p class="text-muted" style="font-size:0.875rem">
                            {{ trans('shift_notice.manager_desc', ['time' => config('constants.SHIFT_NOTICE.SEND_AT')]) }}
                        </p>
                        <form id="form-shift-notice">
                            <div class="mb-3">
                                <label class="form-label" for="notice-manager">{{ trans('shift_notice.manager_field') }}</label>
                                <select class="form-select" id="notice-manager"></select>
                                <small class="form-text text-muted">{{ trans('shift_notice.manager_hint') }}</small>
                            </div>
                            <button type="submit" class="btn btn-primary js-manage-only">{{ trans('shift_notice.action_save') }}</button>
                            <button type="button" class="btn btn-outline-secondary js-manage-only" id="btn-test-notice">{{ trans('shift_notice.action_test') }}</button>
                            <div class="form-text mt-2">{{ trans('shift_notice.test_hint') }}</div>
                        </form>
                    </div>
                </div>

                {{-- 個人班表（純說明，沒有東西要設定） --}}
                <div class="main-card mb-3 card">
                    <div class="card-body">
                        <h5 class="card-title">{{ trans('shift_notice.personal_title') }}</h5>
                        <p class="text-muted mb-0" style="font-size:0.875rem">
                            {{ trans('shift_notice.personal_desc', ['time' => config('constants.SHIFT_NOTICE.SEND_AT')]) }}
                        </p>
                    </div>
                </div>
            </div>

            {{-- 綁定說明。這頁最常被問的就是「為什麼他沒收到」，所以常駐在旁邊而不是收進 collapse --}}
            <div class="col-lg-5">
                <div class="main-card mb-3 card">
                    <div class="card-body">
                        <h5 class="card-title">
                            <i class="fas fa-info-circle me-1"></i>{{ trans('shift_notice.bind_title') }}
                        </h5>
                        <ol class="mb-2 ps-3" style="font-size:0.875rem;line-height:1.9">
                            <li>{{ trans('shift_notice.bind_step1') }}</li>
                            <li>{{ trans('shift_notice.bind_step2') }}</li>
                            <li>{{ trans('shift_notice.bind_step3') }}</li>
                        </ol>
                        <p class="mb-0 text-danger" style="font-size:0.875rem">
                            <i class="fas fa-exclamation-triangle me-1"></i>{{ trans('shift_notice.bind_note') }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- 訊息 Modal（不用 alert） --}}
    <div class="modal fade" id="modal-shift-notice-msg" tabindex="-1">
        <div class="modal-dialog modal-sm">
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
    <script src="{{ asset('js/shift-notice.js') }}?v={{ filemtime(public_path('js/shift-notice.js')) }}"></script>
@endsection
