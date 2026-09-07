/**
 * 文件區（共用／個人）
 *
 * 原本內嵌在 resources/views/admin/shared-file/index.blade.php，
 * 依專案規範抽出到 public/js。
 *
 * 權限與語系由 #shared-file-app 的 data-* 帶進來。
 */
$(function () {
    var root = document.getElementById('shared-file-app');
    if (!root) { return; }

    var csrfToken = $('meta[name="csrf-token"]').attr('content');
    var i18n = JSON.parse(root.dataset.i18n);
    var canUpload = root.dataset.canUpload === '1';
    var canDelete = root.dataset.canDelete === '1';
    var isAdmin = root.dataset.isAdmin === '1';
    var currentUserId = parseInt(root.dataset.userId, 10);

    var selectedSharedFolder = null;
    var selectedPersonalFolder = null;

    // 資料夾樹快取，供刪除確認統計與展開狀態使用
    var folderCache = { shared: [], personal: [] };
    var collapsedFolders = {};

    /**
     * 代入語系字串的參數（:name、:count 這類）
     *
     * @param {string} template
     * @param {Object} params
     * @returns {string}
     */
    function trans(template, params) {
        var text = template || '';
        Object.keys(params || {}).forEach(function (key) {
            text = text.replace(':' + key, params[key]);
        });

        return text;
    }

    /**
     * @param {string} text
     * @returns {string}
     */
    function escapeHtml(text) {
        return $('<span>').text(text === null || typeof text === 'undefined' ? '' : text).html();
    }

    function showMsg(msg) {
        $('#modal-sf-msg-text').text(msg);
        showBsModal('modal-sf-msg');
    }

    function fmtSize(bytes) {
        if (bytes < 1024) { return bytes + ' B'; }
        if (bytes < 1048576) { return (bytes / 1024).toFixed(1) + ' KB'; }

        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    // ===== 資料夾 =====

    function loadFolders(type, targetUserId) {
        var params = { type: type };
        if (targetUserId) { params.user_id = targetUserId; }

        $.ajax({
            url: '/admin/shared-file/ajax-list',
            method: 'GET',
            data: params,
            headers: { 'X-CSRF-TOKEN': csrfToken },
            success: function (res) { renderFolders(type, res.folders); }
        });
    }

    /**
     * 依 parent_id 把扁平清單組成樹
     *
     * @param {Array} folders
     * @returns {Object} parentId（'root' 或 id）-> 子資料夾陣列
     */
    function groupByParent(folders) {
        var byParent = {};
        folders.forEach(function (f) {
            var key = f.parent_id === null || typeof f.parent_id === 'undefined' ? 'root' : String(f.parent_id);
            if (!byParent[key]) { byParent[key] = []; }
            byParent[key].push(f);
        });

        return byParent;
    }

    /**
     * 算出某資料夾底下總共有幾個子資料夾（刪除確認用）
     *
     * @param {Object} byParent
     * @param {number} folderId
     * @returns {number}
     */
    function countDescendants(byParent, folderId) {
        var children = byParent[String(folderId)] || [];
        var total = children.length;
        children.forEach(function (c) { total += countDescendants(byParent, c.id); });

        return total;
    }

    function renderFolders(type, folders) {
        var $container = $('#' + type + '-folder-list');

        folderCache[type] = folders || [];

        if (!folders || folders.length === 0) {
            $container.html('<div class="text-center text-muted py-3">' + escapeHtml(i18n.no_folders) + '</div>');
            return;
        }

        $container.html(renderFolderLevel(type, groupByParent(folders), 'root', 0));
    }

    /**
     * 遞迴渲染一層資料夾
     *
     * @param {string} type
     * @param {Object} byParent
     * @param {string} parentKey
     * @param {number} depth 縮排層級
     * @returns {string}
     */
    function renderFolderLevel(type, byParent, parentKey, depth) {
        var list = byParent[parentKey] || [];
        var html = '';

        list.forEach(function (f) {
            var selected = type === 'shared' ? selectedSharedFolder : selectedPersonalFolder;
            var activeCls = selected === f.id ? ' active' : '';
            var children = byParent[String(f.id)] || [];
            var isCollapsed = !!collapsedFolders[f.id];
            var safeName = escapeHtml(f.name);

            html += '<div class="sf-folder-item' + activeCls + '" data-id="' + f.id + '" data-type="' + type + '"' +
                ' style="padding-left:' + (0.75 + depth * 1.1) + 'rem">';

            // 有子資料夾才給收合箭頭，沒有的話留等寬空位讓名稱對齊
            html += children.length
                ? '<span class="js-toggle-folder sf-folder-toggle" data-id="' + f.id + '">' +
                  '<i class="fas fa-chevron-' + (isCollapsed ? 'right' : 'down') + '"></i></span>'
                : '<span class="sf-folder-toggle"></span>';

            html += '<i class="fas fa-folder me-2 text-warning"></i>' + safeName;

            if (canDelete) {
                html += '<button class="btn btn-sm py-0 px-1 float-end js-delete-folder" data-id="' + f.id +
                    '" data-name="' + safeName + '" data-type="' + type + '">' +
                    '<i class="fas fa-trash-alt text-danger" style="font-size:0.75rem"></i></button>';
            }

            html += '</div>';

            if (children.length && !isCollapsed) {
                html += renderFolderLevel(type, byParent, String(f.id), depth + 1);
            }
        });

        return html;
    }

    /**
     * 組出「父 / 子」的完整路徑，讓標題看得出目前在哪一層
     *
     * @param {string} type
     * @param {number} folderId
     * @returns {string}
     */
    function folderPath(type, folderId) {
        var byId = {};
        folderCache[type].forEach(function (f) { byId[f.id] = f; });

        var parts = [];
        var current = byId[folderId];
        // 資料若因故成環，最多往上追 20 層就停
        for (var i = 0; i < 20 && current; i++) {
            parts.unshift(current.name);
            current = current.parent_id ? byId[current.parent_id] : null;
        }

        return parts.join(' / ');
    }

    /**
     * 共用區要有上傳權限才能開資料夾，個人區一律可以
     *
     * @param {string} type
     * @returns {boolean}
     */
    function canAddFolder(type) {
        return type === 'personal' || canUpload;
    }

    // ===== 檔案 =====

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
        var $body = $('#' + type + '-file-body');

        renderFileActions(type, folderId);

        if (!files || files.length === 0) {
            $body.html('<tr><td colspan="5" class="text-center text-muted py-3">' +
                escapeHtml(i18n.no_files) + '</td></tr>');
            return;
        }

        var html = '';
        files.forEach(function (f) {
            var canDel = (type === 'shared' && canDelete) ||
                (type === 'personal' && (f.uploaded_by === currentUserId || isAdmin));

            html += '<tr>';
            html += '<td><i class="fas fa-file me-1 text-muted"></i>' + escapeHtml(f.original_name) + '</td>';
            html += '<td>' + fmtSize(f.file_size) + '</td>';
            html += '<td>' + escapeHtml(f.uploader ? f.uploader.nickname : '-') + '</td>';
            html += '<td>' + (f.created_at ? f.created_at.substring(0, 16).replace('T', ' ') : '-') + '</td>';
            html += '<td><div class="d-flex gap-1">';
            html += '<a href="/storage/' + f.file_path + '" target="_blank" class="btn btn-sm btn-outline-secondary">' +
                '<i class="fas fa-download me-1"></i>' + escapeHtml(i18n.action_download) + '</a>';
            if (canDel) {
                html += '<button class="btn btn-sm btn-outline-secondary js-delete-file" data-id="' + f.id +
                    '" data-name="' + escapeHtml(f.original_name) + '">' +
                    '<i class="fas fa-trash-alt text-danger me-1"></i>' + escapeHtml(i18n.action_delete) + '</button>';
            }
            html += '</div></td></tr>';
        });

        $body.html(html);
    }

    /**
     * 右上角的操作鈕：新增子資料夾 + 上傳檔案
     *
     * 子資料夾鈕放在這裡而不是資料夾樹裡 —— 樹裡的圖示太小不好按，
     * 而且建立的位置就是目前選中的資料夾，放在檔案區標題旁語意更清楚。
     *
     * @param {string} type
     * @param {number} folderId 目前選中的資料夾
     */
    function renderFileActions(type, folderId) {
        var $actions = $('#' + type + '-file-actions');
        var html = '';

        if (canAddFolder(type)) {
            html += '<button class="btn btn-sm btn-outline-secondary js-add-subfolder me-1" data-parent="' + folderId +
                '" data-type="' + type + '">' +
                '<i class="fas fa-folder-plus me-1"></i>' + escapeHtml(i18n.action_add_subfolder) + '</button>';
        }

        if ((type === 'shared' && canUpload) || type === 'personal') {
            html += '<input type="file" class="js-file-upload-input" data-folder="' + folderId + '" style="display:none">';
            html += '<button class="btn btn-sm btn-primary js-upload-btn">' +
                '<i class="fas fa-upload me-1"></i>' + escapeHtml(i18n.action_upload) + '</button>';
        }

        $actions.html(html).css('display', html ? '' : 'none');
    }

    // ===== 事件 =====

    // 點選資料夾
    $(document).on('click', '.sf-folder-item', function (e) {
        // 點在操作鈕或收合箭頭上時不切換選取
        if ($(e.target).closest('.js-delete-folder, .js-toggle-folder').length) { return; }

        var id = parseInt($(this).data('id'), 10);
        var type = $(this).data('type');

        if (type === 'shared') { selectedSharedFolder = id; } else { selectedPersonalFolder = id; }

        $(this).closest('.sf-folder-list').find('.sf-folder-item').removeClass('active');
        $(this).addClass('active');
        $('#' + type + '-folder-title').text(folderPath(type, id));
        loadFiles(type, id);
    });

    // 展開／收合子資料夾
    $(document).on('click', '.js-toggle-folder', function (e) {
        e.stopPropagation();
        var id = $(this).data('id');
        collapsedFolders[id] = !collapsedFolders[id];
        var type = $(this).closest('.sf-folder-item').data('type');
        renderFolders(type, folderCache[type]);
    });

    // 新增資料夾（最上層）
    $(document).on('click', '.js-add-folder', function () {
        openFolderModal($(this).data('type'), '');
    });

    // 新增子資料夾（建在目前選中的資料夾底下）
    $(document).on('click', '.js-add-subfolder', function () {
        openFolderModal($(this).data('type'), $(this).data('parent'));
    });

    /**
     * @param {string}        type
     * @param {number|string} parentId 空字串代表建在最上層
     */
    function openFolderModal(type, parentId) {
        $('#form-add-folder')[0].reset();
        $('#folder-type').val(type);
        $('#folder-parent-id').val(parentId);
        $('#modal-add-folder .modal-title').text(parentId ? i18n.action_add_subfolder : i18n.action_add_folder);
        $('#folder-parent-hint')
            .toggle(!!parentId)
            .text(parentId ? trans(i18n.create_under, { path: folderPath(type, parseInt(parentId, 10)) }) : '');
        showBsModal('modal-add-folder');
    }

    $('#form-add-folder').on('submit', function (e) {
        e.preventDefault();

        var type = $('#folder-type').val();
        var parentId = $('#folder-parent-id').val();
        var payload = { name: $('#folder-name').val().trim(), type: type };
        if (parentId) { payload.parent_id = parseInt(parentId, 10); }

        $.ajax({
            url: '/admin/shared-file/ajax-store-folder',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            contentType: 'application/json',
            data: JSON.stringify(payload),
            success: function () {
                hideBsModal(document.getElementById('modal-add-folder'));
                // 建在子層時把父層展開，否則新資料夾藏在收合的節點裡看不到
                if (parentId) { collapsedFolders[parentId] = false; }
                setTimeout(function () { loadFolders(type); }, 400);
            },
            error: function (xhr) {
                showMsg((xhr.responseJSON && xhr.responseJSON.message) || i18n.msg.create_failed);
            }
        });
    });

    // 上傳
    $(document).on('click', '.js-upload-btn', function () {
        $(this).siblings('.js-file-upload-input').trigger('click');
    });

    $(document).on('change', '.js-file-upload-input', function () {
        var file = this.files[0];
        if (!file) { return; }

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
            error: function (xhr) {
                showMsg((xhr.responseJSON && xhr.responseJSON.message) || i18n.msg.upload_failed);
            }
        });

        this.value = '';
    });

    // 刪除檔案
    $(document).on('click', '.js-delete-file', function () {
        $('#modal-sf-delete-text').text(trans(i18n.confirm_delete_file, { name: $(this).data('name') }));
        $('#sf-delete-url').val('/admin/shared-file/ajax-delete-file/' + $(this).data('id'));
        showBsModal('modal-sf-delete');
    });

    // 刪除資料夾
    $(document).on('click', '.js-delete-folder', function (e) {
        e.stopPropagation();

        var id = parseInt($(this).data('id'), 10);
        var type = $(this).data('type');

        // 刪父層會把整棵子樹一起帶走，先講清楚會少掉幾個資料夾 ——
        // 這動作不可逆，不能讓人按下去才發現
        var subCount = countDescendants(groupByParent(folderCache[type] || []), id);
        var text = trans(i18n.confirm_delete_folder, { name: $(this).data('name') });
        if (subCount > 0) {
            text += trans(i18n.delete_folder_extra, { count: subCount });
        }

        $('#modal-sf-delete-text').text(text);
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
                setTimeout(function () {
                    showMsg((xhr.responseJSON && xhr.responseJSON.message) || i18n.msg.delete_failed);
                }, 400);
                $btn.prop('disabled', false);
            }
        });
    });

    // Tab 切換時載入
    $('button[data-bs-target="#tab-shared"]').on('shown.bs.tab', function () { loadFolders('shared'); });
    $('button[data-bs-target="#tab-personal"]').on('shown.bs.tab', function () { loadFolders('personal'); });

    // 管理者切換要查看的用戶
    $('#personal-user-select').on('change', function () {
        selectedPersonalFolder = null;
        $('#personal-folder-title').text(i18n.select_folder);
        $('#personal-file-body').html('<tr><td colspan="5" class="text-center text-muted py-3">' +
            escapeHtml(i18n.select_folder) + '</td></tr>');
        $('#personal-file-actions').css('display', 'none');
        loadFolders('personal', $(this).val() || null);
    });

    // 初始載入
    loadFolders('shared');
    loadFolders('personal');
});
