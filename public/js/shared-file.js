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
            html += '<td class="sf-file-name"><a href="javascript:void(0)" class="js-preview-file text-decoration-none"' +
                ' data-path="' + escapeHtml(f.file_path) + '" data-name="' + escapeHtml(f.original_name) + '">' +
                '<i class="fas ' + fileIcon(f.original_name) + ' me-1 text-muted"></i>' +
                escapeHtml(f.original_name) + '</a></td>';
            html += '<td class="sf-file-meta">' + fmtSize(f.file_size) + '</td>';
            html += '<td class="sf-file-meta">' + escapeHtml(f.uploader ? f.uploader.nickname : '-') + '</td>';
            html += '<td class="sf-file-meta">' + (f.created_at ? f.created_at.substring(0, 16).replace('T', ' ') : '-') + '</td>';
            html += '<td class="sf-file-actions"><div class="d-flex gap-1">';
            if (canAddFolder(type)) {
                html += '<button class="btn btn-sm btn-outline-secondary js-move-file" data-id="' + f.id +
                    '" data-name="' + escapeHtml(f.original_name) + '">' +
                    '<i class="fas fa-folder-open me-1"></i>' + escapeHtml(i18n.action_move) + '</button>';
            }
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

    // ===== 搬移檔案 =====

    /**
     * 目前所在的資料夾（用來把它從可選清單裡排除）
     *
     * @param {string} type
     * @returns {number|null}
     */
    function currentFolderId(type) {
        return type === 'shared' ? selectedSharedFolder : selectedPersonalFolder;
    }

    // 搬移選擇器的位置：type 為 null 表示還在最外層（選共用或個人）
    var movePicker = { type: null, parentId: null, excludeId: null };

    /**
     * 渲染搬移選擇器
     *
     * 做法比照檔案總管：清單只負責「瀏覽」，
     * 真正的動作固定在底部那一顆按鈕（目標永遠是目前所在的層）。
     * 讓清單同時兼具選取與導覽兩種語意，使用者會分不清點下去會發生什麼。
     */
    function renderMovePicker() {
        var $box = $('#sf-move-folders');

        $box.html(movePicker.type
            ? moveBreadcrumbHtml() + moveCurrentLevelHtml()
            : moveRootHtml());

        renderMoveConfirm();
    }

    /**
     * 底部的確認鈕。依目前所在的層決定文字與能不能按
     */
    function renderMoveConfirm() {
        var $btn = $('#sf-move-confirm');

        // 還在「共用／個人」這層：檔案不能掛在分類底下
        if (!movePicker.parentId) {
            $btn.prop('disabled', true).removeData('id')
                .html('<i class="fas fa-hand-pointer me-1"></i>' + escapeHtml(i18n.move_pick_folder));

            return;
        }

        var name = folderName(movePicker.type, movePicker.parentId);

        if (movePicker.parentId === movePicker.excludeId) {
            $btn.prop('disabled', true).removeData('id')
                .html('<i class="fas fa-info-circle me-1"></i>' + escapeHtml(i18n.already_in_folder));

            return;
        }

        $btn.prop('disabled', false).data('id', movePicker.parentId)
            .html('<i class="fas fa-arrow-right me-1"></i>' +
                escapeHtml(trans(i18n.move_to_folder, { name: name })));
    }

    /**
     * @param {string} type
     * @param {number} folderId
     * @returns {string}
     */
    function folderName(type, folderId) {
        var folder = (folderCache[type] || []).filter(function (f) {
            return f.id === folderId;
        })[0];

        return folder ? folder.name : '';
    }

    /**
     * 最外層：選共用文件或個人文件
     *
     * @returns {string}
     */
    function moveRootHtml() {
        var html = '';

        ['shared', 'personal'].forEach(function (type) {
            if (!canAddFolder(type) || !(folderCache[type] || []).length) { return; }

            html += '<a href="javascript:void(0)" class="list-group-item list-group-item-action js-move-enter-type"' +
                ' data-type="' + type + '" style="font-size:0.875rem">' +
                '<i class="fas ' + (type === 'shared' ? 'fa-globe' : 'fa-user') + ' me-2 text-muted"></i>' +
                escapeHtml(type === 'shared' ? i18n.tab_shared : i18n.tab_personal) +
                '<i class="fas fa-chevron-right float-end text-muted" style="font-size:0.75rem;margin-top:0.25rem"></i></a>';
        });

        return html || '<div class="text-center text-muted py-4">' + escapeHtml(i18n.no_move_target) + '</div>';
    }

    /**
     * 麵包屑導覽列（灰底，與底下的資料夾清單區隔開）
     *
     * @returns {string}
     */
    function moveBreadcrumbHtml() {
        var typeLabel = movePicker.type === 'shared' ? i18n.tab_shared : i18n.tab_personal;
        var path = movePicker.parentId
            ? typeLabel + ' / ' + folderPath(movePicker.type, movePicker.parentId)
            : typeLabel;

        return '<div class="sf-move-crumb px-2 py-2 d-flex align-items-center gap-2">' +
            '<button type="button" class="btn btn-sm btn-link text-muted p-0 px-1 js-move-back">' +
            '<i class="fas fa-arrow-left"></i></button>' +
            '<span class="text-truncate" style="font-size:0.8125rem">' + escapeHtml(path) + '</span></div>';
    }

    /**
     * 目前這一層的子資料夾。點整列＝進入該資料夾
     *
     * @returns {string}
     */
    function moveCurrentLevelHtml() {
        var byParent = groupByParent(folderCache[movePicker.type] || []);
        var list = byParent[movePicker.parentId ? String(movePicker.parentId) : 'root'] || [];
        var html = '';

        list.forEach(function (f) {
            var isCurrent = f.id === movePicker.excludeId;

            html += '<a href="javascript:void(0)" class="list-group-item list-group-item-action js-move-enter-folder' +
                (isCurrent ? ' sf-move-current' : '') + '"' +
                ' data-id="' + f.id + '" style="font-size:0.875rem">' +
                '<i class="fas fa-folder me-2 text-warning"></i>' + escapeHtml(f.name) +
                // 目前所在的資料夾標記出來，使用者才知道自己原本在哪
                (isCurrent ? '<span class="badge bg-secondary ms-2" style="font-size:0.6875rem">' +
                    escapeHtml(i18n.move_current_badge) + '</span>' : '') +
                '<i class="fas fa-chevron-right float-end text-muted" style="font-size:0.75rem;margin-top:0.25rem"></i>' +
                '</a>';
        });

        return html || '<div class="text-center text-muted py-3" style="font-size:0.8125rem">' +
            escapeHtml(i18n.no_subfolder) + '</div>';
    }

    $(document).on('click', '.js-move-file', function () {
        var $row = $(this);
        var type = $row.closest('#personal-file-body').length ? 'personal' : 'shared';

        var fromId = currentFolderId(type);
        var typeLabel = type === 'shared' ? i18n.tab_shared : i18n.tab_personal;

        $('#sf-move-file-id').val($row.data('id'));
        $('#sf-move-filename').text($row.data('name'));
        // 一開始就講清楚檔案現在在哪，否則瀏覽到一半會忘記起點
        $('#sf-move-origin').text(trans(i18n.move_from, {
            path: typeLabel + ' / ' + folderPath(type, fromId)
        }));

        // 直接進到檔案所在的那一區，少一次點擊
        movePicker = { type: type, parentId: null, excludeId: fromId };
        renderMovePicker();
        showBsModal('modal-sf-move');
    });

    // 進入共用／個人
    $(document).on('click', '.js-move-enter-type', function () {
        movePicker.type = $(this).data('type');
        movePicker.parentId = null;
        renderMovePicker();
    });

    // 點資料夾往下一層
    $(document).on('click', '.js-move-enter-folder', function () {
        movePicker.parentId = parseInt($(this).data('id'), 10);
        renderMovePicker();
    });

    // 返回上一層
    $(document).on('click', '.js-move-back', function () {
        if (!movePicker.parentId) {
            movePicker.type = null;
            renderMovePicker();

            return;
        }

        var folder = (folderCache[movePicker.type] || []).filter(function (f) {
            return f.id === movePicker.parentId;
        })[0];

        movePicker.parentId = folder && folder.parent_id ? folder.parent_id : null;
        renderMovePicker();
    });

    $(document).on('click', '.js-move-target', function () {
        var fileId = $('#sf-move-file-id').val();
        // 目標永遠是目前瀏覽到的那一層，renderMoveConfirm() 已寫進 data-id
        var targetId = movePicker.parentId;
        if (!targetId) { return; }

        $.ajax({
            url: '/admin/shared-file/ajax-move-file/' + fileId,
            method: 'PUT',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            contentType: 'application/json',
            data: JSON.stringify({ folder_id: targetId }),
            success: function (body) {
                hideBsModal(document.getElementById('modal-sf-move'));

                // 等前一個 modal 收完再開提示，否則 backdrop 會疊在一起
                setTimeout(function () {
                    if (selectedSharedFolder) { loadFiles('shared', selectedSharedFolder); }
                    if (selectedPersonalFolder) { loadFiles('personal', selectedPersonalFolder); }
                    showMsg((body && body.message) || i18n.msg.file_moved);
                }, 400);
            },
            error: function (xhr) {
                hideBsModal(document.getElementById('modal-sf-move'));
                setTimeout(function () {
                    showMsg((xhr.responseJSON && xhr.responseJSON.message) || i18n.msg.move_failed);
                }, 400);
            }
        });
    });

    // ===== 檔案預覽 =====

    /** 可直接在瀏覽器裡呈現的類型。其餘一律導向下載 */
    var PREVIEW_TYPES = {
        image: ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico'],
        pdf:   ['pdf'],
        video: ['mp4', 'webm', 'ogv'],
        audio: ['mp3', 'wav', 'ogg', 'm4a'],
        text:  ['txt', 'csv', 'log', 'json', 'xml', 'md', 'ini', 'yml', 'yaml']
    };

    /** 純文字預覽的大小上限，太大會把瀏覽器拖垮 */
    var TEXT_PREVIEW_MAX_BYTES = 1024 * 1024;

    /**
     * @param {string} filename
     * @returns {string} 副檔名（小寫，不含點）
     */
    function fileExt(filename) {
        var parts = String(filename).split('.');

        return parts.length > 1 ? parts.pop().toLowerCase() : '';
    }

    /**
     * @param {string} filename
     * @returns {string|null} PREVIEW_TYPES 的 key，無法預覽回 null
     */
    function previewKind(filename) {
        var ext = fileExt(filename);
        var kinds = Object.keys(PREVIEW_TYPES);

        for (var i = 0; i < kinds.length; i++) {
            if (PREVIEW_TYPES[kinds[i]].indexOf(ext) !== -1) { return kinds[i]; }
        }

        return null;
    }

    /**
     * 依類型挑列表的圖示
     *
     * @param {string} filename
     * @returns {string} Font Awesome class
     */
    function fileIcon(filename) {
        var map = {
            image: 'fa-file-image',
            pdf: 'fa-file-pdf',
            video: 'fa-file-video',
            audio: 'fa-file-audio',
            text: 'fa-file-alt'
        };

        return map[previewKind(filename)] || 'fa-file';
    }

    /**
     * 把檔案渲染進預覽區
     *
     * @param {string} url
     * @param {string} filename
     */
    function renderPreview(url, filename) {
        var $body = $('#sf-preview-body');
        var kind = previewKind(filename);

        // 內容一律不自帶 max-height / overflow：
        // modal-dialog-scrollable 的 modal-body 已經是捲動容器，
        // 再包一層會變成兩個容器搶同一個手勢，手機上就完全滑不動
        if (kind === 'image') {
            // 用 <img> 而非內嵌：SVG 若含腳本，在 img 情境下不會執行
            $body.html($('<img class="img-fluid">').attr('src', url).attr('alt', filename));
            return;
        }

        if (kind === 'pdf') {
            renderPdfPreview(url);
            return;
        }

        if (kind === 'video') {
            $body.html($('<video controls class="img-fluid">').attr('src', url));
            return;
        }

        if (kind === 'audio') {
            $body.html($('<audio controls class="w-100 p-3">').attr('src', url));
            return;
        }

        if (kind === 'text') {
            renderTextPreview(url);
            return;
        }

        // 無法預覽：不要留白，明講並引導去下載
        $body.html('<div class="text-center text-muted py-5">' +
            '<i class="fas fa-file fa-3x mb-3 d-block"></i>' +
            escapeHtml(i18n.preview_unsupported) + '</div>');
    }

    /**
     * PDF 預覽
     *
     * 一律先試內嵌。手機額外附一個「在新分頁開啟」的退路 ——
     * 內嵌 PDF 在行動瀏覽器的支援度不一（iOS Safari 常只顯示第一頁且不能捲、
     * Android Chrome 多半不內嵌），但不是每台裝置都這樣，
     * 所以不預先剝奪內嵌，只是把逃生門放在旁邊。
     *
     * @param {string} url
     */
    function renderPdfPreview(url) {
        var $body = $('#sf-preview-body').empty();
        var isMobile = window.innerWidth < 768;

        $body.append($('<iframe style="width:100%;border:0;display:block">')
            .css('height', isMobile ? '60vh' : '75vh')
            .attr('src', url));

        if (!isMobile) { return; }

        $body.append('<div class="text-center py-3">' +
            '<a href="' + url + '" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm">' +
            '<i class="fas fa-external-link-alt me-1"></i>' + escapeHtml(i18n.preview_open_new_tab) + '</a>' +
            '<div class="text-muted mt-2" style="font-size:0.75rem">' +
            escapeHtml(i18n.preview_pdf_hint) + '</div></div>');
    }

    /**
     * 純文字預覽。先看大小再決定要不要抓，避免把整份大檔拉進記憶體
     *
     * @param {string} url
     */
    function renderTextPreview(url) {
        var $body = $('#sf-preview-body');
        $body.html('<div class="text-center text-muted py-5">' + escapeHtml(i18n.loading) + '</div>');

        fetch(url)
            .then(function (res) {
                if (!res.ok) { throw new Error('fetch failed'); }

                var size = parseInt(res.headers.get('content-length') || '0', 10);
                if (size > TEXT_PREVIEW_MAX_BYTES) { throw new Error('too large'); }

                return res.text();
            })
            .then(function (text) {
                $body.html($('<pre class="mb-0 p-3" style="white-space:pre-wrap;word-break:break-all">').text(text));
            })
            .catch(function () {
                $body.html('<div class="text-center text-muted py-5">' +
                    escapeHtml(i18n.preview_unsupported) + '</div>');
            });
    }

    // ===== 事件 =====

    // 點檔名開預覽
    $(document).on('click', '.js-preview-file', function () {
        var path = $(this).data('path');
        var name = $(this).data('name');
        var url = '/storage/' + path;

        $('#sf-preview-title').text(name);
        renderPreview(url, name);
        showBsModal('modal-sf-preview');
    });

    // 關掉時清空內容：影片／音訊不清會在背景繼續播
    $('#modal-sf-preview').on('hidden.bs.modal', function () {
        $('#sf-preview-body').empty();
    });

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
        // 從所在的操作區判斷分頁，不要用 selectedSharedFolder === folderId 比對 ——
        // 個人與共用的資料夾 id 撞號時會判錯邊
        var type = $(this).closest('#personal-file-actions').length ? 'personal' : 'shared';

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
            success: function (body) {
                loadFiles(type, folderId);
                showMsg((body && body.message) || i18n.msg.file_uploaded);
            },
            error: function (xhr) {
                showMsg((xhr.responseJSON && xhr.responseJSON.message) || i18n.msg.upload_failed);
            }
        });

        this.value = '';
    });

    // 刪的是檔案還是資料夾，決定成功後要重載哪一邊
    var pendingDeleteKind = null;

    // 刪除檔案
    $(document).on('click', '.js-delete-file', function () {
        pendingDeleteKind = 'file';
        $('#modal-sf-delete-text').text(trans(i18n.confirm_delete_file, { name: $(this).data('name') }));
        $('#sf-delete-url').val('/admin/shared-file/ajax-delete-file/' + $(this).data('id'));
        showBsModal('modal-sf-delete');
    });

    // 刪除資料夾
    $(document).on('click', '.js-delete-folder', function (e) {
        e.stopPropagation();

        var id = parseInt($(this).data('id'), 10);
        var type = $(this).data('type');
        pendingDeleteKind = 'folder';

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
            success: function (body) {
                var kind = pendingDeleteKind;
                hideBsModal(document.getElementById('modal-sf-delete'));

                // 等前一個 modal 收完再開下一個，否則 backdrop 會疊在一起
                setTimeout(function () {
                    reloadAfterDelete(kind);
                    showMsg((body && body.message) || i18n.msg.file_deleted);
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

    /**
     * 刪除成功後重載畫面
     *
     * 刪檔案時只需重載目前資料夾的檔案列表 —— 原本只重載資料夾清單，
     * 被刪掉的那一列會繼續留在畫面上，看起來像沒刪掉。
     *
     * @param {string} kind file / folder
     */
    function reloadAfterDelete(kind) {
        if (kind === 'file') {
            if (selectedSharedFolder) { loadFiles('shared', selectedSharedFolder); }
            if (selectedPersonalFolder) { loadFiles('personal', selectedPersonalFolder); }

            return;
        }

        // 資料夾（含子樹）被刪掉，選取狀態要跟著清掉，否則標題會停在已不存在的資料夾
        selectedSharedFolder = null;
        selectedPersonalFolder = null;
        resetFilePanel('shared');
        resetFilePanel('personal');
        loadFolders('shared');
        loadFolders('personal');
    }

    /**
     * 把檔案區還原成「請選擇資料夾」
     *
     * @param {string} type
     */
    function resetFilePanel(type) {
        $('#' + type + '-folder-title').text(i18n.select_folder);
        $('#' + type + '-file-body').html('<tr><td colspan="5" class="text-center text-muted py-3">' +
            escapeHtml(i18n.select_folder) + '</td></tr>');
        $('#' + type + '-file-actions').css('display', 'none');
    }

    // Tab 切換時載入
    $('button[data-bs-target="#tab-shared"]').on('shown.bs.tab', function () { loadFolders('shared'); });
    $('button[data-bs-target="#tab-personal"]').on('shown.bs.tab', function () { loadFolders('personal'); });

    // 管理者切換要查看的用戶
    $('#personal-user-select').on('change', function () {
        selectedPersonalFolder = null;
        resetFilePanel('personal');
        loadFolders('personal', $(this).val() || null);
    });

    // 初始載入
    loadFolders('shared');
    loadFolders('personal');
});
