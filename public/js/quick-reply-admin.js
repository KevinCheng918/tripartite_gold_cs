/**
 * 快速回覆題庫管理
 *
 * 左側類別、右側該類別的問答，皆可新增／編輯／刪除／上下移。
 * 題庫供 Telegram 聊天視窗的快速回覆選單使用。
 */
(function () {
    'use strict';

    var root = document.getElementById('quick-reply-app');
    if (!root) { return; }

    var i18n = JSON.parse(root.dataset.i18n);
    var canEdit = root.dataset.canEdit === '1';
    var csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    var categories = [];
    var selectedCategoryId = null;
    var pendingDelete = null;

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
            Accept: 'application/json',
        }, options.headers || {});

        return fetch(url, options).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok) { throw body; }

                return body;
            });
        });
    }

    /**
     * 前一個 modal 還在淡出時直接開新的，兩層會疊在一起（backdrop 也會打架），
     * 所以有 modal 未關完就等動畫結束再開 —— 與 payment-config / staff-manage 的做法一致。
     *
     * 注意不能只看 .modal-backdrop：hideBsModal() 會「立刻」移除 backdrop，
     * 但 .modal.show 要等淡出動畫結束才拿掉，所以兩個都要檢查。
     *
     * @param {string} message
     */
    function showMsg(message) {
        document.getElementById('modal-qr-msg-text').textContent = message;

        var closing = document.querySelectorAll('.modal.show').length > 0
            || document.querySelectorAll('.modal-backdrop').length > 0;

        if (closing) {
            setTimeout(function () { showBsModal('modal-qr-msg'); }, 400);

            return;
        }

        showBsModal('modal-qr-msg');
    }

    /**
     * 取後端訊息：優先 message，其次第一筆驗證錯誤
     */
    function errorMessage(error, fallback) {
        if (error && error.errors) {
            var first = Object.keys(error.errors)[0];
            if (first && error.errors[first].length) { return error.errors[first][0]; }
        }
        if (error && error.message) { return error.message; }

        return fallback;
    }

    function findCategory(id) {
        return categories.filter(function (c) { return c.id === id; })[0] || null;
    }

    // ===== 載入 =====

    function loadAll() {
        apiFetch('/admin/quick-reply/ajax-list')
            .then(function (body) {
                categories = body.data || body;
                renderCategories();
                renderItems();
            })
            .catch(function (error) {
                document.getElementById('qr-category-list').innerHTML =
                    '<div class="text-center text-danger py-4">' +
                    escapeHtml(errorMessage(error, i18n.msg.load_failed)) + '</div>';
            });
    }

    // ===== 類別 =====

    function renderCategories() {
        var container = document.getElementById('qr-category-list');

        if (!categories.length) {
            container.innerHTML = '<div class="text-center text-muted py-4">' +
                escapeHtml(i18n.no_categories) + '</div>';

            return;
        }

        var html = '';
        categories.forEach(function (category, index) {
            var active = category.id === selectedCategoryId ? ' bg-light' : '';
            // 狀態放名稱前面：這欄有 text-truncate，放後面遇到長名稱會被截掉
            var disabled = category.status ? '' :
                '<span class="badge bg-secondary me-1">' + escapeHtml(i18n.status_disabled) + '</span>';

            html += '<div class="px-3 py-2 border-bottom js-qr-category' + active + '" ' +
                'data-id="' + category.id + '" style="cursor:pointer">' +
                '<div class="d-flex align-items-center">' +
                '<div class="flex-fill text-truncate">' + disabled + escapeHtml(category.label) + '</div>' +
                '<small class="text-muted ms-2" style="flex-shrink:0">' +
                escapeHtml(i18n.item_count.replace(':count', category.items.length)) + '</small>' +
                '</div>';

            // 左欄較窄，操作鈕另起一行才放得下文字
            if (canEdit) {
                html += '<div class="d-flex flex-wrap gap-1 mt-2">' +
                    moveButtons('category', category.id, index === 0, index === categories.length - 1) +
                    editButtons('category', category.id) +
                    '</div>';
            }

            html += '</div>';
        });

        container.innerHTML = html;

        bindAll(container, '.js-qr-category', function (el, event) {
            // 點到操作按鈕時不切換選取
            if (event.target.closest('button')) { return; }
            selectedCategoryId = parseInt(el.dataset.id, 10);
            renderCategories();
            renderItems();
        });

        if (!canEdit) { return; }

        bindAll(container, '.js-qr-edit-category', function (btn) {
            openCategoryModal(findCategory(parseInt(btn.dataset.id, 10)));
        });
        bindAll(container, '.js-qr-del-category', function (btn) {
            confirmDelete(i18n.confirm_delete_category,
                '/admin/quick-reply/ajax-delete-category/' + btn.dataset.id);
        });
        bindMove(container, 'category');
    }

    function openCategoryModal(category) {
        document.getElementById('modal-qr-category-title').textContent =
            category ? i18n.action_edit : i18n.action_add_category;
        document.getElementById('qr-category-id').value = category ? category.id : '';
        document.getElementById('qr-category-label').value = category ? category.label : '';
        document.getElementById('qr-category-status').checked = category ? !!category.status : true;
        showBsModal('modal-qr-category');
    }

    document.getElementById('form-qr-category').addEventListener('submit', function (e) {
        e.preventDefault();

        var id = document.getElementById('qr-category-id').value;
        var payload = {
            label: document.getElementById('qr-category-label').value.trim(),
            status: document.getElementById('qr-category-status').checked ? 1 : 0,
        };

        var url = id ? '/admin/quick-reply/ajax-update-category/' + id : '/admin/quick-reply/ajax-store-category';
        apiFetch(url, { method: id ? 'PUT' : 'POST', body: JSON.stringify(payload) })
            .then(function (body) {
                hideBsModal(document.getElementById('modal-qr-category'));
                loadAll();
                showMsg(body.message);
            })
            .catch(function (error) {
                showMsg(errorMessage(error, i18n.msg.category_create_failed));
            });
    });

    // ===== 問答 =====

    function renderItems() {
        var container = document.getElementById('qr-item-list');
        var title = document.getElementById('qr-item-title');
        var addBtn = document.getElementById('btn-add-item');
        var category = findCategory(selectedCategoryId);

        if (!category) {
            title.textContent = i18n.select_category;
            container.innerHTML = '<div class="text-center text-muted py-4">' +
                escapeHtml(i18n.select_category) + '</div>';
            if (addBtn) { addBtn.style.display = 'none'; }

            return;
        }

        title.textContent = category.label;
        if (addBtn) { addBtn.style.display = ''; }

        if (!category.items.length) {
            container.innerHTML = '<div class="text-center text-muted py-4">' +
                escapeHtml(i18n.no_items) + '</div>';

            return;
        }

        var html = '';
        category.items.forEach(function (item, index) {
            var disabled = item.status ? '' :
                '<span class="badge bg-secondary me-1">' + escapeHtml(i18n.status_disabled) + '</span>';

            html += '<div class="px-3 py-2 border-bottom">' +
                '<div class="fw-bold" style="font-size:0.875rem">' + disabled + escapeHtml(item.label) + '</div>' +
                '<div class="text-muted mt-1" style="font-size:0.8125rem;white-space:pre-wrap">' +
                escapeHtml(item.answer) + '</div>';

            if (canEdit) {
                html += '<div class="d-flex flex-wrap gap-1 mt-2">' +
                    moveButtons('item', item.id, index === 0, index === category.items.length - 1) +
                    editButtons('item', item.id) +
                    '</div>';
            }

            html += '</div>';
        });

        container.innerHTML = html;

        if (!canEdit) { return; }

        bindAll(container, '.js-qr-edit-item', function (btn) {
            var id = parseInt(btn.dataset.id, 10);
            var found = category.items.filter(function (i) { return i.id === id; })[0];
            openItemModal(found);
        });
        bindAll(container, '.js-qr-del-item', function (btn) {
            confirmDelete(i18n.confirm_delete_item, '/admin/quick-reply/ajax-delete-item/' + btn.dataset.id);
        });
        bindMove(container, 'item');
    }

    function openItemModal(item) {
        document.getElementById('modal-qr-item-title').textContent =
            item ? i18n.action_edit : i18n.action_add_item;
        document.getElementById('qr-item-id').value = item ? item.id : '';
        document.getElementById('qr-item-label').value = item ? item.label : '';
        document.getElementById('qr-item-answer').value = item ? item.answer : '';
        document.getElementById('qr-item-status').checked = item ? !!item.status : true;

        var select = document.getElementById('qr-item-category');
        select.innerHTML = categories.map(function (c) {
            return '<option value="' + c.id + '">' + escapeHtml(c.label) + '</option>';
        }).join('');
        select.value = item ? item.category_id : (selectedCategoryId || '');

        showBsModal('modal-qr-item');
    }

    document.getElementById('form-qr-item').addEventListener('submit', function (e) {
        e.preventDefault();

        var id = document.getElementById('qr-item-id').value;
        var payload = {
            category_id: parseInt(document.getElementById('qr-item-category').value, 10),
            label: document.getElementById('qr-item-label').value.trim(),
            answer: document.getElementById('qr-item-answer').value.trim(),
            status: document.getElementById('qr-item-status').checked ? 1 : 0,
        };

        var url = id ? '/admin/quick-reply/ajax-update-item/' + id : '/admin/quick-reply/ajax-store-item';
        apiFetch(url, { method: id ? 'PUT' : 'POST', body: JSON.stringify(payload) })
            .then(function (body) {
                hideBsModal(document.getElementById('modal-qr-item'));
                selectedCategoryId = payload.category_id;
                loadAll();
                showMsg(body.message);
            })
            .catch(function (error) {
                showMsg(errorMessage(error, i18n.msg.item_create_failed));
            });
    });

    // ===== 排序 =====

    /**
     * @param {string}  type     category / item
     * @param {number}  id
     * @param {boolean} isFirst  已在頂端就不給上移
     * @param {boolean} isLast   已在底端就不給下移
     * @returns {string}
     */
    function moveButtons(type, id, isFirst, isLast) {
        return '<button class="btn btn-sm btn-outline-secondary js-qr-move" data-type="' + type + '" ' +
            'data-id="' + id + '" data-direction="up"' + (isFirst ? ' disabled' : '') + '>' +
            '<i class="fas fa-arrow-up me-1"></i>' + escapeHtml(i18n.action_move_up) + '</button>' +
            '<button class="btn btn-sm btn-outline-secondary js-qr-move" data-type="' + type + '" ' +
            'data-id="' + id + '" data-direction="down"' + (isLast ? ' disabled' : '') + '>' +
            '<i class="fas fa-arrow-down me-1"></i>' + escapeHtml(i18n.action_move_down) + '</button>';
    }

    /**
     * 編輯 / 刪除按鈕（皆帶文字說明）
     *
     * @param {string} type category / item
     * @param {number} id
     * @returns {string}
     */
    function editButtons(type, id) {
        return '<button class="btn btn-sm btn-outline-secondary js-qr-edit-' + type + '" data-id="' + id + '">' +
            '<i class="fas fa-pen me-1"></i>' + escapeHtml(i18n.action_edit) + '</button>' +
            '<button class="btn btn-sm btn-outline-danger js-qr-del-' + type + '" data-id="' + id + '">' +
            '<i class="fas fa-trash me-1"></i>' + escapeHtml(i18n.action_delete) + '</button>';
    }

    function bindMove(container, type) {
        bindAll(container, '.js-qr-move[data-type="' + type + '"]', function (btn) {
            var url = '/admin/quick-reply/ajax-move-' + type + '/' + btn.dataset.id;
            apiFetch(url, {
                method: 'PUT',
                body: JSON.stringify({ direction: btn.dataset.direction }),
            })
                .then(function () { loadAll(); })
                .catch(function (error) { showMsg(errorMessage(error, i18n.msg.sort_failed)); });
        });
    }

    // ===== 刪除確認 =====

    function confirmDelete(text, url) {
        pendingDelete = url;
        document.getElementById('modal-qr-confirm-text').textContent = text;
        showBsModal('modal-qr-confirm');
    }

    document.getElementById('btn-qr-confirm-ok').addEventListener('click', function () {
        if (!pendingDelete) { return; }

        var url = pendingDelete;
        pendingDelete = null;
        hideBsModal(document.getElementById('modal-qr-confirm'));

        apiFetch(url, { method: 'DELETE' })
            .then(function (body) {
                loadAll();
                showMsg(body.message);
            })
            .catch(function (error) {
                showMsg(errorMessage(error, i18n.msg.category_delete_failed));
            });
    });

    // ===== 共用綁定 =====

    /**
     * @param {Element}  container
     * @param {string}   selector
     * @param {Function} handler 收到 (元素, 事件)
     */
    function bindAll(container, selector, handler) {
        container.querySelectorAll(selector).forEach(function (el) {
            el.addEventListener('click', function (event) { handler(el, event); });
        });
    }

    // ===== 啟動 =====

    var addCategoryBtn = document.getElementById('btn-add-category');
    if (addCategoryBtn) {
        addCategoryBtn.addEventListener('click', function () { openCategoryModal(null); });
    }

    var addItemBtn = document.getElementById('btn-add-item');
    if (addItemBtn) {
        addItemBtn.addEventListener('click', function () { openItemModal(null); });
    }

    loadAll();
})();
