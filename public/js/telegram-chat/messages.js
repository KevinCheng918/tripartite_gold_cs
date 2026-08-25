/**
 * Telegram Chat — 訊息渲染（Architect chat-box 風格）
 */
(function () {
    var T = window.TgChat;
    if (!T) { return; }

    /**
     * 組合單則訊息 HTML
     */
    function buildMessageHtml(m) {
        var isOutbound = m.direction === 2;
        var time = m.created_at ? m.created_at.substring(11, 16) : '';
        var initial = m.sender_name ? m.sender_name.substring(0, 1).toUpperCase() : '?';

        var mediaHtml = '';
        if (m.media_type === 'photo' && m.media_url) {
            mediaHtml = '<div class="mb-1"><img src="' + m.media_url + '" alt="photo" loading="lazy" class="tg-photo-preview" style="max-width:100%;max-height:240px;border-radius:8px;cursor:pointer;display:block;object-fit:contain"></div>';
        } else if (m.media_type === 'sticker' && m.media_url) {
            mediaHtml = '<div class="mb-1"><img src="' + m.media_url + '" alt="sticker" loading="lazy" style="max-width:120px;max-height:120px;display:block"></div>';
        } else if (m.media_type === 'document' && m.media_url) {
            mediaHtml = '<div class="mb-1"><a href="' + m.media_url + '" target="_blank" class="d-inline-flex align-items-center gap-1 text-decoration-none" style="padding:6px 10px;border-radius:6px;background:rgba(0,0,0,0.05);font-size:0.875rem"><i class="fas fa-file-download" style="font-size:1rem"></i><span>' + T.escapeHtml(m.content || '下載檔案') + '</span></a></div>';
        }

        var replyHtml = '';
        if (m.reply_to_sender || m.reply_to_text) {
            replyHtml = '<div style="border-left:3px solid #a67c00;padding:4px 8px;margin-bottom:6px;background:rgba(0,0,0,0.04);border-radius:4px;font-size:0.8125rem">' +
                '<div style="font-weight:700;color:#a67c00">' + T.escapeHtml(m.reply_to_sender || '') + '</div>' +
                '<div style="color:#6c757d;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical">' + T.escapeHtml(m.reply_to_text || '') + '</div>' +
                '</div>';
        }

        var textHtml = m.content ? T.escapeHtml(m.content) : '';

        var reactHtml = '';
        if (m.reactions && m.reactions.length > 0) {
            reactHtml += '<div class="d-flex flex-wrap gap-1 mt-1">';
            m.reactions.forEach(function (r) {
                reactHtml += '<span class="tg-react-pill">' +
                    '<span style="font-size:1.125rem">' + r.emoji + '</span>' +
                    (r.count > 1 ? '<span class="tg-react-count">' + r.count + '</span>' : '') +
                    '</span>';
            });
            reactHtml += '</div>';
        }

        var reactBtn = '';

        var avatarBg = isOutbound ? 'background:linear-gradient(135deg,#d4af37,#a67c00)' : 'background:#6c757d';
        var avatar = '<div class="rounded-circle text-white d-flex align-items-center justify-content-center" style="width:36px;height:36px;' + avatarBg + ';font-size:0.8rem;font-weight:700">' + initial + '</div>';

        var date = m.created_at ? m.created_at.substring(5, 10) : '';
        var timeLabel = date + ' ' + time;

        var senderLabel = '<div style="font-size:0.8125rem;font-weight:600;margin-bottom:2px;color:' + (isOutbound ? '#a67c00' : '#495057') + '">' + T.escapeHtml(m.sender_name || '') + '</div>';
        var timeHtml = '<small class="text-muted">' + timeLabel + '</small>';
        var bubbleBg = isOutbound ? 'background:#e8f5e9' : 'background:#f5f5f5';
        var darkBubbleBg = isOutbound ? 'tg-bubble-out' : 'tg-bubble-in';

        if (isOutbound) {
            return (
                '<div class="chat-box-wrapper" data-msg-id="' + m.id + '" style="display:flex;justify-content:flex-end">' +
                '<div style="text-align:right">' +
                senderLabel +
                '<div class="chat-box ' + darkBubbleBg + '" style="' + bubbleBg + ';display:inline-block">' + replyHtml + mediaHtml + textHtml + reactBtn + '</div>' +
                reactHtml +
                timeHtml +
                '</div>' +
                '<div class="ms-2 flex-shrink-0">' + avatar + '</div>' +
                '</div>'
            );
        }

        return (
            '<div class="chat-box-wrapper" data-msg-id="' + m.id + '">' +
            '<div class="me-2 flex-shrink-0">' + avatar + '</div>' +
            '<div>' +
            senderLabel +
            '<div class="chat-box ' + darkBubbleBg + '" style="' + bubbleBg + '">' + replyHtml + mediaHtml + textHtml + reactBtn + '</div>' +
            reactHtml +
            timeHtml +
            '</div>' +
            '</div>'
        );
    }

    /**
     * 載入指定群組的訊息
     */
    T.loadMessages = function (groupId, callback) {
        var container = document.getElementById('tg-messages');
        if (!callback) {
            container.innerHTML = '<div class="text-center text-muted py-5">Loading...</div>';
        }

        T.apiFetch('/admin/telegram-chat/ajax-messages?group_id=' + groupId + '&per_page=100')
            .then(function (body) {
                var messages = (body.data || []).reverse();
                T.renderMessages(messages);
                if (callback) { callback(); }
            });
    };

    /**
     * 渲染所有訊息
     */
    T.renderMessages = function (messages) {
        var container = document.getElementById('tg-messages');

        if (messages.length === 0) {
            container.innerHTML = '<div class="text-center text-muted py-5">' + T.i18n.no_messages + '</div>';
            return;
        }

        container.innerHTML = messages.map(buildMessageHtml).join('');

        requestAnimationFrame(function () {
            container.scrollTop = container.scrollHeight;
        });
        T.bindReactionButtons();
    };

    /**
     * 追加單則新訊息
     */
    T.appendMessage = function (msg) {
        var container = document.getElementById('tg-messages');
        if (!container) { return; }

        var empty = container.querySelector('.text-center.text-muted');
        if (empty) { empty.remove(); }

        container.insertAdjacentHTML('beforeend', buildMessageHtml(msg));
        container.scrollTo({ top: container.scrollHeight, behavior: 'smooth' });
        T.bindReactionButtons();
    };

    // 圖片 Lightbox
    document.addEventListener('click', function (e) {
        if (!e.target.classList.contains('tg-photo-preview')) return;
        var src = e.target.src;
        var overlay = document.createElement('div');
        overlay.className = 'tg-lightbox';
        overlay.innerHTML = '<img src="' + src + '">';
        overlay.addEventListener('click', function () { overlay.remove(); });
        document.body.appendChild(overlay);
    });
})();
