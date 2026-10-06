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
     * 畫收件人清單（可多選 + 全選）
     *
     * 沒綁定的人後面掛「未綁定」—— 選了也收不到，要在勾之前就看得出來。
     * 不直接把他們拿掉：那樣會變成「名單裡沒有這個人」，更難判斷。
     */
    function render() {
        var list = document.getElementById('notice-manager-list');
        var selected = settings.manager_user_ids || [];

        list.innerHTML = '';

        (settings.candidates || []).forEach(function (user) {
            var wrap = document.createElement('div');
            wrap.className = 'form-check';

            var input = document.createElement('input');
            input.className = 'form-check-input js-manager';
            input.type = 'checkbox';
            input.id = 'notice-manager-' + user.id;
            input.value = user.id;
            input.checked = selected.indexOf(user.id) !== -1;

            var label = document.createElement('label');
            label.className = 'form-check-label';
            label.setAttribute('for', input.id);
            label.textContent = user.dm_ready
                ? user.nickname
                : user.nickname + '（' + i18n.manager_unbound + '）';

            // 沒綁定的標成灰的，一眼看出這幾個收不到
            if (!user.dm_ready) { label.classList.add('text-muted'); }

            wrap.appendChild(input);
            wrap.appendChild(label);
            list.appendChild(wrap);
        });

        syncAllCheckbox();

        if (!canManage) {
            root.querySelectorAll('input, .js-manage-only').forEach(function (el) { el.disabled = true; });
        }
    }

    /**
     * 目前勾選的 id
     *
     * @return {Array<number>}
     */
    function checkedIds() {
        var ids = [];

        root.querySelectorAll('.js-manager:checked').forEach(function (el) {
            ids.push(parseInt(el.value, 10));
        });

        return ids;
    }

    /**
     * 「全選」的勾選狀態要跟著個別項目走
     *
     * 少了這段，手動把人逐一勾完之後「全選」還是沒勾，看起來像壞掉。
     */
    function syncAllCheckbox() {
        var all = document.getElementById('notice-manager-all');
        var boxes = root.querySelectorAll('.js-manager');

        if (!all) { return; }

        all.checked = boxes.length > 0 && checkedIds().length === boxes.length;
    }

    function bind() {
        if (!canManage) { return; }

        // 全選：一次勾完或一次清空
        document.getElementById('notice-manager-all').addEventListener('change', function () {
            var checked = this.checked;

            root.querySelectorAll('.js-manager').forEach(function (el) { el.checked = checked; });
        });

        // 個別勾選要回頭同步「全選」
        document.getElementById('notice-manager-list').addEventListener('change', function (event) {
            if (event.target.classList.contains('js-manager')) { syncAllCheckbox(); }
        });

        document.getElementById('form-shift-notice').addEventListener('submit', function (event) {
            event.preventDefault();

            var button = this.querySelector('button[type="submit"]');
            var original = button.textContent;
            var ids = checkedIds();

            button.disabled = true;
            button.textContent = i18n.action_saving;

            apiFetch('/admin/shift-notice/ajax-update', {
                method: 'PUT',
                // 空陣列代表「不發送」，後端的 nullable|array 規則接得住
                body: JSON.stringify({ manager_user_ids: ids })
            })
                .then(function (body) {
                    showMessage(body.message || i18n.msg.saved);
                    settings.manager_user_ids = ids;
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
