/**
 * Telegram Chat — 輸入區域（發送文字 + 圖片 + 貼上截圖 + IME 處理）
 */
(function () {
    var T = window.TgChat;
    if (!T) { return; }

    // 後端 ajax-send-image 限制 max:5120（KB）
    var MAX_IMAGE_BYTES = 5120 * 1024;

    // 後端 SendFileRequest::$maxKb 限制 51200（KB），即 Telegram Bot API 的上傳上限
    var MAX_FILE_MB = 50;
    var MAX_FILE_BYTES = MAX_FILE_MB * 1024 * 1024;

    // 輸入框與功能鈕共用同一個字級
    var INPUT_FONT_SIZE = '0.9375rem';

    // 圖示相對文字放大：同樣 font-size 下中文字撐滿 em 方塊，
    // Font Awesome 的圖形只佔約 0.8em，不放大看起來會比文字小一截
    var ICON_FONT_SIZE = '1.15em';

    // 待傳送的附件（截圖與一般檔案），按下發送才會真的送到客戶群組
    var pendingFiles = [];

    /**
     * 輸入區上方的功能鈕（圖示 + 文字說明）
     *
     * @param {string} id
     * @param {string} icon  Font Awesome class
     * @param {string} label
     * @returns {string}
     */
    function toolButton(id, icon, label) {
        // 文字對齊輸入框字級，圖示再依 ICON_FONT_SIZE 放大，兩者視覺才等高
        return '<button class="btn btn-outline-secondary d-flex align-items-center" id="' + id + '" type="button" ' +
            'style="font-size:' + INPUT_FONT_SIZE + ';padding:0.25rem 0.625rem;line-height:1.5">' +
            '<i class="fas ' + icon + ' me-1" style="font-size:' + ICON_FONT_SIZE + ';line-height:1"></i>' + label +
            '</button>';
    }

    /**
     * 是否為手機版面。對齊 Bootstrap 的 md 斷點
     *
     * @returns {boolean}
     */
    function isMobileViewport() {
        return window.innerWidth < 768;
    }

    /**
     * 手機版寬度不夠放長提示，只有桌機顯示「可直接貼上截圖」
     *
     * @returns {string}
     */
    function placeholder() {
        if (isMobileViewport()) { return T.i18n.input_placeholder; }

        return T.i18n.input_placeholder_wide || T.i18n.input_placeholder;
    }

    T.showInput = function () {
        var inputArea = document.getElementById('tg-input');
        if (!inputArea) { return; }

        // 切換群組時清空，避免附件誤送到別的對話
        pendingFiles = [];

        // 輸入區會整個重建，殘留的表情選單會指向已消失的按鈕
        if (T.closeEmojiPicker) { T.closeEmojiPicker(); }

        if (!T.canReply) {
            inputArea.style.display = 'none';
            return;
        }

        inputArea.style.display = 'block';
        inputArea.innerHTML =
            '<div id="tg-pending-images" class="px-3 pt-2 flex-wrap gap-2 align-items-center" style="display:none"></div>' +
            '<div id="tg-upload-progress" class="px-3 pt-2" style="display:none"></div>' +
            '<div id="tg-input-error" class="px-3 pt-2 text-danger" style="display:none;font-size:0.8125rem"></div>' +
            // 功能鈕獨立一列並帶文字說明，輸入框才有整列寬度
            '<div class="d-flex align-items-center flex-wrap gap-2 px-3 pt-2" id="tg-input-tools">' +
            // 不設 accept：圖片與一般檔案都從這個按鈕挑，選完再依 MIME 分流
            '<input type="file" id="tg-image-input" style="display:none">' +
            toolButton('btn-tg-image', 'fa-paperclip', T.i18n.btn_attachment || '檔案') +
            toolButton('btn-tg-shared-file', 'fa-file-alt', T.i18n.btn_file || '文件') +
            toolButton('btn-tg-quick-reply', 'fa-bolt', T.i18n.btn_quick_reply || '快速回覆') +
            '</div>' +
            '<div class="d-flex align-items-center gap-1 px-3 py-2">' +
            // 表情鈕嵌在輸入框內側右邊（Telegram / LINE 的做法），送出鈕留在框外
            '<div class="tg-input-wrap">' +
            '<textarea id="tg-reply-text" class="form-control form-control-sm" placeholder="' + placeholder() + '" rows="1" style="resize:none;max-height:100px;border-radius:1rem;font-size:' + INPUT_FONT_SIZE + '"></textarea>' +
            '<button class="tg-emoji-btn" id="btn-tg-emoji" type="button" title="' + (T.i18n.btn_emoji || '表情') + '"><i class="far fa-smile"></i></button>' +
            '</div>' +
            '<button class="btn btn-primary rounded-circle d-flex align-items-center justify-content-center ms-1" id="btn-tg-send" type="button" style="width:38px;height:38px;flex-shrink:0"><i class="fas fa-paper-plane" style="font-size:0.875rem"></i></button>' +
            '</div>';

        var textarea = document.getElementById('tg-reply-text');
        var sendBtn = document.getElementById('btn-tg-send');
        var imageBtn = document.getElementById('btn-tg-image');
        var imageInput = document.getElementById('tg-image-input');

        sendBtn.addEventListener('click', function () { sendReply(); });
        imageBtn.addEventListener('click', function () { imageInput.click(); });
        imageInput.addEventListener('change', function () {
            if (imageInput.files.length > 0) {
                sendAttachment(imageInput.files[0]);
                imageInput.value = '';
            }
        });

        // 文件區按鈕
        var sfBtn = document.getElementById('btn-tg-shared-file');
        if (sfBtn) {
            sfBtn.addEventListener('click', function () { openSharedFileModal(); });
        }

        // 表情選單按鈕（實作在 emoji.js）
        var emojiBtn = document.getElementById('btn-tg-emoji');
        if (emojiBtn && T.toggleEmojiPicker) {
            emojiBtn.addEventListener('click', function () { T.toggleEmojiPicker(emojiBtn); });
        }

        // 快速回覆按鈕（實作在 quick-reply.js）
        var qrBtn = document.getElementById('btn-tg-quick-reply');
        if (qrBtn && T.openQuickReplyModal) {
            qrBtn.addEventListener('click', function () { T.openQuickReplyModal(); });
        }

        // 自動高度 + typing 通知
        var typingTimer = null;
        textarea.addEventListener('input', function () {
            textarea.style.height = 'auto';
            textarea.style.height = Math.min(textarea.scrollHeight, 120) + 'px';

            // 節流：3 秒內只發一次 typing
            if (!typingTimer && T.selectedGroupId) {
                T.apiFetch('/admin/telegram-chat/ajax-typing', {
                    method: 'POST',
                    body: JSON.stringify({ group_id: T.selectedGroupId }),
                }).catch(function () {});
                typingTimer = setTimeout(function () { typingTimer = null; }, 3000);
            }
        });

        // Enter 送出（IME 安全）
        var imeActive = false;
        textarea.addEventListener('compositionstart', function () { imeActive = true; });
        textarea.addEventListener('compositionend', function () {
            setTimeout(function () { imeActive = false; }, 50);
        });
        textarea.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' || e.shiftKey || imeActive) { return; }

            // 手機的 Enter 是軟鍵盤的換行鍵，攔下來當送出會讓人打不出多行訊息，
            // 而且很容易在句子沒打完時誤送。手機一律只用送出鈕
            if (isMobileViewport()) { return; }

            e.preventDefault();
            sendReply();
            textarea.style.height = '';
        });

        renderPendingFiles();
        hideUploadProgress();
    };

    // ===== 貼上截圖 =====

    /**
     * 綁在 document 上，截圖後不必先點輸入框就能直接 Ctrl+V
     */
    document.addEventListener('paste', function (e) {
        if (!T.canReply || !T.selectedGroupId) { return; }
        if (!document.getElementById('tg-reply-text')) { return; }

        // 在別的輸入框（搜尋框等）貼上時不攔截
        var target = e.target;
        if (target && target !== document.getElementById('tg-reply-text')) {
            var tag = (target.tagName || '').toLowerCase();
            if (tag === 'input' || tag === 'textarea' || target.isContentEditable) { return; }
        }

        var clipboard = e.clipboardData || window.clipboardData;
        if (!clipboard || !clipboard.items) { return; }

        var images = [];
        for (var i = 0; i < clipboard.items.length; i++) {
            var item = clipboard.items[i];
            if (item.kind !== 'file' || item.type.indexOf('image/') !== 0) { continue; }
            var file = item.getAsFile();
            if (file) { images.push(namedImage(file)); }
        }

        // 純文字貼上：交還給瀏覽器預設行為
        if (!images.length) { return; }

        e.preventDefault();
        images.forEach(addPendingFile);
    });

    // ===== 拖曳截圖進聊天視窗 =====

    // dragenter/dragleave 會在子元素之間反覆觸發，用計數避免遮罩閃爍
    var dragDepth = 0;

    /**
     * 拖的是檔案（而非選取的文字或連結）
     *
     * @param {DragEvent} e
     * @returns {boolean}
     */
    function isFileDrag(e) {
        var types = e.dataTransfer && e.dataTransfer.types;
        if (!types) { return false; }

        // types 在不同瀏覽器是 DOMStringList 或 Array
        return Array.prototype.indexOf.call(types, 'Files') !== -1;
    }

    /**
     * 落在聊天頁範圍內 —— 這層只用來阻止瀏覽器直接開啟檔案
     *
     * @param {DragEvent} e
     * @returns {boolean}
     */
    function inChatPage(e) {
        return !!(e.target && e.target.closest && T.root.contains(e.target));
    }

    /**
     * 真正接受截圖的範圍：右側聊天欄，且已選對話、有回覆權限
     *
     * @param {DragEvent} e
     * @returns {boolean}
     */
    function canDropHere(e) {
        if (!T.canReply || !T.selectedGroupId) { return false; }
        if (!isFileDrag(e) || !inChatPage(e)) { return false; }

        return !!e.target.closest('.app-inner-layout__content');
    }

    document.addEventListener('dragenter', function (e) {
        if (!canDropHere(e)) { return; }
        e.preventDefault();
        dragDepth++;
        toggleDropOverlay(true);
    });

    document.addEventListener('dragover', function (e) {
        // 聊天頁內一律 preventDefault，否則拖歪到左側列表會被瀏覽器當成開啟檔案而離開頁面
        if (!isFileDrag(e) || !inChatPage(e)) { return; }
        e.preventDefault();
        e.dataTransfer.dropEffect = canDropHere(e) ? 'copy' : 'none';
    });

    document.addEventListener('dragleave', function (e) {
        if (!dragDepth) { return; }

        // 拖出瀏覽器視窗時 relatedTarget 為 null，直接收掉遮罩免得卡住
        if (!e.relatedTarget) {
            dragDepth = 0;
            toggleDropOverlay(false);
            return;
        }

        if (!canDropHere(e)) { return; }
        dragDepth = Math.max(0, dragDepth - 1);
        if (!dragDepth) { toggleDropOverlay(false); }
    });

    // 拖曳取消（例如按 Esc）也要收掉遮罩
    document.addEventListener('dragend', function () {
        dragDepth = 0;
        toggleDropOverlay(false);
    });

    document.addEventListener('drop', function (e) {
        if (!isFileDrag(e) || !inChatPage(e)) { return; }

        // 落在聊天頁任何位置都吞掉，不讓瀏覽器開啟檔案
        e.preventDefault();
        dragDepth = 0;
        toggleDropOverlay(false);

        if (!canDropHere(e)) { return; }

        var files = e.dataTransfer.files;
        if (!files || !files.length) { return; }

        // 圖片與一般檔案都收，圖片補檔名是因為拖進來的截圖常叫 blob
        for (var i = 0; i < files.length; i++) {
            addPendingFile(isImageFile(files[i]) ? namedImage(files[i]) : files[i]);
        }
    });

    /**
     * 拖曳中的提示遮罩，蓋在右側聊天欄上
     *
     * @param {boolean} visible
     */
    function toggleDropOverlay(visible) {
        var content = document.querySelector('.app-inner-layout__content');
        if (!content) { return; }

        var overlay = document.getElementById('tg-drop-overlay');
        if (!overlay) {
            if (window.getComputedStyle(content).position === 'static') {
                content.style.position = 'relative';
            }

            overlay = document.createElement('div');
            overlay.id = 'tg-drop-overlay';
            // pointer-events:none —— 讓 dragleave/drop 能穿透到底下元素，否則遮罩會自己吃掉事件
            overlay.style.cssText = 'position:absolute;top:0;right:0;bottom:0;left:0;z-index:10;display:none;' +
                'align-items:center;justify-content:center;pointer-events:none;' +
                'background:rgba(212,175,55,0.12);border:2px dashed #d4af37;border-radius:0.5rem';
            overlay.innerHTML = '<div class="fw-bold" style="color:#a67c00;font-size:1.0625rem">' +
                '<i class="fas fa-paperclip me-2"></i>' + (T.i18n.drop_hint || '放開以加入附件') +
                '</div>';
            content.appendChild(overlay);
        }

        overlay.style.display = visible ? 'flex' : 'none';
    }

    /**
     * 剪貼簿與拖曳進來的圖片常沒有檔名（或叫 blob），補一個帶副檔名的名稱，
     * 後端 putFileAs 才不會存成沒有副檔名的檔案
     */
    function namedImage(file) {
        if (file.name && file.name !== 'blob' && file.name.indexOf('.') > -1) { return file; }

        var ext = (file.type.split('/')[1] || 'png').toLowerCase();
        if (ext === 'jpeg') { ext = 'jpg'; }
        var name = 'screenshot_' + new Date().getTime() + '.' + ext;

        try {
            return new File([file], name, { type: file.type });
        } catch (err) {
            // 舊瀏覽器不支援 File 建構子，退回原檔（後端仍會依 MIME 驗證）
            return file;
        }
    }

    /**
     * 上傳失敗時的訊息：後端回的 JSON 才採用它的 message，
     * 其他（例如 PHP upload_max_filesize 擋下、回傳非 JSON）一律用預設文案
     *
     * @param {*}      error
     * @param {string} [fallback] 沒帶時用圖片的失敗文案
     * @returns {string}
     */
    function errorMessage(error, fallback) {
        if (error && typeof error === 'object' && !(error instanceof Error) && error.message) {
            return error.message;
        }

        return fallback || T.i18n.msg.image_send_failed || '圖片傳送失敗';
    }

    /**
     * @param {File} file
     * @returns {boolean}
     */
    function isImageFile(file) {
        return (file.type || '').indexOf('image/') === 0;
    }

    /**
     * 加入待送區。圖片與一般檔案的大小上限不同，先在前端擋掉超標的，
     * 不必等整個檔案上傳完才收到 422
     *
     * @param {File} file
     */
    function addPendingFile(file) {
        if (isImageFile(file)) {
            if (file.size > MAX_IMAGE_BYTES) {
                showInputError(T.i18n.msg.image_too_large || '圖片超過 5MB，無法傳送');
                return;
            }
        } else if (file.size > MAX_FILE_BYTES) {
            showInputError(fileTooLargeMessage());
            return;
        }

        pendingFiles.push(file);
        showInputError('');
        renderPendingFiles();
    }

    /**
     * 檔案大小顯示成 KB / MB
     *
     * @param {number} bytes
     * @returns {string}
     */
    function formatSize(bytes) {
        if (bytes < 1024 * 1024) { return Math.max(1, Math.round(bytes / 1024)) + ' KB'; }

        return (bytes / 1024 / 1024).toFixed(1) + ' MB';
    }

    /**
     * 待送區的圖片縮圖
     *
     * @param {File} file
     * @returns {HTMLElement}
     */
    function pendingImageNode(file) {
        var img = document.createElement('img');
        img.src = URL.createObjectURL(file);
        img.style.cssText = 'width:56px;height:56px;object-fit:cover;border-radius:0.375rem;border:1px solid rgba(0,0,0,0.1)';
        img.onload = function () { URL.revokeObjectURL(img.src); };

        return img;
    }

    /**
     * 待送區的檔案卡片：非圖片沒有縮圖可看，改顯示圖示 + 檔名 + 大小
     *
     * @param {File} file
     * @returns {HTMLElement}
     */
    function pendingFileNode(file) {
        var card = document.createElement('div');
        card.className = 'd-flex align-items-center gap-2 px-2';
        card.style.cssText = 'height:56px;max-width:220px;border-radius:0.375rem;border:1px solid rgba(0,0,0,0.1);background:rgba(0,0,0,0.03)';

        var icon = document.createElement('i');
        icon.className = 'fas fa-file-alt text-muted';

        var text = document.createElement('div');
        text.style.cssText = 'min-width:0;font-size:0.75rem;line-height:1.3';

        var name = document.createElement('div');
        name.className = 'text-truncate';
        name.textContent = file.name;
        name.title = file.name;

        var size = document.createElement('div');
        size.className = 'text-muted';
        size.textContent = formatSize(file.size);

        text.appendChild(name);
        text.appendChild(size);
        card.appendChild(icon);
        card.appendChild(text);

        return card;
    }

    function renderPendingFiles() {
        var container = document.getElementById('tg-pending-images');
        if (!container) { return; }

        if (!pendingFiles.length) {
            container.style.display = 'none';
            container.innerHTML = '';
            return;
        }

        container.style.display = 'flex';
        container.innerHTML = '';
        pendingFiles.forEach(function (file, index) {
            var wrapper = document.createElement('div');
            wrapper.className = 'position-relative';

            wrapper.appendChild(isImageFile(file) ? pendingImageNode(file) : pendingFileNode(file));

            var removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn-close position-absolute top-0 end-0 bg-white rounded-circle';
            removeBtn.style.cssText = 'font-size:0.5rem;padding:0.2rem';
            removeBtn.title = T.i18n.btn_remove_image || '移除';
            removeBtn.addEventListener('click', function () {
                pendingFiles.splice(index, 1);
                renderPendingFiles();
            });

            wrapper.appendChild(removeBtn);
            container.appendChild(wrapper);
        });
    }

    /**
     * 傳送中的狀態列：檔名 + 第幾筆 + 進度條
     *
     * 大檔案上傳要好幾秒，沒有回饋使用者會以為當掉而重複點發送。
     *
     * @param {string} filename
     * @param {number} index 從 1 起算
     * @param {number} total
     */
    function showUploadProgress(filename, index, total) {
        var box = document.getElementById('tg-upload-progress');
        if (!box) { return; }

        var label = T.i18n.msg.file_sending || '傳送中';
        var counter = total > 1 ? ' (' + index + '/' + total + ')' : '';

        box.style.display = 'block';
        box.innerHTML =
            '<div class="d-flex align-items-center gap-2 mb-1" style="font-size:0.75rem">' +
            '<i class="fas fa-spinner fa-spin text-muted"></i>' +
            '<span class="text-truncate" style="min-width:0">' + T.escapeHtml(label + counter + '：' + filename) + '</span>' +
            '<span class="ms-auto text-muted flex-shrink-0" id="tg-upload-percent">0%</span>' +
            '</div>' +
            '<div class="progress" style="height:4px">' +
            '<div class="progress-bar" id="tg-upload-bar" role="progressbar" style="width:0%"></div>' +
            '</div>';
    }

    /**
     * @param {number} percent 0–100
     */
    function updateUploadProgress(percent) {
        var bar = document.getElementById('tg-upload-bar');
        var text = document.getElementById('tg-upload-percent');
        var value = Math.max(0, Math.min(100, Math.round(percent)));

        if (bar) { bar.style.width = value + '%'; }
        if (text) { text.textContent = value + '%'; }
    }

    function hideUploadProgress() {
        var box = document.getElementById('tg-upload-progress');
        if (!box) { return; }

        box.style.display = 'none';
        box.innerHTML = '';
    }

    function showInputError(message) {
        var box = document.getElementById('tg-input-error');
        if (!box) { return; }

        if (!message) {
            box.style.display = 'none';
            box.textContent = '';
            return;
        }

        box.textContent = message;
        box.style.display = 'block';
    }

    function sendReply() {
        if (!T.selectedGroupId) { return; }

        // 有待傳送的附件時，改走附件流程（文字會當成第一個的 caption）
        if (pendingFiles.length) {
            sendPendingFiles();
            return;
        }

        var textarea = document.getElementById('tg-reply-text');
        var sendBtn = document.getElementById('btn-tg-send');
        var content = textarea.value.trim();
        if (!content) { return; }

        textarea.disabled = true;
        if (sendBtn) { sendBtn.disabled = true; sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin" style="font-size:0.875rem"></i>'; }

        T.apiFetch('/admin/telegram-chat/ajax-reply', {
            method: 'POST',
            body: JSON.stringify({ group_id: T.selectedGroupId, content: content }),
        })
            .then(function () {
                textarea.value = '';
                textarea.style.height = '';
                textarea.disabled = false;
                if (sendBtn) { sendBtn.disabled = false; sendBtn.innerHTML = '<i class="fas fa-paper-plane" style="font-size:0.875rem"></i>'; }
                textarea.focus();
                // 重新載入訊息（不依賴 Pusher）
                T.loadMessages(T.selectedGroupId);
            })
            .catch(function (error) {
                textarea.disabled = false;
                if (sendBtn) { sendBtn.disabled = false; sendBtn.innerHTML = '<i class="fas fa-paper-plane" style="font-size:0.875rem"></i>'; }
                alert(error.message || T.i18n.msg.reply_failed);
            });
    }

    /**
     * 選檔後先開預覽視窗確認，不直接送出 ——
     * 選錯檔案卻已經送到客戶群組是收不回來的
     *
     * @param {File} file
     */
    function sendAttachment(file) {
        if (!T.selectedGroupId) { return; }

        // 超過上限先擋下來，不必等使用者填完說明才發現送不出去
        if (isImageFile(file)) {
            if (file.size > MAX_IMAGE_BYTES) {
                showInputError(T.i18n.msg.image_too_large || '圖片超過 5MB，無法傳送');
                return;
            }
        } else if (file.size > MAX_FILE_BYTES) {
            showInputError(fileTooLargeMessage());
            return;
        }

        showInputError('');
        openSendFileModal(file);
    }

    // 等待確認送出的檔案
    var stagedFile = null;

    /**
     * 傳送確認視窗：圖片顯示縮圖、其他只顯示檔名與大小，可另外填說明文字
     *
     * @param {File} file
     */
    function openSendFileModal(file) {
        stagedFile = file;

        var modalEl = document.getElementById('modal-tg-send-file');
        if (!modalEl) {
            document.body.insertAdjacentHTML('beforeend',
                '<div class="modal fade" id="modal-tg-send-file" tabindex="-1">' +
                '<div class="modal-dialog modal-dialog-scrollable">' +
                '<div class="modal-content">' +
                '<div class="modal-header py-2">' +
                '<h5 class="modal-title" style="font-size:0.9375rem">' + T.escapeHtml(T.i18n.send_file_title || '傳送檔案') + '</h5>' +
                '<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>' +
                '<div class="modal-body">' +
                '<div id="tg-send-file-preview" class="text-center mb-3"></div>' +
                '<label class="form-label" style="font-size:0.8125rem">' + T.escapeHtml(T.i18n.send_file_caption || '說明文字') + '</label>' +
                '<textarea id="tg-send-file-caption" class="form-control" rows="2" maxlength="1024"></textarea>' +
                '</div>' +
                '<div class="modal-footer py-2">' +
                '<button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">' + T.escapeHtml(T.i18n.btn_cancel || '取消') + '</button>' +
                '<button type="button" class="btn btn-primary btn-sm" id="btn-tg-send-file-ok">' +
                '<i class="fas fa-paper-plane me-1"></i>' + T.escapeHtml(T.i18n.btn_send || '發送') + '</button>' +
                '</div></div></div></div>');

            modalEl = document.getElementById('modal-tg-send-file');
            document.getElementById('btn-tg-send-file-ok').addEventListener('click', confirmSendFile);

            // 關閉時清掉暫存，避免下次開啟殘留上一個檔案
            modalEl.addEventListener('hidden.bs.modal', function () {
                stagedFile = null;
                document.getElementById('tg-send-file-preview').innerHTML = '';
                document.getElementById('tg-send-file-caption').value = '';
            });
        }

        renderStagedPreview(file);
        document.getElementById('tg-send-file-caption').value = '';
        showBsModal('modal-tg-send-file');
    }

    /**
     * 圖片給縮圖，其他檔案只給檔名與大小 ——
     * pdf、doc 這類沒辦法在這裡預覽內容，硬塞一個框只是浪費空間
     *
     * @param {File} file
     */
    function renderStagedPreview(file) {
        var $box = $('#tg-send-file-preview').empty();

        if (isImageFile(file)) {
            var img = document.createElement('img');
            img.src = URL.createObjectURL(file);
            img.style.cssText = 'max-width:100%;max-height:260px;border-radius:0.375rem';
            img.onload = function () { URL.revokeObjectURL(img.src); };
            $box.append(img);
        } else {
            $box.append($('<div class="d-inline-flex align-items-center gap-2 px-3 py-2">')
                .css({ border: '1px solid #dee2e6', borderRadius: '0.375rem', background: 'rgba(0,0,0,0.03)' })
                .append($('<i class="fas fa-file-alt fa-2x text-muted">'))
                .append($('<div class="text-start" style="min-width:0">')
                    .append($('<div class="text-truncate" style="max-width:220px;font-size:0.875rem">').text(file.name))
                    .append($('<div class="text-muted" style="font-size:0.75rem">').text(formatSize(file.size)))));
        }
    }

    /**
     * 按下傳送：關掉視窗後才開始上傳，進度條顯示在輸入區
     */
    function confirmSendFile() {
        var file = stagedFile;
        if (!file) { return; }

        var caption = $('#tg-send-file-caption').val().trim();
        hideBsModal(document.getElementById('modal-tg-send-file'));

        showUploadProgress(file.name, 1, 1);
        uploadAttachment(file, caption, updateUploadProgress)
            .then(function () {
                hideUploadProgress();
                T.loadMessages(T.selectedGroupId);
            })
            .catch(function (error) {
                hideUploadProgress();
                showInputError(errorMessage(error, sendFailedMessage(file)));
            });
    }

    /**
     * 檔案過大的提示（後端訊息帶 :value，前端自己組同一句）
     *
     * @returns {string}
     */
    function fileTooLargeMessage() {
        var template = T.i18n.msg.file_too_large;
        if (!template) { return '檔案不可超過 ' + MAX_FILE_MB + ' MB'; }

        return template.replace(':value', MAX_FILE_MB);
    }

    /**
     * 上傳附件並透過 Bot API 送出
     *
     * 用 XMLHttpRequest 而非 fetch —— fetch 沒有上傳進度事件，
     * 而大檔案要好幾秒，沒有進度條使用者會以為卡住
     *
     * @param {File}     file
     * @param {string}   caption
     * @param {Function} [onProgress] 收到 0–100 的百分比
     * @returns {Promise}
     */
    function uploadAttachment(file, caption, onProgress) {
        var isImage = isImageFile(file);
        var url = isImage
            ? '/admin/telegram-chat/ajax-send-image'
            : '/admin/telegram-chat/ajax-send-file';

        var formData = new FormData();
        formData.append('group_id', T.selectedGroupId);
        formData.append(isImage ? 'image' : 'file', file);
        if (caption) { formData.append('caption', caption); }

        return new Promise(function (resolve, reject) {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', url, true);
            xhr.setRequestHeader('X-CSRF-TOKEN', T.csrfToken);
            xhr.setRequestHeader('Accept', 'application/json');

            if (onProgress && xhr.upload) {
                xhr.upload.addEventListener('progress', function (e) {
                    // 來源不明長度時（例如壓縮傳輸）就不更新，維持上一個百分比
                    if (e.lengthComputable) { onProgress(e.loaded / e.total * 100); }
                });
            }

            xhr.addEventListener('load', function () {
                var body = null;
                try { body = JSON.parse(xhr.responseText); } catch (err) { body = null; }

                // 非 JSON 回應（PHP upload_max_filesize 擋下時會回 HTML）交給 errorMessage 用預設文案
                if (xhr.status >= 200 && xhr.status < 300) {
                    resolve(body);
                    return;
                }

                reject(body);
            });

            xhr.addEventListener('error', function () { reject(null); });
            xhr.addEventListener('abort', function () { reject(null); });

            xhr.send(formData);
        });
    }

    /**
     * 依序送出待傳送的附件，文字只掛在第一個當 caption。
     * 逐個序列送出而非平行，確保客戶端看到的順序與加入的順序一致。
     */
    function sendPendingFiles() {
        var textarea = document.getElementById('tg-reply-text');
        var sendBtn = document.getElementById('btn-tg-send');
        var caption = textarea ? textarea.value.trim() : '';
        var queue = pendingFiles.slice();

        setSending(true);
        showInputError('');

        var sentCount = 0;
        var chain = Promise.resolve();
        queue.forEach(function (file, index) {
            chain = chain.then(function () {
                showUploadProgress(file.name, index + 1, queue.length);

                return uploadAttachment(file, index === 0 ? caption : '', updateUploadProgress).then(function (result) {
                    sentCount = index + 1;

                    return result;
                });
            });
        });

        chain
            .then(function () {
                pendingFiles = [];
                renderPendingFiles();
                if (textarea) {
                    textarea.value = '';
                    textarea.style.height = '';
                }
                hideUploadProgress();
                setSending(false);
                if (textarea) { textarea.focus(); }
                T.loadMessages(T.selectedGroupId);
            })
            .catch(function (error) {
                hideUploadProgress();
                setSending(false);
                showInputError(errorMessage(error, sendFailedMessage(queue[sentCount])));
                // 已成功送出的不重送，保留失敗那筆與其後未送的，讓使用者可再按一次
                pendingFiles = queue.slice(sentCount);
                renderPendingFiles();
                T.loadMessages(T.selectedGroupId);
            });

        function setSending(sending) {
            if (textarea) { textarea.disabled = sending; }
            if (!sendBtn) { return; }
            sendBtn.disabled = sending;
            sendBtn.innerHTML = sending
                ? '<i class="fas fa-spinner fa-spin" style="font-size:0.875rem"></i>'
                : '<i class="fas fa-paper-plane" style="font-size:0.875rem"></i>';
        }
    }

    /**
     * 依失敗的是圖片還是檔案選預設文案
     *
     * @param {File} [file]
     * @returns {string}
     */
    function sendFailedMessage(file) {
        if (file && !isImageFile(file)) {
            return T.i18n.msg.file_send_failed || '檔案傳送失敗';
        }

        return T.i18n.msg.image_send_failed || '圖片傳送失敗';
    }

    // ===== 文件區 Modal =====
    function openSharedFileModal() {
        // 動態建 modal（如果不存在）
        var modalEl = document.getElementById('modal-tg-shared-files');
        if (!modalEl) {
            var html = '<div class="modal fade" id="modal-tg-shared-files" tabindex="-1">' +
                '<div class="modal-dialog modal-dialog-scrollable">' +
                '<div class="modal-content">' +
                '<div class="modal-header"><h5 class="modal-title"><i class="fas fa-file-alt me-2"></i>文件區</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>' +
                '<div class="modal-body">' +
                '<ul class="nav nav-tabs mb-2"><li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#sf-modal-shared">共用</button></li><li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#sf-modal-personal">個人</button></li></ul>' +
                '<div class="tab-content"><div class="tab-pane show active" id="sf-modal-shared"><div class="text-center text-muted py-3">載入中...</div></div><div class="tab-pane" id="sf-modal-personal"><div class="text-center text-muted py-3">載入中...</div></div></div>' +
                '</div></div></div></div>';
            document.body.insertAdjacentHTML('beforeend', html);
            modalEl = document.getElementById('modal-tg-shared-files');
        }

        // 載入檔案
        T.apiFetch('/admin/telegram-chat/ajax-shared-files')
            .then(function (data) {
                renderSfModalTab('sf-modal-shared', data.shared || []);
                renderSfModalTab('sf-modal-personal', data.personal || []);
            });

        var modal = new bootstrap.Modal(modalEl);
        modal.show();
    }

    function renderSfModalTab(tabId, folders) {
        var container = document.getElementById(tabId);
        if (!container) return;
        if (!folders.length) {
            container.innerHTML = '<div class="text-center text-muted py-3">無檔案</div>';
            return;
        }
        var html = '';
        folders.forEach(function (f) {
            html += '<div class="fw-bold mb-1" style="font-size:0.875rem"><i class="fas fa-folder text-warning me-1"></i>' + T.escapeHtml(f.folder_name) + '</div>';
            f.files.forEach(function (file) {
                html += '<div class="d-flex justify-content-between align-items-center py-1 ps-3 border-bottom" style="font-size:0.875rem">';
                html += '<span class="text-truncate" style="max-width:70%"><i class="fas fa-file me-1 text-muted"></i>' + T.escapeHtml(file.original_name) + '</span>';
                html += '<button class="btn btn-sm btn-primary js-sf-send-file" data-file-id="' + file.id + '" style="flex-shrink:0"><i class="fas fa-paper-plane me-1"></i>傳送</button>';
                html += '</div>';
            });
            html += '<div class="mb-2"></div>';
        });
        container.innerHTML = html;

        // 綁定傳送按鈕
        container.querySelectorAll('.js-sf-send-file').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var fileId = parseInt(btn.dataset.fileId, 10);
                if (!T.selectedGroupId) { alert('請先選擇對話'); return; }
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                T.apiFetch('/admin/telegram-chat/ajax-send-document', {
                    method: 'POST',
                    body: JSON.stringify({ group_id: T.selectedGroupId, file_id: fileId }),
                }).then(function () {
                    var modalEl = document.getElementById('modal-tg-shared-files');
                    if (modalEl) { bootstrap.Modal.getInstance(modalEl).hide(); }
                    T.loadMessages(T.selectedGroupId);
                }).catch(function (err) {
                    alert(err.message || '傳送失敗');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane me-1"></i>傳送';
                });
            });
        });
    }
})();
