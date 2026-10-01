@extends('layouts.app')

@section('title', trans('daily_rate.page_title'))
{{-- 這版 Font Awesome 沒有 money-bill-trend-up（FA6 才有），用 exchange-alt --}}
@section('icon', 'exchange-alt')
@section('subtitle', trans('daily_rate.subtitle'))

@section('content')

    @php($canManage = Auth::user()->hasPermission('daily_rate.manage'))

    {{-- 今日匯率 --}}
    <div class="main-card mb-3 card">
        <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <div class="text-muted small">{{ trans('daily_rate.today_title') }}</div>
                @if(filled($todayRate))
                    <div class="dr-today-rate">{{ rtrim(rtrim(number_format($todayRate, 4, '.', ''), '0'), '.') }}</div>
                @else
                    <div class="dr-today-rate dr-today-pending">{{ trans('daily_rate.today_pending') }}</div>
                    <small class="text-muted">{{ trans('daily_rate.today_hint') }}</small>
                @endif
            </div>
            @if($canManage)
                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-outline-secondary" id="btn-dr-ask-now"
                            title="{{ trans('daily_rate.ask_now_hint') }}">
                        <i class="fas fa-paper-plane me-1"></i>{{ trans('daily_rate.action_ask_now') }}
                    </button>
                    <button class="btn btn-primary js-dr-edit"
                            data-date="{{ now()->toDateString() }}"
                            data-rate="{{ $todayRate }}">
                        <i class="fas fa-pen me-1"></i>{{ trans('daily_rate.action_edit') }}
                    </button>
                </div>
            @endif
        </div>
    </div>

    {{-- 走勢圖截圖 --}}
    <div class="main-card mb-3 card">
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                    <strong><i class="fas fa-image me-1"></i>{{ trans('daily_rate.screenshot_title') }}</strong>
                    @if($screenshot['available'])
                        <span class="badge bg-success ms-2">{{ trans('daily_rate.screenshot_ready') }}</span>
                        <div class="text-muted small mt-1">{{ $screenshot['binary'] }}</div>
                    @else
                        <span class="badge bg-warning text-dark ms-2">{{ trans('daily_rate.screenshot_missing') }}</span>
                        <div class="text-muted small mt-1"><code>{{ trans('daily_rate.screenshot_install') }}</code></div>
                    @endif
                    <small class="text-muted d-block mt-1">{{ trans('daily_rate.screenshot_hint') }}</small>
                </div>
                @if($canManage)
                    <button class="btn btn-outline-secondary" id="btn-dr-test-shot"
                            @if(!$screenshot['available']) disabled @endif>
                        <i class="fas fa-camera me-1"></i>{{ trans('daily_rate.action_test_shot') }}
                    </button>
                @endif
            </div>
        </div>
    </div>

    {{-- 報價公版 --}}
    <div class="main-card mb-3 card">
        <div class="card-header d-flex justify-content-between align-items-center"
             role="button" data-bs-toggle="collapse" data-bs-target="#dr-template-section"
             aria-expanded="false" aria-controls="dr-template-section">
            <strong><i class="fas fa-comment-dots me-1"></i>{{ trans('daily_rate.template_title') }}</strong>
            <i class="fas fa-chevron-down"></i>
        </div>
        <div class="collapse" id="dr-template-section">
            <div class="card-body">
                <textarea class="form-control" id="dr-template" rows="9"
                          @if(!$canManage) disabled @endif>{{ $template }}</textarea>
                <small class="text-muted d-block mt-1">{{ trans('daily_rate.template_hint') }}</small>

                <div class="d-flex gap-2 mt-3">
                    <button class="btn btn-outline-secondary" id="btn-dr-preview">
                        <i class="fas fa-eye me-1"></i>{{ trans('daily_rate.action_preview') }}
                    </button>
                    @if($canManage)
                        <button class="btn btn-primary" id="btn-dr-save-template">
                            <i class="fas fa-save me-1"></i>{{ trans('daily_rate.action_save') }}
                        </button>
                    @endif
                </div>

                <div id="dr-preview-wrap" class="mt-3 d-none">
                    <p class="text-muted mb-1">{{ trans('daily_rate.template_preview_title') }}：</p>
                    <div class="pc-template-box" id="dr-preview-box"></div>
                </div>
            </div>
        </div>
    </div>

    {{-- 歷史 --}}
    <div class="main-card mb-3 card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>{{ trans('daily_rate.field_date') }}</th>
                            <th class="text-end">{{ trans('daily_rate.field_rate') }}</th>
                            <th class="text-end d-none d-md-table-cell">{{ trans('daily_rate.field_reference_rate') }}</th>
                            <th class="text-end d-none d-md-table-cell">{{ trans('daily_rate.field_suggested_rate') }}</th>
                            <th class="d-none d-lg-table-cell">{{ trans('daily_rate.field_replier') }}</th>
                            <th class="d-none d-lg-table-cell">{{ trans('daily_rate.field_replied_at') }}</th>
                            <th class="text-center d-none d-lg-table-cell">{{ trans('daily_rate.field_remind_count') }}</th>
                            @if($canManage)<th></th>@endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rates as $row)
                            <tr>
                                {{-- 用 DatePresenter 而不是 isoFormat：沒設 Carbon locale 的話
                                     isoFormat 會吐英文星期，而且格式要跟出勤那邊一致 --}}
                                <td>{{ \App\Presenters\DatePresenter::withWeekday($row->date) }}</td>
                                <td class="text-end">
                                    @if(filled($row->rate))
                                        <strong>{{ rtrim(rtrim(number_format($row->rate, 4, '.', ''), '0'), '.') }}</strong>
                                    @else
                                        <span class="badge bg-warning text-dark">{{ trans('daily_rate.today_pending') }}</span>
                                    @endif
                                </td>
                                <td class="text-end text-muted d-none d-md-table-cell">{{ filled($row->reference_rate) ? rtrim(rtrim(number_format($row->reference_rate, 4, '.', ''), '0'), '.') : '-' }}</td>
                                <td class="text-end text-muted d-none d-md-table-cell">{{ filled($row->suggested_rate) ? rtrim(rtrim(number_format($row->suggested_rate, 4, '.', ''), '0'), '.') : '-' }}</td>
                                <td class="d-none d-lg-table-cell">{{ $row->replier ? $row->replier->nickname : '-' }}</td>
                                <td class="d-none d-lg-table-cell text-muted small">{{ filled($row->replied_at) ? $row->replied_at->format('Y-m-d H:i') : '-' }}</td>
                                <td class="text-center d-none d-lg-table-cell">{{ $row->remind_count > 0 ? $row->remind_count : '-' }}</td>
                                @if($canManage)
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-secondary js-dr-edit"
                                                data-date="{{ $row->date->toDateString() }}"
                                                data-rate="{{ $row->rate }}">
                                            <i class="fas fa-pen"></i>
                                        </button>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $canManage ? 8 : 7 }}" class="text-center text-muted py-4">{{ trans('daily_rate.empty') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($rates->hasPages())
                <div class="mt-3">{{ $rates->links() }}</div>
            @endif
        </div>
    </div>

    {{-- 設定匯率 Modal --}}
    <div class="modal fade" id="modal-dr-edit" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ trans('daily_rate.edit_title') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="dr-edit-date">{{ trans('daily_rate.field_date') }}</label>
                        <input type="date" class="form-control" id="dr-edit-date" max="{{ now()->toDateString() }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="dr-edit-rate">{{ trans('daily_rate.field_rate') }}</label>
                        <input type="number" step="0.0001" min="0.0001" class="form-control" id="dr-edit-rate">
                    </div>
                    <small class="text-muted">{{ trans('daily_rate.edit_hint') }}</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary" id="btn-dr-save-rate">{{ trans('daily_rate.action_save') }}</button>
                </div>
            </div>
        </div>
    </div>

    {{-- 立即報價的確認 --}}
    <div class="modal fade" id="modal-dr-ask-confirm" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-body text-center py-4">
                    <p class="mb-0">{{ trans('daily_rate.ask_now_confirm') }}</p>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary" id="btn-dr-ask-confirm">{{ trans('daily_rate.action_ask_now') }}</button>
                </div>
            </div>
        </div>
    </div>

    {{-- 訊息 Modal --}}
    <div class="modal fade" id="modal-dr-msg" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-body text-center py-4">
                    <p class="mb-0" id="modal-dr-msg-text"></p>
                </div>
                <div class="modal-footer justify-content-center">
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
        $('#modal-dr-msg-text').text(msg);
        // 另一個 modal 還在關閉動畫中就等它收完，不然 backdrop 會疊在一起
        var hasBackdrop = document.querySelectorAll('.modal-backdrop').length > 0;
        if (hasBackdrop) {
            setTimeout(function () { showBsModal('modal-dr-msg'); }, 400);
        } else {
            showBsModal('modal-dr-msg');
        }
    }

    // 展開/收起時箭頭跟著轉
    $('#dr-template-section')
        .on('show.bs.collapse', function () {
            $('[data-bs-target="#dr-template-section"] .fa-chevron-down').addClass('fa-rotate-180');
        })
        .on('hide.bs.collapse', function () {
            $('[data-bs-target="#dr-template-section"] .fa-chevron-down').removeClass('fa-rotate-180');
        });

    // 設定匯率
    $('.js-dr-edit').on('click', function () {
        var $btn = $(this);
        $('#dr-edit-date').val($btn.data('date'));
        $('#dr-edit-rate').val($btn.data('rate'));
        showBsModal('modal-dr-edit');
    });

    $('#btn-dr-save-rate').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true);

        $.ajax({
            url: '{{ route("admin.daily-rate.ajax-update-rate") }}',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            data: { date: $('#dr-edit-date').val(), rate: $('#dr-edit-rate').val() },
            success: function (body) {
                hideBsModal(document.getElementById('modal-dr-edit'));
                showMessage(body.message || '{{ trans("daily_rate.msg.saved") }}');
                $('#modal-dr-msg').off('hidden.bs.modal.reload').on('hidden.bs.modal.reload', function () {
                    location.reload();
                });
            },
            error: function (xhr) {
                showMessage((xhr.responseJSON && xhr.responseJSON.message) || '{{ trans("daily_rate.msg.save_failed") }}');
            },
            complete: function () { $btn.prop('disabled', false); }
        });
    });

    // 立即報價：會真的發訊息，所以先確認
    $('#btn-dr-ask-now').on('click', function () {
        showBsModal('modal-dr-ask-confirm');
    });

    $('#btn-dr-ask-confirm').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true);

        $.ajax({
            url: '{{ route("admin.daily-rate.ajax-ask-now") }}',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            success: function (body) {
                hideBsModal(document.getElementById('modal-dr-ask-confirm'));
                showMessage(body.message || '{{ trans("daily_rate.msg.ask_sent") }}');
                // 報價會建立今天那筆紀錄，關掉訊息後重整才看得到
                $('#modal-dr-msg').off('hidden.bs.modal.reload').on('hidden.bs.modal.reload', function () {
                    location.reload();
                });
            },
            error: function (xhr) {
                hideBsModal(document.getElementById('modal-dr-ask-confirm'));
                showMessage((xhr.responseJSON && xhr.responseJSON.message) || '{{ trans("daily_rate.msg.ask_send_failed") }}');
            },
            complete: function () { $btn.prop('disabled', false); }
        });
    });

    // 截圖測試：只驗證截得到圖，不會動到今天的報價紀錄
    $('#btn-dr-test-shot').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true);

        $.ajax({
            url: '{{ route("admin.daily-rate.ajax-test-screenshot") }}',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            success: function (body) {
                showMessage(body.message || '已送出');
            },
            error: function (xhr) {
                showMessage((xhr.responseJSON && xhr.responseJSON.message) || '截圖測試失敗');
            },
            complete: function () { $btn.prop('disabled', false); }
        });
    });

    // 公版預覽：走後端渲染，看到的排版就是實際送出去的
    $('#btn-dr-preview').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true);

        $.ajax({
            url: '{{ route("admin.daily-rate.ajax-preview-template") }}',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            data: { template: $('#dr-template').val() },
            success: function (body) {
                $('#dr-preview-box').text(body.text || '');
                $('#dr-preview-wrap').removeClass('d-none');
            },
            error: function (xhr) {
                showMessage((xhr.responseJSON && xhr.responseJSON.message) || '預覽失敗');
            },
            complete: function () { $btn.prop('disabled', false); }
        });
    });

    $('#btn-dr-save-template').on('click', function () {
        var $btn = $(this);
        var $field = $('#dr-template');

        // 存檔中鎖住欄位，避免等回傳的空檔又被改掉
        $btn.prop('disabled', true);
        $field.prop('disabled', true);

        $.ajax({
            url: '{{ route("admin.daily-rate.ajax-update-template") }}',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            data: { template: $field.val() },
            success: function (body) {
                showMessage(body.message || '{{ trans("daily_rate.msg.template_saved") }}');
            },
            error: function (xhr) {
                showMessage((xhr.responseJSON && xhr.responseJSON.message) || '{{ trans("daily_rate.msg.template_save_failed") }}');
            },
            complete: function () {
                $btn.prop('disabled', false);
                $field.prop('disabled', false);
            }
        });
    });
});
</script>
@endsection
