@extends('layouts.app')

@section('title', trans('attendance.page_title'))
@section('icon', 'clock')
@section('subtitle', trans('attendance.subtitle'))

@section('content')

    <div id="attendance-app"
         data-i18n='@json(trans("attendance"))'
         data-user-id="{{ Auth::id() }}"
         data-is-admin="{{ Auth::user()->isAdmin() ? '1' : '0' }}"
         data-permissions='@json(Auth::user()->isAdmin() ? ["all"] : Auth::user()->permissions()->pluck("permission_keyword")->all())'>
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
                            <input id="amend-date" type="text" class="form-control" required placeholder="選擇日期" autocomplete="off">
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
                            <input id="amend-time" type="text" class="form-control" required placeholder="HH:mm" autocomplete="off">
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
