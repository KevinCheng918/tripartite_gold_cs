/**
 * AI 引擎（原本叫「全域設定」）
 *
 * Claude 憑證（訂閱與備援）、用量流量。
 *
 * ⚠ 內部支援群組 2026-10-06 搬到「通訊管理 → 通知設定」（notification-setting.js）。
 * 憑證欄位永遠只拿得到遮罩，明文不會從後端送出來。
 *
 * 原本還有一頁「對客話術」，2026-09-30 移除 —— 開頭由 AI 的承接句負責、
 * 答案用題庫原文，中間那層外殼反而會跟承接句重複問候。
 * 剩下的兩段固定話術搬進 config/auto_reply.php 的 templates。
 */
(function () {
    'use strict';

    var root = document.getElementById('setting-app');
    if (!root) { return; }

    var i18n = JSON.parse(root.dataset.i18n);
    var canManage = root.dataset.canManage === '1';
    var csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    var settings = null;

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
        var modalEl = document.getElementById('modal-setting-msg');
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

    function checked(id) {
        var el = document.getElementById(id);

        return el ? el.checked : false;
    }

    // ===== 載入 =====

    /**
     * 用手上的資料把畫面畫出來
     */
    function render() {
        renderOptions();
        renderClaude();
        renderFallback();
        renderUsage();
        applyPermission();
    }

    /**
     * 從伺服器重新取一份（儲存之後用）
     *
     * 首次載入不走這裡 —— 初始資料已經隨頁面送來了，再發一次只是讓畫面慢一拍。
     */
    function reload() {
        apiFetch('/admin/setting/ajax-settings')
            .then(function (body) {
                settings = body;
                render();
            })
            .catch(function () {
                showMessage(i18n.msg.load_failed);
            });
    }

    /**
     * 下拉選單：模型與 Bot
     */
    function renderOptions() {
        var models = settings.options.models || {};
        var html = '';

        Object.keys(models).forEach(function (key) {
            html += '<option value="' + escapeHtml(key) + '">' + escapeHtml(models[key]) + '</option>';
        });

        document.getElementById('claude-model').innerHTML = html;
        document.getElementById('fallback-model').innerHTML = html;

    }

    function renderClaude() {
        var claude = settings.claude;

        document.getElementById('claude-model').value = claude.model || 'opus';
        document.getElementById('claude-token').placeholder = claude.has_token
            ? claude.token_masked
            : i18n.claude_not_set;
        document.getElementById('claude-verified-at').textContent = claude.verified_at || '-';
    }

    function renderFallback() {
        var fallback = settings.fallback;

        document.getElementById('fallback-enabled').checked = !!fallback.enabled;
        document.getElementById('fallback-model').value = fallback.model || 'haiku';
        document.getElementById('fallback-daily-limit').value = fallback.daily_limit;
        document.getElementById('fallback-used-today').textContent = fallback.used_today;
        document.getElementById('fallback-api-key').placeholder = fallback.has_api_key
            ? fallback.api_key_masked
            : i18n.claude_not_set;
    }

    function renderUsage() {
        var usage = settings.usage;

        document.getElementById('usage-summary').innerHTML =
            usageCard(i18n.usage_today, usage.today) + usageCard(i18n.usage_month, usage.month);

        var rows = '';
        (usage.daily || []).forEach(function (day) {
            rows += '<tr>' +
                '<td>' + escapeHtml(day.date) + '</td>' +
                '<td>' + day.subscription_calls + '</td>' +
                '<td>' + day.fallback_calls + '</td>' +
                '<td>' + (day.rate_limited > 0
                    ? '<span class="text-danger">' + day.rate_limited + '</span>'
                    : day.rate_limited) + '</td>' +
                '</tr>';
        });

        document.getElementById('usage-daily').innerHTML = rows ||
            '<tr><td colspan="4" class="text-center text-muted">-</td></tr>';
    }

    /**
     * 一個期間的統計卡
     *
     * 訂閱看撞限額次數，備援看花了多少錢 —— 兩者要盯的東西不一樣。
     */
    function usageCard(title, period) {
        var subscription = period.subscription;
        var fallback = period.fallback;

        return '<div class="mb-3">' +
            '<h6>' + escapeHtml(title) + '</h6>' +
            '<div class="row">' +
            '<div class="col-md-6">' +
            '<div class="p-2" style="background:rgba(0,0,0,0.03);border-radius:6px">' +
            '<strong>' + escapeHtml(i18n.usage_subscription) + '</strong><br>' +
            escapeHtml(i18n.usage_calls) + '：' + subscription.calls + '　' +
            escapeHtml(i18n.usage_rate_limited) + '：' +
            (subscription.rate_limited > 0
                ? '<span class="text-danger">' + subscription.rate_limited + '</span>'
                : subscription.rate_limited) + '<br>' +
            escapeHtml(i18n.usage_errors) + '：' + subscription.errors + '　' +
            escapeHtml(i18n.usage_avg_duration) + '：' + subscription.avg_duration_ms + ' ms' +
            '</div></div>' +
            '<div class="col-md-6">' +
            '<div class="p-2" style="background:rgba(0,0,0,0.03);border-radius:6px">' +
            '<strong>' + escapeHtml(i18n.usage_fallback) + '</strong><br>' +
            escapeHtml(i18n.usage_calls) + '：' + fallback.calls + '<br>' +
            escapeHtml(i18n.usage_cost) + '：US$' + fallback.cost_usd + '（NT$' + fallback.cost_twd + '）' +
            '</div></div>' +
            '</div></div>';
    }

    /**
     * 只有檢視權限時鎖住所有輸入
     */
    function applyPermission() {
        if (canManage) { return; }

        root.querySelectorAll('input, select, textarea, button.js-manage-only').forEach(function (el) {
            el.disabled = true;
        });
    }

    // ===== 儲存 =====

    /**
     * 共用的送出流程
     *
     * 存檔成功後重新載入 —— 憑證遮罩、驗證時間、用量都會跟著更新。
     */
    function submit(url, payload, button) {
        var original = button ? button.textContent : '';

        if (button) {
            button.disabled = true;
            button.textContent = i18n.action_saving;
        }

        apiFetch(url, { method: 'PUT', body: JSON.stringify(payload) })
            .then(function (body) {
                showMessage(body.message || i18n.msg.saved);
                reload();
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

    function bindForms() {
        document.getElementById('form-claude').addEventListener('submit', function (e) {
            e.preventDefault();
            submit('/admin/setting/ajax-update-claude', {
                token: value('claude-token'),
                model: value('claude-model')
            }, e.target.querySelector('button[type="submit"]'));
        });

        document.getElementById('form-fallback').addEventListener('submit', function (e) {
            e.preventDefault();
            submit('/admin/setting/ajax-update-fallback', {
                enabled: checked('fallback-enabled'),
                api_key: value('fallback-api-key'),
                model: value('fallback-model'),
                daily_limit: parseInt(value('fallback-daily-limit'), 10) || 0
            }, e.target.querySelector('button[type="submit"]'));
        });

    }

    bindForms();

    // 初始資料隨頁面一起到，直接畫 —— 不必等一次 ajax 往返
    settings = JSON.parse(root.dataset.initial);
    render();
})();
