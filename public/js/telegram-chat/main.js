/**
 * Telegram Chat — 初始化 + Pusher
 */
(function () {
    var T = window.TgChat;
    if (!T) { return; }

    /*
     * Pusher 即時訊息。
     *
     * ⚠ **優先用 layout 建好的 `window.AppPusher`**（sidebar 的未讀數也訂同一個
     * channel）。各建一個的話，同一個瀏覽器分頁會開兩條 WebSocket 訂同一件事。
     *
     * 仍保留自己建的路徑：沒有 sidebar 權限的人看得到這一頁（路由沒綁 can:），
     * 那種情況 layout 不會輸出 `#app-realtime`，這裡要自己來。
     */
    if (T.wsKey || window.AppPusher) {
        try {
            var pusher = window.AppPusher || new Pusher(T.wsKey, {
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
                // 編輯是既有訊息的內容改變，不能當新訊息 append，要整份重載
                if (msg.edited) {
                    T.loadGroups();
                    if (msg.group_id === T.selectedGroupId) { T.loadMessages(T.selectedGroupId); }
                    return;
                }
                T.loadGroups();
                if (msg.group_id === T.selectedGroupId) { T.appendMessage(msg); }
            });

            // AI 正在回覆 —— 有明確的開始與結束，不像 typing 那樣自動消失
            channel.bind('auto-reply.progress', function (data) {
                T.setAutoReplyRunning(data.groupId, data.running);
            });

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
