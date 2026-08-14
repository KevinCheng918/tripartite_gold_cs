@extends('layouts.app')

@section('title', trans('broadcast.page_title'))
@section('icon', 'bullhorn')
@section('subtitle', trans('broadcast.subtitle'))

@section('content')

    {{-- Tab 切換 --}}
    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-broadcast-send">
                <i class="fas fa-paper-plane me-1"></i>{{ trans('broadcast.tab_send') }}
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-broadcast-history">
                <i class="fas fa-history me-1"></i>{{ trans('broadcast.tab_history') }}
            </button>
        </li>
    </ul>

    <div class="tab-content">
        {{-- 發送公告 Tab --}}
        <div class="tab-pane fade show active" id="tab-broadcast-send">
            <form id="form-broadcast">
            <div class="row">
                {{-- 左側：公告內容 --}}
                <div class="col-md-7 mb-3 d-flex">
                    <div class="main-card card w-100">
                        <div class="card-header fw-bold">
                            <i class="fas fa-paper-plane me-2 text-muted"></i>{{ trans('broadcast.tab_send') }}
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">{{ trans('broadcast.field_content') }} <span class="text-danger">*</span></label>
                                <textarea id="bc-content" class="form-control" rows="10" required placeholder="{{ trans('broadcast.field_content') }}..."></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">圖片（選填，可多選）</label>
                                <input id="bc-images" type="file" class="form-control" accept="image/*" multiple>
                                <div id="bc-image-preview" class="mt-2 d-flex flex-wrap gap-2" style="display:none"></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">{{ trans('broadcast.field_target') }} <span class="text-danger">*</span></label>
                                <div class="d-flex gap-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="target_type" value="1" id="target-all" checked>
                                        <label class="form-check-label" for="target-all">{{ trans('broadcast.target_all') }}</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="target_type" value="2" id="target-selected">
                                        <label class="form-check-label" for="target-selected">{{ trans('broadcast.target_selected') }}</label>
                                    </div>
                                </div>
                            </div>
                            <div class="text-end">
                                <button type="submit" class="btn btn-primary" id="btn-send">
                                    <i class="fas fa-paper-plane me-1"></i>{{ trans('broadcast.btn_send') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- 右側：發送範圍 --}}
                <div class="col-md-5 mb-3 d-flex">
                    <div class="main-card card w-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span class="fw-bold"><i class="fas fa-users me-2 text-muted"></i>發送範圍</span>
                            <div class="d-flex gap-2">
                                <select id="bc-system-filter" class="form-select form-select-sm" style="width:auto">
                                    <option value="">全部系統</option>
                                    @foreach($systems as $sys)
                                        <option value="{{ $sys->id }}">{{ $sys->name }}</option>
                                    @endforeach
                                </select>
                                <input type="text" id="bc-search" class="form-control form-control-sm" placeholder="搜尋..." style="width:120px">
                            </div>
                        </div>
                        <div class="card-body" id="group-list-wrap" style="overflow-y:auto;flex:1">
                            {{-- 全部群組時顯示提示 --}}
                            <div id="bc-all-hint" class="text-center text-muted py-4">
                                <i class="fas fa-users fa-2x mb-2 d-block"></i>
                                將發送給所有正常狀態的群組
                            </div>
                            {{-- 指定群組時顯示勾選列表 --}}
                            <div id="bc-group-checkboxes" style="display:none">
                                <div class="mb-2">
                                    <label class="form-check d-inline-block me-3">
                                        <input type="checkbox" class="form-check-input" id="bc-select-all">
                                        <span class="form-check-label fw-bold">全選</span>
                                    </label>
                                    <small class="text-muted" id="bc-selected-count">已選 0 個</small>
                                </div>
                                <div id="bc-group-list">
                                    @foreach($groups as $g)
                                        <div class="form-check bc-group-item" data-system-id="{{ $g['system_id'] ?? '' }}" data-name="{{ strtolower($g['title']) }}">
                                            <input class="form-check-input bc-group-cb" type="checkbox" name="group_ids[]" value="{{ $g['id'] }}" id="grp-{{ $g['id'] }}">
                                            <label class="form-check-label" for="grp-{{ $g['id'] }}">
                                                {{ $g['title'] }}
                                                @if(!empty($g['system']))
                                                    <small class="text-muted">({{ $g['system'] }})</small>
                                                @endif
                                            </label>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            </form>
        </div>

        {{-- 歷史紀錄 Tab --}}
        <div class="tab-pane fade" id="tab-broadcast-history">
            {{-- 桌面版：表格 --}}
            <div class="main-card mb-3 card d-none d-md-block">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>{{ trans('broadcast.field_time') }}</th>
                                    <th>{{ trans('broadcast.field_sender') }}</th>
                                    <th>{{ trans('broadcast.field_target') }}</th>
                                    <th>{{ trans('broadcast.field_content') }}</th>
                                    <th>{{ trans('broadcast.field_total') }}</th>
                                    <th>{{ trans('broadcast.field_success') }}</th>
                                    <th>{{ trans('broadcast.field_fail') }}</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($history as $record)
                                    <tr>
                                        <td>{{ $record->sent_at ? $record->sent_at->format('m/d H:i') : '-' }}</td>
                                        <td>{{ $record->sender ? $record->sender->nickname : '-' }}</td>
                                        <td>
                                            @if($record->target_type == 1)
                                                <span class="badge bg-primary">{{ trans('broadcast.target_all') }}</span>
                                            @else
                                                <span class="badge bg-secondary">{{ trans('broadcast.target_selected') }}</span>
                                            @endif
                                        </td>
                                        <td>{{ Str::limit($record->content, 50) }}</td>
                                        <td>{{ $record->total_count }}</td>
                                        <td><span class="badge bg-success">{{ $record->success_count }}</span></td>
                                        <td>
                                            @if($record->fail_count > 0)
                                                <span class="badge bg-danger">{{ $record->fail_count }}</span>
                                            @else
                                                0
                                            @endif
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-secondary js-copy-content" data-content="{{ $record->content }}">
                                                <i class="fas fa-copy me-1"></i>複製
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="text-center text-muted py-4">{{ trans('broadcast.no_history') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                @if($history->hasPages())
                    <div class="card-footer">{{ $history->links() }}</div>
                @endif
            </div>

            {{-- 手機版：卡片 --}}
            <div class="d-md-none">
                @forelse($history as $record)
                    <div class="card mb-2 shadow-sm">
                        <div class="card-body py-3">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <strong>{{ $record->sender ? $record->sender->nickname : '-' }}</strong>
                                    <div class="text-muted" style="font-size:0.8125rem">{{ $record->sent_at ? $record->sent_at->format('m/d H:i') : '-' }}</div>
                                </div>
                                @if($record->target_type == 1)
                                    <span class="badge bg-primary">{{ trans('broadcast.target_all') }}</span>
                                @else
                                    <span class="badge bg-secondary">{{ trans('broadcast.target_selected') }}</span>
                                @endif
                            </div>
                            <div class="mb-2" style="font-size:0.875rem; white-space:pre-wrap; word-break:break-all">{{ $record->content }}</div>
                            <div class="d-flex justify-content-between align-items-center">
                                <div style="font-size:0.8125rem">
                                    <span class="badge bg-success">{{ $record->success_count }}</span>
                                    @if($record->fail_count > 0)
                                        <span class="badge bg-danger">{{ $record->fail_count }}</span>
                                    @endif
                                    <span class="text-muted">/ {{ $record->total_count }}</span>
                                </div>
                                <button class="btn btn-sm btn-outline-secondary js-copy-content" data-content="{{ $record->content }}">
                                    <i class="fas fa-copy me-1"></i>複製
                                </button>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="text-center text-muted py-4">{{ trans('broadcast.no_history') }}</div>
                @endforelse
                @if($history->hasPages())
                    <div class="mt-2">{{ $history->links() }}</div>
                @endif
            </div>
        </div>
    </div>

    {{-- 訊息 Modal --}}
    <div class="modal fade" id="modal-broadcast-msg" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-body text-center py-4">
                    <p id="modal-broadcast-msg-text" class="mb-3"></p>
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
        $('#modal-broadcast-msg-text').text(msg);
        showBsModal('modal-broadcast-msg');
    }

    // 切換全部/指定
    $('input[name="target_type"]').on('change', function () {
        var isSelected = $(this).val() === '2';
        $('#bc-all-hint').toggle(!isSelected);
        $('#bc-group-checkboxes').toggle(isSelected);
    });

    // 系統篩選
    $('#bc-system-filter').on('change', function () {
        var sysId = $(this).val();
        $('.bc-group-item').each(function () {
            var match = !sysId || $(this).data('system-id') == sysId;
            $(this).toggle(match);
        });
    });

    // 搜尋
    $('#bc-search').on('input', function () {
        var kw = $(this).val().toLowerCase();
        $('.bc-group-item').each(function () {
            var name = $(this).data('name') || '';
            $(this).toggle(name.indexOf(kw) !== -1);
        });
    });

    // 全選
    $('#bc-select-all').on('change', function () {
        var checked = $(this).prop('checked');
        $('.bc-group-item:visible .bc-group-cb').prop('checked', checked);
        updateSelectedCount();
    });

    // 計數
    function updateSelectedCount() {
        var visibleCbs = $('.bc-group-item:visible .bc-group-cb');
        var checkedCount = visibleCbs.filter(':checked').length;
        $('#bc-selected-count').text('已選 ' + checkedCount + ' 個');
        $('#bc-select-all').prop('checked', visibleCbs.length > 0 && checkedCount === visibleCbs.length);
    }
    $(document).on('change', '.bc-group-cb', updateSelectedCount);

    // 多張圖片預覽
    $('#bc-images').on('change', function () {
        var files = this.files;
        var $preview = $('#bc-image-preview');
        $preview.empty();
        if (files.length === 0) { $preview.hide(); return; }

        for (var i = 0; i < files.length; i++) {
            (function (file) {
                var reader = new FileReader();
                reader.onload = function (e) {
                    $preview.append(
                        '<div class="position-relative">' +
                        '<img src="' + e.target.result + '" style="width:80px;height:80px;object-fit:cover;border-radius:0.375rem" alt="preview">' +
                        '</div>'
                    );
                };
                reader.readAsDataURL(file);
            })(files[i]);
        }
        $preview.show();
    });

    // 發送
    $('#form-broadcast').on('submit', function (e) {
        e.preventDefault();
        var content = $('#bc-content').val().trim();
        if (!content) { showMessage('{{ trans("broadcast.msg.content_required") }}'); return; }

        var targetType = parseInt($('input[name="target_type"]:checked').val(), 10);

        if (targetType === 2) {
            var ids = [];
            $('.bc-group-cb:checked').each(function () { ids.push(parseInt($(this).val(), 10)); });
            if (ids.length === 0) { showMessage('{{ trans("broadcast.msg.no_group_selected") }}'); return; }
        }

        var formData = new FormData();
        formData.append('content', content);
        formData.append('target_type', targetType);

        if (targetType === 2) {
            ids.forEach(function (id) { formData.append('group_ids[]', id); });
        }

        var imageFiles = document.getElementById('bc-images').files;
        for (var i = 0; i < imageFiles.length; i++) {
            formData.append('images[]', imageFiles[i]);
        }

        var $btn = $('#btn-send');
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i>發送中...');

        $.ajax({
            url: '/admin/telegram-broadcast/ajax-send',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            data: formData,
            processData: false,
            contentType: false,
            success: function (body) {
                $btn.prop('disabled', false).html('<i class="fas fa-paper-plane me-1"></i>{{ trans("broadcast.btn_send") }}');
                $('#bc-content').val('');
                showMessage(body.message || '已發送');
                setTimeout(function () { location.reload(); }, 1500);
            },
            error: function (xhr) {
                $btn.prop('disabled', false).html('<i class="fas fa-paper-plane me-1"></i>{{ trans("broadcast.btn_send") }}');
                showMessage((xhr.responseJSON && xhr.responseJSON.message) || '發送失敗');
            }
        });
    });

    // 複製公告內容
    $('.js-copy-content').on('click', function () {
        var content = $(this).data('content');
        var $btn = $(this);
        if (navigator.clipboard) {
            navigator.clipboard.writeText(content).then(function () {
                $btn.html('<i class="fas fa-check me-1"></i>已複製');
                setTimeout(function () { $btn.html('<i class="fas fa-copy me-1"></i>複製'); }, 1500);
            });
        } else {
            // fallback
            var ta = document.createElement('textarea');
            ta.value = content;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            $btn.html('<i class="fas fa-check me-1"></i>已複製');
            setTimeout(function () { $btn.html('<i class="fas fa-copy me-1"></i>複製'); }, 1500);
        }
    });
});
</script>
@endsection
