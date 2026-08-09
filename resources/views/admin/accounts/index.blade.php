@extends('layouts.app')

@section('title', trans('account.page_title'))
@section('icon', 'users')

@section('content')

    <div class="main-card mb-3 card">
        <div class="card-header d-flex align-items-center">
            <span class="me-auto fw-bold">{{ trans('account.page_title') }}</span>
            @if(Auth::user()->isAdmin())
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-create-account">
                    <i class="fas fa-plus me-1"></i>{{ trans('account.action_create') }}
                </button>
            @endif
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>{{ trans('account.field_account') }}</th>
                            <th>{{ trans('account.field_nickname') }}</th>
                            <th>{{ trans('account.field_status') }}</th>
                            <th>{{ trans('account.field_level') }}</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $idx = 0; @endphp
                        @forelse($accounts as $account)
                            @if($account->level == config('constants.USER.LEVEL.ADMIN'))
                                @continue
                            @endif
                            @php $idx++; @endphp
                            <tr>
                                <td>{{ $idx }}</td>
                                <td>{{ $account->account }}</td>
                                <td><strong>{{ $account->nickname }}</strong></td>
                                <td>
                                    @if($account->status == config('constants.USER.STATUS.NORMAL'))
                                        <span class="badge bg-success">{{ trans('account.status_normal') }}</span>
                                    @elseif($account->status == config('constants.USER.STATUS.LOCK'))
                                        <span class="badge bg-warning text-dark">{{ trans('account.status_lock') }}</span>
                                    @else
                                        <span class="badge bg-danger">{{ trans('account.status_deactivate') }}</span>
                                    @endif
                                </td>
                                <td><span class="badge bg-secondary">{{ trans('account.level_cs') }}</span></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-secondary js-edit"
                                            data-id="{{ $account->id }}"
                                            data-nickname="{{ $account->nickname }}">
                                        <i class="fas fa-edit me-1"></i>{{ trans('account.action_edit') }}
                                    </button>
                                    <button class="btn btn-sm btn-outline-secondary js-change-status"
                                            data-id="{{ $account->id }}"
                                            data-status="{{ $account->status }}">
                                        {{ trans('account.action_change_status') }}
                                    </button>
                                    <a href="{{ route('admin.accounts.permissions', $account->id) }}"
                                       class="btn btn-sm btn-outline-secondary">
                                        <i class="fas fa-key me-1"></i>{{ trans('account.action_assign_permissions') }}
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">暫無資料</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($accounts instanceof \Illuminate\Pagination\LengthAwarePaginator && $accounts->hasPages())
            <div class="card-footer">
                {{ $accounts->links() }}
            </div>
        @endif
    </div>

    {{-- 新增帳號 Modal --}}
    <div class="modal fade" id="modal-create-account" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ trans('account.action_create') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="form-create-account">
                        <div class="mb-3">
                            <label class="form-label">{{ trans('account.field_account') }}</label>
                            <input id="create-account" type="text" class="form-control" name="account" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('account.field_nickname') }}</label>
                            <input id="create-nickname" type="text" class="form-control" name="nickname" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('account.field_password') }}</label>
                            <input id="create-password" type="password" class="form-control" name="password" required minlength="8">
                        </div>
                        <div class="text-end">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ trans('shift.modal_cancel') }}</button>
                            <button type="submit" class="btn btn-primary">{{ trans('shift.modal_confirm') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- 編輯帳號 Modal --}}
    <div class="modal fade" id="modal-edit-account" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ trans('account.action_edit') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="form-edit-account">
                        <input type="hidden" id="edit-account-id">
                        <div class="mb-3">
                            <label class="form-label">{{ trans('account.field_nickname') }}</label>
                            <input id="edit-nickname" type="text" class="form-control" name="nickname" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('account.field_password') }}（{{ trans('account.password_hint') }}）</label>
                            <input id="edit-password" type="password" class="form-control" name="password" minlength="8">
                        </div>
                        <div class="text-end">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ trans('shift.modal_cancel') }}</button>
                            <button type="submit" class="btn btn-primary">{{ trans('shift.modal_confirm') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- 調整狀態 Modal --}}
    <div class="modal fade" id="modal-change-status" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ trans('account.action_change_status') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="form-change-status">
                        <input type="hidden" id="status-user-id">
                        <div class="mb-3">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="status" value="1" id="status-normal">
                                <label class="form-check-label" for="status-normal">
                                    <strong>{{ trans('account.status_normal') }}</strong>
                                    <small class="text-muted d-block">{{ trans('account.status_normal_hint') }}</small>
                                </label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="status" value="2" id="status-lock">
                                <label class="form-check-label" for="status-lock">
                                    <strong>{{ trans('account.status_lock') }}</strong>
                                    <small class="text-muted d-block">{{ trans('account.status_lock_hint') }}</small>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="status" value="0" id="status-deactivate">
                                <label class="form-check-label" for="status-deactivate">
                                    <strong>{{ trans('account.status_deactivate') }}</strong>
                                    <small class="text-muted d-block">{{ trans('account.status_deactivate_hint') }}</small>
                                </label>
                            </div>
                        </div>
                        <div class="text-end">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ trans('shift.modal_cancel') }}</button>
                            <button type="submit" class="btn btn-primary">{{ trans('shift.modal_confirm') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- 訊息提示 Modal --}}
    <div class="modal fade" id="modal-account-message" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-body text-center py-4">
                    <p id="modal-account-message-text" class="mb-3"></p>
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
                </div>
            </div>
        </div>
    </div>

@endsection

@section('scripts')
<script>
$(function () {
    var csrfToken = $('meta[name="csrf-token"]').attr('content');

    function showMessage(msg) {
        $('#modal-account-message-text').text(msg);
        showBsModal('modal-account-message');
    }

    function getErrorMsg(xhr) {
        var body = xhr.responseJSON || {};
        if (body.errors) {
            var keys = Object.keys(body.errors);
            return body.errors[keys[0]][0];
        }
        return body.message || 'Failed';
    }

    // 編輯按鈕
    $('.js-edit').on('click', function () {
        var $btn = $(this);
        $('#edit-account-id').val($btn.data('id'));
        $('#edit-nickname').val($btn.data('nickname'));
        $('#edit-password').val('');
        showBsModal('modal-edit-account');
    });

    // 調整狀態按鈕
    $('.js-change-status').on('click', function () {
        var $btn = $(this);
        $('#status-user-id').val($btn.data('id'));
        $('input[name="status"][value="' + $btn.data('status') + '"]').prop('checked', true);
        showBsModal('modal-change-status');
    });

    // 新增帳號
    $('#form-create-account').on('submit', function (e) {
        e.preventDefault();
        $.ajax({
            url: '/admin/accounts/ajax-store',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            contentType: 'application/json',
            data: JSON.stringify({
                account: $('#create-account').val(),
                nickname: $('#create-nickname').val(),
                password: $('#create-password').val(),
            }),
            success: function () { location.reload(); },
            error: function (xhr) { showMessage(getErrorMsg(xhr)); }
        });
    });

    // 編輯帳號
    $('#form-edit-account').on('submit', function (e) {
        e.preventDefault();
        var data = { nickname: $('#edit-nickname').val() };
        var pw = $('#edit-password').val();
        if (pw) { data.password = pw; }

        $.ajax({
            url: '/admin/accounts/ajax-update/' + $('#edit-account-id').val(),
            method: 'PUT',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            contentType: 'application/json',
            data: JSON.stringify(data),
            success: function () { location.reload(); },
            error: function (xhr) { showMessage(getErrorMsg(xhr)); }
        });
    });

    // 調整狀態
    $('#form-change-status').on('submit', function (e) {
        e.preventDefault();
        $.ajax({
            url: '/admin/accounts/ajax-update/' + $('#status-user-id').val(),
            method: 'PUT',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            contentType: 'application/json',
            data: JSON.stringify({ status: parseInt($('input[name="status"]:checked').val(), 10) }),
            success: function () { location.reload(); },
            error: function (xhr) { showMessage(getErrorMsg(xhr)); }
        });
    });
});
</script>
@endsection
