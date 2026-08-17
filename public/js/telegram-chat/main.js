/**
 * Telegram Chat — 初始化 + Pusher
 */
(function () {
    var T = window.TgChat;
    if (!T) { return; }

    // Pusher 即時訊息
    if (T.wsKey) {
        try {
            var pusher = new Pusher(T.wsKey, {
                wsHost: T.wsHost, wsPort: T.wsPort, wssPort: T.wsPort,
                forceTLS: T.wsScheme === 'https', encrypted: T.wsScheme === 'https',
                disableStats: true, enabledTransports: ['ws', 'wss'],
            });
            var channel = pusher.subscribe('telegram-chat');

            channel.bind('telegram.message', function (data) {
                var msg = data.message;
                if (!msg) { return; }
                if (msg.type === 'reaction_update') {
                    if (msg.group_id === T.selectedGroupId) { T.loadMessages(T.selectedGroupId); }
                    return;
                }
                T.loadGroups();
                if (msg.group_id === T.selectedGroupId) { T.appendMessage(msg); }
            });

            channel.bind('telegram.alert', function (data) { T.showAlert(data); });

            // 正在輸入
            var typingTimeout = null;
            channel.bind('telegram.typing', function (data) {
                if (data.groupId !== T.selectedGroupId) { return; }
                var $indicator = document.getElementById('tg-typing-indicator');
                if (!$indicator) { return; }
                $indicator.textContent = data.nickname + ' 正在輸入...';
                $indicator.style.display = 'block';
                clearTimeout(typingTimeout);
                typingTimeout = setTimeout(function () {
                    $indicator.style.display = 'none';
                }, 4000);
            });
        } catch (e) {
            console.warn('Pusher 初始化失敗', e);
        }
    }

    // 初始化
    T.renderLayout();
    T.loadGroups();
    setInterval(T.loadGroups, 30000);
})();
