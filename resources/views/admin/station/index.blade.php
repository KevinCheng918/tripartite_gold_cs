@extends('layouts.app')

@section('title', trans('station.page_title'))
@section('subtitle', trans('station.subtitle'))

@section('content')

    <div id="station-app"
         data-i18n='@json(trans("station"))'
         data-can-create="{{ Auth::user()->hasPermission('station.create') ? '1' : '0' }}"
         data-can-update="{{ Auth::user()->hasPermission('station.update') ? '1' : '0' }}">
        <p>Loading…</p>
    </div>

    {{-- 新增/編輯站台 Modal --}}
    @component('components.modal', ['id' => 'modal-station', 'title' => trans('station.action_create')])
        <form id="form-station">
            <input type="hidden" id="station-id">
            <div class="form-group">
                <label>{{ trans('station.field_system') }}</label>
                <div style="display:flex;gap:0.5rem;align-items:center">
                    <select id="station-system" name="system_id" style="flex:1"></select>
                    <button type="button" class="btn-sm" id="btn-add-system" title="新增系統">+</button>
                </div>
            </div>
            <div class="form-group">
                <label for="station-name">{{ trans('station.field_name') }}</label>
                <input id="station-name" type="text" name="name" required>
            </div>
            <div class="form-group">
                <label for="station-domain">{{ trans('station.field_domain') }}</label>
                <input id="station-domain" type="text" name="domain">
            </div>
            <div class="form-group">
                <label for="station-api-url">{{ trans('station.field_api_url') }}</label>
                <input id="station-api-url" type="text" name="api_url" placeholder="https://..." autocomplete="off">
            </div>
            <div class="form-group">
                <label for="station-api-key">{{ trans('station.field_api_key') }}</label>
                <input id="station-api-key" type="text" name="api_key" autocomplete="off">
            </div>
            <div class="form-group">
                <label for="station-telegram-chat-id">{{ trans('station.field_telegram_chat_id') }}</label>
                <div style="display:flex;gap:0.5rem;align-items:center">
                    <input id="station-telegram-chat-id" type="text" name="telegram_chat_id" placeholder="-100xxxxxxxxxx" style="flex:1">
                    <button type="button" class="btn-sm" id="btn-fetch-bot-groups" title="讀取機器人群組">讀取群組</button>
                </div>
            </div>
            <div class="form-group">
                <label for="station-note">{{ trans('station.field_note') }}</label>
                <input id="station-note" type="text" name="note">
            </div>
            <div class="modal-actions">
                <button type="button" data-modal-close>{{ trans('shift.modal_cancel') }}</button>
                <button type="submit" class="btn-primary">{{ trans('shift.modal_confirm') }}</button>
            </div>
        </form>
    @endcomponent

    {{-- 調整狀態 Modal --}}
    @component('components.modal', ['id' => 'modal-station-status', 'title' => trans('station.field_status')])
        <input type="hidden" id="modal-status-station-id">
        <div class="form-group">
            <label class="status-radio">
                <input type="radio" name="station_status" value="1">
                <span class="status-radio__label">{{ trans('station.status_active') }}</span>
            </label>
            <label class="status-radio">
                <input type="radio" name="station_status" value="2">
                <span class="status-radio__label">{{ trans('station.status_frozen') }}</span>
            </label>
            <label class="status-radio">
                <input type="radio" name="station_status" value="0">
                <span class="status-radio__label">{{ trans('station.status_disabled') }}</span>
            </label>
        </div>
        <div class="modal-actions">
            <button type="button" data-modal-close>{{ trans('shift.modal_cancel') }}</button>
            <button type="button" class="btn-primary" id="btn-submit-station-status">{{ trans('shift.modal_confirm') }}</button>
        </div>
    @endcomponent

    {{-- 站台詳細資訊 Modal --}}
    @component('components.modal', ['id' => 'modal-station-detail', 'title' => ''])
        <div id="station-detail-body"></div>
        <div class="modal-actions">
            <button type="button" data-modal-close class="btn-primary">OK</button>
        </div>
    @endcomponent

    {{-- Bot 群組列表 Modal --}}
    @component('components.modal', ['id' => 'modal-bot-groups', 'title' => trans('station.bot_groups_title')])
        <div id="bot-groups-body"></div>
        <div class="modal-actions">
            <button type="button" data-modal-close class="btn-primary">OK</button>
        </div>
    @endcomponent

    {{-- 訊息提示 Modal --}}
    @component('components.modal', ['id' => 'modal-station-msg', 'title' => ''])
        <p id="modal-station-msg-text"></p>
        <div class="modal-actions">
            <button type="button" data-modal-close class="btn-primary">OK</button>
        </div>
    @endcomponent

@endsection

@section('scripts')
    <script src="{{ asset('js/station.js') }}?v={{ filemtime(public_path('js/station.js')) }}"></script>
@endsection
