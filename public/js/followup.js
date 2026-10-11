/**
 * 待追蹤事項（客服管理 → 待追蹤事項）
 *
 * 對話裡有人說「稍等」就會記一筆。平常由當班人員在支援群組按按鈕決定，
 * 這一頁是想看全部、或那則按鈕訊息被洗掉時的入口。
 *
 * ⚠ 狀態與來源的文字**一律用後端給的 label**（Resource 已經翻好）——
 * 前端自己比對 status 數字的話，config 改了定義兩邊就會不一致，
 * 而前端那份不會報錯。
 */
(function () {
    'use strict';

    var root = document.getElementById('followup-app');
    if (!root) { return; }

    var i18n = JSON.parse(root.dataset.i18n);
    var sources = JSON.parse(root.dataset.sources);
    var statuses = JSON.parse(root.dataset.statuses);
    var customerSource = parseInt(root.dataset.customerSource, 10);
    var canManage = root.dataset.canManage === '1';
    var csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    var currentPage = 1;
    var lastPage = 1;
    // 確認 modal 按下「確定」之後要做的事
    var pendingAction = null;

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
        var modalEl = document.getElementById('modal-followup-msg');
        if (!modalEl) { return; }

        modalEl.querySelector('.modal-body').textContent = message;
        new bootstrap.Modal(modalEl).show();
    }

    function errorMessage(body, fallback) {
        return (body && body.message) || fallback;
    }

    /**
     * 等多久（天／小時／分，只取最大的兩個單位）
     *
     * ⚠ 這裡算的是「從計時起點到現在」，跟 Telegram 那則訊息同一個語意，
     * 但**後端不會每次都重算一遍傳過來** —— 頁面開著不動時那個數字會停住，
     * 所以用瀏覽器的時間算，重新查詢就會更新。
     */
    function waited(value) {
        if (!value) { return '-'; }

        // 後端給的是 'Y-m-d H:i:s'（伺服器時區），換成瀏覽器看得懂的格式
        var minutes = Math.max(0, Math.floor((Date.now() - new Date(value.replace(/-/g, '/')).getTime()) / 60000));
        var days = Math.floor(minutes / 1440);
        var hours = Math.floor((minutes % 1440) / 60);

        // 樣板在語系檔，不要寫死中文 —— 簡中與英文的使用者看的是同一支 JS
        if (days > 0) {
            return i18n.waited_days.replace(':days', days).replace(':hours', hours);
        }

        if (hours > 0) {
            return i18n.waited_hours.replace(':hours', hours).replace(':minutes', minutes % 60);
        }

        return i18n.waited_mins.replace(':minutes', minutes);
    }

    // ===== 篩選 =====

    function renderFilters() {
        var sourceSelect = document.getElementById('followup-filter-source');
        var statusSelect = document.getElementById('followup-filter-status');

        var sourceHtml = '<option value="">' + escapeHtml(i18n.filter_all) + '</option>';

        Object.keys(sources).forEach(function (value) {
            sourceHtml += '<option value="' + value + '">' + escapeHtml(sources[value]) + '</option>';
        });

        sourceSelect.innerHTML = sourceHtml;

        /*
         * 狀態的預設是空字串＝「只看未結案」（後端那兩個狀態的定義在
         * Service 裡），`all` 才是全部。這一段的順序刻意是
         * 「只看未結案 → 全部 → 各個狀態」。
         */
        var statusHtml = '<option value="">' + escapeHtml(i18n.filter_open_only) + '</option>'
            + '<option value="all">' + escapeHtml(i18n.filter_all) + '</option>';

        Object.keys(statuses).forEach(function (value) {
            statusHtml += '<option value="' + value + '">' + escapeHtml(statuses[value]) + '</option>';
        });

        statusSelect.innerHTML = statusHtml;
    }

    function filterParams() {
        var params = ['page=' + currentPage];
        var source = document.getElementById('followup-filter-source').value;
        var status = document.getElementById('followup-filter-status').value;

        if (source) { params.push('source=' + encodeURIComponent(source)); }
        if (status) { params.push('status=' + encodeURIComponent(status)); }

        return params.join('&');
    }

    // ===== 列表 =====

    function loadList() {
        apiFetch('/admin/followup/ajax-list?' + filterParams())
            .then(function (body) {
                renderRows(body.data || []);
                renderPagination(body.meta || {});
            })
            .catch(function (body) {
                showMessage(errorMessage(body, i18n.msg.load_failed));
            });
    }

    function renderRows(rows) {
        var tbody = document.getElementById('followup-rows');

        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">'
                + escapeHtml(i18n.empty) + '</td></tr>';

            return;
        }

        var html = '';

        rows.forEach(function (row) {
            html += '<tr>'
                + '<td>' + escapeHtml(row.source_label) + '</td>'
                + '<td>' + escapeHtml(row.group_title) + '</td>'
                + '<td>' + escapeHtml(row.sender_name || '-') + '</td>'
                + '<td>' + escapeHtml(row.wait_text || '-')
                + (row.keyword ? '<br><small class="text-muted">'
                    + escapeHtml(i18n.keyword.replace(':keyword', row.keyword)) + '</small>' : '')
                + '</td>'
                + '<td>' + escapeHtml(waited(row.wait_at)) + '</td>'
                + '<td>' + escapeHtml(i18n.counts
                    .replace(':waits', row.wait_count)
                    .replace(':rounds', row.ask_round)) + '</td>'
                + '<td>' + row.status_icon + ' ' + escapeHtml(row.status_label) + '</td>'
                + '<td>' + (row.decided_by
                    ? escapeHtml(row.decided_by) + '<br><small class="text-muted">'
                        + escapeHtml(window.formatDateTime ? window.formatDateTime(row.decided_at) : row.decided_at)
                        + '</small>'
                    : '<span class="text-muted">' + escapeHtml(i18n.never_asked) + '</span>')
                + '</td>'
                + '<td>' + actionButtons(row) + '</td>'
                + '</tr>';
        });

        tbody.innerHTML = html;
    }

    /**
     * 操作欄
     *
     * ⚠ 能不能操作由後端判斷（`can_decide`）—— 前端不要自己比對狀態數字。
     * 沒有管理權限的人只看得到清單。
     */
    function actionButtons(row) {
        if (!canManage || !row.can_decide) { return '<span class="text-muted">-</span>'; }

        return '<div class="btn-group btn-group-sm row-actions">'
            + '<button type="button" class="btn btn-outline-primary btn-transition js-followup-follow" data-id="' + row.id
            + '" data-source="' + row.source + '">' + escapeHtml(i18n.action_follow) + '</button>'
            + '<button type="button" class="btn btn-outline-secondary btn-transition js-followup-close" data-id="' + row.id
            + '">' + escapeHtml(i18n.action_close) + '</button>'
            + '</div>';
    }

    function renderPagination(meta) {
        currentPage = meta.current_page || 1;
        lastPage = meta.last_page || 1;

        var container = document.getElementById('followup-pagination');

        if (lastPage < 2) {
            container.innerHTML = '';

            return;
        }

        var html = '<nav><ul class="pagination mb-0">';

        html += '<li class="page-item' + (currentPage <= 1 ? ' disabled' : '') + '">'
            + '<button class="page-link js-followup-page" data-page="' + (currentPage - 1) + '">&lsaquo;</button></li>';

        html += '<li class="page-item disabled"><span class="page-link">'
            + currentPage + ' / ' + lastPage + '</span></li>';

        html += '<li class="page-item' + (currentPage >= lastPage ? ' disabled' : '') + '">'
            + '<button class="page-link js-followup-page" data-page="' + (currentPage + 1) + '">&rsaquo;</button></li>';

        container.innerHTML = html + '</ul></nav>';
    }

    // ===== 操作 =====

    /**
     * 先問再做
     *
     * ⚠ 客人承諾與我方承諾的後果不同（一個會發訊息給客人、一個只是 tag
     * 當班人員），所以確認的文字也不一樣 —— 按下去會發生什麼事，
     * 按之前就要看得出來。
     */
    function confirmDecide(id, follow, source) {
        var message = i18n.confirm_close;

        if (follow) {
            message = source === customerSource
                ? i18n.confirm_follow_customer
                : i18n.confirm_follow_staff;
        }

        var modalEl = document.getElementById('modal-followup-confirm');
        modalEl.querySelector('.modal-body').textContent = message;

        pendingAction = { id: id, follow: follow };
        new bootstrap.Modal(modalEl).show();
    }

    function runDecide() {
        if (!pendingAction) { return; }

        var action = pendingAction;
        pendingAction = null;

        bootstrap.Modal.getInstance(document.getElementById('modal-followup-confirm')).hide();

        apiFetch('/admin/followup/ajax-decide/' + action.id, {
            method: 'PUT',
            body: JSON.stringify({ follow: action.follow })
        })
            .then(function (body) {
                showMessage(body.message || i18n.msg.decided);
                loadList();
            })
            .catch(function (body) {
                showMessage(errorMessage(body, i18n.msg.decide_failed));
                // 失敗也要重載：很可能是別人剛在 Telegram 按過了
                loadList();
            });
    }

    // ===== 綁定 =====

    document.getElementById('followup-search').addEventListener('click', function () {
        currentPage = 1;
        loadList();
    });

    document.getElementById('followup-reset').addEventListener('click', function () {
        document.getElementById('followup-filter-source').value = '';
        document.getElementById('followup-filter-status').value = '';
        currentPage = 1;
        loadList();
    });

    /*
     * 列表與分頁都是重畫出來的，所以事件委派綁在容器上。
     *
     * ⚠ 綁在每顆按鈕上會在每次重畫後失效，綁在 document 上則會在重複
     * 初始化時累積（見 bugfix/2026-09-08-task-comment-emoji-duplicate）。
     */
    root.addEventListener('click', function (event) {
        var follow = event.target.closest('.js-followup-follow');

        if (follow) {
            confirmDecide(follow.dataset.id, true, parseInt(follow.dataset.source, 10));

            return;
        }

        var close = event.target.closest('.js-followup-close');

        if (close) {
            confirmDecide(close.dataset.id, false, null);

            return;
        }

        var page = event.target.closest('.js-followup-page');

        if (page) {
            currentPage = parseInt(page.dataset.page, 10);
            loadList();
        }
    });

    document.getElementById('followup-confirm-ok').addEventListener('click', runDecide);

    // ===== 啟動 =====

    renderFilters();
    loadList();
}());
