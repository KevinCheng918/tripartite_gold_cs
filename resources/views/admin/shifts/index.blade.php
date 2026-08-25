@extends('layouts.app')

@section('title', trans('shift.page_title'))
@section('icon', 'calendar-alt')
@section('subtitle', trans('shift.subtitle'))

@section('content')
    <style>
        /* dark mode: modal 表單元素 */
        [data-theme="dark"] .modal-content .form-control,
        [data-theme="dark"] .modal-content .form-select { background: #2d2d2d; color: #e0e0e0; border-color: #444; }
        [data-theme="dark"] .modal-content .input-group-text { background: #333; color: #ccc; border-color: #444; }
        [data-theme="dark"] .modal-content .form-check-label { color: #e0e0e0; }
        /* 瀏覽器原生 date/time picker dark mode */
        [data-theme="dark"] input[type="date"]::-webkit-calendar-picker-indicator,
        [data-theme="dark"] input[type="time"]::-webkit-calendar-picker-indicator { filter: invert(1); }
        /* input-group 統一白底 */
        #leave-date-group .input-group-text { background: #fff; }
        #leave-date-group .form-control { background: #fff; }
        [data-theme="dark"] #leave-date-group .input-group-text { background: #2d2d2d; color: #ccc; border-color: #444; }
        [data-theme="dark"] #leave-date-group .form-control { background: #2d2d2d; color: #e0e0e0; border-color: #444; }
        /* 時長提示 */
        #leave-duration-hint:empty { display: none; }
    </style>
    <div id="shift-app"
         data-i18n='@json(trans('shift'))'
         data-user-id="{{ Auth::id() }}"
         data-is-admin="{{ Auth::user()->isAdmin() ? '1' : '0' }}"
         data-cover-i18n='@json(trans("cover"))'
         data-leave-i18n='@json(trans("leave"))'
         data-permissions='@json(Auth::user()->isAdmin() ? ["all"] : Auth::user()->permissions()->pluck("permission_keyword")->all())'>
        <p>Loading…</p>
    </div>

    {{-- 報班 Modal --}}
    @component('components.modal', ['id' => 'modal-assign', 'title' => trans('shift.modal_assign_title')])
        <form id="form-assign">
            @if(Auth::user()->isAdmin())
            <div class="mb-3">
                <label>{{ trans('shift.field_user') }}</label>
                <div id="assign-user-list" class="assign-user-checkboxes"></div>
            </div>
            @endif
            <div class="mb-3">
                <label class="assign-user-cb">
                    <input type="checkbox" id="assign-allday">
                    <span>{{ trans('shift.allday_shift') }}</span>
                </label>
            </div>
            <div class="mb-3" id="assign-shift-group">
                <label class="form-label" for="assign-shift">{{ trans('shift.field_shift') }}</label>
                <select id="assign-shift" name="shift_id" class="form-select"></select>
            </div>
            <div class="mb-3">
                <label class="form-label" for="assign-date">{{ trans('shift.field_date') }}</label>
                <input id="assign-date" type="text" name="date" class="form-control" required placeholder="選擇日期" autocomplete="off">
            </div>
            <div class="text-end mt-3">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ trans('shift.modal_cancel') }}</button>
                <button type="submit" class="btn btn-primary">{{ trans('shift.modal_confirm') }}</button>
            </div>
        </form>
    @endcomponent

    {{-- 換班 Modal --}}
    @component('components.modal', ['id' => 'modal-swap', 'title' => trans('shift.modal_swap_title')])
        <form id="form-swap">
            <p class="modal-section-label">{{ trans('shift.swap_my_section') }}</p>
            <div class="mb-3">
                <label class="form-label" for="swap-my-date">{{ trans('shift.field_date') }}</label>
                <input id="swap-my-date" type="text" class="form-control" required placeholder="選擇日期" autocomplete="off">
            </div>
            <div class="mb-3">
                <label class="form-label" for="swap-my-shift">{{ trans('shift.field_shift') }}</label>
                <select id="swap-my-shift" class="form-select"></select>
            </div>

            <p class="modal-section-label">{{ trans('shift.swap_target_section') }}</p>
            <div class="mb-3">
                <label class="form-label" for="swap-target-date">{{ trans('shift.field_date') }}</label>
                <input id="swap-target-date" type="text" class="form-control" required placeholder="選擇日期" autocomplete="off">
            </div>
            <div class="mb-3">
                <label class="form-label" for="swap-target-shift">{{ trans('shift.field_shift') }}</label>
                <select id="swap-target-shift" class="form-select"></select>
            </div>

            <div class="text-end mt-3">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ trans('shift.modal_cancel') }}</button>
                <button type="submit" class="btn btn-primary">{{ trans('shift.modal_confirm') }}</button>
            </div>
        </form>
    @endcomponent

    {{-- 新增班別 Modal (Admin) --}}
    @component('components.modal', ['id' => 'modal-create-shift', 'title' => '新增班別'])
        <form id="form-create-shift">
            <div class="mb-3">
                <label class="form-label" for="create-display-name">{{ trans('shift.field_display_name') }}</label>
                <input id="create-display-name" type="text" name="display_name" class="form-control" required placeholder="例：大夜班">
            </div>
            <div class="mb-3">
                <label class="form-label" for="create-start-time">{{ trans('shift.field_start_time') }}</label>
                <input id="create-start-time" type="text" name="start_time" class="form-control" required placeholder="HH:mm" autocomplete="off">
            </div>
            <div class="mb-3">
                <label class="form-label" for="create-end-time">{{ trans('shift.field_end_time') }}</label>
                <input id="create-end-time" type="text" name="end_time" class="form-control" required placeholder="HH:mm" autocomplete="off">
            </div>
            <div class="mb-3">
                <label class="form-label">主要回訊時間</label>
                <div class="d-flex gap-2 align-items-center">
                    <input id="create-reply-start-time" type="text" class="form-control" placeholder="HH:mm" autocomplete="off">
                    <span>~</span>
                    <input id="create-reply-end-time" type="text" class="form-control" placeholder="HH:mm" autocomplete="off">
                </div>
            </div>
            <div class="text-end mt-3">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ trans('shift.modal_cancel') }}</button>
                <button type="submit" class="btn btn-primary">{{ trans('shift.modal_confirm') }}</button>
            </div>
        </form>
    @endcomponent

    {{-- 申請請假 Modal --}}
    @component('components.modal', ['id' => 'modal-leave-apply', 'title' => trans('leave.action_apply')])
        <form id="form-leave-apply">
            @if(Auth::user()->isAdmin())
            <div class="mb-3">
                <label class="form-label">{{ trans('leave.field_user') }} <span class="text-danger">*</span></label>
                <select id="leave-user-id" class="form-select" required>
                    <option value="">請選擇</option>
                    @foreach($csUsers as $u)
                        <option value="{{ $u->id }}">{{ $u->nickname }}</option>
                    @endforeach
                </select>
            </div>
            @endif
            <div class="mb-3">
                <label class="form-label">{{ trans('leave.field_date') }} <span class="text-danger">*</span></label>
                <div class="input-group" id="leave-date-group">
                    <input id="leave-start-date" type="date" class="form-control" required>
                    <span class="input-group-text" id="leave-date-separator">~</span>
                    <input id="leave-end-date" type="date" class="form-control" required>
                </div>
            </div>
            <div class="mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="leave-full-day" checked>
                    <label class="form-check-label" for="leave-full-day">{{ trans('leave.type_full_day') }}</label>
                </div>
            </div>
            <div class="mb-3" id="leave-time-fields" style="display:none">
                <label class="form-label">{{ trans('leave.field_time') }} <span class="text-danger">*</span></label>
                <div class="row g-2">
                    <div class="col">
                        <input id="leave-start-time" type="time" class="form-control">
                    </div>
                    <div class="col-auto d-flex align-items-center">~</div>
                    <div class="col">
                        <input id="leave-end-time" type="time" class="form-control">
                    </div>
                </div>
            </div>
            <div id="leave-duration-hint" class="mb-3 fw-bold text-center py-2 rounded" style="font-size:1.25rem;background:rgba(212,175,55,0.15);color:#a67c00"></div>
            <div class="mb-3">
                <label class="form-label">{{ trans('leave.field_reason') }}</label>
                <textarea id="leave-reason" class="form-control" rows="2" maxlength="500"></textarea>
            </div>
            <div class="text-end">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ trans('shift.modal_cancel') }}</button>
                <button type="submit" class="btn btn-primary">{{ trans('shift.modal_confirm') }}</button>
            </div>
        </form>
    @endcomponent

    {{-- 請假審核確認 Modal --}}
    <div class="modal fade" id="modal-leave-confirm" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header justify-content-center">
                    <h4 class="modal-title text-center fw-bold">請假審核確認</h4>
                    <button type="button" class="btn-close position-absolute end-0 me-3" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="leave-confirm-body" class="text-center"></div>
                    <input type="hidden" id="leave-confirm-id">
                    <input type="hidden" id="leave-confirm-status">
                    <div class="d-flex justify-content-between mt-3">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                        <button type="button" class="btn btn-primary" id="btn-leave-confirm-ok">確認</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- 編輯班別 Modal (Admin) --}}
    @component('components.modal', ['id' => 'modal-edit-shift', 'title' => trans('shift.modal_edit_shift_title')])
        <form id="form-edit-shift">
            <input type="hidden" id="edit-shift-id">
            <div class="mb-3">
                <label class="form-label" for="edit-display-name">{{ trans('shift.field_display_name') }}</label>
                <input id="edit-display-name" type="text" name="display_name" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="edit-start-time">{{ trans('shift.field_start_time') }}</label>
                <input id="edit-start-time" type="text" name="start_time" class="form-control" required placeholder="HH:mm" autocomplete="off">
            </div>
            <div class="mb-3">
                <label class="form-label" for="edit-end-time">{{ trans('shift.field_end_time') }}</label>
                <input id="edit-end-time" type="text" name="end_time" class="form-control" required placeholder="HH:mm" autocomplete="off">
            </div>
            <div class="mb-3">
                <label class="form-label" for="edit-reply-start-time">主要回訊時間</label>
                <div class="d-flex gap-2 align-items-center">
                    <input id="edit-reply-start-time" type="text" class="form-control" placeholder="HH:mm" autocomplete="off">
                    <span>~</span>
                    <input id="edit-reply-end-time" type="text" class="form-control" placeholder="HH:mm" autocomplete="off">
                </div>
            </div>
            <div class="text-end mt-3">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ trans('shift.modal_cancel') }}</button>
                <button type="submit" class="btn btn-primary">{{ trans('shift.modal_confirm') }}</button>
            </div>
        </form>
    @endcomponent

    {{-- 排班操作 Modal（點擊色塊觸發） --}}
    @component('components.modal', ['id' => 'modal-assignment-action', 'title' => trans('shift.assignment_detail')])
        <input type="hidden" id="modal-assignment-action-id">
        <div id="modal-assignment-action-body"></div>
        <div class="text-end mt-3">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ trans('shift.modal_cancel') }}</button>
            <div class="d-flex gap-2">
                @if(Auth::user()->hasPermission('shift.cover'))
                <button type="button" id="btn-cover-assignment" class="btn btn-primary">{{ trans('cover.action_request') }}</button>
                @endif
                @if(Auth::user()->hasPermission('shift.delete'))
                <button type="button" id="btn-delete-assignment" class="btn btn-danger">{{ trans('shift.action_delete_assignment') }}</button>
                @endif
            </div>
        </div>
    @endcomponent

    {{-- 申請代班 Modal（需要 shift.cover 權限） --}}
    @if(Auth::user()->hasPermission('shift.cover'))
    @component('components.modal', ['id' => 'modal-cover-request', 'title' => trans('cover.modal_request_title')])
        <form id="form-cover-request">
            <input type="hidden" id="cover-assignment-id">
            <div class="mb-3">
                <label class="form-label" for="cover-user-id">{{ trans('cover.field_cover_user') }}</label>
                <select id="cover-user-id" name="cover_user_id" class="form-select"></select>
            </div>
            <div class="mb-3">
                <label class="form-label" for="cover-start">{{ trans('shift.field_start_time') }}</label>
                <input id="cover-start" type="text" name="cover_start" class="form-control" required placeholder="HH:mm" autocomplete="off">
            </div>
            <div class="mb-3">
                <label class="form-label" for="cover-end">{{ trans('shift.field_end_time') }}</label>
                <input id="cover-end" type="text" name="cover_end" class="form-control" required placeholder="HH:mm" autocomplete="off">
            </div>
            <div class="mb-3">
                <label class="form-label" for="cover-reason">{{ trans('cover.field_reason') }}</label>
                <input id="cover-reason" type="text" name="reason" class="form-control" placeholder="選填" autocomplete="off">
            </div>
            <div class="text-end mt-3">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ trans('cover.modal_cancel') }}</button>
                <button type="submit" class="btn btn-primary">{{ trans('cover.modal_confirm') }}</button>
            </div>
        </form>
    @endcomponent
    @endif

    {{-- 二次確認 Modal --}}
    @component('components.modal', ['id' => 'modal-cover-confirm', 'title' => ''])
        <div id="modal-cover-confirm-body"></div>
        <div class="text-end mt-3">
            <button type="button" data-bs-dismiss="modal" class="btn btn-secondary">{{ trans('cover.modal_cancel') }}</button>
            <button type="button" id="btn-cover-confirm-ok" class="btn btn-primary">{{ trans('cover.modal_confirm') }}</button>
        </div>
    @endcomponent

    {{-- 訊息提示 Modal --}}
    @component('components.modal', ['id' => 'modal-message', 'title' => ''])
        <p id="modal-message-text"></p>
        <div class="text-end mt-3">
            <button type="button" data-bs-dismiss="modal" class="btn btn-primary">OK</button>
        </div>
    @endcomponent
@endsection

@section('scripts')
    <script src="{{ asset('js/shifts.js') }}?v={{ filemtime(public_path('js/shifts.js')) }}"></script>
@endsection
