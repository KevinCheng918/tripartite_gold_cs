/**
 * 班表通知設定
 *
 * 只有一個欄位要設定：今日完整班表私訊給誰。
 * 個人那份自動發給當天有班的人，不需要設定。
 */
(function () {
    'use strict';

    var root = document.getElementById('shift-notice-app');
    if (!root) { return; }

    var i18n = JSON.parse(root.dataset.i18n);
    var canManage = root.dataset.canManage === '1';
    var csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    var settings = JSON.parse(root.dataset.initial);

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
        var modalEl = document.getElementById('modal-shift-notice-msg');
        if (!modalEl) { return; }

        modalEl.querySelector('.modal-body').textContent = message;
        new bootstrap.Modal(modalEl).show();
    }

    /**
     * 把後端的錯誤訊息挖出來
     *
     * 驗證失敗時 Laravel 回的是 errors 物件，只有一般錯誤才有 message。
     */
    function errorMessage(body, fallback) {
        if (body && body.message) { return body.message; }

        if (body && body.errors) {
            var keys = Object.keys(body.errors);

            if (keys.length) { return body.errors[keys[0]][0]; }
        }

        return fallback;
    }

    /**
     * 畫收件人下拉
     *
     * 沒綁定的人後面掛「未綁定」—— 選了也收不到，要在選之前就看得出來。
     * 不直接把他們拿掉：那樣會變成「名單裡沒有這個人」，更難判斷。
     */
    function render() {
        var select = document.getElementById('notice-manager');
        var current = parseInt(settings.manager_user_id, 10) || 0;

        select.innerHTML = '';

        var none = document.createElement('option');
        none.value = '';
        none.textContent = i18n.manager_none;
        select.appendChild(none);

        (settings.candidates || []).forEach(function (user) {
            var option = document.createElement('option');
            option.value = user.id;
            option.textContent = user.dm_ready
                ? user.nickname
                : user.nickname + '（' + i18n.manager_unbound + '）';
            option.selected = user.id === current;
            select.appendChild(option);
        });

        if (!canManage) {
            select.disabled = true;
            root.querySelectorAll('.js-manage-only').forEach(function (el) { el.disabled = true; });
        }
    }

    function bind() {
        if (!canManage) { return; }

        document.getElementById('form-shift-notice').addEventListener('submit', function (event) {
            event.preventDefault();

            var button = this.querySelector('button[type="submit"]');
            var original = button.textContent;
            var value = document.getElementById('notice-manager').value;

            button.disabled = true;
            button.textContent = i18n.action_saving;

            apiFetch('/admin/shift-notice/ajax-update', {
                method: 'PUT',
                // 空字串代表「不發送」，後端的 nullable 規則接得住
                body: JSON.stringify({ manager_user_id: value === '' ? null : parseInt(value, 10) })
            })
                .then(function (body) {
                    showMessage(body.message || i18n.msg.saved);
                    settings.manager_user_id = value === '' ? 0 : parseInt(value, 10);
                })
                .catch(function (body) {
                    showMessage(errorMessage(body, i18n.msg.save_failed));
                })
                .then(function () {
                    button.disabled = false;
                    button.textContent = original;
                });
        });

        document.getElementById('btn-test-notice').addEventListener('click', function () {
            var button = this;
            var original = button.textContent;

            button.disabled = true;
            button.textContent = i18n.action_testing;

            apiFetch('/admin/shift-notice/ajax-test', { method: 'POST' })
                .then(function (body) {
                    showMessage(body.message || i18n.msg.test_sent);
                })
                .catch(function (body) {
                    showMessage(errorMessage(body, i18n.msg.test_failed));
                })
                .then(function () {
                    button.disabled = false;
                    button.textContent = original;
                });
        });
    }

    render();
    bind();
}());
