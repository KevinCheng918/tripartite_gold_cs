@extends('layouts.app')

@section('title', trans('shift.page_title'))

@section('content')
    <h1>{{ trans('shift.page_title') }}</h1>

    <div id="shift-app"
         data-i18n='@json(trans('shift'))'
         data-user-id="{{ Auth::id() }}"
         data-is-admin="{{ Auth::user()->isAdmin() ? '1' : '0' }}"
         data-cover-i18n='@json(trans("cover"))'
         data-permissions='@json(Auth::user()->isAdmin() ? ["all"] : Auth::user()->permissions()->pluck("permission_keyword")->all())'>
        <p>Loading…</p>
    </div>

    {{-- 報班 Modal --}}
    @component('components.modal', ['id' => 'modal-assign', 'title' => trans('shift.modal_assign_title')])
        <form id="form-assign">
            @if(Auth::user()->isAdmin())
            <div class="form-group">
                <label>{{ trans('shift.field_user') }}</label>
                <div id="assign-user-list" class="assign-user-checkboxes"></div>
            </div>
            @endif
            <div class="form-group">
                <label for="assign-shift">{{ trans('shift.field_shift') }}</label>
                <select id="assign-shift" name="shift_id"></select>
            </div>
            <div class="form-group">
                <label for="assign-date">{{ trans('shift.field_date') }}</label>
                <input id="assign-date" type="text" name="date" required placeholder="選擇日期" autocomplete="off">
            </div>
            <div class="modal-actions">
                <button type="button" data-modal-close>{{ trans('shift.modal_cancel') }}</button>
                <button type="submit" class="btn-primary">{{ trans('shift.modal_confirm') }}</button>
            </div>
        </form>
    @endcomponent

    {{-- 換班 Modal --}}
    @component('components.modal', ['id' => 'modal-swap', 'title' => trans('shift.modal_swap_title')])
        <form id="form-swap">
            <p class="modal-section-label">{{ trans('shift.swap_my_section') }}</p>
            <div class="form-group">
                <label for="swap-my-date">{{ trans('shift.field_date') }}</label>
                <input id="swap-my-date" type="text" required placeholder="選擇日期" autocomplete="off">
            </div>
            <div class="form-group">
                <label for="swap-my-shift">{{ trans('shift.field_shift') }}</label>
                <select id="swap-my-shift"></select>
            </div>

            <p class="modal-section-label">{{ trans('shift.swap_target_section') }}</p>
            <div class="form-group">
                <label for="swap-target-date">{{ trans('shift.field_date') }}</label>
                <input id="swap-target-date" type="text" required placeholder="選擇日期" autocomplete="off">
            </div>
            <div class="form-group">
                <label for="swap-target-shift">{{ trans('shift.field_shift') }}</label>
                <select id="swap-target-shift"></select>
            </div>

            <div class="modal-actions">
                <button type="button" data-modal-close>{{ trans('shift.modal_cancel') }}</button>
                <button type="submit" class="btn-primary">{{ trans('shift.modal_confirm') }}</button>
            </div>
        </form>
    @endcomponent

    {{-- 編輯班別 Modal (Admin) --}}
    @component('components.modal', ['id' => 'modal-edit-shift', 'title' => trans('shift.modal_edit_shift_title')])
        <form id="form-edit-shift">
            <input type="hidden" id="edit-shift-id">
            <div class="form-group">
                <label for="edit-display-name">{{ trans('shift.field_display_name') }}</label>
                <input id="edit-display-name" type="text" name="display_name" required>
            </div>
            <div class="form-group">
                <label for="edit-start-time">{{ trans('shift.field_start_time') }}</label>
                <input id="edit-start-time" type="text" name="start_time" required placeholder="HH:mm" autocomplete="off">
            </div>
            <div class="form-group">
                <label for="edit-end-time">{{ trans('shift.field_end_time') }}</label>
                <input id="edit-end-time" type="text" name="end_time" required placeholder="HH:mm" autocomplete="off">
            </div>
            <div class="modal-actions">
                <button type="button" data-modal-close>{{ trans('shift.modal_cancel') }}</button>
                <button type="submit" class="btn-primary">{{ trans('shift.modal_confirm') }}</button>
            </div>
        </form>
    @endcomponent

    {{-- 排班操作 Modal（點擊色塊觸發） --}}
    @component('components.modal', ['id' => 'modal-assignment-action', 'title' => trans('shift.assignment_detail')])
        <input type="hidden" id="modal-assignment-action-id">
        <div id="modal-assignment-action-body"></div>
        <div class="modal-actions">
            @if(Auth::user()->hasPermission('shift.cover'))
            <button type="button" id="btn-cover-assignment" class="btn-primary">{{ trans('cover.action_request') }}</button>
            @endif
            <button type="button" data-modal-close>{{ trans('shift.modal_cancel') }}</button>
            @if(Auth::user()->hasPermission('shift.delete'))
            <button type="button" id="btn-delete-assignment" class="btn-danger-full">{{ trans('shift.action_delete_assignment') }}</button>
            @endif
        </div>
    @endcomponent

    {{-- 申請代班 Modal（需要 shift.cover 權限） --}}
    @if(Auth::user()->hasPermission('shift.cover'))
    @component('components.modal', ['id' => 'modal-cover-request', 'title' => trans('cover.modal_request_title')])
        <form id="form-cover-request">
            <input type="hidden" id="cover-assignment-id">
            <div class="form-group">
                <label for="cover-user-id">{{ trans('cover.field_cover_user') }}</label>
                <select id="cover-user-id" name="cover_user_id"></select>
            </div>
            <div class="form-group">
                <label for="cover-start">{{ trans('shift.field_start_time') }}</label>
                <input id="cover-start" type="text" name="cover_start" required placeholder="HH:mm" autocomplete="off">
            </div>
            <div class="form-group">
                <label for="cover-end">{{ trans('shift.field_end_time') }}</label>
                <input id="cover-end" type="text" name="cover_end" required placeholder="HH:mm" autocomplete="off">
            </div>
            <div class="form-group">
                <label for="cover-reason">{{ trans('cover.field_reason') }}</label>
                <input id="cover-reason" type="text" name="reason" placeholder="選填" autocomplete="off">
            </div>
            <div class="modal-actions">
                <button type="button" data-modal-close>{{ trans('cover.modal_cancel') }}</button>
                <button type="submit" class="btn-primary">{{ trans('cover.modal_confirm') }}</button>
            </div>
        </form>
    @endcomponent
    @endif

    {{-- 訊息提示 Modal --}}
    @component('components.modal', ['id' => 'modal-message', 'title' => ''])
        <p id="modal-message-text"></p>
        <div class="modal-actions">
            <button type="button" data-modal-close class="btn-primary">OK</button>
        </div>
    @endcomponent
@endsection

@section('scripts')
    <script src="{{ asset('js/shifts.js') }}"></script>
@endsection
