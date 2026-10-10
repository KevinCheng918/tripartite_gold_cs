/**
 * 通知設定（通訊管理 → 通知設定）
 *
 * 五個分頁：支援群組與話題、求助單提醒、班表通知、超時提醒統計、任務卡通知。
 *
 * ⚠ 每個分頁各自一支 ajax —— 合成一支的話，存一個分頁會把其他分頁的值
 * 一起寫掉（那些欄位在這次送出裡是空的）。
 */
(function () {
    'use strict';

    var root = document.getElementById('notification-app');
    if (!root) { return; }

    var i18n = JSON.parse(root.dataset.i18n);
    var canManage = root.dataset.canManage === '1';
    var csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    var settings = JSON.parse(root.dataset.initial);

    // ===== 工具 =====

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text === null || text === undefined ? '' : text;

        return div.innerHTML;
    }

    function apiFetch(url, options) {
        options = options || {};
        options.headers = Object.assign({
            'X-CSRF-TOKEN': csrfToken,
            'Content-Type': 'application/json',
            Accept: 'application/json'
        }, options.headers || {});

        return fetch(url, options).then(function (response) {
            // session 過期交給 AuthGuard 統一處理，理由見 public/js/common.js
            var intercepted = window.AuthGuard && window.AuthGuard.intercept(response);

            if (intercepted) { return intercepted; }

            return response.json().then(function (body) {
                if (!response.ok) { throw body; }

                return body;
            });
        });
    }

    /**
     * 顯示訊息（不用 alert）
     */
    function showMessage(message) {
        var modalEl = document.getElementById('modal-notification-msg');
        if (!modalEl) { return; }

        modalEl.querySelector('.modal-body').textContent = message;
        new bootstrap.Modal(modalEl).show();
    }

    /**
     * 把後端的錯誤訊息挖出來
     *
     * ⚠ **errors 要先看，message 後看**。驗證失敗時 Laravel 兩個都會給：
     * `errors` 是真正的原因（「這個群組已經是客服對話的群組…」），
     * `message` 只有一句通用的 `The given data was invalid.`。
     * 順序寫反的話，使用者永遠只看得到那句廢話，完全不知道要改什麼。
     */
    function errorMessage(body, fallback) {
        if (body && body.errors) {
            var first = Object.keys(body.errors)[0];

            if (first && body.errors[first].length) { return body.errors[first][0]; }
        }

        return (body && body.message) || fallback;
    }

    /**
     * 送出表單的共用流程
     */
    function submit(url, payload, button, onDone) {
        var original = button ? button.textContent : '';

        if (button) {
            button.disabled = true;
            button.textContent = i18n.action_saving;
        }

        apiFetch(url, { method: 'PUT', body: JSON.stringify(payload) })
            .then(function (body) {
                showMessage(body.message || i18n.msg.saved);

                if (onDone) { onDone(); }
            })
            .catch(function (body) {
                showMessage(errorMessage(body, i18n.msg.save_failed));
            })
            .then(function () {
                if (button) {
                    button.disabled = false;
                    button.textContent = original;
                }
            });
    }

    /**
     * 測試發送的共用流程
     */
    function runTest(url, button, fallbackMessage) {
        var original = button.textContent;

        button.disabled = true;
        button.textContent = i18n.action_testing;

        apiFetch(url, { method: 'POST' })
            .then(function (body) {
                showMessage(body.message || fallbackMessage);
            })
            .catch(function (body) {
                showMessage(errorMessage(body, fallbackMessage));
            })
            .then(function () {
                button.disabled = false;
                button.textContent = original;
            });
    }

    /**
     * 畫一份「可勾多位 + 全選」的人員清單
     *
     * 沒私訊過機器人的人標成灰的但**不隱藏** —— 勾了也收不到，要在勾之前就
     * 看得出來；整個拿掉會變成「名單裡沒這個人」，更難判斷。
     *
     * @param {string} listId    清單容器的 id
     * @param {string} itemClass 個別 checkbox 的 class
     * @param {Array}  selected  已勾選的 user id
     */
    function renderUserList(listId, itemClass, selected) {
        var list = document.getElementById(listId);
        if (!list) { return; }

        var candidates = settings.options.dm_candidates || [];
        var html = '';

        candidates.forEach(function (user) {
            var label = user.dm_ready
                ? user.nickname
                : user.nickname + '（' + i18n.unbound + '）';
            var checked = (selected || []).indexOf(user.id) !== -1 ? ' checked' : '';
            var muted = user.dm_ready ? '' : ' text-muted';

            html += '<div class="form-check">' +
                '<input class="form-check-input ' + itemClass + '" type="checkbox" ' +
                'id="' + listId + '-' + user.id + '" value="' + user.id + '"' + checked + '>' +
                '<label class="form-check-label' + muted + '" for="' + listId + '-' + user.id + '">' +
                escapeHtml(label) + '</label>' +
                '</div>';
        });

        list.innerHTML = html;
    }

    /**
     * 某個清單目前勾選的 id
     */
    function checkedIds(itemClass) {
        var ids = [];

        root.querySelectorAll('.' + itemClass + ':checked').forEach(function (el) {
            ids.push(parseInt(el.value, 10));
        });

        return ids;
    }

    /**
     * 「全選」的勾選狀態要跟著個別項目走
     *
     * 少了這段，手動把人逐一勾完之後「全選」還是沒勾，看起來像壞掉。
     */
    function syncAll(allId, itemClass) {
        var all = document.getElementById(allId);
        if (!all) { return; }

        var boxes = root.querySelectorAll('.' + itemClass);
        all.checked = boxes.length > 0 && checkedIds(itemClass).length === boxes.length;
    }

    /**
     * 綁「全選」與個別項目的連動
     */
    function bindSelectAll(allId, listId, itemClass) {
        var all = document.getElementById(allId);
        var list = document.getElementById(listId);
        if (!all || !list) { return; }

        all.addEventListener('change', function () {
            var checked = this.checked;

            root.querySelectorAll('.' + itemClass).forEach(function (el) { el.checked = checked; });
        });

        list.addEventListener('change', function (event) {
            if (event.target.classList.contains(itemClass)) { syncAll(allId, itemClass); }
        });
    }

    // ===== 分頁一：支援群組（話題分流在下面單獨一段）=====

    function renderGroup() {
        var support = settings.support;

        document.getElementById('support-chat-id').value = support.chat_id || '';

        // Bot 來源：沒選就用 .env 的預設 bot
        var systemHtml = '<option value="">' + escapeHtml(i18n.support_system_default) + '</option>';

        (settings.options.systems || []).forEach(function (system) {
            // 沒設 bot_token 的系統選了也沒用，直接不列
            if (!system.has_token) { return; }
            systemHtml += '<option value="' + system.id + '">' + escapeHtml(system.name) + '</option>';
        });

        var select = document.getElementById('support-system');
        select.innerHTML = systemHtml;
        select.value = support.system_id || '';
    }

    function bindGroup() {
        document.getElementById('form-group').addEventListener('submit', function (event) {
            event.preventDefault();

            submit('/admin/notification/ajax-update-group', {
                chat_id: document.getElementById('support-chat-id').value.trim(),
                system_id: document.getElementById('support-system').value || null
            }, event.target.querySelector('button[type="submit"]'));
        });

        document.getElementById('btn-test-support').addEventListener('click', function () {
            runTest('/admin/notification/ajax-test-support', this, i18n.msg.support_test_failed);
        });
    }

    // ===== 分頁二：求助單提醒 =====

    function renderRemind() {
        var support = settings.support;

        document.getElementById('support-remind-first').value = support.remind_first_minutes;
        document.getElementById('support-remind-interval').value = support.remind_interval_minutes;
        document.getElementById('support-remind-max').value = support.remind_max_count;
    }

    function bindRemind() {
        document.getElementById('form-remind').addEventListener('submit', function (event) {
            event.preventDefault();

            submit('/admin/notification/ajax-update-remind', {
                remind_first_minutes: parseInt(document.getElementById('support-remind-first').value, 10) || 10,
                remind_interval_minutes: parseInt(document.getElementById('support-remind-interval').value, 10) || 10,
                remind_max_count: parseInt(document.getElementById('support-remind-max').value, 10) || 30
            }, event.target.querySelector('button[type="submit"]'));
        });
    }

    // ===== 分頁四：超時提醒統計 =====

    function renderReport() {
        renderUserList('support-report-list', 'js-report-user', settings.support.remind_report_user_ids);
        syncAll('support-report-all', 'js-report-user');
    }

    function bindReport() {
        bindSelectAll('support-report-all', 'support-report-list', 'js-report-user');

        document.getElementById('form-report').addEventListener('submit', function (event) {
            event.preventDefault();

            var ids = checkedIds('js-report-user');

            submit('/admin/notification/ajax-update-report', { remind_report_user_ids: ids },
                event.target.querySelector('button[type="submit"]'), function () {
                    settings.support.remind_report_user_ids = ids;
                });
        });

        document.getElementById('btn-test-report').addEventListener('click', function () {
            runTest('/admin/notification/ajax-test-report', this, i18n.msg.report_test_failed);
        });
    }

    // ===== 分頁一下半：話題分流 =====

    /**
     * 畫一列話題
     *
     * @param {Object} topic
     * @param {number} index
     */
    function topicRowHtml(topic, index) {
        var types = settings.options.notice_types || [];
        var checks = '';

        types.forEach(function (type) {
            var checked = (topic.types || []).indexOf(type.key) !== -1 ? ' checked' : '';
            /*
             * needs_reply 的類型要標出「只能勾一個話題」—— 不標的話使用者會勾兩個，
             * 然後要等按儲存被後端擋下來才知道。
             */
            var badge = type.needs_reply
                ? ' <span class="badge" style="background:rgba(212,175,55,0.2);color:#a67c00;font-size:0.6875rem">'
                    + escapeHtml(i18n.topic_single_badge) + '</span>'
                : '';

            checks += '<div class="form-check form-check-inline" style="min-width:14rem">' +
                '<input class="form-check-input js-topic-type" type="checkbox" ' +
                'id="topic-' + index + '-' + type.key + '" value="' + type.key + '"' + checked + '>' +
                '<label class="form-check-label" for="topic-' + index + '-' + type.key + '" title="' +
                escapeHtml(type.hint) + '">' + escapeHtml(type.label) + badge + '</label>' +
                '</div>';
        });

        return '<div class="js-topic-row notice-topic-row p-3 mb-3">' +
            '<div class="row align-items-end mb-2">' +
                '<div class="col-sm-5 mb-2">' +
                    '<label class="form-label">' + escapeHtml(i18n.topic_name) + '</label>' +
                    '<input type="text" class="form-control form-control-sm js-topic-name" maxlength="30" ' +
                        'value="' + escapeHtml(topic.name || '') + '" placeholder="' + escapeHtml(i18n.topic_name_ph) + '">' +
                '</div>' +
                '<div class="col-sm-4 mb-2">' +
                    '<label class="form-label">' + escapeHtml(i18n.topic_thread_id) + '</label>' +
                    '<input type="number" class="form-control form-control-sm js-topic-thread" min="1" step="1" ' +
                        'value="' + (topic.thread_id || '') + '" placeholder="42">' +
                '</div>' +
                '<div class="col-sm-3 mb-2 text-sm-end">' +
                    '<button type="button" class="btn btn-sm btn-outline-danger js-remove-topic js-manage-only">' +
                        '<i class="fas fa-trash-alt me-1"></i>' + escapeHtml(i18n.topic_remove) +
                    '</button>' +
                '</div>' +
            '</div>' +
            '<label class="form-label">' + escapeHtml(i18n.topic_types) + '</label>' +
            '<div>' + checks + '</div>' +
            '</div>';
    }

    function renderTopics() {
        var list = document.getElementById('topic-list');
        var topics = (settings.topics && settings.topics.list) || [];
        var html = '';

        topics.forEach(function (topic, index) {
            html += topicRowHtml(topic, index);
        });

        // 一筆都沒有時給一句說明，不要只是一片空白
        if (!topics.length) {
            html = '<p class="text-muted" style="font-size:0.875rem">' + escapeHtml(i18n.topic_empty) + '</p>';
        }

        list.innerHTML = html;
        applyPermission();
    }

    /**
     * 讀出目前畫面上的話題設定
     */
    function collectTopics() {
        var topics = [];

        root.querySelectorAll('.js-topic-row').forEach(function (row) {
            var types = [];

            row.querySelectorAll('.js-topic-type:checked').forEach(function (el) {
                types.push(el.value);
            });

            topics.push({
                name: row.querySelector('.js-topic-name').value.trim(),
                thread_id: parseInt(row.querySelector('.js-topic-thread').value, 10) || 0,
                types: types
            });
        });

        return topics;
    }

    function bindTopics() {
        document.getElementById('btn-add-topic').addEventListener('click', function () {
            var max = (settings.topics && settings.topics.max_topics) || 20;
            var current = root.querySelectorAll('.js-topic-row').length;

            if (current >= max) {
                showMessage(i18n.msg.topic_too_many);

                return;
            }

            // 先把畫面上的內容收回 settings，再重畫 —— 不然新增一列會把沒存的改動抹掉
            settings.topics.list = collectTopics();
            settings.topics.list.push({ name: '', thread_id: 0, types: [] });
            renderTopics();
        });

        document.getElementById('topic-list').addEventListener('click', function (event) {
            var button = event.target.closest('.js-remove-topic');
            if (!button) { return; }

            var row = button.closest('.js-topic-row');
            var rows = Array.prototype.slice.call(root.querySelectorAll('.js-topic-row'));
            var index = rows.indexOf(row);

            settings.topics.list = collectTopics();
            settings.topics.list.splice(index, 1);
            renderTopics();
        });

        document.getElementById('form-topic').addEventListener('submit', function (event) {
            event.preventDefault();

            var topics = collectTopics();

            submit('/admin/notification/ajax-update-topics', { topics: topics }, event.target.querySelector('button[type="submit"]'), function () {
                settings.topics.list = topics;
            });
        });

        document.getElementById('btn-test-topic').addEventListener('click', function () {
            runTest('/admin/notification/ajax-test-topics', this, i18n.msg.topic_test_failed);
        });
    }

    // ===== 分頁三：班表通知 =====

    function renderShift() {
        renderUserList('shift-user-list', 'js-shift-user', settings.shift.manager_user_ids);
        syncAll('shift-user-all', 'js-shift-user');
    }

    function bindShift() {
        bindSelectAll('shift-user-all', 'shift-user-list', 'js-shift-user');

        document.getElementById('form-shift').addEventListener('submit', function (event) {
            event.preventDefault();

            var ids = checkedIds('js-shift-user');

            submit('/admin/notification/ajax-update-shift', { manager_user_ids: ids }, event.target.querySelector('button[type="submit"]'), function () {
                settings.shift.manager_user_ids = ids;
            });
        });

        document.getElementById('btn-test-shift').addEventListener('click', function () {
            runTest('/admin/notification/ajax-test-shift', this, i18n.msg.shift_test_failed);
        });
    }

    // ===== 分頁五：任務卡通知 =====

    function renderTask() {
        renderUserList('task-user-list', 'js-task-user', settings.task.user_ids);
        syncAll('task-user-all', 'js-task-user');
    }

    function bindTask() {
        bindSelectAll('task-user-all', 'task-user-list', 'js-task-user');

        document.getElementById('form-task').addEventListener('submit', function (event) {
            event.preventDefault();

            var ids = checkedIds('js-task-user');

            submit('/admin/notification/ajax-update-task', { task_notice_user_ids: ids },
                event.target.querySelector('button[type="submit"]'), function () {
                    settings.task.user_ids = ids;
                });
        });

        document.getElementById('btn-test-task').addEventListener('click', function () {
            runTest('/admin/notification/ajax-test-task', this, i18n.msg.task_test_failed);
        });
    }

    // ===== 分頁六：打卡報表（週報與月報共用同一份收件人） =====

    function renderAttendance() {
        renderUserList('attendance-user-list', 'js-attendance-user', settings.attendance.user_ids);
        syncAll('attendance-user-all', 'js-attendance-user');
    }

    function bindAttendance() {
        bindSelectAll('attendance-user-all', 'attendance-user-list', 'js-attendance-user');

        document.getElementById('form-attendance').addEventListener('submit', function (event) {
            event.preventDefault();

            var ids = checkedIds('js-attendance-user');

            submit('/admin/notification/ajax-update-attendance', { attendance_report_user_ids: ids },
                event.target.querySelector('button[type="submit"]'), function () {
                    settings.attendance.user_ids = ids;
                });
        });

        document.getElementById('btn-test-attendance').addEventListener('click', function () {
            runTest('/admin/notification/ajax-test-attendance', this, i18n.msg.attendance_test_failed);
        });
    }

    // ===== 權限 =====

    /**
     * 只有檢視權限時鎖住所有輸入
     *
     * 話題那一區是動態重畫的，所以每次 renderTopics() 之後都要再套一次。
     */
    function applyPermission() {
        if (canManage) { return; }

        root.querySelectorAll('input, select, textarea, button.js-manage-only').forEach(function (el) {
            el.disabled = true;
        });
    }

    // ===== 啟動 =====

    renderGroup();
    renderRemind();
    renderTopics();
    renderShift();
    renderReport();
    renderTask();
    renderAttendance();
    applyPermission();

    if (canManage) {
        bindGroup();
        bindRemind();
        bindTopics();
        bindShift();
        bindReport();
        bindTask();
        bindAttendance();
    }
}());
