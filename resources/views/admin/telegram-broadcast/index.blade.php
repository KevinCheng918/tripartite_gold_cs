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
            <div class="main-card mb-3 card">
                <div class="card-body">
                    <form id="form-broadcast">
                        <div class="mb-3">
                            <label class="form-label">{{ trans('broadcast.field_content') }}</label>
                            <textarea id="bc-content" class="form-control" rows="5" required placeholder="{{ trans('broadcast.field_content') }}..."></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('broadcast.field_target') }}</label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="target_type" value="1" id="target-all" checked>
                                <label class="form-check-label" for="target-all">{{ trans('broadcast.target_all') }}</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="target_type" value="2" id="target-selected">
                                <label class="form-check-label" for="target-selected">{{ trans('broadcast.target_selected') }}</label>
                            </div>
                        </div>
                        <div class="mb-3" id="group-list-wrap" style="display:none">
                            <label class="form-label">{{ trans('broadcast.field_groups') }}</label>
                            @foreach($groups as $g)
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="group_ids[]" value="{{ $g['id'] }}" id="grp-{{ $g['id'] }}">
                                    <label class="form-check-label" for="grp-{{ $g['id'] }}">{{ $g['title'] }}</label>
                                </div>
                            @endforeach
                        </div>
                        <button type="submit" class="btn btn-primary" id="btn-send">
                            <i class="fas fa-paper-plane me-1"></i>{{ trans('broadcast.btn_send') }}
                        </button>
                    </form>
                </div>
            </div>
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
        $('#group-list-wrap').toggle($(this).val() === '2');
    });

    // 發送
    $('#form-broadcast').on('submit', function (e) {
        e.preventDefault();
        var content = $('#bc-content').val().trim();
        if (!content) { showMessage('{{ trans("broadcast.msg.content_required") }}'); return; }

        var targetType = parseInt($('input[name="target_type"]:checked').val(), 10);
        var data = { content: content, target_type: targetType };

        if (targetType === 2) {
            var ids = [];
            $('input[name="group_ids[]"]:checked').each(function () { ids.push(parseInt($(this).val(), 10)); });
            if (ids.length === 0) { showMessage('{{ trans("broadcast.msg.no_group_selected") }}'); return; }
            data.group_ids = ids;
        }

        var $btn = $('#btn-send');
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i>發送中...');

        $.ajax({
            url: '/admin/telegram-broadcast/ajax-send',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            contentType: 'application/json',
            data: JSON.stringify(data),
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
