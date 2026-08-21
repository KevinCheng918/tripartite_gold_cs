@extends('layouts.app')

@section('title', '專案管理')
@section('icon', 'folder-open')
@section('subtitle', '管理系統專案')

@section('content')

    {{-- 操作列 --}}
    <div class="main-card mb-3 card">
        <div class="card-header d-flex align-items-center justify-content-between">
            @if(Auth::user()->hasPermission('project.edit'))
            <button class="btn btn-primary btn-sm" id="btn-add-project"><i class="fas fa-plus me-1"></i>新增專案</button>
            @else
            <div></div>
            @endif
            <span class="text-muted" style="font-size:0.875rem">共 <strong id="project-total">{{ $projects->count() }}</strong> 個專案</span>
        </div>
    </div>

    {{-- 專案 Tabs --}}
    @if($projects->isEmpty())
        <div class="text-center text-muted py-5"><i class="fas fa-inbox fa-3x mb-3 d-block"></i>目前沒有專案</div>
    @else
        <ul class="nav nav-tabs mb-3" role="tablist">
            @foreach($projects as $idx => $project)
            <li class="nav-item" role="presentation">
                <button class="nav-link{{ $idx === 0 ? ' active' : '' }}" data-bs-toggle="tab" data-bs-target="#project-tab-{{ $project->id }}" type="button" role="tab">
                    <i class="fas fa-folder me-1"></i>{{ $project->name }}
                    @if($project->status !== 1)
                        <span class="badge bg-secondary ms-1" style="font-size:0.65rem">停用</span>
                    @endif
                </button>
            </li>
            @endforeach
        </ul>
        <div class="tab-content">
            @foreach($projects as $idx => $project)
            <div class="tab-pane fade{{ $idx === 0 ? ' show active' : '' }}" id="project-tab-{{ $project->id }}" role="tabpanel">
                <div class="main-card mb-3 card">
                    <div class="card-body">
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <div class="d-flex justify-content-between mb-2" style="font-size:0.875rem">
                                    <span class="text-muted">專案名稱</span>
                                    <strong>{{ $project->name }}</strong>
                                </div>
                                <div class="d-flex justify-content-between mb-2" style="font-size:0.875rem">
                                    <span class="text-muted">狀態</span>
                                    @if($project->status === 1)
                                        <span class="badge bg-success">啟用</span>
                                    @else
                                        <span class="badge bg-secondary">停用</span>
                                    @endif
                                </div>
                                <div class="d-flex justify-content-between mb-2" style="font-size:0.875rem">
                                    <span class="text-muted">建立者</span>
                                    <span>{{ $project->creator ? $project->creator->nickname : '-' }}</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2" style="font-size:0.875rem">
                                    <span class="text-muted">建立時間</span>
                                    <span>{{ $project->created_at ? $project->created_at->format('Y-m-d H:i') : '-' }}</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2" style="font-size:0.875rem">
                                    <span class="text-muted">最後更新</span>
                                    <span>{{ $project->updated_at ? $project->updated_at->format('Y-m-d H:i') : '-' }}</span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="text-muted" style="font-size:0.875rem">說明</label>
                                <div style="font-size:0.875rem;white-space:pre-wrap">{{ $project->description ?: '無說明' }}</div>
                            </div>
                        </div>
                        @if(Auth::user()->hasPermission('project.edit'))
                        <div class="text-end">
                            <button class="btn btn-sm btn-outline-secondary js-edit-project"
                                data-id="{{ $project->id }}"
                                data-name="{{ $project->name }}"
                                data-desc="{{ $project->description }}"
                                data-status="{{ $project->status }}">
                                <i class="fas fa-edit me-1"></i>編輯
                            </button>
                        </div>
                        @endif
                    </div>
                </div>
            </div>
            @endforeach
        </div>
    @endif

    {{-- 新增/編輯專案 Modal --}}
    <div class="modal fade" id="modal-project" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-project-title">新增專案</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="form-project">
                        <input type="hidden" id="project-edit-id">
                        <div class="mb-3">
                            <label class="form-label">專案名稱 <span class="text-danger">*</span></label>
                            <input type="text" id="project-name" class="form-control" required maxlength="100">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">說明</label>
                            <textarea id="project-desc" class="form-control" rows="3" maxlength="500"></textarea>
                        </div>
                        <div class="mb-3" id="project-status-group" style="display:none">
                            <label class="form-label">狀態</label>
                            <select id="project-status" class="form-select">
                                <option value="1">啟用</option>
                                <option value="0">停用</option>
                            </select>
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
    <div class="modal fade" id="modal-project-msg" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-body text-center py-4">
                    <p id="modal-project-msg-text" class="mb-3"></p>
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

    function showMsg(msg) {
        $('#modal-project-msg-text').text(msg);
        showBsModal('modal-project-msg');
    }

    // 新增
    $('#btn-add-project').on('click', function () {
        $('#modal-project-title').text('新增專案');
        $('#form-project')[0].reset();
        $('#project-edit-id').val('');
        $('#project-status-group').hide();
        showBsModal('modal-project');
    });

    // 編輯
    $(document).on('click', '.js-edit-project', function () {
        var $btn = $(this);
        $('#modal-project-title').text('編輯專案');
        $('#project-edit-id').val($btn.data('id'));
        $('#project-name').val($btn.data('name'));
        $('#project-desc').val($btn.data('desc'));
        $('#project-status').val($btn.data('status'));
        $('#project-status-group').show();
        showBsModal('modal-project');
    });

    // 送出
    $('#form-project').on('submit', function (e) {
        e.preventDefault();
        var id = $('#project-edit-id').val();
        var data = { name: $('#project-name').val().trim(), description: $('#project-desc').val().trim() };
        if (!data.name) return;
        if (id) { data.status = parseInt($('#project-status').val(), 10); }

        $.ajax({
            url: id ? '/admin/project/ajax-update/' + id : '/admin/project/ajax-store',
            method: id ? 'PUT' : 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            contentType: 'application/json',
            data: JSON.stringify(data),
            success: function (body) {
                hideBsModal(document.getElementById('modal-project'));
                setTimeout(function () { showMsg(body.message || '已儲存'); }, 400);
                document.getElementById('modal-project-msg').addEventListener('hidden.bs.modal', function handler() {
                    location.reload();
                    this.removeEventListener('hidden.bs.modal', handler);
                });
            },
            error: function (xhr) {
                showMsg((xhr.responseJSON && xhr.responseJSON.message) || '操作失敗');
            }
        });
    });
});
</script>
@endsection
