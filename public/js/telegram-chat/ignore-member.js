/**
 * Telegram Chat — 不自動回覆的成員
 *
 * 名單裡的人發言時，訊息照常收、照常通知，只是系統不會自動回覆。
 *
 * 兩種加入方式：
 * - 從「發言過的人」清單挑 → 背後記的是 Telegram 使用者 ID，最可靠
 * - 手動輸入 @username     → 對方還沒發過言、或上線前就在群組裡的人只能這樣加
 *
 * Modal 與 quick-reply 一樣是第一次開啟時才建出來，不佔 blade 版面。
 */
(function () {
    var T = window.TgChat;
    if (!T) { return; }

    var ACTION = { IGNORE: 'ignore', ADD: 'add', RESTORE: 'restore' };

    var currentGroupId = null;
    // 「發言過的人」整批留著，搜尋框就在這批裡過濾，不為了搜尋再打一次後端
    var recentMembers = [];
    var recentDays = 30;

    T.openIgnorePanel = function (groupId) {
        if (!groupId) { return; }

        currentGroupId = groupId;

        var modalEl = ensureModal();
        new bootstrap.Modal(modalEl).show();

        load();
    };

    /**
     * 載入名單
     */
    function load() {
        setBody('<div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin"></i></div>');

        T.apiFetch('/admin/telegram-chat/ajax-ignore-members?group_id=' + currentGroupId)
            .then(function (body) {
                recentMembers = body.recent || [];
                recentDays = body.recent_days || recentDays;
                render(body.ignored || []);
            })
            .catch(function () {
                setBody('<div class="text-center text-danger py-4">' +
                    T.escapeHtml(T.i18n.msg.ignore_load_failed) + '</div>');
            });
    }

    /**
     * 畫出整個面板
     *
     * @param {Array} ignored 已忽略的成員
     */
    function render(ignored) {
        var html =
            '<div class="alert alert-light border py-2 mb-3" style="font-size:0.8125rem">' +
            '<i class="fas fa-info-circle me-1 text-muted"></i>' + T.escapeHtml(T.i18n.ignore_hint) +
            '</div>' +

            '<div class="fw-bold mb-2" style="font-size:0.875rem">' +
            T.escapeHtml(T.i18n.ignore_section_ignored) + '</div>' +
            '<div id="tg-ignore-list" class="mb-4">' + buildIgnoredHtml(ignored) + '</div>' +

            '<div class="fw-bold mb-2" style="font-size:0.875rem">' +
            T.escapeHtml(T.i18n.ignore_section_recent.replace(':days', recentDays)) + '</div>' +
            '<input type="text" class="form-control form-control-sm mb-2" id="tg-ignore-search" ' +
            'placeholder="' + T.escapeHtml(T.i18n.ignore_search) + '" autocomplete="off">' +
            '<div id="tg-ignore-recent" class="mb-4">' + buildRecentHtml(recentMembers) + '</div>' +

            '<div class="fw-bold mb-2" style="font-size:0.875rem">' +
            T.escapeHtml(T.i18n.ignore_section_manual) + '</div>' +
            '<div class="d-flex gap-2 mb-2">' +
            '<input type="text" class="form-control form-control-sm" id="tg-ignore-username" ' +
            'placeholder="' + T.escapeHtml(T.i18n.ignore_username) + '" maxlength="33" autocomplete="off">' +
            '<input type="text" class="form-control form-control-sm" id="tg-ignore-note" ' +
            'placeholder="' + T.escapeHtml(T.i18n.ignore_note) + '" maxlength="255" autocomplete="off">' +
            '<button class="btn btn-sm btn-primary flex-shrink-0" id="tg-ignore-add">' +
            T.escapeHtml(T.i18n.ignore_action_add) + '</button>' +
            '</div>' +
            '<div class="text-muted" style="font-size:0.75rem">' +
            T.escapeHtml(T.i18n.ignore_manual_hint) + '<br>' +
            T.escapeHtml(T.i18n.ignore_wrong_hint) +
            '</div>' +

            '<div id="tg-ignore-msg" class="mt-3"></div>';

        setBody(html);
        bindEvents();
    }

    /**
     * 已忽略清單
     *
     * @param {Array} list
     * @return {string}
     */
    function buildIgnoredHtml(list) {
        if (!list.length) {
            return '<div class="text-muted" style="font-size:0.8125rem">' +
                T.escapeHtml(T.i18n.ignore_empty_ignored) + '</div>';
        }

        return list.map(function (m) {
            var manualBadge = m.is_manual
                ? '<span class="badge bg-light text-muted border ms-1" style="font-size:0.6875rem;font-weight:400">' +
                  T.escapeHtml(T.i18n.ignore_badge_manual) + '</span>'
                : '';

            // 設定者的帳號被刪掉時 ignored_by 會是 null（nullOnDelete）
            var setter = T.i18n.ignore_set_by
                .replace(':name', m.ignored_by || T.i18n.ignore_deleted_user)
                .replace(':time', (m.ignored_at || '').substring(0, 16));

            return '<div class="d-flex align-items-center justify-content-between border-bottom py-2">' +
                '<div style="min-width:0">' +
                '<div class="text-truncate" style="font-size:0.875rem">' +
                T.escapeHtml(m.display_name || ('@' + (m.username || ''))) + manualBadge +
                '</div>' +
                '<div class="text-muted" style="font-size:0.75rem">' + T.escapeHtml(setter) + '</div>' +
                '</div>' +
                '<button class="btn btn-sm btn-outline-secondary flex-shrink-0 js-tg-ignore-restore" data-id="' + m.id + '">' +
                T.escapeHtml(T.i18n.ignore_action_restore) + '</button>' +
                '</div>';
        }).join('');
    }

    /**
     * 發言過的人清單
     *
     * @param {Array} list
     * @return {string}
     */
    function buildRecentHtml(list) {
        if (!list.length) {
            var empty = recentMembers.length ? T.i18n.ignore_empty_search : T.i18n.ignore_empty_recent;

            return '<div class="text-muted" style="font-size:0.8125rem">' + T.escapeHtml(empty) + '</div>';
        }

        return list.map(function (m) {
            var username = m.username
                ? '<span class="text-muted ms-1" style="font-size:0.75rem">@' + T.escapeHtml(m.username) + '</span>'
                : '';

            return '<div class="d-flex align-items-center justify-content-between border-bottom py-2">' +
                '<div class="text-truncate" style="font-size:0.875rem">' +
                T.escapeHtml(m.display_name || '') + username +
                '</div>' +
                '<button class="btn btn-sm btn-outline-secondary flex-shrink-0 js-tg-ignore-add" data-id="' + m.id + '">' +
                T.escapeHtml(T.i18n.ignore_action_ignore) + '</button>' +
                '</div>';
        }).join('');
    }

    /**
     * 綁定面板上的操作
     */
    function bindEvents() {
        document.querySelectorAll('.js-tg-ignore-add').forEach(function (btn) {
            btn.addEventListener('click', function () {
                send({ action: ACTION.IGNORE, member_id: parseInt(btn.dataset.id, 10) });
            });
        });

        document.querySelectorAll('.js-tg-ignore-restore').forEach(function (btn) {
            btn.addEventListener('click', function () {
                send({ action: ACTION.RESTORE, member_id: parseInt(btn.dataset.id, 10) });
            });
        });

        var addBtn = document.getElementById('tg-ignore-add');
        if (addBtn) {
            addBtn.addEventListener('click', function () {
                var username = document.getElementById('tg-ignore-username').value.trim();
                var note = document.getElementById('tg-ignore-note').value.trim();

                if (!username) {
                    showMessage(T.i18n.msg.username_required, true);

                    return;
                }

                send({ action: ACTION.ADD, username: username, note: note });
            });
        }

        var search = document.getElementById('tg-ignore-search');
        if (search) {
            search.addEventListener('input', function () {
                filterRecent(search.value.trim().toLowerCase());
            });
        }
    }

    /**
     * 前端過濾「發言過的人」
     *
     * @param {string} keyword 已轉小寫
     */
    function filterRecent(keyword) {
        var container = document.getElementById('tg-ignore-recent');
        if (!container) { return; }

        var matched = recentMembers;

        if (keyword) {
            matched = recentMembers.filter(function (m) {
                var name = (m.display_name || '').toLowerCase();
                var username = (m.username || '').toLowerCase();

                return name.indexOf(keyword) !== -1 || username.indexOf(keyword) !== -1;
            });
        }

        container.innerHTML = buildRecentHtml(matched);
        bindEvents();
    }

    /**
     * 送出變更後重載名單
     *
     * @param {Object} payload
     */
    function send(payload) {
        payload.group_id = currentGroupId;

        T.apiFetch('/admin/telegram-chat/ajax-toggle-ignore', {
            method: 'POST',
            body: JSON.stringify(payload),
        })
            .then(function (body) {
                load();
                // 名單變了，訊息上的標籤要跟著更新
                if (T.selectedGroupId === currentGroupId && T.loadMessages) {
                    T.loadMessages(currentGroupId, function () {});
                }
                showMessage((body && body.message) || T.i18n.msg.ignore_added, false);
            })
            .catch(function (body) {
                showMessage(pickError(body), true);
            });
    }

    /**
     * 取出後端的錯誤訊息
     *
     * 驗證失敗時 Laravel 回的是 errors 物件，只有一般錯誤才有 message。
     *
     * @param {Object} body
     * @return {string}
     */
    function pickError(body) {
        if (body && body.errors) {
            var first = Object.keys(body.errors)[0];
            if (first && body.errors[first].length) { return body.errors[first][0]; }
        }

        return (body && body.message) || T.i18n.msg.ignore_failed;
    }

    /**
     * 面板內的提示訊息
     *
     * @param {string}  text
     * @param {boolean} isError
     */
    function showMessage(text, isError) {
        var box = document.getElementById('tg-ignore-msg');
        if (!box) { return; }

        box.innerHTML = '<div class="alert ' + (isError ? 'alert-danger' : 'alert-success') +
            ' py-2 mb-0" style="font-size:0.8125rem">' + T.escapeHtml(text) + '</div>';

        setTimeout(function () {
            var current = document.getElementById('tg-ignore-msg');
            if (current) { current.innerHTML = ''; }
        }, 4000);
    }

    /**
     * @param {string} html
     */
    function setBody(html) {
        var body = document.getElementById('modal-tg-ignore-body');
        if (body) { body.innerHTML = html; }
    }

    /**
     * 第一次開啟時才建 Modal
     *
     * @return {HTMLElement}
     */
    function ensureModal() {
        var modalEl = document.getElementById('modal-tg-ignore');
        if (modalEl) { return modalEl; }

        modalEl = document.createElement('div');
        modalEl.className = 'modal fade';
        modalEl.id = 'modal-tg-ignore';
        modalEl.tabIndex = -1;
        modalEl.innerHTML =
            '<div class="modal-dialog modal-dialog-scrollable">' +
            '<div class="modal-content">' +
            '<div class="modal-header py-2">' +
            '<h5 class="modal-title" style="font-size:0.9375rem">' +
            T.escapeHtml(T.i18n.ignore_title) + '</h5>' +
            '<button type="button" class="btn-close" data-bs-dismiss="modal"></button>' +
            '</div>' +
            '<div class="modal-body" id="modal-tg-ignore-body"></div>' +
            '</div>' +
            '</div>';

        document.body.appendChild(modalEl);

        return modalEl;
    }
})();
