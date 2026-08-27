/**
 * Telegram Chat — 快速回覆選單
 *
 * 兩層：類別 → 問題 → 答案預覽，可「填入輸入框」微調後再送，或「直接送出」。
 * 資料來自 config/quick_reply.php，經 ajax-quick-replies 取得後在前端快取。
 */
(function () {
    var T = window.TgChat;
    if (!T) { return; }

    // 只在第一次開啟時載入，之後沿用
    var data = null;

    T.openQuickReplyModal = function () {
        if (!T.selectedGroupId) { return; }

        var modalEl = ensureModal();
        var modal = new bootstrap.Modal(modalEl);
        modal.show();

        if (data) {
            renderCategories();
            return;
        }

        setBody('<div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin"></i></div>');
        T.apiFetch('/admin/telegram-chat/ajax-quick-replies')
            .then(function (body) {
                data = body || {};
                renderCategories();
            })
            .catch(function () {
                setBody('<div class="text-center text-danger py-4">' +
                    T.escapeHtml(T.i18n.msg.qr_load_failed || '快速回覆選單載入失敗') + '</div>');
            });
    };

    function ensureModal() {
        var modalEl = document.getElementById('modal-tg-quick-reply');
        if (modalEl) { return modalEl; }

        var html = '<div class="modal fade" id="modal-tg-quick-reply" tabindex="-1">' +
            '<div class="modal-dialog modal-lg modal-dialog-scrollable">' +
            '<div class="modal-content">' +
            '<div class="modal-header">' +
            '<h5 class="modal-title"><i class="fas fa-bolt me-2"></i>' +
            T.escapeHtml(T.i18n.btn_quick_reply || '快速回覆') + '</h5>' +
            '<button type="button" class="btn-close" data-bs-dismiss="modal"></button>' +
            '</div>' +
            '<div class="modal-body">' +
            '<input type="text" class="form-control form-control-sm mb-3" id="tg-qr-search" ' +
            'placeholder="' + T.escapeHtml(T.i18n.qr_search || '搜尋問題...') + '" autocomplete="off">' +
            '<div id="tg-qr-body"></div>' +
            '</div>' +
            '</div></div></div>';

        document.body.insertAdjacentHTML('beforeend', html);
        modalEl = document.getElementById('modal-tg-quick-reply');

        // 搜尋：跨類別比對問題與答案
        var search = document.getElementById('tg-qr-search');
        search.addEventListener('input', function () {
            var keyword = search.value.trim();
            if (!keyword) {
                renderCategories();
                return;
            }
            renderSearchResults(keyword);
        });

        // 關閉時清掉搜尋，下次打開回到類別列表
        modalEl.addEventListener('hidden.bs.modal', function () {
            search.value = '';
        });

        return modalEl;
    }

    function setBody(html) {
        var body = document.getElementById('tg-qr-body');
        if (body) { body.innerHTML = html; }
    }

    /**
     * 第一層：類別
     */
    function renderCategories() {
        var html = '<div class="text-muted mb-2" style="font-size:0.8125rem">' +
            T.escapeHtml(T.i18n.qr_select_category || '請選擇問題類型') + '</div>' +
            '<div class="row g-2">';

        Object.keys(data).forEach(function (key) {
            var category = data[key];
            html += '<div class="col-6 col-md-4">' +
                '<button type="button" class="btn btn-outline-secondary w-100 text-truncate js-qr-category" ' +
                'data-key="' + T.escapeHtml(key) + '">' +
                T.escapeHtml(category.label) +
                '<span class="badge bg-light text-muted ms-1">' + category.items.length + '</span>' +
                '</button></div>';
        });

        html += '</div>';
        setBody(html);

        bindAll('.js-qr-category', function (btn) {
            renderItems(btn.dataset.key);
        });
    }

    /**
     * 第二層：該類別的問題
     */
    function renderItems(categoryKey) {
        var category = data[categoryKey];
        if (!category) { return; }

        var html = backButton() +
            '<div class="fw-bold mb-2">' + T.escapeHtml(category.label) + '</div>' +
            '<div class="list-group">';

        category.items.forEach(function (item, index) {
            html += '<button type="button" class="list-group-item list-group-item-action js-qr-item" ' +
                'data-category="' + T.escapeHtml(categoryKey) + '" data-index="' + index + '" ' +
                'style="font-size:0.875rem">' + T.escapeHtml(item.label) + '</button>';
        });

        html += '</div>';
        setBody(html);

        bindBack(renderCategories);
        bindAll('.js-qr-item', function (btn) {
            renderAnswer(data[btn.dataset.category].items[parseInt(btn.dataset.index, 10)], function () {
                renderItems(categoryKey);
            });
        });
    }

    /**
     * 搜尋結果（跨類別）
     */
    function renderSearchResults(keyword) {
        var lower = keyword.toLowerCase();
        var matched = [];

        Object.keys(data).forEach(function (categoryKey) {
            var category = data[categoryKey];
            category.items.forEach(function (item) {
                var haystack = (item.label + ' ' + item.answer).toLowerCase();
                if (haystack.indexOf(lower) !== -1) {
                    matched.push({ categoryLabel: category.label, item: item });
                }
            });
        });

        if (!matched.length) {
            setBody('<div class="text-center text-muted py-4">' +
                T.escapeHtml(T.i18n.qr_empty || '找不到符合的問題') + '</div>');
            return;
        }

        var html = '<div class="list-group">';
        matched.forEach(function (row, index) {
            html += '<button type="button" class="list-group-item list-group-item-action js-qr-search-item" ' +
                'data-index="' + index + '" style="font-size:0.875rem">' +
                '<span class="badge bg-secondary me-2">' + T.escapeHtml(row.categoryLabel) + '</span>' +
                T.escapeHtml(row.item.label) +
                '</button>';
        });
        html += '</div>';
        setBody(html);

        bindAll('.js-qr-search-item', function (btn) {
            var row = matched[parseInt(btn.dataset.index, 10)];
            renderAnswer(row.item, function () { renderSearchResults(keyword); });
        });
    }

    /**
     * 第三層：答案預覽
     *
     * @param {Object}   item    含 label / answer
     * @param {Function} onBack  返回上一層的渲染函式
     */
    function renderAnswer(item, onBack) {
        setBody(
            backButton() +
            '<div class="fw-bold mb-2">' + T.escapeHtml(item.label) + '</div>' +
            '<div class="border rounded p-3 mb-3" ' +
            'style="white-space:pre-wrap;font-size:0.875rem;max-height:40vh;overflow-y:auto">' +
            T.escapeHtml(item.answer) + '</div>' +
            '<div class="d-flex justify-content-end gap-2">' +
            '<button type="button" class="btn btn-outline-secondary" id="tg-qr-fill">' +
            T.escapeHtml(T.i18n.qr_fill || '填入輸入框') + '</button>' +
            '<button type="button" class="btn btn-primary" id="tg-qr-send">' +
            '<i class="fas fa-paper-plane me-1"></i>' + T.escapeHtml(T.i18n.qr_send || '直接送出') + '</button>' +
            '</div>'
        );

        bindBack(onBack);

        document.getElementById('tg-qr-fill').addEventListener('click', function () {
            fillInput(item.answer);
        });

        document.getElementById('tg-qr-send').addEventListener('click', function (e) {
            sendAnswer(item.answer, e.currentTarget);
        });
    }

    /**
     * 填入輸入框讓客服可以先改再送
     */
    function fillInput(answer) {
        var textarea = document.getElementById('tg-reply-text');
        if (textarea) {
            textarea.value = answer;
            textarea.style.height = 'auto';
            textarea.style.height = Math.min(textarea.scrollHeight, 120) + 'px';
        }

        hideModal();
        if (textarea) { textarea.focus(); }
    }

    /**
     * 直接送到目前對話的群組
     */
    function sendAnswer(answer, btn) {
        if (!T.selectedGroupId) { return; }

        btn.disabled = true;
        T.apiFetch('/admin/telegram-chat/ajax-reply', {
            method: 'POST',
            body: JSON.stringify({ group_id: T.selectedGroupId, content: answer }),
        })
            .then(function () {
                btn.disabled = false;
                hideModal();
                T.loadMessages(T.selectedGroupId);
            })
            .catch(function (error) {
                btn.disabled = false;
                var message = (error && error.message) || T.i18n.msg.reply_failed;
                setBody('<div class="text-center text-danger py-4">' + T.escapeHtml(message) + '</div>');
            });
    }

    function hideModal() {
        var modalEl = document.getElementById('modal-tg-quick-reply');
        if (!modalEl) { return; }

        var modal = bootstrap.Modal.getInstance(modalEl);
        if (modal) { modal.hide(); }
    }

    function backButton() {
        return '<button type="button" class="btn btn-sm btn-link ps-0 mb-2" id="tg-qr-back">' +
            '<i class="fas fa-arrow-left me-1"></i>' + T.escapeHtml(T.i18n.qr_back || '返回') + '</button>';
    }

    function bindBack(handler) {
        var btn = document.getElementById('tg-qr-back');
        if (btn) { btn.addEventListener('click', handler); }
    }

    /**
     * @param {string}   selector
     * @param {Function} handler 收到被點擊的按鈕元素
     */
    function bindAll(selector, handler) {
        var body = document.getElementById('tg-qr-body');
        if (!body) { return; }

        body.querySelectorAll(selector).forEach(function (btn) {
            btn.addEventListener('click', function () { handler(btn); });
        });
    }
})();
