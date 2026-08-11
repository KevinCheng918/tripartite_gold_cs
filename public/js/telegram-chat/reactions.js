/**
 * Telegram Chat — Emoji Reaction 互動
 */
(function () {
    var T = window.TgChat;
    if (!T) { return; }

    var QUICK_EMOJIS = [
        '👍', '❤️', '😂', '😮', '😢', '🙏',
        '❤️‍🔥', '👌', '😁', '🤗', '🔥', '🤔',
        '👎', '🥰', '👏', '🤯', '🎉', '🤩',
        '🤮', '💩', '🕊️', '🤡', '🫣', '😌',
        '😍', '🐳', '🌚', '🌭', '💯', '🤣',
        '⚡', '🍌', '🏆', '💔', '🤨', '🙂',
        '🍓', '🍾', '💋', '🖕', '😈', '😴',
        '😭', '🤓', '👻', '🤷', '👀', '🎃', '🙈',
        '😇', '😱', '🤝', '✍️', '🫡', '🎅', '🎄',
        '☃️', '🎆', '🤪', '🗿', '🆒', '💘', '🙉',
        '🦄', '🥴', '🙊', '👾', '🤷‍♂️'
    ];

    var longPressTimer = null;

    T.bindReactionButtons = function () {
        // 右鍵（桌面）
        document.querySelectorAll('.chat-box-wrapper').forEach(function (wrapper) {
            wrapper.removeEventListener('contextmenu', handleContext);
            wrapper.addEventListener('contextmenu', handleContext);
            // 長按（手機）
            wrapper.removeEventListener('touchstart', handleTouchStart);
            wrapper.removeEventListener('touchend', handleTouchEnd);
            wrapper.addEventListener('touchstart', handleTouchStart, { passive: true });
            wrapper.addEventListener('touchend', handleTouchEnd);
        });
    };

    function handleContext(e) {
        e.preventDefault();
        if (!T.canReply) { return; }
        var wrapper = e.currentTarget;
        var msgId = wrapper.dataset.msgId;
        if (!msgId) { return; }
        openPicker(msgId, e.clientX, e.clientY);
    }

    var longPressTriggered = false;

    function handleTouchStart(e) {
        if (!T.canReply) { return; }
        var wrapper = e.currentTarget;
        var msgId = wrapper.dataset.msgId;
        if (!msgId) { return; }
        longPressTriggered = false;
        var touch = e.touches[0];
        longPressTimer = setTimeout(function () {
            longPressTriggered = true;
            openPicker(msgId, touch.clientX, touch.clientY);
        }, 500);
    }

    function handleTouchEnd(e) {
        if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
        // 長按觸發後阻止後續的 click 事件關閉面板
        if (longPressTriggered) {
            e.preventDefault();
            longPressTriggered = false;
        }
    }

    function openPicker(msgId, x, y) {

        closeReactionPicker();

        var picker = document.createElement('div');
        picker.className = 'tg-reaction-picker';
        picker.innerHTML = QUICK_EMOJIS.map(function (emoji) {
            return '<button class="tg-reaction-picker__item" data-emoji="' + emoji + '">' + emoji + '</button>';
        }).join('');

        document.body.appendChild(picker);

        var left = x;
        var top = y - Math.min(220, picker.scrollHeight) - 4;
        if (left + 280 > window.innerWidth) { left = window.innerWidth - 288; }
        if (left < 8) { left = 8; }
        if (top < 8) { top = y + 10; }
        picker.style.left = left + 'px';
        picker.style.top = top + 'px';

        picker.querySelectorAll('.tg-reaction-picker__item').forEach(function (item) {
            item.addEventListener('click', function (ev) {
                ev.stopPropagation();
                sendReaction(parseInt(msgId, 10), item.dataset.emoji);
                closeReactionPicker();
            });
            // 手機觸控支援
            item.addEventListener('touchend', function (ev) {
                ev.preventDefault();
                ev.stopPropagation();
                sendReaction(parseInt(msgId, 10), item.dataset.emoji);
                closeReactionPicker();
            });
        });

        setTimeout(function () {
            document.addEventListener('click', closeReactionPicker, { once: true });
        }, 0);
    }

    function closeReactionPicker() {
        var existing = document.querySelector('.tg-reaction-picker');
        if (existing) { existing.remove(); }
    }

    function sendReaction(messageId, emoji) {
        T.apiFetch('/admin/telegram-chat/ajax-react', {
            method: 'POST',
            body: JSON.stringify({ message_id: messageId, emoji: emoji }),
        }).then(function () {
            T.loadMessages(T.selectedGroupId);
        }).catch(function () {});
    }
})();
