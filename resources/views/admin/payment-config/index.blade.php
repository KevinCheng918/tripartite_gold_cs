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

    {{-- 站台餘點告警：全站台共用一份設定，跟下面 per-system 的繳款設定是兩件事 --}}
    <div class="main-card mb-3 card">
        <div class="card-header d-flex justify-content-between align-items-center"
             role="button" data-bs-toggle="collapse" data-bs-target="#pc-alert-section"
             aria-expanded="false" aria-controls="pc-alert-section">
            <div>
                <strong><i class="fas fa-exclamation-triangle me-1"></i>{{ trans('payment_config.alert_section_title') }}</strong>
                <small class="text-muted d-none d-md-inline ms-2">{{ trans('payment_config.alert_section_subtitle') }}</small>
            </div>
            <i class="fas fa-chevron-down"></i>
        </div>
        <div class="collapse" id="pc-alert-section">
            <div class="card-body">
                <small class="text-muted d-block d-md-none mb-3">{{ trans('payment_config.alert_section_subtitle') }}</small>

                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-bold" for="pc-alert-threshold">{{ trans('payment_config.alert_field_threshold') }}</label>
                        <input type="number" step="0.01" min="0" class="form-control" id="pc-alert-threshold"
                               value="{{ $alertSetting['threshold'] }}"
                               @if(!Auth::user()->hasPermission('payment_config.manage')) disabled @endif>
                        <small class="text-muted">{{ trans('payment_config.alert_threshold_hint') }}</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold" for="pc-alert-cooldown">{{ trans('payment_config.alert_field_cooldown') }}</label>
                        <div class="input-group">
                            <input type="number" step="1" min="0" max="365" class="form-control" id="pc-alert-cooldown"
                                   value="{{ $alertSetting['cooldown_days'] }}"
                                   @if(!Auth::user()->hasPermission('payment_config.manage')) disabled @endif>
                            <span class="input-group-text">天</span>
                        </div>
                        <small class="text-muted">{{ trans('payment_config.alert_cooldown_hint') }}</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold" for="pc-alert-template">{{ trans('payment_config.alert_field_template') }}</label>
                        <textarea class="form-control" id="pc-alert-template" rows="5"
                                  @if(!Auth::user()->hasPermission('payment_config.manage')) disabled @endif>{{ $alertSetting['template'] }}</textarea>
                        <small class="text-muted">{{ trans('payment_config.alert_template_hint') }}</small>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-bold" for="pc-topup-template">{{ trans('payment_config.topup_field_template') }}</label>
                        <textarea class="form-control" id="pc-topup-template" rows="4"
                                  @if(!Auth::user()->hasPermission('payment_config.manage')) disabled @endif>{{ $alertSetting['topup_template'] }}</textarea>
                        <small class="text-muted">{{ trans('payment_config.topup_template_hint') }}</small>
                        <div class="alert alert-info mt-2 mb-0 py-2">
                            <small><i class="fas fa-info-circle me-1"></i>{{ trans('payment_config.topup_condition_hint') }}</small>
                        </div>
                    </div>
                </div>

                <div class="alert alert-info mt-3 mb-0 py-2">
                    <small><i class="fas fa-info-circle me-1"></i>{{ trans('payment_config.alert_target_hint') }}</small>
                </div>

                <div class="d-flex gap-2 mt-3">
                    <button class="btn btn-outline-secondary" id="btn-pc-alert-preview">
                        <i class="fas fa-eye me-1"></i>{{ trans('payment_config.alert_preview') }}
                    </button>
                    @can('payment_config.manage')
                    <button class="btn btn-primary" id="btn-pc-alert-save">
                        <i class="fas fa-save me-1"></i>{{ trans('payment_config.action_save_alert') }}
                    </button>
                    @endcan
                </div>

                <div id="pc-alert-preview-wrap" class="mt-3 d-none">
                    <p class="text-muted mb-1">{{ trans('payment_config.alert_preview_title') }}：</p>
                    <div class="pc-template-box" id="pc-alert-preview-box"></div>
                </div>
            </div>
        </div>
    </div>

    {{-- 篩選 --}}
    <div class="main-card mb-3 card">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-auto">
                    <label class="form-label fw-bold">{{ trans('payment_config.field_system') }}</label>
                    <select id="filter-system" class="form-select">
                        <option value="">全部</option>
                        @foreach($systems as $sys)
                            <option value="{{ $sys->id }}" {{ ($systemId ?? '') == $sys->id ? 'selected' : '' }}>{{ $sys->name }}</option>
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
                        <button class="btn btn-sm btn-outline-secondary js-delete-config" data-id="{{ $config->id }}" data-title="{{ $config->title }}" data-system="{{ $config->system ? $config->system->name : '-' }}">
                            <i class="fas fa-trash me-1"></i>{{ trans('payment_config.action_delete') }}
                        </button>
                    </div>
                    @endif
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="{{ $config->image ? 'col-md-8' : 'col-12' }}">
                            <p class="text-muted mb-1">{{ trans('payment_config.field_content') }}：</p>
                            <div class="pc-content-box">{{ $config->content }}</div>
                            @if(filled($config->template))
                                <p class="text-muted mb-1 mt-3">{{ trans('payment_config.field_template') }}：</p>
                                <div class="pc-template-box">{{ $config->template }}</div>
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
                    <p><strong>確定要刪除此繳款設定？</strong></p>
                    <div id="pc-delete-detail" class="mb-3"></div>
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
            success: function (body) {
                hideBsModal(document.getElementById('modal-payment-config'));
                showMessage(body.message || '{{ trans("payment_config.msg.updated") }}');
                // OK 按下後 reload
                $('#modal-pc-msg').off('hidden.bs.modal.reload').on('hidden.bs.modal.reload', function () {
                    location.reload();
                });
            },
            error: function (xhr) {
                showMessage((xhr.responseJSON && xhr.responseJSON.message) || '操作失敗');
            }
        });
    });

    // 刪除
    var deleteId = null;
    $('.js-delete-config').on('click', function () {
        deleteId = $(this).data('id');
        var title = $(this).data('title');
        var system = $(this).data('system');
        $('#pc-delete-detail').html(
            '<table class="table table-sm text-center"><tbody>' +
            '<tr><th style="width:80px">系統</th><td>' + system + '</td></tr>' +
            '<tr><th>名稱</th><td>' + title + '</td></tr>' +
            '</tbody></table>'
        );
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

    // ── 站台餘點告警設定 ──

    // 展開/收起時箭頭跟著轉，否則看不出目前是開還是關
    $('#pc-alert-section')
        .on('show.bs.collapse', function () {
            $('[data-bs-target="#pc-alert-section"] .fa-chevron-down').addClass('fa-rotate-180');
        })
        .on('hide.bs.collapse', function () {
            $('[data-bs-target="#pc-alert-section"] .fa-chevron-down').removeClass('fa-rotate-180');
        });

    /*
     * 預覽走後端的 render-template，不在前端自己做字串替換 ——
     * 變數代換只有一份實作，客服看到的就是客戶會收到的。
     */
    $('#btn-pc-alert-preview').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true);

        $.ajax({
            url: '{{ route("admin.payment-config.ajax-render-template") }}',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            data: {
                template: $('#pc-alert-template').val(),
                station: '{{ trans("payment_config.alert_preview_station") }}',
                // 範例點數，只為了看格式；實際數字由每天的同步帶入
                credit: '16390.94',
                threshold: $('#pc-alert-threshold').val()
            },
            success: function (body) {
                $('#pc-alert-preview-box').text(body.text || '');
                $('#pc-alert-preview-wrap').removeClass('d-none');
            },
            error: function (xhr) {
                showMessage((xhr.responseJSON && xhr.responseJSON.message) || '預覽失敗');
            },
            complete: function () { $btn.prop('disabled', false); }
        });
    });

    $('#btn-pc-alert-save').on('click', function () {
        var $btn = $(this);
        var $fields = $('#pc-alert-threshold, #pc-alert-cooldown, #pc-alert-template, #pc-topup-template');

        // 存檔中鎖住欄位，避免等回傳的空檔又被改掉
        $btn.prop('disabled', true);
        $fields.prop('disabled', true);

        $.ajax({
            url: '{{ route("admin.payment-config.ajax-alert-setting") }}',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            data: {
                alert_template: $('#pc-alert-template').val(),
                topup_template: $('#pc-topup-template').val(),
                threshold: $('#pc-alert-threshold').val(),
                cooldown_days: $('#pc-alert-cooldown').val()
            },
            success: function (body) {
                showMessage(body.message || '{{ trans("payment_config.msg.alert_saved") }}');
            },
            error: function (xhr) {
                showMessage((xhr.responseJSON && xhr.responseJSON.message) || '{{ trans("payment_config.msg.alert_save_failed") }}');
            },
            complete: function () {
                $btn.prop('disabled', false);
                $fields.prop('disabled', false);
            }
        });
    });
});
</script>
@endsection
