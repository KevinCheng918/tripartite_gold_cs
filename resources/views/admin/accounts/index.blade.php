@extends('layouts.app')

@section('title', trans('account.page_title'))
@section('icon', 'users')
@section('subtitle', trans('account.subtitle'))

@section('content')

    {{-- 搜尋區 --}}
    <div class="main-card mb-3 card">
        <div class="card-header d-flex align-items-center justify-content-between">
            <div class="d-flex gap-2">
                @if(Auth::user()->isAdmin())
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-create-account">
                        <i class="fas fa-plus me-1"></i>{{ trans('account.action_create') }}
                    </button>
                @endif
            </div>
            <a href="javascript:void(0)" class="text-muted text-decoration-none" data-bs-toggle="collapse" data-bs-target="#account-search-collapse" aria-expanded="true">
                — 折疊 —
            </a>
        </div>
        <div class="collapse show" id="account-search-collapse">
            <div class="card-body pt-3">
                <form method="GET">
                    <div class="row g-3 mb-3">
                        <div class="col-md-3 col-6">
                            <label class="form-label fw-bold">{{ trans('account.field_account') }}：</label>
                            <input type="text" class="form-control" name="account" value="{{ $filters['account'] ?? '' }}" placeholder="{{ trans('account.field_account') }}">
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label fw-bold">{{ trans('account.field_nickname') }}：</label>
                            <input type="text" class="form-control" name="nickname" value="{{ $filters['nickname'] ?? '' }}" placeholder="{{ trans('account.field_nickname') }}">
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label fw-bold">{{ trans('account.field_status') }}：</label>
                            <select name="status" class="form-select">
                                <option value="">全部</option>
                                <option value="1" {{ ($filters['status'] ?? '') === '1' ? 'selected' : '' }}>{{ trans('account.status_normal') }}</option>
                                <option value="2" {{ ($filters['status'] ?? '') === '2' ? 'selected' : '' }}>{{ trans('account.status_lock') }}</option>
                                <option value="0" {{ ($filters['status'] ?? '') === '0' ? 'selected' : '' }}>{{ trans('account.status_deactivate') }}</option>
                            </select>
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label fw-bold">{{ trans('account.field_level') }}：</label>
                            <select name="level" class="form-select">
                                <option value="">全部</option>
                                <option value="1" {{ ($filters['level'] ?? '') === '1' ? 'selected' : '' }}>{{ trans('account.level_cs') }}</option>
                            </select>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end gap-2">
                        <a href="{{ route('admin.accounts.index') }}" class="btn btn-outline-secondary">重置</a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search me-1"></i>搜尋
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- 統計 --}}
    <div class="main-card mb-3 card">
        <div class="card-body py-2">
            <div class="d-flex flex-wrap gap-4 align-items-center">
                <span><span class="badge bg-success">{{ trans('account.status_normal') }}</span> <strong>{{ $accountStats['normal'] }}</strong></span>
                <span><span class="badge bg-warning text-dark">{{ trans('account.status_lock') }}</span> <strong>{{ $accountStats['lock'] }}</strong></span>
                <span><span class="badge bg-danger">{{ trans('account.status_deactivate') }}</span> <strong>{{ $accountStats['deactivate'] }}</strong></span>
                <span class="ms-auto fw-bold">客服總數：{{ $accountStats['total'] }} 人</span>
            </div>
        </div>
    </div>

    <div class="main-card mb-3 card">
        {{-- 桌面版：表格 --}}
        <div class="card-body p-0 d-none d-md-block">
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
                                            data-nickname="{{ $account->nickname }}"
                                            data-project-ids="{{ json_encode($account->project_ids ?? []) }}">
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
            <div class="card-footer d-none d-md-block">
                {{ $accounts->links() }}
            </div>
        @endif
    </div>

    {{-- 手機版：獨立卡片 --}}
    <div class="d-md-none">
        @php $idx = 0; @endphp
        @forelse($accounts as $account)
            @if($account->level == config('constants.USER.LEVEL.ADMIN'))
                @continue
            @endif
            @php $idx++; @endphp
            <div class="card mb-2 shadow-sm">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <strong style="font-size:1.0625rem">{{ $account->nickname }}</strong>
                            <div class="text-muted" style="font-size:0.8125rem">{{ $account->account }}</div>
                        </div>
                        <div class="d-flex gap-1 align-items-center">
                            @if($account->status == config('constants.USER.STATUS.NORMAL'))
                                <span class="badge bg-success">{{ trans('account.status_normal') }}</span>
                            @elseif($account->status == config('constants.USER.STATUS.LOCK'))
                                <span class="badge bg-warning text-dark">{{ trans('account.status_lock') }}</span>
                            @else
                                <span class="badge bg-danger">{{ trans('account.status_deactivate') }}</span>
                            @endif
                        </div>
                    </div>
                    <div class="d-grid gap-1" style="grid-template-columns: 1fr 1fr">
                        <button class="btn btn-sm btn-outline-secondary js-edit"
                                data-id="{{ $account->id }}"
                                data-nickname="{{ $account->nickname }}">
                            <i class="fas fa-edit me-1"></i>{{ trans('account.action_edit') }}
                        </button>
                        <button class="btn btn-sm btn-outline-secondary js-change-status"
                                data-id="{{ $account->id }}"
                                data-status="{{ $account->status }}">
                            <i class="fas fa-exchange-alt me-1"></i>{{ trans('account.action_change_status') }}
                        </button>
                        <a href="{{ route('admin.accounts.permissions', $account->id) }}"
                           class="btn btn-sm btn-outline-secondary" style="grid-column: 1 / -1">
                            <i class="fas fa-key me-1"></i>{{ trans('account.action_assign_permissions') }}
                        </a>
                    </div>
                </div>
            </div>
        @empty
            <div class="text-center text-muted py-4">暫無資料</div>
        @endforelse
        @if($accounts instanceof \Illuminate\Pagination\LengthAwarePaginator && $accounts->hasPages())
            <div class="mt-2">{{ $accounts->links() }}</div>
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
                        <div class="mb-3">
                            <label class="form-label">參與專案</label>
                            <div id="edit-project-list" style="max-height:150px;overflow-y:auto;border:1px solid #dee2e6;border-radius:0.25rem;padding:0.5rem">
                                @foreach($projects as $p)
                                    <div class="form-check">
                                        <input class="form-check-input js-edit-project-check" type="checkbox" value="{{ $p->id }}" id="edit-project-{{ $p->id }}">
                                        <label class="form-check-label" for="edit-project-{{ $p->id }}">{{ $p->name }}</label>
                                    </div>
                                @endforeach
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

    // 折疊文字切換
    var $acCollapse = $('#account-search-collapse');
    var $acToggle = $('[data-bs-target="#account-search-collapse"]');
    $acCollapse.on('show.bs.collapse', function () { $acToggle.text('— 折疊 —'); });
    $acCollapse.on('hide.bs.collapse', function () { $acToggle.text('— 展開 —'); });

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
        // 帶入參與專案
        var projectIds = $btn.data('project-ids') || [];
        $('.js-edit-project-check').each(function () {
            $(this).prop('checked', projectIds.indexOf(parseInt($(this).val(), 10)) !== -1);
        });
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
        var projectIds = [];
        $('.js-edit-project-check:checked').each(function () { projectIds.push(parseInt($(this).val(), 10)); });
        data.project_ids = projectIds;

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
