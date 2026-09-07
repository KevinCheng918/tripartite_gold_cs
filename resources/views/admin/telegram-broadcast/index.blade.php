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
                                <textarea id="bc-content" class="form-control" rows="10" required maxlength="4096" placeholder="{{ trans('broadcast.field_content') }}..."></textarea>
                                <div class="text-end mt-1" style="font-size:0.8125rem"><span id="bc-content-count">0</span> / 4096</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">{{ trans('broadcast.field_image') }}</label>
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
                            {{-- 預約傳送：勾了才出現時間欄位，平常不佔版面 --}}
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="bc-schedule-enable">
                                    <label class="form-check-label" for="bc-schedule-enable">
                                        <i class="far fa-clock me-1 text-muted"></i>{{ trans('broadcast.schedule_enable') }}
                                    </label>
                                </div>
                                <div id="bc-schedule-wrap" class="mt-2 d-none">
                                    <label class="form-label">{{ trans('broadcast.field_schedule') }} <span class="text-danger">*</span></label>
                                    <input type="datetime-local" id="bc-scheduled-at" class="form-control" step="60">
                                    <div class="form-text">{{ trans('broadcast.schedule_hint') }}</div>
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
                            <span class="fw-bold"><i class="fas fa-users me-2 text-muted"></i>{{ trans('broadcast.field_scope') }}</span>
                            <div class="d-flex gap-2">
                                <select id="bc-system-filter" class="form-select form-select-sm" style="width:auto">
                                    <option value="">{{ trans('broadcast.all_systems') }}</option>
                                    @foreach($systems as $sys)
                                        <option value="{{ $sys->id }}">{{ $sys->name }}</option>
                                    @endforeach
                                </select>
                                <input type="text" id="bc-search" class="form-control form-control-sm" placeholder="{{ trans('broadcast.search_placeholder') }}" style="width:120px">
                            </div>
                        </div>
                        <div class="card-body" id="group-list-wrap" style="overflow-y:auto;flex:1">
                            {{-- 全部群組時顯示提示 --}}
                            <div id="bc-all-hint" class="text-center text-muted py-4">
                                <i class="fas fa-users fa-2x mb-2 d-block"></i>
                                {{ trans('broadcast.all_hint') }}
                            </div>
                            {{-- 指定群組時顯示勾選列表 --}}
                            <div id="bc-group-checkboxes" style="display:none">
                                <div class="mb-2">
                                    <label class="form-check d-inline-block me-3">
                                        <input type="checkbox" class="form-check-input" id="bc-select-all">
                                        <span class="form-check-label fw-bold">{{ trans('broadcast.select_all') }}</span>
                                    </label>
                                    <small class="text-muted" id="bc-selected-count">{{ trans('broadcast.selected_count', ['count' => 0]) }}</small>
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
                                    <th>{{ trans('broadcast.field_status') }}</th>
                                    <th>{{ trans('broadcast.field_time') }}</th>
                                    <th>{{ trans('broadcast.field_sender') }}</th>
                                    <th>{{ trans('broadcast.field_target') }}</th>
                                    <th>{{ trans('broadcast.field_content') }}</th>
                                    <th>{{ trans('broadcast.field_total') }}</th>
                                    <th>{{ trans('broadcast.field_success') }}</th>
                                    <th>{{ trans('broadcast.field_fail') }}</th>
                                    <th>{{ trans('broadcast.field_action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($history as $record)
                                    <tr>
                                        <td>@include('admin.telegram-broadcast.partials.status-badge', ['record' => $record])</td>
                                        {{-- 預約中的還沒送出，顯示預約時間才知道何時會發 --}}
                                        <td>
                                            @if($record->status === \App\Models\TelegramBroadcast::STATUS_PENDING && $record->scheduled_at)
                                                <i class="far fa-clock me-1 text-muted"></i>{{ $record->scheduled_at->format('m/d H:i') }}
                                            @else
                                                {{ $record->sent_at ? $record->sent_at->format('m/d H:i') : '-' }}
                                            @endif
                                        </td>
                                        <td>{{ $record->sender ? $record->sender->nickname : '-' }}</td>
                                        <td>
                                            @if($record->target_type == 1)
                                                <a href="javascript:void(0)" class="badge bg-primary js-show-send-detail" style="cursor:pointer;text-decoration:none" data-results="{{ json_encode($record->send_results ?? []) }}">{{ trans('broadcast.target_all') }}</a>
                                            @else
                                                <a href="javascript:void(0)" class="badge bg-secondary js-show-send-detail" style="cursor:pointer;text-decoration:none" data-results="{{ json_encode($record->send_results ?? []) }}">{{ trans('broadcast.target_selected') }}</a>
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
                                                <i class="fas fa-copy me-1"></i>{{ trans('broadcast.btn_copy') }}
                                            </button>
                                            @if($record->status === \App\Models\TelegramBroadcast::STATUS_PENDING)
                                                <button class="btn btn-sm btn-outline-secondary text-danger js-cancel-schedule ms-1" data-id="{{ $record->id }}">
                                                    <i class="fas fa-ban me-1"></i>{{ trans('broadcast.btn_cancel_schedule') }}
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="9" class="text-center text-muted py-4">{{ trans('broadcast.no_history') }}</td>
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
                                    @include('admin.telegram-broadcast.partials.status-badge', ['record' => $record])
                                    <div class="text-muted" style="font-size:0.8125rem">
                                        @if($record->status === \App\Models\TelegramBroadcast::STATUS_PENDING && $record->scheduled_at)
                                            <i class="far fa-clock me-1"></i>{{ $record->scheduled_at->format('m/d H:i') }}
                                        @else
                                            {{ $record->sent_at ? $record->sent_at->format('m/d H:i') : '-' }}
                                        @endif
                                    </div>
                                </div>
                                @if($record->target_type == 1)
                                    <a href="javascript:void(0)" class="badge bg-primary js-show-send-detail" style="cursor:pointer;text-decoration:none" data-results="{{ json_encode($record->send_results ?? []) }}">{{ trans('broadcast.target_all') }}</a>
                                @else
                                    <a href="javascript:void(0)" class="badge bg-secondary js-show-send-detail" style="cursor:pointer;text-decoration:none" data-results="{{ json_encode($record->send_results ?? []) }}">{{ trans('broadcast.target_selected') }}</a>
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
                                <div class="d-flex gap-1">
                                    <button class="btn btn-sm btn-outline-secondary js-copy-content" data-content="{{ $record->content }}">
                                        <i class="fas fa-copy me-1"></i>{{ trans('broadcast.btn_copy') }}
                                    </button>
                                    @if($record->status === \App\Models\TelegramBroadcast::STATUS_PENDING)
                                        <button class="btn btn-sm btn-outline-secondary text-danger js-cancel-schedule" data-id="{{ $record->id }}">
                                            <i class="fas fa-ban"></i>
                                        </button>
                                    @endif
                                </div>
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

    {{-- 取消預約確認 Modal（專案規範不使用原生 confirm） --}}
    <div class="modal fade" id="modal-broadcast-confirm" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-body text-center py-4">
                    <p id="modal-broadcast-confirm-text" class="mb-3"></p>
                    <div class="d-flex gap-2 justify-content-center">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ trans('broadcast.btn_cancel') }}</button>
                        <button type="button" class="btn btn-primary" id="btn-broadcast-confirm-ok">{{ trans('broadcast.btn_confirm') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- 發送明細 Modal --}}
    <div class="modal fade" id="modal-send-detail" tabindex="-1">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-list-alt me-2"></i>{{ trans('broadcast.send_detail') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="send-detail-empty" class="text-center text-muted py-4" style="display:none">
                        <i class="fas fa-inbox fa-2x mb-2 d-block"></i>{{ trans('broadcast.no_send_detail') }}
                    </div>
                    <div id="send-detail-table-wrap" style="display:none">
                        <div class="d-flex gap-3 mb-3" id="send-detail-summary"></div>
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>{{ trans('broadcast.field_station') }}</th>
                                    <th style="width:80px;text-align:center">{{ trans('broadcast.field_status') }}</th>
                                </tr>
                            </thead>
                            <tbody id="send-detail-body"></tbody>
                        </table>
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
    var pendingReload = false;

    // 一次帶進整包語系，免得散落數十個 blade 插值
    var BC_I18N = @json(trans('broadcast'));

    function showMessage(msg) {
        $('#modal-broadcast-msg-text').text(msg);
        showBsModal('modal-broadcast-msg');
    }

    /**
     * 確認視窗（專案規範不使用原生 confirm）
     *
     * @param {string}   text
     * @param {Function} onConfirm
     */
    function showConfirm(text, onConfirm) {
        $('#modal-broadcast-confirm-text').text(text);

        // 用 .off() 先解綁，否則連開兩次會把上一次的 callback 一起觸發
        $('#btn-broadcast-confirm-ok').off('click').on('click', function () {
            hideBsModal('modal-broadcast-confirm');
            onConfirm();
        });

        showBsModal('modal-broadcast-confirm');
    }

    document.getElementById('modal-broadcast-msg').addEventListener('hidden.bs.modal', function () {
        if (pendingReload) { location.reload(); }
    });

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
        $('#bc-selected-count').text(BC_I18N.selected_count.replace(':count', checkedCount));
        $('#bc-select-all').prop('checked', visibleCbs.length > 0 && checkedCount === visibleCbs.length);
    }
    $(document).on('change', '.bc-group-cb', updateSelectedCount);

    // 多張圖片累加 + 預覽
    var bcImageFiles = [];

    $('#bc-images').on('change', function () {
        var newFiles = this.files;
        for (var i = 0; i < newFiles.length; i++) {
            bcImageFiles.push(newFiles[i]);
        }
        renderImagePreviews();
        // 清空 input 讓下次還能選同樣的檔案
        $(this).val('');
    });

    function renderImagePreviews() {
        var $preview = $('#bc-image-preview');
        $preview.empty();
        if (bcImageFiles.length === 0) { $preview.hide(); return; }

        bcImageFiles.forEach(function (file, idx) {
            var reader = new FileReader();
            reader.onload = function (e) {
                $preview.append(
                    '<div class="position-relative" style="display:inline-block">' +
                    '<img src="' + e.target.result + '" style="width:80px;height:80px;object-fit:cover;border-radius:0.375rem" alt="preview">' +
                    '<button type="button" class="btn btn-sm btn-danger position-absolute" style="top:-5px;right:-5px;padding:0 4px;font-size:0.625rem;line-height:1.2;border-radius:50%" data-remove="' + idx + '">&times;</button>' +
                    '</div>'
                );
            };
            reader.readAsDataURL(file);
        });
        $preview.show();

        // 刪除單張
        $preview.off('click', '[data-remove]').on('click', '[data-remove]', function () {
            var rmIdx = parseInt($(this).data('remove'), 10);
            bcImageFiles.splice(rmIdx, 1);
            renderImagePreviews();
        });
    }

    // 預約傳送：勾選才展開時間欄位，並把送出鈕文字換成「預約傳送」
    $('#bc-schedule-enable').on('change', function () {
        var on = $(this).is(':checked');
        $('#bc-schedule-wrap').toggleClass('d-none', !on);
        $('#btn-send').html(on
            ? '<i class="far fa-clock me-1"></i>' + BC_I18N.btn_schedule
            : '<i class="fas fa-paper-plane me-1"></i>' + BC_I18N.btn_send);

        // 預設帶「五分鐘後」，省得每次都要從頭選
        if (on && !$('#bc-scheduled-at').val()) {
            $('#bc-scheduled-at').val(localDatetimeValue(5));
        }
    });

    /**
     * 產生 datetime-local 需要的本地時間字串（YYYY-MM-DDTHH:mm）
     *
     * toISOString() 會轉成 UTC，直接拿來填會差時區，必須自己補零組字串。
     *
     * @param {number} minutesLater 從現在往後幾分鐘
     * @returns {string}
     */
    function localDatetimeValue(minutesLater) {
        var d = new Date(Date.now() + minutesLater * 60 * 1000);
        var pad = function (n) { return n < 10 ? '0' + n : String(n); };

        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
            + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    /**
     * 還原送出鈕的文字（依目前是否勾選預約）
     */
    function resetSendButton() {
        var on = $('#bc-schedule-enable').is(':checked');
        $('#btn-send').prop('disabled', false).html(on
            ? '<i class="far fa-clock me-1"></i>' + BC_I18N.btn_schedule
            : '<i class="fas fa-paper-plane me-1"></i>' + BC_I18N.btn_send);
    }

    // 發送
    $('#form-broadcast').on('submit', function (e) {
        e.preventDefault();
        var content = $('#bc-content').val().trim();
        if (!content) { showMessage(BC_I18N.msg.content_required); return; }

        var targetType = parseInt($('input[name="target_type"]:checked').val(), 10);

        if (targetType === 2) {
            var ids = [];
            $('.bc-group-cb:checked').each(function () { ids.push(parseInt($(this).val(), 10)); });
            if (ids.length === 0) { showMessage(BC_I18N.msg.no_group_selected); return; }
        }

        var scheduledAt = '';
        if ($('#bc-schedule-enable').is(':checked')) {
            scheduledAt = $('#bc-scheduled-at').val();
            if (!scheduledAt) { showMessage(BC_I18N.msg.schedule_required); return; }

            // datetime-local 給的是 YYYY-MM-DDTHH:mm，後端要 YYYY-MM-DD HH:mm
            scheduledAt = scheduledAt.replace('T', ' ');
            if (new Date(scheduledAt.replace(' ', 'T')) <= new Date()) {
                showMessage(BC_I18N.msg.schedule_past);
                return;
            }
        }

        var formData = new FormData();
        formData.append('content', content);
        formData.append('target_type', targetType);
        if (scheduledAt) { formData.append('scheduled_at', scheduledAt); }

        if (targetType === 2) {
            ids.forEach(function (id) { formData.append('group_ids[]', id); });
        }

        bcImageFiles.forEach(function (file) {
            formData.append('images[]', file);
        });

        $('#btn-send').prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i>' + BC_I18N.sending);

        $.ajax({
            url: '/admin/telegram-broadcast/ajax-send',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            data: formData,
            processData: false,
            contentType: false,
            success: function (body) {
                resetSendButton();
                $('#bc-content').val('');
                bcImageFiles = [];
                renderImagePreviews();
                showMessage(body.message || BC_I18N.msg.send_success);
                pendingReload = true;
            },
            error: function (xhr) {
                resetSendButton();
                showMessage((xhr.responseJSON && xhr.responseJSON.message) || BC_I18N.msg.send_failed);
            }
        });
    });

    // 取消預約
    $(document).on('click', '.js-cancel-schedule', function () {
        var id = $(this).data('id');
        showConfirm(BC_I18N.msg.confirm_cancel, function () {
            $.ajax({
                url: '/admin/telegram-broadcast/ajax-cancel-schedule/' + id,
                method: 'PUT',
                headers: { 'X-CSRF-TOKEN': csrfToken },
                success: function (body) {
                    showMessage(body.message || BC_I18N.msg.cancel_success);
                    location.reload();
                },
                error: function (xhr) {
                    showMessage((xhr.responseJSON && xhr.responseJSON.message) || BC_I18N.msg.cancel_not_pending);
                }
            });
        });
    });

    // 發送明細
    $(document).on('click', '.js-show-send-detail', function () {
        var results = $(this).data('results') || [];
        $('#send-detail-empty, #send-detail-table-wrap').hide();
        if (!results.length) {
            $('#send-detail-empty').show();
        } else {
            var successCount = 0;
            var failCount = 0;
            var html = '';
            results.forEach(function (r) {
                if (r.success) { successCount++; } else { failCount++; }
                html += '<tr>';
                html += '<td>' + $('<span>').text(r.name).html() + '</td>';
                html += '<td class="text-center">';
                if (r.success) {
                    html += '<span class="badge bg-success">' + BC_I18N.field_success + '</span>';
                } else {
                    html += '<span class="badge bg-danger">' + BC_I18N.field_fail + '</span>';
                }
                html += '</td></tr>';
            });
            $('#send-detail-summary').html(
                '<span class="text-muted" style="font-size:0.875rem">' + BC_I18N.detail_total.replace(':count', results.length) + '</span>' +
                '<span style="font-size:0.875rem"><span class="badge bg-success">' + successCount + '</span> ' + BC_I18N.field_success + '</span>' +
                (failCount > 0 ? '<span style="font-size:0.875rem"><span class="badge bg-danger">' + failCount + '</span> ' + BC_I18N.field_fail + '</span>' : '')
            );
            $('#send-detail-body').html(html);
            $('#send-detail-table-wrap').show();
        }
        var modal = new bootstrap.Modal(document.getElementById('modal-send-detail'));
        modal.show();
    });

    // 字數即時計算
    $('#bc-content').on('input', function () {
        var len = $(this).val().length;
        var $counter = $('#bc-content-count');
        $counter.text(len);
        $counter.css('color', len >= 4000 ? '#dc3545' : '');
    });

    // 複製公告內容
    $('.js-copy-content').on('click', function () {
        var content = $(this).data('content');
        var $btn = $(this);
        if (navigator.clipboard) {
            navigator.clipboard.writeText(content).then(function () {
                $btn.html('<i class="fas fa-check me-1"></i>' + BC_I18N.copied);
                setTimeout(function () { $btn.html('<i class="fas fa-copy me-1"></i>' + BC_I18N.btn_copy); }, 1500);
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
            $btn.html('<i class="fas fa-check me-1"></i>' + BC_I18N.copied);
            setTimeout(function () { $btn.html('<i class="fas fa-copy me-1"></i>' + BC_I18N.btn_copy); }, 1500);
        }
    });
});
</script>
@endsection
