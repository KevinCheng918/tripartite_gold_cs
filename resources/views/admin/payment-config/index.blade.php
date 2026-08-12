@extends('layouts.app')

@section('title', trans('payment_config.page_title'))
@section('icon', 'credit-card')
@section('subtitle', trans('payment_config.subtitle'))

@section('content')

    @if(Auth::user()->hasPermission('payment_config.manage'))
    <div class="mb-3">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-payment-config">
            <i class="fas fa-plus me-1"></i>{{ trans('payment_config.action_create') }}
        </button>
    </div>
    @endif

    {{-- 篩選 --}}
    <div class="main-card mb-3 card">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-auto">
                    <label class="form-label fw-bold">{{ trans('payment_config.field_system') }}</label>
                    <select id="filter-system" class="form-select">
                        <option value="">全部</option>
                        @foreach($systems as $sys)
                            <option value="{{ $sys->id }}">{{ $sys->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <button class="btn btn-primary" id="btn-filter">
                        <i class="fas fa-search me-1"></i>搜尋
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- 列表 --}}
    <div id="config-list">
        @forelse($configs as $config)
            <div class="main-card mb-3 card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <strong>{{ $config->title }}</strong>
                        <span class="badge bg-secondary ms-2">{{ $config->system ? $config->system->name : '-' }}</span>
                        @if($config->status == 1)
                            <span class="badge bg-success ms-1">{{ trans('payment_config.status_active') }}</span>
                        @else
                            <span class="badge bg-danger ms-1">{{ trans('payment_config.status_disabled') }}</span>
                        @endif
                    </div>
                    @if(Auth::user()->hasPermission('payment_config.manage'))
                    <div class="d-flex gap-1">
                        <button class="btn btn-sm btn-outline-secondary js-edit-config"
                                data-id="{{ $config->id }}"
                                data-system-id="{{ $config->system_id }}"
                                data-title="{{ $config->title }}"
                                data-content="{{ $config->content }}"
                                data-template="{{ $config->template }}"
                                data-status="{{ $config->status }}"
                                data-sort="{{ $config->sort_order }}">
                            <i class="fas fa-edit me-1"></i>{{ trans('payment_config.action_edit') }}
                        </button>
                        <button class="btn btn-sm btn-outline-secondary js-delete-config" data-id="{{ $config->id }}">
                            <i class="fas fa-trash me-1"></i>{{ trans('payment_config.action_delete') }}
                        </button>
                    </div>
                    @endif
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="{{ $config->image ? 'col-md-8' : 'col-12' }}">
                            <p class="text-muted mb-1">{{ trans('payment_config.field_content') }}：</p>
                            <div style="white-space:pre-wrap;background:#f8f9fa;padding:0.75rem;border-radius:0.375rem;font-size:0.875rem">{{ $config->content }}</div>
                            @if(filled($config->template))
                                <p class="text-muted mb-1 mt-3">{{ trans('payment_config.field_template') }}：</p>
                                <div style="white-space:pre-wrap;background:#fef3c7;padding:0.75rem;border-radius:0.375rem;font-size:0.875rem">{{ $config->template }}</div>
                            @endif
                        </div>
                        @if($config->image)
                        <div class="col-md-4 mt-3 mt-md-0">
                            <p class="text-muted mb-1">{{ trans('payment_config.field_image') }}：</p>
                            <img src="{{ asset("storage/{$config->image}") }}" style="max-width:100%;border-radius:0.375rem" alt="payment">
                        </div>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="text-center text-muted py-4">暫無資料</div>
        @endforelse
    </div>

    {{-- 新增/編輯 Modal --}}
    <div class="modal fade" id="modal-payment-config" tabindex="-1">
        <div class="modal-dialog modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ trans('payment_config.action_create') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="form-payment-config" enctype="multipart/form-data">
                        <input type="hidden" id="config-id">
                        <div class="mb-3">
                            <label class="form-label">{{ trans('payment_config.field_system') }}</label>
                            <select id="config-system" class="form-select" required>
                                @foreach($systems as $sys)
                                    <option value="{{ $sys->id }}">{{ $sys->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('payment_config.field_title') }}</label>
                            <input id="config-title" type="text" class="form-control" required placeholder="例：銀行轉帳、USDT">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('payment_config.field_content') }}</label>
                            <textarea id="config-content" class="form-control" rows="4" required placeholder="帳戶資訊、收款地址等"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('payment_config.field_template') }}</label>
                            <textarea id="config-template" class="form-control" rows="5" placeholder="{{ trans('payment_config.template_example') }}"></textarea>
                            <small class="text-muted">{{ trans('payment_config.template_hint') }}</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('payment_config.field_image') }}</label>
                            <input id="config-image" type="file" class="form-control" accept="image/*">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('payment_config.field_sort') }}</label>
                            <input id="config-sort" type="number" class="form-control" value="0" min="0">
                        </div>
                        <div class="text-end">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                            <button type="submit" class="btn btn-primary">確認</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- 訊息 Modal --}}
    <div class="modal fade" id="modal-pc-msg" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-body text-center py-4">
                    <p id="modal-pc-msg-text" class="mb-3"></p>
                    <div class="text-end">
                        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- 刪除確認 Modal --}}
    <div class="modal fade" id="modal-pc-confirm" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-body py-4">
                    <p class="mb-3">確定要刪除此繳款設定？</p>
                    <div class="text-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                        <button type="button" class="btn btn-danger" id="btn-confirm-delete">刪除</button>
                    </div>
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
        $('#modal-pc-msg-text').text(msg);
        var hasBackdrop = document.querySelectorAll('.modal-backdrop').length > 0;
        if (hasBackdrop) {
            setTimeout(function () { showBsModal('modal-pc-msg'); }, 400);
        } else {
            showBsModal('modal-pc-msg');
        }
    }

    // 篩選
    $('#btn-filter').on('click', function () {
        var systemId = $('#filter-system').val();
        var url = systemId ? '?system_id=' + systemId : '';
        location.href = '{{ route("admin.payment-config.index") }}' + url;
    });

    // 新增 Modal 清空
    $('[data-bs-target="#modal-payment-config"]').on('click', function () {
        $('#config-id').val('');
        $('#form-payment-config')[0].reset();
        $('#modal-payment-config .modal-title').text('{{ trans("payment_config.action_create") }}');
    });

    // 編輯
    $('.js-edit-config').on('click', function () {
        var $btn = $(this);
        $('#config-id').val($btn.data('id'));
        $('#config-system').val($btn.data('system-id'));
        $('#config-title').val($btn.data('title'));
        $('#config-content').val($btn.data('content'));
        $('#config-template').val($btn.data('template'));
        $('#config-sort').val($btn.data('sort'));
        $('#config-image').val('');
        $('#modal-payment-config .modal-title').text('{{ trans("payment_config.action_edit") }}');
        showBsModal('modal-payment-config');
    });

    // 新增/編輯提交
    $('#form-payment-config').on('submit', function (e) {
        e.preventDefault();
        var id = $('#config-id').val();
        var url = id ? '/admin/payment-config/ajax-update/' + id : '/admin/payment-config/ajax-store';

        var formData = new FormData();
        formData.append('system_id', $('#config-system').val());
        formData.append('title', $('#config-title').val());
        formData.append('content', $('#config-content').val());
        formData.append('template', $('#config-template').val());
        formData.append('sort_order', $('#config-sort').val());

        var imageFile = document.getElementById('config-image').files[0];
        if (imageFile) { formData.append('image', imageFile); }

        $.ajax({
            url: url,
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            data: formData,
            processData: false,
            contentType: false,
            success: function () { location.reload(); },
            error: function (xhr) {
                showMessage((xhr.responseJSON && xhr.responseJSON.message) || '操作失敗');
            }
        });
    });

    // 刪除
    var deleteId = null;
    $('.js-delete-config').on('click', function () {
        deleteId = $(this).data('id');
        showBsModal('modal-pc-confirm');
    });

    $('#btn-confirm-delete').on('click', function () {
        if (!deleteId) { return; }
        $.ajax({
            url: '/admin/payment-config/ajax-delete/' + deleteId,
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            success: function () { location.reload(); },
            error: function (xhr) {
                hideBsModal(document.getElementById('modal-pc-confirm'));
                showMessage((xhr.responseJSON && xhr.responseJSON.message) || '刪除失敗');
            }
        });
    });
});
</script>
@endsection
