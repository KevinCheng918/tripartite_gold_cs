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
    var longPressTriggered = false;
    var touchStartX = 0;
    var touchStartY = 0;
    var MOVE_THRESHOLD = 10; // 移動超過 10px 取消長按

    T.bindReactionButtons = function () {
        // 右鍵（桌面）
        document.querySelectorAll('.chat-box-wrapper').forEach(function (wrapper) {
            wrapper.removeEventListener('contextmenu', handleContext);
            wrapper.addEventListener('contextmenu', handleContext);
            // 長按（手機）
            wrapper.removeEventListener('touchstart', handleTouchStart);
            wrapper.removeEventListener('touchmove', handleTouchMove);
            wrapper.removeEventListener('touchend', handleTouchEnd);
            wrapper.addEventListener('touchstart', handleTouchStart, { passive: true });
            wrapper.addEventListener('touchmove', handleTouchMove, { passive: true });
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

    var activeWrapper = null;

    function handleTouchStart(e) {
        if (!T.canReply) { return; }
        var wrapper = e.currentTarget;
        var msgId = wrapper.dataset.msgId;
        if (!msgId) { return; }

        longPressTriggered = false;
        var touch = e.touches[0];
        touchStartX = touch.clientX;
        touchStartY = touch.clientY;

        // 按壓效果
        activeWrapper = wrapper;
        wrapper.style.transition = 'transform 0.2s, opacity 0.2s';
        wrapper.style.transform = 'scale(0.97)';
        wrapper.style.opacity = '0.7';

        longPressTimer = setTimeout(function () {
            longPressTriggered = true;
            // 震動回饋（如果支援）
            if (navigator.vibrate) { navigator.vibrate(50); }
            // 長按成功效果
            wrapper.style.transform = 'scale(1.02)';
            wrapper.style.opacity = '1';
            setTimeout(function () {
                resetWrapperStyle(wrapper);
            }, 200);
            openPicker(msgId, touch.clientX, touch.clientY);
        }, 800);
    }

    function resetWrapperStyle(wrapper) {
        if (!wrapper) { return; }
        wrapper.style.transform = '';
        wrapper.style.opacity = '';
    }

    function handleTouchMove(e) {
        // 滑動時取消長按 + 還原效果
        if (!longPressTimer) { return; }
        var touch = e.touches[0];
        var dx = Math.abs(touch.clientX - touchStartX);
        var dy = Math.abs(touch.clientY - touchStartY);
        if (dx > MOVE_THRESHOLD || dy > MOVE_THRESHOLD) {
            clearTimeout(longPressTimer);
            longPressTimer = null;
            resetWrapperStyle(activeWrapper);
            activeWrapper = null;
        }
    }

    function handleTouchEnd(e) {
        if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
        resetWrapperStyle(activeWrapper);
        activeWrapper = null;
        if (longPressTriggered) {
            e.preventDefault();
            setTimeout(function () { longPressTriggered = false; }, 300);
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

        // emoji 只用 click 事件，不用 touchend（避免誤觸）
        picker.querySelectorAll('.tg-reaction-picker__item').forEach(function (item) {
            item.addEventListener('click', function (ev) {
                ev.stopPropagation();
                sendReaction(parseInt(msgId, 10), item.dataset.emoji);
                closeReactionPicker();
            });
        });

        // 點擊其他地方關閉 + 聊天區滑動時關閉（延遲綁定避免立刻觸發）
        setTimeout(function () {
            document.addEventListener('click', closeOnOutside);
            document.addEventListener('touchstart', closeOnOutside);
            var chatArea = document.querySelector('.chat-wrapper') || document.getElementById('tg-messages');
            if (chatArea) {
                chatArea.addEventListener('scroll', closeReactionPicker);
                chatArea._reactionScrollBound = true;
            }
        }, 100);
    }

    function closeOnOutside(e) {
        var picker = document.querySelector('.tg-reaction-picker');
        if (picker && !picker.contains(e.target)) {
            closeReactionPicker();
        }
    }

    function closeReactionPicker() {
        var existing = document.querySelector('.tg-reaction-picker');
        if (existing) { existing.remove(); }
        document.removeEventListener('click', closeOnOutside);
        document.removeEventListener('touchstart', closeOnOutside);
        var chatArea = document.querySelector('.chat-wrapper') || document.getElementById('tg-messages');
        if (chatArea) { chatArea.removeEventListener('scroll', closeReactionPicker); }
    }

    function sendReaction(messageId, emoji) {
        // 記住捲動位置
        var chatArea = document.querySelector('.chat-wrapper') || document.getElementById('tg-messages');
        var scrollPos = chatArea ? chatArea.scrollTop : 0;

        T.apiFetch('/admin/telegram-chat/ajax-react', {
            method: 'POST',
            body: JSON.stringify({ message_id: messageId, emoji: emoji }),
        }).then(function () {
            T.loadMessages(T.selectedGroupId, function () {
                // 恢復捲動位置
                if (chatArea) { chatArea.scrollTop = scrollPos; }
            });
        }).catch(function () {});
    }
})();
