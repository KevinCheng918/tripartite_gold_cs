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
            mediaHtml = '<div class="mb-1"><img src="' + m.media_url + '" alt="photo" loading="lazy" style="max-width:240px;max-height:240px;border-radius:8px;cursor:pointer;display:block"></div>';
        } else if (m.media_type === 'sticker' && m.media_url) {
            mediaHtml = '<div class="mb-1"><img src="' + m.media_url + '" alt="sticker" loading="lazy" style="max-width:120px;max-height:120px;display:block"></div>';
        }

        var textHtml = m.content ? T.escapeHtml(m.content) : '';

        var reactHtml = '';
        if (m.reactions && m.reactions.length > 0) {
            reactHtml += '<div class="mt-1">';
            m.reactions.forEach(function (r) {
                reactHtml += '<span class="me-1" style="cursor:default">' + r.emoji + (r.count > 1 ? '<small class="text-muted">' + r.count + '</small>' : '') + '</span>';
            });
            reactHtml += '</div>';
        }

        var reactBtn = '';

        var avatarBg = isOutbound ? 'background:linear-gradient(135deg,#d4af37,#a67c00)' : 'background:#6c757d';
        var avatar = '<div class="rounded-circle text-white d-flex align-items-center justify-content-center" style="width:36px;height:36px;' + avatarBg + ';font-size:0.8rem;font-weight:700">' + initial + '</div>';

        var date = m.created_at ? m.created_at.substring(5, 10) : '';
        var timeLabel = date + ' ' + time;

        if (isOutbound) {
            return (
                '<div class="chat-box-wrapper chat-box-wrapper-right" data-msg-id="' + m.id + '">' +
                '<div style="text-align:right">' +
                '<div class="chat-box" style="background:#e0f3ff">' + mediaHtml + textHtml + reactBtn + '</div>' +
                reactHtml +
                '<small class="text-muted">' + timeLabel + ' | ' + m.sender_name + '</small>' +
                '</div>' +
                '<div class="ms-2 flex-shrink-0">' + avatar + '</div>' +
                '</div>'
            );
        }

        return (
            '<div class="chat-box-wrapper" data-msg-id="' + m.id + '">' +
            '<div class="me-2 flex-shrink-0">' + avatar + '</div>' +
            '<div>' +
            '<div class="chat-box" style="background:#e0f3ff">' + mediaHtml + textHtml + reactBtn + '</div>' +
            reactHtml +
            '<small class="text-muted">' + timeLabel + ' | ' + m.sender_name + '</small>' +
            '</div>' +
            '</div>'
        );
    }

    /**
     * 載入指定群組的訊息
     */
    T.loadMessages = function (groupId) {
        var container = document.getElementById('tg-messages');
        container.innerHTML = '<div class="text-center text-muted py-5">Loading...</div>';

        T.apiFetch('/admin/telegram-chat/ajax-messages?group_id=' + groupId + '&per_page=100')
            .then(function (body) {
                var messages = (body.data || []).reverse();
                T.renderMessages(messages);
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
})();
