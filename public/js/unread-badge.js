/**
 * Sidebar 的 Telegram 客服未讀數
 *
 * 全站都載。做兩件事：
 *
 *   1. 建立**全站共用的** Pusher 連線（`window.AppPusher`）
 *   2. 訂閱客人來訊，更新 sidebar 上那顆數字
 *
 * ⚠ Pusher 實例一定要共用。Telegram 客服那一頁自己也要收同一個 channel，
 * 各建一個的話同一個瀏覽器分頁會開兩條 WebSocket 訂同一件事 ——
 * 那一頁的 `main.js` 改成有 `window.AppPusher` 就拿來用。
 *
 * ⚠ 數字是**有未讀的對話數**，不是訊息總數（需求方 2026-10-11）：
 * 它回答「我還有幾個人要回」，訊息總數會被同一個客人連發十則灌爆。
 *
 * ⚠ 沒有 `#app-realtime` 就什麼都不做 —— 那個元素只在看得到 sidebar
 * 這一項的人身上輸出，沒權限的人不該開 WebSocket。
 */
(function () {
    var root = document.getElementById('app-realtime');
    if (!root) { return; }

    var badge = document.getElementById('sidebar-unread-badge');

    /**
     * 建立全站共用的 Pusher 連線
     *
     * 連不上不能影響頁面其他功能 —— 未讀數只是輔助資訊，
     * 整頁的 JS 不該為了它掛掉。
     *
     * @returns {Object|null}
     */
    function connect() {
        if (window.AppPusher) { return window.AppPusher; }
        if (!root.dataset.wsKey || typeof Pusher === 'undefined') { return null; }

        try {
            window.AppPusher = new Pusher(root.dataset.wsKey, {
                wsHost: root.dataset.wsHost,
                wsPort: root.dataset.wsPort,
                wssPort: root.dataset.wsPort,
                forceTLS: root.dataset.wsScheme === 'https',
                encrypted: root.dataset.wsScheme === 'https',
                disableStats: true,
                enabledTransports: ['ws', 'wss']
            });
        } catch (e) {
            console.warn('Pusher 初始化失敗', e);

            return null;
        }

        return window.AppPusher;
    }

    /**
     * 重新拉一次未讀數並更新 badge
     *
     * @returns {void}
     */
    function refresh() {
        if (!badge) { return; }

        fetch('/admin/telegram-chat/ajax-unread-count', {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin'
        }).then(function (response) {
            // session 過期時 AuthGuard 會接手，這裡安靜退場就好
            if (!response.ok) { return null; }

            return response.json();
        }).then(function (body) {
            if (!body) { return; }

            var count = body.groups || 0;

            badge.textContent = count;
            badge.style.display = count > 0 ? '' : 'none';
        }).catch(function () {
            // 網路斷了就維持上一次的數字，不要把它歸零造成「都處理完了」的錯覺
        });
    }

    // 讓同一頁的其他腳本（例如點進對話後）能主動要求更新
    window.refreshUnreadBadge = refresh;

    refresh();

    var pusher = connect();

    if (pusher) {
        var channel = pusher.subscribe('telegram-chat');

        /*
         * 客人來訊就重拉。這裡不自己 +1 ——
         * 「有未讀的對話數」要看那個對話本來是不是已經有未讀，
         * 前端自己算會在同一個客人連發時多加。
         */
        channel.bind('telegram.message', function () {
            refresh();
        });
    }
})();
