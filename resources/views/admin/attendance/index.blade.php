@extends('layouts.app')

@section('title', trans('attendance.page_title'))
@section('icon', 'clock')
@section('subtitle', trans('attendance.subtitle'))

@section('content')

    <div id="attendance-app"
         {{-- JSON_HEX_APOS：屬性用單引號包，JSON 內的單引號（英文縮寫如 don't）會提早結束屬性 --}}
         data-i18n='@json(trans("attendance"), JSON_HEX_APOS | JSON_HEX_QUOT)'
         data-user-id="{{ Auth::id() }}"
         data-is-admin="{{ Auth::user()->isAdmin() ? '1' : '0' }}"
         data-permissions='@json(Auth::user()->isAdmin() ? ["all"] : Auth::user()->permissions()->pluck("permission_keyword")->all(), JSON_HEX_APOS | JSON_HEX_QUOT)'>
        <p>Loading…</p>
    </div>

    {{-- 打卡二次確認 Modal --}}
    @component('components.modal', ['id' => 'modal-attendance-confirm', 'title' => ''])
        <div id="modal-attendance-confirm-body"></div>
        <div class="text-end mt-3">
            <button type="button" data-bs-dismiss="modal" class="btn btn-secondary">取消</button>
            <button type="button" id="btn-confirm-clock" class="btn btn-primary">確認</button>
        </div>
    @endcomponent

    {{-- 補打卡申請 Modal --}}
    @if(Auth::user()->hasPermission('attendance.amend'))
    <div class="modal fade" id="modal-amend" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ trans('attendance.amend_title') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="form-amend">
                        <div class="mb-3">
                            <label class="form-label">{{ trans('attendance.amend_field_date') }}</label>
                            {{-- 原生 date/time：手機上會跳出系統的滾輪選擇器，好按得多，
                                 而且桌機可以直接打字。值的格式固定是 YYYY-MM-DD / HH:mm，
                                 正好是後端 date_format:H:i 要的，不必再轉。 --}}
                            <input id="amend-date" type="date" class="form-control" required autocomplete="off">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('attendance.amend_field_type') }}</label>
                            <select id="amend-type" class="form-select" required>
                                <option value="1">{{ trans('attendance.amend_type_in') }}</option>
                                <option value="2">{{ trans('attendance.amend_type_out') }}</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('attendance.amend_field_time') }}</label>
                            {{-- lang="en-GB"：原生 time 顯示 12 或 24 小時制是看 locale，
                                 沒有屬性可以直接指定。頁面的 lang 是 zh-TW，Chrome 會顯示
                                 「上午／下午」，跟系統其他地方寫的 13:00 對不起來。
                                 en-GB 是 24 小時制的 locale，桌機與 Android Chrome 會吃這個設定。
                                 ⚠ iOS Safari 不看 lang，它跟著裝置的「24 小時制」開關走。
                                 value 送出去的永遠是 24 小時制的 HH:mm，只有顯示會變。 --}}
                            <input id="amend-time" type="time" lang="en-GB" class="form-control" required autocomplete="off">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('attendance.amend_field_reason') }}</label>
                            <textarea id="amend-reason" class="form-control" rows="2" placeholder="選填"></textarea>
                        </div>
                        <div class="text-end">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                            <button type="submit" class="btn btn-primary">送出申請</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- 訊息提示 Modal --}}
    @component('components.modal', ['id' => 'modal-attendance-message', 'title' => ''])
        <p id="modal-attendance-message-text"></p>
        <div class="text-end mt-3">
            <button type="button" data-bs-dismiss="modal" class="btn-primary">OK</button>
        </div>
    @endcomponent

@endsection

@section('scripts')
    <script src="{{ asset('js/attendance.js') }}?v={{ filemtime(public_path('js/attendance.js')) }}"></script>
@endsection
