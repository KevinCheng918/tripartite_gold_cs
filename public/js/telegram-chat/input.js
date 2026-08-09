/**
 * Telegram Chat — 輸入區域（發送文字 + 圖片 + IME 處理）
 */
(function () {
    var T = window.TgChat;
    if (!T) { return; }

    T.showInput = function () {
        var inputArea = document.getElementById('tg-input');
        if (!inputArea) { return; }

        if (!T.canReply) {
            inputArea.style.display = 'none';
            return;
        }

        inputArea.style.display = 'block';
        inputArea.innerHTML =
            '<div class="p-3">' +
            '<div class="input-group">' +
            '<input type="file" id="tg-image-input" accept="image/*" style="display:none">' +
            '<button class="btn btn-outline-secondary" id="btn-tg-image" type="button" title="' + (T.i18n.btn_image || '傳送圖片') + '"><i class="fas fa-image"></i></button>' +
            '<textarea id="tg-reply-text" class="form-control" placeholder="' + T.i18n.input_placeholder + '" rows="1" style="resize:none;max-height:120px"></textarea>' +
            '<button class="btn btn-primary" id="btn-tg-send" type="button">' + T.i18n.btn_send + '</button>' +
            '</div>' +
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

        // 自動高度
        textarea.addEventListener('input', function () {
            textarea.style.height = 'auto';
            textarea.style.height = Math.min(textarea.scrollHeight, 120) + 'px';
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
    };

    function sendReply() {
        var textarea = document.getElementById('tg-reply-text');
        var sendBtn = document.getElementById('btn-tg-send');
        var content = textarea.value.trim();
        if (!content || !T.selectedGroupId) { return; }

        textarea.disabled = true;
        if (sendBtn) { sendBtn.disabled = true; sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }

        T.apiFetch('/admin/telegram-chat/ajax-reply', {
            method: 'POST',
            body: JSON.stringify({ group_id: T.selectedGroupId, content: content }),
        })
            .then(function () {
                textarea.value = '';
                textarea.style.height = '';
                textarea.disabled = false;
                if (sendBtn) { sendBtn.disabled = false; sendBtn.textContent = T.i18n.btn_send; }
                textarea.focus();
            })
            .catch(function (error) {
                textarea.disabled = false;
                if (sendBtn) { sendBtn.disabled = false; sendBtn.textContent = T.i18n.btn_send; }
                alert(error.message || T.i18n.msg.reply_failed);
            });
    }

    function sendImage(file) {
        if (!T.selectedGroupId) { return; }

        var formData = new FormData();
        formData.append('group_id', T.selectedGroupId);
        formData.append('image', file);

        var caption = document.getElementById('tg-reply-text').value.trim();
        if (caption) { formData.append('caption', caption); }

        fetch('/admin/telegram-chat/ajax-send-image', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': T.csrfToken, Accept: 'application/json' },
            body: formData,
        })
            .then(function (response) {
                return response.json().then(function (body) {
                    if (!response.ok) { throw body; }
                    return body;
                });
            })
            .then(function () {
                var textarea = document.getElementById('tg-reply-text');
                if (textarea) { textarea.value = ''; textarea.focus(); }
            })
            .catch(function (error) {
                alert(error.message || 'Failed');
            });
    }
})();
