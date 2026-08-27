/**
 * Telegram Chat — 輸入區域（發送文字 + 圖片 + 貼上截圖 + IME 處理）
 */
(function () {
    var T = window.TgChat;
    if (!T) { return; }

    // 後端 ajax-send-image 限制 max:5120（KB）
    var MAX_IMAGE_BYTES = 5120 * 1024;

    // 待傳送的截圖，按下發送才會真的送到客戶群組
    var pendingImages = [];

    T.showInput = function () {
        var inputArea = document.getElementById('tg-input');
        if (!inputArea) { return; }

        // 切換群組時清空，避免截圖誤送到別的對話
        pendingImages = [];

        if (!T.canReply) {
            inputArea.style.display = 'none';
            return;
        }

        inputArea.style.display = 'block';
        inputArea.innerHTML =
            '<div id="tg-pending-images" class="px-3 pt-2 flex-wrap gap-2 align-items-center" style="display:none"></div>' +
            '<div id="tg-input-error" class="px-3 pt-2 text-danger" style="display:none;font-size:0.8125rem"></div>' +
            '<div class="d-flex align-items-center gap-1 px-3 py-2">' +
            '<input type="file" id="tg-image-input" accept="image/*" style="display:none">' +
            '<button class="btn btn-outline-secondary rounded-circle d-flex align-items-center justify-content-center" id="btn-tg-image" type="button" title="' + (T.i18n.btn_image || '傳送圖片') + '" style="width:36px;height:36px;flex-shrink:0"><i class="fas fa-paperclip" style="font-size:0.875rem"></i></button>' +
            '<button class="btn btn-outline-secondary rounded-circle d-flex align-items-center justify-content-center" id="btn-tg-shared-file" type="button" title="文件區" style="width:36px;height:36px;flex-shrink:0"><i class="fas fa-file-alt" style="font-size:0.875rem"></i></button>' +
            '<textarea id="tg-reply-text" class="form-control form-control-sm ms-1" placeholder="' + T.i18n.input_placeholder + '" rows="1" style="resize:none;max-height:100px;border-radius:1rem"></textarea>' +
            '<button class="btn btn-primary rounded-circle d-flex align-items-center justify-content-center ms-1" id="btn-tg-send" type="button" style="width:36px;height:36px;flex-shrink:0"><i class="fas fa-paper-plane" style="font-size:0.875rem"></i></button>' +
            '</div>';

        var textarea = document.getElementById('tg-reply-text');
        var sendBtn = document.getElementById('btn-tg-send');
        var imageBtn = document.getElementById('btn-tg-image');
        var imageInput = document.getElementById('tg-image-input');

        sendBtn.addEventListener('click', function () { sendReply(); });
        imageBtn.addEventListener('click', function () { imageInput.click(); });
        imageInput.addEventListener('change', function () {
            if (imageInput.files.length > 0) {
                sendImage(imageInput.files[0]);
                imageInput.value = '';
            }
        });

        // 文件區按鈕
        var sfBtn = document.getElementById('btn-tg-shared-file');
        if (sfBtn) {
            sfBtn.addEventListener('click', function () { openSharedFileModal(); });
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
            e.preventDefault();
            sendReply();
            textarea.style.height = '';
        });

        renderPendingImages();
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
        images.forEach(addPendingImage);
    });

    /**
     * 剪貼簿的圖片常沒有檔名（或叫 blob），補一個帶副檔名的名稱，
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
     * @param {*} error
     * @returns {string}
     */
    function errorMessage(error) {
        if (error && typeof error === 'object' && !(error instanceof Error) && error.message) {
            return error.message;
        }

        return T.i18n.msg.image_send_failed || '圖片傳送失敗';
    }

    function addPendingImage(file) {
        if (file.type.indexOf('image/') !== 0) {
            showInputError(T.i18n.msg.image_invalid || '只能貼上圖片');
            return;
        }
        if (file.size > MAX_IMAGE_BYTES) {
            showInputError(T.i18n.msg.image_too_large || '圖片超過 5MB，無法傳送');
            return;
        }

        pendingImages.push(file);
        showInputError('');
        renderPendingImages();
    }

    function renderPendingImages() {
        var container = document.getElementById('tg-pending-images');
        if (!container) { return; }

        if (!pendingImages.length) {
            container.style.display = 'none';
            container.innerHTML = '';
            return;
        }

        container.style.display = 'flex';
        container.innerHTML = '';
        pendingImages.forEach(function (file, index) {
            var wrapper = document.createElement('div');
            wrapper.className = 'position-relative';
            wrapper.style.width = '56px';
            wrapper.style.height = '56px';

            var img = document.createElement('img');
            img.src = URL.createObjectURL(file);
            img.style.cssText = 'width:56px;height:56px;object-fit:cover;border-radius:0.375rem;border:1px solid rgba(0,0,0,0.1)';
            img.onload = function () { URL.revokeObjectURL(img.src); };

            var removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn-close position-absolute top-0 end-0 bg-white rounded-circle';
            removeBtn.style.cssText = 'font-size:0.5rem;padding:0.2rem';
            removeBtn.title = T.i18n.btn_remove_image || '移除';
            removeBtn.addEventListener('click', function () {
                pendingImages.splice(index, 1);
                renderPendingImages();
            });

            wrapper.appendChild(img);
            wrapper.appendChild(removeBtn);
            container.appendChild(wrapper);
        });
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

        // 有待傳送的截圖時，改走圖片流程（文字會當成第一張的 caption）
        if (pendingImages.length) {
            sendPendingImages();
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

    function sendImage(file) {
        if (!T.selectedGroupId) { return; }

        var caption = document.getElementById('tg-reply-text').value.trim();

        uploadImage(file, caption)
            .then(function () {
                var textarea = document.getElementById('tg-reply-text');
                if (textarea) { textarea.value = ''; textarea.focus(); }
                T.loadMessages(T.selectedGroupId);
            })
            .catch(function (error) {
                showInputError(errorMessage(error));
            });
    }

    /**
     * 上傳單張圖片並透過 Bot API 送出
     *
     * @param {File}   file
     * @param {string} caption
     * @returns {Promise}
     */
    function uploadImage(file, caption) {
        var formData = new FormData();
        formData.append('group_id', T.selectedGroupId);
        formData.append('image', file);
        if (caption) { formData.append('caption', caption); }

        return fetch('/admin/telegram-chat/ajax-send-image', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': T.csrfToken, Accept: 'application/json' },
            body: formData,
        }).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok) { throw body; }
                return body;
            });
        });
    }

    /**
     * 依序送出待傳送的截圖，文字只掛在第一張當 caption。
     * 逐張序列送出而非平行，確保客戶端看到的順序與貼上的順序一致。
     */
    function sendPendingImages() {
        var textarea = document.getElementById('tg-reply-text');
        var sendBtn = document.getElementById('btn-tg-send');
        var caption = textarea ? textarea.value.trim() : '';
        var queue = pendingImages.slice();

        setSending(true);
        showInputError('');

        var sentCount = 0;
        var chain = Promise.resolve();
        queue.forEach(function (file, index) {
            chain = chain.then(function () {
                return uploadImage(file, index === 0 ? caption : '').then(function (result) {
                    sentCount = index + 1;

                    return result;
                });
            });
        });

        chain
            .then(function () {
                pendingImages = [];
                renderPendingImages();
                if (textarea) {
                    textarea.value = '';
                    textarea.style.height = '';
                }
                setSending(false);
                if (textarea) { textarea.focus(); }
                T.loadMessages(T.selectedGroupId);
            })
            .catch(function (error) {
                setSending(false);
                showInputError(errorMessage(error));
                // 已成功送出的不重送，保留失敗那張與其後未送的，讓使用者可再按一次
                pendingImages = queue.slice(sentCount);
                renderPendingImages();
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
