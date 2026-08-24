@extends('layouts.app')

@section('title', '共用文件區')
@section('icon', 'file-alt')
@section('subtitle', '共用與個人文件管理')

@section('content')

    <style>
        .sf-folder-list .sf-folder-item { padding: 0.5rem 0.75rem; border-bottom: 1px solid #eee; cursor: pointer; font-size: 0.9375rem; }
        .sf-folder-list .sf-folder-item:hover { background: rgba(0,0,0,0.03); }
        .sf-folder-list .sf-folder-item.active { background: rgba(212,175,55,0.1); font-weight: 700; border-left: 3px solid #d4af37; }
        [data-theme="dark"] .sf-folder-list .sf-folder-item { border-bottom-color: #333; }
        [data-theme="dark"] .sf-folder-list .sf-folder-item:hover { background: rgba(255,255,255,0.05); }
        [data-theme="dark"] .sf-folder-list .sf-folder-item.active { background: rgba(212,175,55,0.15); }
    </style>

    @php $canViewShared = Auth::user()->isAdmin() || Auth::user()->hasPermission('shared_file.view'); @endphp
    {{-- Tabs --}}
    <ul class="nav nav-tabs mb-3">
        @if($canViewShared)
        <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-shared">
                <i class="fas fa-globe me-1"></i>共用文件
            </button>
        </li>
        @endif
        <li class="nav-item">
            <button class="nav-link{{ !$canViewShared ? ' active' : '' }}" data-bs-toggle="tab" data-bs-target="#tab-personal">
                <i class="fas fa-user me-1"></i>個人文件
            </button>
        </li>
    </ul>

    <div class="tab-content">
        {{-- 共用文件 Tab --}}
        @if($canViewShared)
        <div class="tab-pane fade{{ $canViewShared ? ' show active' : '' }}" id="tab-shared">
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="main-card card">
                        <div class="card-header d-flex justify-content-between align-items-center py-2">
                            <span class="fw-bold" style="font-size:0.875rem">資料夾</span>
                            @if(Auth::user()->hasPermission('shared_file.upload'))
                            <button class="btn btn-sm btn-primary js-add-folder" data-type="shared"><i class="fas fa-plus"></i></button>
                            @endif
                        </div>
                        <div class="card-body p-0 sf-folder-list" id="shared-folder-list">
                            <div class="text-center text-muted py-3">載入中...</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-9">
                    <div class="main-card card">
                        <div class="card-header d-flex justify-content-between align-items-center py-2">
                            <span class="fw-bold" style="font-size:0.875rem" id="shared-folder-title">請選擇資料夾</span>
                            <div class="d-flex gap-1" id="shared-file-actions" style="display:none !important"></div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover align-middle mb-0">
                                    <thead class="thead-gold">
                                        <tr><th>檔名</th><th>大小</th><th>上傳者</th><th>時間</th><th>操作</th></tr>
                                    </thead>
                                    <tbody id="shared-file-body">
                                        <tr><td colspan="5" class="text-center text-muted py-3">請選擇資料夾</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        @endif

        {{-- 個人文件 Tab --}}
        <div class="tab-pane fade{{ !$canViewShared ? ' show active' : '' }}" id="tab-personal">
            @if(Auth::user()->isAdmin())
            <div class="mb-3">
                <label class="form-label fw-bold" style="font-size:0.875rem">查看用戶</label>
                <select id="personal-user-select" class="form-select form-select-sm" style="width:200px">
                    <option value="">自己</option>
                    @foreach($allUsers as $u)
                        <option value="{{ $u->id }}">{{ $u->nickname }}（{{ $u->account }}）</option>
                    @endforeach
                </select>
            </div>
            @endif
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="main-card card">
                        <div class="card-header d-flex justify-content-between align-items-center py-2">
                            <span class="fw-bold" style="font-size:0.875rem">資料夾</span>
                            <button class="btn btn-sm btn-primary js-add-folder" data-type="personal"><i class="fas fa-plus"></i></button>
                        </div>
                        <div class="card-body p-0 sf-folder-list" id="personal-folder-list">
                            <div class="text-center text-muted py-3">載入中...</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-9">
                    <div class="main-card card">
                        <div class="card-header d-flex justify-content-between align-items-center py-2">
                            <span class="fw-bold" style="font-size:0.875rem" id="personal-folder-title">請選擇資料夾</span>
                            <div class="d-flex gap-1" id="personal-file-actions" style="display:none !important"></div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover align-middle mb-0">
                                    <thead class="thead-gold">
                                        <tr><th>檔名</th><th>大小</th><th>上傳者</th><th>時間</th><th>操作</th></tr>
                                    </thead>
                                    <tbody id="personal-file-body">
                                        <tr><td colspan="5" class="text-center text-muted py-3">請選擇資料夾</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- 新增資料夾 Modal --}}
    <div class="modal fade" id="modal-add-folder" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">新增資料夾</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <form id="form-add-folder">
                        <input type="hidden" id="folder-type">
                        <div class="mb-3"><label class="form-label">名稱 <span class="text-danger">*</span></label><input type="text" id="folder-name" class="form-control" required maxlength="100"></div>
                        <div class="text-end"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button> <button type="submit" class="btn btn-primary">確認</button></div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- 訊息 Modal --}}
    <div class="modal fade" id="modal-sf-msg" tabindex="-1">
        <div class="modal-dialog modal-sm"><div class="modal-content"><div class="modal-body text-center py-4"><p id="modal-sf-msg-text" class="mb-3"></p><button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button></div></div></div>
    </div>

    {{-- 刪除確認 Modal --}}
    <div class="modal fade" id="modal-sf-delete" tabindex="-1">
        <div class="modal-dialog modal-sm"><div class="modal-content"><div class="modal-body text-center py-4">
            <p class="mb-3" id="modal-sf-delete-text">確定刪除？</p>
            <input type="hidden" id="sf-delete-url">
            <div class="d-flex justify-content-center gap-2"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button><button type="button" class="btn btn-danger" id="btn-sf-delete-ok">確定刪除</button></div>
        </div></div></div>
    </div>

@endsection

@section('scripts')
<script>
$(function () {
    var csrfToken = $('meta[name="csrf-token"]').attr('content');
    var canUpload = {{ Auth::user()->hasPermission('shared_file.upload') ? 'true' : 'false' }};
    var canDelete = {{ Auth::user()->hasPermission('shared_file.delete') ? 'true' : 'false' }};
    var isAdmin = {{ Auth::user()->isAdmin() ? 'true' : 'false' }};
    var currentUserId = {{ Auth::id() }};
    var selectedSharedFolder = null;
    var selectedPersonalFolder = null;

    function showMsg(msg) { $('#modal-sf-msg-text').text(msg); showBsModal('modal-sf-msg'); }

    function fmtSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    // ===== 載入資料夾 =====
    function loadFolders(type, targetUserId) {
        var params = { type: type };
        if (targetUserId) params.user_id = targetUserId;
        $.ajax({
            url: '/admin/shared-file/ajax-list',
            method: 'GET',
            data: params,
            headers: { 'X-CSRF-TOKEN': csrfToken },
            success: function (res) { renderFolders(type, res.folders); }
        });
    }

    function renderFolders(type, folders) {
        var containerId = type === 'shared' ? 'shared-folder-list' : 'personal-folder-list';
        var $container = $('#' + containerId);
        if (!folders || folders.length === 0) {
            $container.html('<div class="text-center text-muted py-3">尚無資料夾</div>');
            return;
        }
        var html = '';
        folders.forEach(function (f) {
            var activeCls = (type === 'shared' && selectedSharedFolder === f.id) || (type === 'personal' && selectedPersonalFolder === f.id) ? ' active' : '';
            html += '<div class="sf-folder-item' + activeCls + '" data-id="' + f.id + '" data-type="' + type + '">';
            html += '<i class="fas fa-folder me-2 text-warning"></i>' + $('<span>').text(f.name).html();
            if (canDelete) {
                html += '<button class="btn btn-sm py-0 px-1 float-end js-delete-folder" data-id="' + f.id + '" data-name="' + $('<span>').text(f.name).html() + '"><i class="fas fa-trash-alt text-danger" style="font-size:0.75rem"></i></button>';
            }
            html += '</div>';
        });
        $container.html(html);
    }

    // ===== 載入檔案 =====
    function loadFiles(type, folderId) {
        $.ajax({
            url: '/admin/shared-file/ajax-list',
            method: 'GET',
            data: { type: type, folder_id: folderId },
            headers: { 'X-CSRF-TOKEN': csrfToken },
            success: function (res) { renderFiles(type, res.files, folderId); }
        });
    }

    function renderFiles(type, files, folderId) {
        var bodyId = type === 'shared' ? 'shared-file-body' : 'personal-file-body';
        var titleId = type === 'shared' ? 'shared-folder-title' : 'personal-folder-title';
        var actionsId = type === 'shared' ? 'shared-file-actions' : 'personal-file-actions';
        var $body = $('#' + bodyId);

        // 上傳按鈕
        var showUpload = (type === 'shared' && canUpload) || type === 'personal';
        if (showUpload) {
            var uploadHtml = '<input type="file" class="js-file-upload-input" data-folder="' + folderId + '" style="display:none">';
            uploadHtml += '<button class="btn btn-sm btn-primary js-upload-btn"><i class="fas fa-upload me-1"></i>上傳檔案</button>';
            $('#' + actionsId).html(uploadHtml).css('display', '');
        }

        if (!files || files.length === 0) {
            $body.html('<tr><td colspan="5" class="text-center text-muted py-3">此資料夾無檔案</td></tr>');
            return;
        }
        var html = '';
        files.forEach(function (f) {
            var canDel = (type === 'shared' && canDelete) || (type === 'personal' && (f.uploaded_by === currentUserId || isAdmin));
            html += '<tr>';
            html += '<td><i class="fas fa-file me-1 text-muted"></i>' + $('<span>').text(f.original_name).html() + '</td>';
            html += '<td>' + fmtSize(f.file_size) + '</td>';
            html += '<td>' + (f.uploader ? f.uploader.nickname : '-') + '</td>';
            html += '<td>' + (f.created_at ? f.created_at.substring(0, 16).replace('T', ' ') : '-') + '</td>';
            html += '<td><div class="d-flex gap-1">';
            html += '<a href="/storage/' + f.file_path + '" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="fas fa-download me-1"></i>下載</a>';
            if (canDel) {
                html += '<button class="btn btn-sm btn-outline-secondary js-delete-file" data-id="' + f.id + '" data-name="' + $('<span>').text(f.original_name).html() + '"><i class="fas fa-trash-alt text-danger me-1"></i>刪除</button>';
            }
            html += '</div></td></tr>';
        });
        $body.html(html);
    }

    // ===== 事件 =====
    // 點選資料夾
    $(document).on('click', '.sf-folder-item', function (e) {
        if ($(e.target).closest('.js-delete-folder').length) return;
        var id = parseInt($(this).data('id'), 10);
        var type = $(this).data('type');
        if (type === 'shared') { selectedSharedFolder = id; } else { selectedPersonalFolder = id; }
        $(this).closest('.sf-folder-list').find('.sf-folder-item').removeClass('active');
        $(this).addClass('active');
        var titleId = type === 'shared' ? 'shared-folder-title' : 'personal-folder-title';
        $('#' + titleId).text($(this).text().trim());
        loadFiles(type, id);
    });

    // 新增資料夾
    $(document).on('click', '.js-add-folder', function () {
        $('#folder-type').val($(this).data('type'));
        $('#form-add-folder')[0].reset();
        showBsModal('modal-add-folder');
    });

    $('#form-add-folder').on('submit', function (e) {
        e.preventDefault();
        var type = $('#folder-type').val();
        $.ajax({
            url: '/admin/shared-file/ajax-store-folder',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            contentType: 'application/json',
            data: JSON.stringify({ name: $('#folder-name').val().trim(), type: type }),
            success: function () {
                hideBsModal(document.getElementById('modal-add-folder'));
                setTimeout(function () { loadFolders(type); }, 400);
            },
            error: function (xhr) { showMsg((xhr.responseJSON && xhr.responseJSON.message) || '建立失敗'); }
        });
    });

    // 上傳
    $(document).on('click', '.js-upload-btn', function () {
        $(this).siblings('.js-file-upload-input').trigger('click');
    });

    $(document).on('change', '.js-file-upload-input', function () {
        var file = this.files[0];
        if (!file) return;
        var folderId = $(this).data('folder');
        var fd = new FormData();
        fd.append('folder_id', folderId);
        fd.append('file', file);
        $.ajax({
            url: '/admin/shared-file/ajax-upload',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            data: fd,
            processData: false,
            contentType: false,
            success: function () {
                var type = selectedSharedFolder === folderId ? 'shared' : 'personal';
                loadFiles(type, folderId);
            },
            error: function (xhr) { showMsg((xhr.responseJSON && xhr.responseJSON.message) || '上傳失敗'); }
        });
        this.value = '';
    });

    // 刪除檔案
    $(document).on('click', '.js-delete-file', function () {
        var id = $(this).data('id');
        var name = $(this).data('name');
        $('#modal-sf-delete-text').text('確定刪除檔案「' + name + '」？');
        $('#sf-delete-url').val('/admin/shared-file/ajax-delete-file/' + id);
        showBsModal('modal-sf-delete');
    });

    // 刪除資料夾
    $(document).on('click', '.js-delete-folder', function (e) {
        e.stopPropagation();
        var id = $(this).data('id');
        var name = $(this).data('name');
        $('#modal-sf-delete-text').text('確定刪除資料夾「' + name + '」及所有檔案？');
        $('#sf-delete-url').val('/admin/shared-file/ajax-delete-folder/' + id);
        showBsModal('modal-sf-delete');
    });

    $('#btn-sf-delete-ok').on('click', function () {
        var url = $('#sf-delete-url').val();
        var $btn = $(this).prop('disabled', true);
        $.ajax({
            url: url,
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            success: function () {
                hideBsModal(document.getElementById('modal-sf-delete'));
                setTimeout(function () {
                    loadFolders('shared');
                    loadFolders('personal');
                }, 400);
                $btn.prop('disabled', false);
            },
            error: function (xhr) {
                hideBsModal(document.getElementById('modal-sf-delete'));
                setTimeout(function () { showMsg((xhr.responseJSON && xhr.responseJSON.message) || '刪除失敗'); }, 400);
                $btn.prop('disabled', false);
            }
        });
    });

    // Tab 切換時載入
    $('button[data-bs-target="#tab-shared"]').on('shown.bs.tab', function () { loadFolders('shared'); });
    $('button[data-bs-target="#tab-personal"]').on('shown.bs.tab', function () { loadFolders('personal'); });

    @if(Auth::user()->isAdmin())
    $('#personal-user-select').on('change', function () {
        selectedPersonalFolder = null;
        $('#personal-folder-title').text('請選擇資料夾');
        $('#personal-file-body').html('<tr><td colspan="5" class="text-center text-muted py-3">請選擇資料夾</td></tr>');
        $('#personal-file-actions').css('display', 'none');
        loadFolders('personal', $(this).val() || null);
    });
    @endif

    // 初始載入
    loadFolders('shared');
    loadFolders('personal');
});
</script>
@endsection
