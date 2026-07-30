@extends('layouts.app')

@section('title', trans('account.page_title'))

@section('content')
    <div id="account-app"
         data-i18n='@json(trans('account'))'
         data-permission-i18n='@json(trans('permission'))'>
        <p>Loading…</p>
    </div>

    {{-- 新增帳號 Modal --}}
    @component('components.modal', ['id' => 'modal-create-account', 'title' => trans('account.action_create')])
        <form id="form-create-account">
            <div class="form-group">
                <label for="create-account">{{ trans('account.field_account') }}</label>
                <input id="create-account" type="text" name="account" required>
            </div>
            <div class="form-group">
                <label for="create-nickname">{{ trans('account.field_nickname') }}</label>
                <input id="create-nickname" type="text" name="nickname" required>
            </div>
            <div class="form-group">
                <label for="create-password">{{ trans('account.field_password') }}</label>
                <input id="create-password" type="password" name="password" required minlength="8">
            </div>
            <div class="modal-actions">
                <button type="button" data-modal-close>{{ trans('shift.modal_cancel') }}</button>
                <button type="submit" class="btn-primary">{{ trans('shift.modal_confirm') }}</button>
            </div>
        </form>
    @endcomponent

    {{-- 編輯帳號 Modal --}}
    @component('components.modal', ['id' => 'modal-edit-account', 'title' => trans('account.action_edit')])
        <form id="form-edit-account">
            <input type="hidden" id="edit-account-id">
            <div class="form-group">
                <label for="edit-nickname">{{ trans('account.field_nickname') }}</label>
                <input id="edit-nickname" type="text" name="nickname" required>
            </div>
            <div class="form-group">
                <label for="edit-password">{{ trans('account.field_password') }}（{{ trans('account.password_hint') }}）</label>
                <input id="edit-password" type="password" name="password" minlength="8">
            </div>
            <div class="modal-actions">
                <button type="button" data-modal-close>{{ trans('shift.modal_cancel') }}</button>
                <button type="submit" class="btn-primary">{{ trans('shift.modal_confirm') }}</button>
            </div>
        </form>
    @endcomponent

    {{-- 設定權限 Modal --}}
    @component('components.modal', ['id' => 'modal-assign-permissions', 'title' => trans('account.action_assign_permissions')])
        <form id="form-assign-permissions">
            <input type="hidden" id="assign-perm-user-id">
            <div id="permission-checkbox-list"></div>
            <div class="modal-actions">
                <button type="button" data-modal-close>{{ trans('shift.modal_cancel') }}</button>
                <button type="submit" class="btn-primary">{{ trans('shift.modal_confirm') }}</button>
            </div>
        </form>
    @endcomponent

    {{-- 調整狀態 Modal --}}
    @component('components.modal', ['id' => 'modal-change-status', 'title' => trans('account.action_change_status')])
        <form id="form-change-status">
            <input type="hidden" id="status-user-id">
            <div class="form-group">
                <label class="status-radio">
                    <input type="radio" name="status" value="1">
                    <span class="status-radio__label">{{ trans('account.status_normal') }}</span>
                    <span class="status-radio__hint">{{ trans('account.status_normal_hint') }}</span>
                </label>
                <label class="status-radio">
                    <input type="radio" name="status" value="2">
                    <span class="status-radio__label">{{ trans('account.status_lock') }}</span>
                    <span class="status-radio__hint">{{ trans('account.status_lock_hint') }}</span>
                </label>
                <label class="status-radio">
                    <input type="radio" name="status" value="0">
                    <span class="status-radio__label">{{ trans('account.status_deactivate') }}</span>
                    <span class="status-radio__hint">{{ trans('account.status_deactivate_hint') }}</span>
                </label>
            </div>
            <div class="modal-actions">
                <button type="button" data-modal-close>{{ trans('shift.modal_cancel') }}</button>
                <button type="submit" class="btn-primary">{{ trans('shift.modal_confirm') }}</button>
            </div>
        </form>
    @endcomponent

    {{-- 訊息提示 Modal --}}
    @component('components.modal', ['id' => 'modal-account-message', 'title' => ''])
        <p id="modal-account-message-text"></p>
        <div class="modal-actions">
            <button type="button" data-modal-close class="btn-primary">OK</button>
        </div>
    @endcomponent
@endsection

@section('scripts')
    <script src="{{ asset('js/accounts.js') }}"></script>
@endsection
