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
            '<div class="app-inner-layout__wrapper row g-0" style="min-height:calc(100vh - 180px)">' +

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
            '<div class="app-inner-layout__bottom-pane border-top" id="tg-input" style="display:none"></div>' +
            '</div>' +

            '</div>' +
            '</div>' +
            '<div class="tg-alert-bar" id="tg-alert-bar" style="display:none"></div>';
    };

    /**
     * 載入群組列表
     */
    T.loadGroups = function () {
        T.apiFetch('/admin/telegram-chat/ajax-groups')
            .then(function (body) {
                T.groupsData = body;
                renderGroupList(body);
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
            var dutyText = g.on_duty_users && g.on_duty_users.length > 0 ? g.on_duty_users.join('、') : '';
            var unreadBadge = g.unread_count > 0
                ? '<span class="badge bg-danger rounded-pill ms-auto">' + g.unread_count + '</span>'
                : '';

            return (
                '<div class="p-3 border-bottom d-flex align-items-center tg-group-item' + activeCls + '" data-id="' + g.id + '" style="cursor:pointer">' +
                '<div class="widget-content-left me-3">' +
                '<div class="rounded-circle text-white d-flex align-items-center justify-content-center" style="width:40px;height:40px;background:#6c757d;font-weight:700">' + initial + '</div>' +
                '</div>' +
                '<div class="widget-content-left flex-fill" style="min-width:0">' +
                '<div class="fw-bold text-truncate">' + g.title + '</div>' +
                '<small class="text-muted">' + (dutyText || time) + '</small>' +
                '</div>' +
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
        var dutyText = group.on_duty_users && group.on_duty_users.length > 0
            ? T.i18n.assigned_to + '：' + group.on_duty_users.join('、')
            : T.i18n.unassigned;

        header.innerHTML =
            '<div class="px-3 py-2 d-flex align-items-center">' +
            '<button class="btn btn-link text-muted p-0 me-2 d-md-none" id="btn-tg-back"><i class="fas fa-arrow-left"></i></button>' +
            '<div class="rounded-circle text-white d-flex align-items-center justify-content-center me-2" style="width:36px;height:36px;background:#6c757d;font-size:0.9rem;font-weight:700">' + initial + '</div>' +
            '<span class="fw-bold" style="font-size:1.0625rem">' + group.title + '</span>' +
            '<span class="text-muted ms-2" style="font-size:0.875rem">' + dutyText + '</span>' +
            '</div>';

        // 手機版返回按鈕
        var backBtn = document.getElementById('btn-tg-back');
        if (backBtn) {
            backBtn.addEventListener('click', function () {
                var chatLayout = document.querySelector('.chat-layout');
                if (chatLayout) { chatLayout.classList.remove('tg-chatting'); }
            });
        }
    };
})();
