/**
 * Telegram Chat — Layout 渲染（主框架 + 群組列表 + 標頭）
 */
(function () {
    var T = window.TgChat;
    if (!T) { return; }

    /**
     * 渲染主框架（Architect chat-layout 風格）
     */
    T.renderLayout = function () {
        T.root.innerHTML =
            '<div class="app-inner-layout app-inner-layout-page chat-layout">' +
            '<div class="app-inner-layout__wrapper row g-0">' +

            '<div class="app-inner-layout__sidebar card col-md-4 col-lg-3" style="border-radius:0">' +
            '<div class="app-inner-layout__top-pane">' +
            '<div class="p-3 border-bottom fw-bold">' +
            '<i class="fas fa-comments me-2 text-muted"></i>' + T.i18n.group_list +
            '</div>' +
            '</div>' +
            '<div class="scrollbar-container" id="tg-group-list" style="overflow-y:auto;flex:1"></div>' +
            '</div>' +

            '<div class="app-inner-layout__content card col-md-8 col-lg-9" style="border-radius:0;display:flex;flex-direction:column">' +
            '<div class="app-inner-layout__top-pane border-bottom" id="tg-header">' +
            '<div class="p-3 text-muted">' + T.i18n.select_group + '</div>' +
            '</div>' +
            '<div class="chat-wrapper p-3" id="tg-messages" style="flex:1;overflow-y:auto">' +
            '<div class="text-center text-muted py-5">' + T.i18n.select_group + '</div>' +
            '</div>' +
            '<div id="tg-typing-indicator" style="display:none;padding:0.25rem 1rem;font-size:0.8125rem;color:#6c757d;font-style:italic"></div>' +
            // 不與 typing 共用同一個元素：typing 收到就顯示、4 秒後自動隱藏，
            // 會把還在跑的 AI 提示一起關掉
            '<div id="tg-auto-reply-indicator" style="display:none;padding:0.25rem 1rem;font-size:0.8125rem;color:#6c757d"></div>' +
            '<div class="app-inner-layout__bottom-pane border-top" id="tg-input" style="display:none"></div>' +
            '</div>' +

            '</div>' +
            '</div>' +
            '<div class="tg-alert-bar" id="tg-alert-bar" style="display:none"></div>';
    };

    // 安全逾時：broadcast 可能丟失（Pusher 斷線、worker 在送出結束事件前就被砍），
    // 少了這道，提示會一直掛在畫面上，客服會以為系統壞了
    var runningTimeout = null;

    /**
     * 套用「AI 正在回覆」提示
     *
     * @param {boolean} running
     */
    function applyRunningIndicator(running) {
        var el = document.getElementById('tg-auto-reply-indicator');
        if (!el) { return; }

        clearTimeout(runningTimeout);

        if (!running) {
            el.style.display = 'none';
            return;
        }

        el.innerHTML = '<i class="fas fa-robot me-1"></i>' + T.escapeHtml(T.i18n.auto_reply_running);
        el.style.display = 'block';

        runningTimeout = setTimeout(function () { el.style.display = 'none'; }, 180000);
    }

    /**
     * 收到後端的進行中／結束事件
     *
     * @param {number}  groupId
     * @param {boolean} running
     */
    T.setAutoReplyRunning = function (groupId, running) {
        (T.groupsData || []).forEach(function (g) {
            if (g.id === groupId) { g.auto_reply_running = running; }
        });

        renderGroupList(T.groupsData || []);

        // 提示只在目前開著的那個對話顯示，其他對話看左側列表的機器人圖示
        if (groupId === T.selectedGroupId) { applyRunningIndicator(running); }
    };

    /**
     * 依目前選到的對話還原提示
     *
     * 切換對話或重新整理時，Pusher 事件早就發完了，要靠列表帶回來的狀態補畫面。
     */
    T.syncAutoReplyIndicator = function () {
        var current = (T.groupsData || []).filter(function (g) { return g.id === T.selectedGroupId; })[0];

        applyRunningIndicator(!!(current && current.auto_reply_running));
    };

    /**
     * 載入群組列表
     */
    T.loadGroups = function () {
        T.apiFetch('/admin/telegram-chat/ajax-groups')
            .then(function (body) {
                T.groupsData = body;
                renderGroupList(body);
                // 列表每 30 秒重載一次，順便讓提示自我修正 ——
                // 萬一 broadcast 丟了，最多 30 秒畫面就會跟後端一致
                T.syncAutoReplyIndicator();
            });
    };

    /**
     * 渲染群組列表
     */
    function renderGroupList(groups) {
        var container = document.getElementById('tg-group-list');
        if (!container) { return; }

        if (groups.length === 0) {
            container.innerHTML = '<div class="text-center text-muted py-4">' + T.i18n.no_groups + '</div>';
            return;
        }

        container.innerHTML = groups.map(function (g) {
            var activeCls = g.id === T.selectedGroupId ? ' bg-light' : '';
            var initial = g.title ? g.title.substring(0, 1).toUpperCase() : '?';
            var time = g.last_message_at ? g.last_message_at.substring(11, 16) : '';
            var unreadBadge = g.unread_count > 0
                ? '<span class="badge bg-danger rounded-pill ms-auto">' + g.unread_count + '</span>'
                : '';

            // AI 正在回覆這個對話 —— 客服在看別的對話時也要看得出來
            var runningIcon = g.auto_reply_running
                ? '<i class="fas fa-robot text-muted ms-2" title="' + T.escapeHtml(T.i18n.auto_reply_running) + '"></i>'
                : '';

            return (
                '<div class="p-3 border-bottom d-flex align-items-center tg-group-item' + activeCls + '" data-id="' + g.id + '" style="cursor:pointer">' +
                '<div class="widget-content-left me-3">' +
                '<div class="rounded-circle text-white d-flex align-items-center justify-content-center" style="width:40px;height:40px;background:#6c757d;font-weight:700">' + initial + '</div>' +
                '</div>' +
                '<div class="widget-content-left flex-fill" style="min-width:0">' +
                '<div class="fw-bold text-truncate">' + g.title + '</div>' +
                '<small class="text-muted">' + time + '</small>' +
                '</div>' +
                runningIcon +
                unreadBadge +
                '</div>'
            );
        }).join('');

        container.querySelectorAll('.tg-group-item').forEach(function (el) {
            el.addEventListener('click', function () {
                T.selectedGroupId = parseInt(el.dataset.id, 10);
                renderGroupList(T.groupsData);
                T.loadMessages(T.selectedGroupId);
                T.renderHeader(T.selectedGroupId);
                T.showInput();
                T.syncAutoReplyIndicator();

                // 手機版切到聊天
                var chatLayout = document.querySelector('.chat-layout');
                if (chatLayout) { chatLayout.classList.add('tg-chatting'); }
            });
        });
    }

    /**
     * 渲染聊天標頭
     */
    T.renderHeader = function (groupId) {
        var header = document.getElementById('tg-header');
        var group = T.groupsData.filter(function (g) { return g.id === groupId; })[0];
        if (!group || !header) { return; }

        var initial = group.title ? group.title.substring(0, 1).toUpperCase() : '?';

        var deleteBtn = T.canDelete
            ? '<button class="btn btn-sm btn-outline-secondary ms-auto" id="btn-tg-delete-conv" data-id="' + group.id + '" title="刪除對話紀錄" style="white-space:nowrap;flex-shrink:0"><i class="fas fa-trash-alt me-1"></i>刪除對話</button>'
            : '';

        // 忽略名單只有在這個對話開了自動回覆時才有意義，沒開就不佔標題列的位置
        var ignoreBtn = (T.canIgnore && group.auto_reply)
            ? '<button class="btn btn-sm btn-outline-secondary ms-auto me-2" id="btn-tg-ignore" data-id="' + group.id + '" title="' + T.escapeHtml(T.i18n.ignore_btn) + '" style="white-space:nowrap;flex-shrink:0"><i class="fas fa-bell-slash"></i></button>'
            : '';

        // 兩顆都在時，只讓第一顆把自己推到最右邊，否則會被擠開
        if (ignoreBtn && deleteBtn) {
            deleteBtn = deleteBtn.replace(' ms-auto', '');
        }

        header.innerHTML =
            '<div class="px-3 py-2 d-flex align-items-center">' +
            '<button class="btn btn-link text-muted p-0 me-2 d-md-none" id="btn-tg-back"><i class="fas fa-arrow-left"></i></button>' +
            '<div class="rounded-circle text-white d-flex align-items-center justify-content-center me-2" style="width:36px;height:36px;background:#6c757d;font-size:0.9rem;font-weight:700">' + initial + '</div>' +
            '<div class="flex-fill" style="min-width:0"><span class="fw-bold text-truncate" style="font-size:1.0625rem">' + group.title + '</span></div>' +
            ignoreBtn +
            deleteBtn +
            '</div>';

        // 忽略名單按鈕
        var ignoreButton = document.getElementById('btn-tg-ignore');
        if (ignoreButton && T.openIgnorePanel) {
            ignoreButton.addEventListener('click', function () {
                T.openIgnorePanel(parseInt(ignoreButton.dataset.id, 10));
            });
        }

        // 手機版返回按鈕
        var backBtn = document.getElementById('btn-tg-back');
        if (backBtn) {
            backBtn.addEventListener('click', function () {
                var chatLayout = document.querySelector('.chat-layout');
                if (chatLayout) { chatLayout.classList.remove('tg-chatting'); }
            });
        }

        // 刪除對話按鈕
        var delBtn = document.getElementById('btn-tg-delete-conv');
        if (delBtn) {
            delBtn.addEventListener('click', function () {
                var gid = parseInt(delBtn.dataset.id, 10);
                if (!confirm('確定刪除此對話的所有紀錄？新訊息仍會繼續接收。')) { return; }
                T.apiFetch('/admin/telegram-chat/ajax-delete-conversation/' + gid, { method: 'DELETE' })
                    .then(function (body) {
                        T.loadMessages(gid);
                        T.loadGroups();
                    })
                    .catch(function () { alert('刪除失敗'); });
            });
        }
    };
})();
