(function () {
    var root = document.getElementById('telegram-chat-app');
    if (!root) { return; }

    var i18n = JSON.parse(root.dataset.i18n);
    var currentUserId = parseInt(root.dataset.userId, 10);
    var nickname = root.dataset.userNickname;
    var canReply = root.dataset.canReply === '1';
    // 值班客服自動從排班系統指派，不需手動操作
    var wsKey = root.dataset.wsKey;
    var wsHost = root.dataset.wsHost || '127.0.0.1';
    var wsPort = parseInt(root.dataset.wsPort || '6001', 10);
    var wsScheme = root.dataset.wsScheme || 'http';
    var csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    var selectedGroupId = null;
    var groupsData = [];

    // ---------------------------------------------------------------
    //  共用工具
    // ---------------------------------------------------------------

    function apiFetch(url, options) {
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
    }

    // ---------------------------------------------------------------
    //  Pusher 即時訊息
    // ---------------------------------------------------------------

    var pusher = null;
    if (wsKey) {
        try {
            pusher = new Pusher(wsKey, {
                wsHost: wsHost,
                wsPort: wsPort,
                wssPort: wsPort,
                forceTLS: wsScheme === 'https',
                encrypted: wsScheme === 'https',
                disableStats: true,
                enabledTransports: ['ws', 'wss'],
            });
            var channel = pusher.subscribe('telegram-chat');

            // 收到新訊息
            channel.bind('telegram.message', function (data) {
                // 更新群組列表
                loadGroups();

                // 如果是當前選中的群組，追加訊息
                if (data.message && data.message.group_id === selectedGroupId) {
                    appendMessage(data.message);
                }
            });

            // 收到告警
            channel.bind('telegram.alert', function (data) {
                showAlert(data);
            });
        } catch (e) {
            console.warn('Pusher 初始化失敗', e);
        }
    }

    // ---------------------------------------------------------------
    //  渲染主框架
    // ---------------------------------------------------------------

    function renderLayout() {
        root.innerHTML =
            '<div class="tg-chat">' +
            '<div class="tg-chat__sidebar">' +
            '<div class="tg-chat__sidebar-header">' + i18n.group_list + '</div>' +
            '<div class="tg-chat__group-list" id="tg-group-list"></div>' +
            '</div>' +
            '<div class="tg-chat__main">' +
            '<div class="tg-chat__header" id="tg-header"></div>' +
            '<div class="tg-chat__messages" id="tg-messages">' +
            '<div class="tg-chat__empty">' + i18n.select_group + '</div>' +
            '</div>' +
            '<div class="tg-chat__input" id="tg-input" style="display:none"></div>' +
            '</div>' +
            '</div>' +
            '<div class="tg-alert-bar" id="tg-alert-bar" style="display:none"></div>';
    }

    // ---------------------------------------------------------------
    //  群組列表
    // ---------------------------------------------------------------

    function loadGroups() {
        apiFetch('/admin/telegram-chat/ajax-groups')
            .then(function (body) {
                groupsData = body;
                renderGroupList(body);
            });
    }

    function renderGroupList(groups) {
        var container = document.getElementById('tg-group-list');
        if (!container) { return; }

        if (groups.length === 0) {
            container.innerHTML = '<div class="tg-chat__empty">' + i18n.no_groups + '</div>';
            return;
        }

        container.innerHTML = groups.map(function (g) {
            var active = g.id === selectedGroupId ? ' tg-group--active' : '';
            var unread = g.unread_count > 0 ? '<span class="tg-unread">' + g.unread_count + '</span>' : '';
            var time = g.last_message_at ? g.last_message_at.substring(11, 16) : '';

            return (
                '<div class="tg-group' + active + '" data-id="' + g.id + '">' +
                '<div class="tg-group__info">' +
                '<div class="tg-group__title">' + g.title + '</div>' +
                '<div class="tg-group__meta">' +
                (g.assigned_user ? '<span class="tg-group__assigned">' + g.assigned_user + '</span>' : '') +
                '<span class="tg-group__time">' + time + '</span>' +
                '</div>' +
                '</div>' +
                unread +
                '</div>'
            );
        }).join('');

        // 綁定點擊
        container.querySelectorAll('.tg-group').forEach(function (el) {
            el.addEventListener('click', function () {
                selectedGroupId = parseInt(el.dataset.id, 10);
                renderGroupList(groupsData);
                loadMessages(selectedGroupId);
                renderHeader(selectedGroupId);
                showInput();
            });
        });
    }

    // ---------------------------------------------------------------
    //  聊天標頭
    // ---------------------------------------------------------------

    function renderHeader(groupId) {
        var header = document.getElementById('tg-header');
        var group = groupsData.filter(function (g) { return g.id === groupId; })[0];
        if (!group || !header) { return; }

        header.innerHTML =
            '<div class="tg-header__title">' + group.title + '</div>' +
            '<div class="tg-header__right">' +
            '<span class="tg-header__assigned">' + i18n.assigned_to + '：' + (group.assigned_user || i18n.unassigned) + '</span>' +
            '</div>';
    }

    // ---------------------------------------------------------------
    //  訊息
    // ---------------------------------------------------------------

    function loadMessages(groupId) {
        var container = document.getElementById('tg-messages');
        container.innerHTML = '<div class="tg-chat__empty">Loading...</div>';

        apiFetch('/admin/telegram-chat/ajax-messages?group_id=' + groupId + '&per_page=100')
            .then(function (body) {
                var messages = (body.data || []).reverse(); // API 回傳倒序，反轉為正序
                renderMessages(messages);
            });
    }

    function renderMessages(messages) {
        var container = document.getElementById('tg-messages');

        if (messages.length === 0) {
            container.innerHTML = '<div class="tg-chat__empty">' + i18n.no_messages + '</div>';
            return;
        }

        container.innerHTML = messages.map(function (m) {
            var isOutbound = m.direction === 2;
            var bubbleCls = isOutbound ? 'tg-bubble--outbound' : 'tg-bubble--inbound';
            var time = m.created_at ? m.created_at.substring(11, 16) : '';

            return (
                '<div class="tg-bubble ' + bubbleCls + '">' +
                '<div class="tg-bubble__sender">' + m.sender_name + '</div>' +
                '<div class="tg-bubble__content">' + escapeHtml(m.content) + '</div>' +
                '<div class="tg-bubble__time">' + time + '</div>' +
                '</div>'
            );
        }).join('');

        // 捲到最下面
        container.scrollTop = container.scrollHeight;
    }

    function appendMessage(msg) {
        var container = document.getElementById('tg-messages');
        if (!container) { return; }

        // 移除空訊息提示
        var empty = container.querySelector('.tg-chat__empty');
        if (empty) { empty.remove(); }

        var isOutbound = msg.direction === 2;
        var bubbleCls = isOutbound ? 'tg-bubble--outbound' : 'tg-bubble--inbound';
        var time = msg.created_at ? msg.created_at.substring(11, 16) : '';

        var html =
            '<div class="tg-bubble ' + bubbleCls + '">' +
            '<div class="tg-bubble__sender">' + msg.sender_name + '</div>' +
            '<div class="tg-bubble__content">' + escapeHtml(msg.content) + '</div>' +
            '<div class="tg-bubble__time">' + time + '</div>' +
            '</div>';

        container.insertAdjacentHTML('beforeend', html);
        container.scrollTop = container.scrollHeight;
    }

    // ---------------------------------------------------------------
    //  輸入框
    // ---------------------------------------------------------------

    function showInput() {
        var inputArea = document.getElementById('tg-input');
        if (!inputArea) { return; }

        if (!canReply) {
            inputArea.style.display = 'none';
            return;
        }

        inputArea.style.display = 'flex';
        inputArea.innerHTML =
            '<textarea id="tg-reply-text" placeholder="' + i18n.input_placeholder + '" rows="1"></textarea>' +
            '<button class="btn-primary" id="btn-tg-send">' + i18n.btn_send + '</button>';

        var textarea = document.getElementById('tg-reply-text');
        var sendBtn = document.getElementById('btn-tg-send');

        sendBtn.addEventListener('click', function () { sendReply(); });

        // Enter 送出（Shift+Enter 換行）
        textarea.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendReply();
            }
        });
    }

    function sendReply() {
        var textarea = document.getElementById('tg-reply-text');
        var content = textarea.value.trim();
        if (!content || !selectedGroupId) { return; }

        textarea.disabled = true;

        apiFetch('/admin/telegram-chat/ajax-reply', {
            method: 'POST',
            body: JSON.stringify({ group_id: selectedGroupId, content: content }),
        })
            .then(function () {
                textarea.value = '';
                textarea.disabled = false;
                textarea.focus();
                // Pusher 會推送新訊息，不需手動追加
            })
            .catch(function (error) {
                textarea.disabled = false;
                var msg = error.message || i18n.msg.reply_failed;
                alert(msg);
            });
    }

    // ---------------------------------------------------------------
    //  告警
    // ---------------------------------------------------------------

    function showAlert(data) {
        var bar = document.getElementById('tg-alert-bar');
        if (!bar) { return; }

        bar.style.display = 'block';
        bar.innerHTML =
            '<span class="tg-alert__icon">⚠</span>' +
            '<span>' + data.groupTitle + ' — ' +
            i18n.alert_unreplied.replace(':minutes', data.minutes) +
            '</span>';

        // 5 秒後自動隱藏
        setTimeout(function () {
            bar.style.display = 'none';
        }, 5000);
    }

    // ---------------------------------------------------------------
    //  工具
    // ---------------------------------------------------------------

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // ---------------------------------------------------------------
    //  初始化
    // ---------------------------------------------------------------

    renderLayout();
    loadGroups();

    // Fallback polling（每 30 秒刷新群組列表）
    setInterval(loadGroups, 30000);
})();
