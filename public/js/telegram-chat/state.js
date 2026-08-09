/**
 * Telegram Chat — 共享狀態與工具函數
 */
window.TgChat = (function () {
    var root = document.getElementById('telegram-chat-app');
    if (!root) { return null; }

    var i18n = JSON.parse(root.dataset.i18n);
    var csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    return {
        root: root,
        i18n: i18n,
        csrfToken: csrfToken,
        currentUserId: parseInt(root.dataset.userId, 10),
        nickname: root.dataset.userNickname,
        canReply: root.dataset.canReply === '1',
        wsKey: root.dataset.wsKey,
        wsHost: root.dataset.wsHost || '127.0.0.1',
        wsPort: parseInt(root.dataset.wsPort || '6001', 10),
        wsScheme: root.dataset.wsScheme || 'http',

        selectedGroupId: null,
        groupsData: [],

        apiFetch: function (url, options) {
            options = options || {};
            options.headers = Object.assign({
                'X-CSRF-TOKEN': csrfToken,
                'Content-Type': 'application/json',
                Accept: 'application/json',
            }, options.headers || {});

            return fetch(url, options).then(function (response) {
                return response.json().then(function (body) {
                    if (!response.ok) { throw body; }
                    return body;
                });
            });
        },

        escapeHtml: function (text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    };
})();
