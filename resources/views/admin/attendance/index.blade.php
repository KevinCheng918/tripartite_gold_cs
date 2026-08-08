@extends('layouts.app')

@section('title', trans('attendance.page_title'))
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
        <div class="modal-actions">
            <button type="button" data-modal-close class="btn-cancel">{{ trans('attendance.msg.cancel') }}</button>
            <button type="button" id="btn-confirm-clock" class="btn-primary">{{ trans('attendance.msg.confirm') }}</button>
        </div>
    @endcomponent

    {{-- 訊息提示 Modal --}}
    @component('components.modal', ['id' => 'modal-attendance-message', 'title' => ''])
        <p id="modal-attendance-message-text"></p>
        <div class="modal-actions">
            <button type="button" data-modal-close class="btn-primary">OK</button>
        </div>
    @endcomponent

@endsection

@section('scripts')
    <script src="{{ asset('js/attendance.js') }}"></script>
@endsection
