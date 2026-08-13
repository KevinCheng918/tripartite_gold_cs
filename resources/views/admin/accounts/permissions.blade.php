@extends('layouts.app')

@section('title', trans('account.action_assign_permissions'))
@section('icon', 'key')
@section('subtitle', $targetUser->nickname . '（' . $targetUser->account . '）')

@section('content')

    <div class="mb-3">
        <a href="{{ route('admin.accounts.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i>返回帳號管理
        </a>
    </div>

    <form id="form-permissions">
        <div class="row">
        @foreach($permissionMap as $group)
            <div class="col-md-4 mb-3">
                <div class="main-card card">
                    <div class="card-header py-2 d-flex justify-content-between align-items-center">
                        <strong style="font-size:0.875rem">{{ $group['label'] }}</strong>
                        <button type="button" class="btn btn-sm btn-outline-primary js-toggle-all" style="font-size:0.75rem;padding:0.15rem 0.5rem">全選</button>
                    </div>
                    <div class="card-body py-1">
                        @foreach($group['keywords'] as $item)
                            <div class="form-check" style="margin-bottom:0.15rem">
                                <input class="form-check-input" type="checkbox"
                                       name="permissions[]"
                                       value="{{ $item['keyword'] }}"
                                       id="perm-{{ $item['keyword'] }}"
                                       {{ in_array($item['keyword'], $currentKeywords) ? 'checked' : '' }}>
                                <label class="form-check-label" for="perm-{{ $item['keyword'] }}" style="font-size:0.8125rem">
                                    {{ $item['label'] }}
                                </label>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endforeach
        </div>

        <div class="text-end mb-4">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save me-1"></i>儲存權限
            </button>
        </div>
    </form>

    {{-- 訊息 Modal --}}
    <div class="modal fade" id="modal-perm-message" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-body text-center py-4">
                    <p id="modal-perm-message-text" class="mb-3"></p>
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
        $('#modal-perm-message-text').text(msg);
        showBsModal('modal-perm-message');
    }

    // 全選/取消全選
    $('.js-toggle-all').on('click', function () {
        var $card = $(this).closest('.card');
        var $checkboxes = $card.find('input[type="checkbox"]');
        var allChecked = $checkboxes.length === $checkboxes.filter(':checked').length;
        $checkboxes.prop('checked', !allChecked);
        $(this).text(allChecked ? '全選' : '取消全選');
    });

    // 儲存
    $('#form-permissions').on('submit', function (e) {
        e.preventDefault();
        var keywords = [];
        $('input[name="permissions[]"]:checked').each(function () {
            keywords.push($(this).val());
        });

        $.ajax({
            url: '/admin/accounts/ajax-assign-permissions/{{ $targetUser->id }}',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            contentType: 'application/json',
            data: JSON.stringify({ permissions: keywords }),
            success: function () {
                showMessage('權限已更新');
            },
            error: function () {
                showMessage('儲存失敗');
            }
        });
    });
});
</script>
@endsection
