/**
 * 對客話術
 *
 * 自動回覆送給客人的訊息內容。答案取自題庫原文，這裡管的是包在外層的語氣。
 * 每種情境有完整版與精簡版：連續對話時用精簡版，避免每則都「您好，感謝您的詢問」。
 */
(function () {
    'use strict';

    var root = document.getElementById('reply-template-app');
    if (!root) { return; }

    var i18n = JSON.parse(root.dataset.i18n);
    var canManage = root.dataset.canManage === '1';
    var signature = root.dataset.signature || '';
    var greetingGap = root.dataset.greetingGap || '';
    var csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    /**
     * 四種情境。placeholder 是必須保留的變數，後端也會驗一次。
     */
    var SCENES = [
        { key: 'answer', placeholder: '{答案}' },
        { key: 'clarify', placeholder: '{選項}' },
        { key: 'wait', placeholder: null },
        { key: 'support', placeholder: '{答案}' }
    ];

    var templates = {};

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
            return response.json().then(function (body) {
                if (!response.ok) { throw body; }

                return body;
            });
        });
    }

    function showMessage(message) {
        var modalEl = document.getElementById('modal-template-msg');
        if (!modalEl) { return; }

        modalEl.querySelector('.modal-body').textContent = message;
        new bootstrap.Modal(modalEl).show();
    }

    /**
     * 把後端的錯誤訊息挖出來
     *
     * 驗證失敗時 Laravel 回的是 errors 物件（例如模板少了必要變數），
     * 只有一般錯誤才有 message。
     */
    function errorMessage(body, fallback) {
        if (body && body.errors) {
            var first = Object.keys(body.errors)[0];
            if (first && body.errors[first].length) { return body.errors[first][0]; }
        }

        return (body && body.message) || fallback;
    }

    function value(id) {
        var el = document.getElementById(id);

        return el ? el.value.trim() : '';
    }

    // ===== 渲染 =====

    function render() {
        document.getElementById('signature-hint').textContent =
            i18n.signature_hint.replace(':signature', signature);

        var fullHint = i18n.full_hint.replace(':minutes', greetingGap);
        var html = '';

        SCENES.forEach(function (scene) {
            var badge = scene.placeholder
                ? '<span class="badge bg-warning text-dark ms-2">' +
                    escapeHtml(i18n.var_required.replace(':value', scene.placeholder)) + '</span>'
                : '';

            html += '<div class="main-card mb-3 card">' +
                '<div class="card-body">' +
                '<h5 class="card-title">' + escapeHtml(i18n[scene.key]) + badge + '</h5>' +
                '<p class="text-muted" style="font-size:0.875rem">' + escapeHtml(i18n[scene.key + '_desc']) + '</p>' +
                '<div class="row">' +
                field(scene.key + '_full', i18n.full, fullHint) +
                field(scene.key + '_short', i18n.short, i18n.short_hint) +
                '</div></div></div>';
        });

        document.getElementById('template-fields').innerHTML = html;

        SCENES.forEach(function (scene) {
            setValue(scene.key + '_full');
            setValue(scene.key + '_short');
        });

        applyPermission();
    }

    function field(name, label, hint) {
        return '<div class="col-md-6 mb-2">' +
            '<label class="form-label" for="tpl-' + name + '">' + escapeHtml(label) + '</label>' +
            '<textarea class="form-control" id="tpl-' + name + '" rows="7" maxlength="2000" style="font-size:0.875rem"></textarea>' +
            '<small class="form-text text-muted">' + escapeHtml(hint) + '</small>' +
            '</div>';
    }

    function setValue(name) {
        var el = document.getElementById('tpl-' + name);
        if (el) { el.value = templates[name] || ''; }
    }

    /**
     * 只有檢視權限時鎖住輸入
     */
    function applyPermission() {
        if (canManage) { return; }

        root.querySelectorAll('textarea, button.js-manage-only').forEach(function (el) {
            el.disabled = true;
        });
    }

    // ===== 載入與儲存 =====

    /**
     * 從伺服器重新取一份（儲存之後用）
     *
     * 首次載入不走這裡 —— 初始資料已經隨頁面送來了。
     */
    function reload() {
        apiFetch('/admin/reply-template/ajax-templates')
            .then(function (body) {
                templates = body;
                render();
            })
            .catch(function () {
                showMessage(i18n.msg.load_failed);
            });
    }

    document.getElementById('form-templates').addEventListener('submit', function (e) {
        e.preventDefault();

        var button = e.target.querySelector('button[type="submit"]');
        var original = button.textContent;
        var payload = {};

        SCENES.forEach(function (scene) {
            payload[scene.key + '_full'] = value('tpl-' + scene.key + '_full');
            payload[scene.key + '_short'] = value('tpl-' + scene.key + '_short');
        });

        button.disabled = true;
        button.textContent = i18n.action_saving;

        apiFetch('/admin/reply-template/ajax-update', {
            method: 'PUT',
            body: JSON.stringify(payload)
        })
            .then(function (body) {
                showMessage(body.message || i18n.msg.saved);
                reload();
            })
            .catch(function (body) {
                showMessage(errorMessage(body, i18n.msg.save_failed));
            })
            .then(function () {
                button.disabled = false;
                button.textContent = original;
            });
    });

    // 初始資料隨頁面一起到，直接畫 —— 不必等一次 ajax 往返
    templates = JSON.parse(root.dataset.initial);
    render();
})();
