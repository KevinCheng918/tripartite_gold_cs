/**
 * 內勤管理 — 消耗品分頁
 *
 * 領用（進）與使用（出）的流水，以及每人每品項的剩餘。
 *
 * **品項沒有清單表**，登記時直接打名稱 —— 已經用過的名稱會變成輸入建議
 * （datalist），但不限制只能選那些。
 *
 * 可見範圍由後端決定：沒有 `staff_manage.consumable_view_all` 的人，
 * 不管前端怎麼送，拿到的都只有自己的 —— 這裡的「不顯示人員篩選」
 * 只是不要給他一個按了沒用的東西，不是安全機制。
 *
 * Modal 一律用 JS 建（比照 telegram-chat 的做法），blade 不放版面。
 */
(function () {
    var root = document.getElementById('tab-consumable');

    if (!root) { return; }

    var I18N = JSON.parse(root.dataset.i18n || '{}');
    var MSG = I18N.msg || {};
    var ME = parseInt(root.dataset.me, 10) || 0;
    var CAN_EDIT = root.dataset.canEdit === '1';
    var CAN_LOG = root.dataset.canLog === '1';

    var TYPE_ISSUE = 1;
    var TYPE_USE = 2;

    var csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    var state = {
        loaded: false,
        balances: [],
        itemNames: [],   // 已經用過的品項名稱，當輸入建議
        users: [],       // 可以登記給誰（後端給，不是從 balances 推導）
        canViewAll: false,
        filterUser: '',
        filterItem: '',
        /*
         * 展開中的那一組，存 { userId, itemName } 而不是索引 ——
         * 篩選一改索引就跟著變，但「展開的是誰的哪個品項」不該變。
         */
        expanded: null,
        visible: [],     // 這次畫出來的列，給 data-idx 對照用
    };

    // ---------------------------------------------------------------
    //  載入
    // ---------------------------------------------------------------

    function load() {
        setBody('<div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin"></i></div>');

        apiGet('/admin/staff-manage/ajax-consumable-overview')
            .then(function (body) {
                state.balances = body.balances || [];
                state.itemNames = body.item_names || [];
                state.users = body.users || [];
                state.canViewAll = !!body.can_view_all;
                state.loaded = true;
                renderToolbar();
                render();
            })
            .catch(function () {
                setBody('<div class="text-center text-danger py-4">' + esc(MSG.action_failed) + '</div>');
            });
    }

    /**
     * 工具列：篩選 + 登記按鈕
     *
     * 人員篩選只在看得到所有人時才出現 —— 只看得到自己的人，那個下拉
     * 永遠只有一個選項。
     */
    function renderToolbar() {
        var html = '';

        if (state.canViewAll) {
            html += '<select class="form-select form-select-sm w-auto" id="consumable-filter-user">' +
                '<option value="">' + esc(I18N.consumable_all_users) + '</option>' +
                state.users.map(function (u) {
                    return '<option value="' + u.id + '">' + esc(u.nickname) + '</option>';
                }).join('') +
                '</select>';
        }

        html += '<select class="form-select form-select-sm w-auto" id="consumable-filter-item">' +
            '<option value="">' + esc(I18N.consumable_all_items) + '</option>' +
            state.itemNames.map(function (name) {
                return '<option value="' + esc(name) + '">' + esc(name) + '</option>';
            }).join('') +
            '</select>';

        html += '<div class="ms-auto">';

        if (CAN_LOG) {
            html += '<button class="btn btn-sm btn-primary" id="consumable-btn-add">' +
                '<i class="fas fa-plus me-1"></i>' + esc(I18N.consumable_action_log) + '</button>';
        }

        html += '</div>';

        document.getElementById('consumable-toolbar').innerHTML = html;
        bindToolbar();
    }

    // ---------------------------------------------------------------
    //  總覽
    // ---------------------------------------------------------------

    function render() {
        state.visible = state.balances.filter(function (b) {
            if (state.filterUser && String(b.user_id) !== state.filterUser) { return false; }
            if (state.filterItem && b.item_name !== state.filterItem) { return false; }

            return true;
        });

        if (!state.visible.length) {
            setBody('<div class="text-center text-muted py-4">' + esc(I18N.consumable_empty) + '</div>');

            return;
        }

        // 同一個人的品項收在一起，人名只出現一次
        var order = [];
        var grouped = {};

        state.visible.forEach(function (b, idx) {
            if (!grouped[b.user_id]) {
                grouped[b.user_id] = { name: b.user_name, rows: [] };
                order.push(b.user_id);
            }

            grouped[b.user_id].rows.push({ data: b, idx: idx });
        });

        var html = '';

        order.forEach(function (userId) {
            var group = grouped[userId];

            html += '<div class="mb-3">' +
                '<div class="fw-bold mb-2" style="font-size:0.9375rem">' +
                '<i class="fas fa-user me-1 text-muted"></i>' + esc(group.name) + '</div>' +
                '<div class="list-group">';

            group.rows.forEach(function (row) {
                var b = row.data;
                var open = isExpanded(b);

                /*
                 * data-idx 而不是把品項名稱塞進 data-key —— 名稱是使用者自己
                 * 打的字串，裡面可能有引號或 CSS 選擇器的特殊字元，
                 * 當成選擇器用會直接壞掉。索引對照 state.visible 就沒這問題。
                 */
                html += '<div class="list-group-item js-consumable-row" data-idx="' + row.idx + '" style="cursor:pointer">' +
                    '<div class="d-flex align-items-center justify-content-between flex-wrap gap-2">' +
                    '<span style="font-size:0.875rem">' +
                    '<i class="fas fa-chevron-' + (open ? 'down' : 'right') + ' me-2 text-muted"></i>' +
                    esc(b.item_name) + '</span>' +
                    '<span class="d-flex gap-3" style="font-size:0.8125rem">' +
                    '<span class="text-muted">' + esc(I18N.consumable_issued) + ' ' + b.issued + '</span>' +
                    '<span class="text-muted">' + esc(I18N.consumable_used) + ' ' + b.used + '</span>' +
                    '<span class="' + balanceClass(b.balance) + '">' +
                    esc(I18N.consumable_balance) + ' <strong>' + b.balance + '</strong></span>' +
                    '</span></div>' +
                    '<div class="mt-2 js-consumable-detail" data-idx="' + row.idx + '"' +
                    (open ? '' : ' style="display:none"') + '></div>' +
                    '</div>';
            });

            html += '</div></div>';
        });

        setBody(html);
        bindRows();

        // 重新整理後把原本展開的那組再打開，不然每次登記完都會收合
        state.visible.forEach(function (b, idx) {
            if (isExpanded(b)) { loadDetail(idx); }
        });
    }

    function isExpanded(row) {
        return !!state.expanded
            && state.expanded.userId === row.user_id
            && state.expanded.itemName === row.item_name;
    }

    /**
     * 剩餘的顏色：0 要警告、負數是不該發生的狀態
     *
     * 負數理論上進不來（登記與刪除都擋了），真的出現就是有 bug，
     * 標紅讓人看得見而不是默默算下去。
     */
    function balanceClass(balance) {
        if (balance < 0) { return 'text-danger fw-bold'; }

        return balance === 0 ? 'text-warning' : 'text-success';
    }

    // ---------------------------------------------------------------
    //  流水明細
    // ---------------------------------------------------------------

    function loadDetail(idx) {
        var row = state.visible[idx];
        var box = document.querySelector('.js-consumable-detail[data-idx="' + idx + '"]');

        if (!row || !box) { return; }

        box.innerHTML = '<div class="text-muted py-2" style="font-size:0.8125rem">' +
            '<i class="fas fa-spinner fa-spin me-1"></i></div>';

        apiGet('/admin/staff-manage/ajax-consumable-records?user_id=' + encodeURIComponent(row.user_id) +
            '&item_name=' + encodeURIComponent(row.item_name))
            .then(function (body) {
                var records = (body && body.data) || [];

                box.innerHTML = buildDetail(records);

                /*
                 * 把這批紀錄掛在 DOM 節點上，編輯時直接拿得到整筆 ——
                 * 不然按編輯只有一個 id，還要再打一次 API 才知道原本的值。
                 */
                box.__records = {};
                records.forEach(function (r) { box.__records[r.id] = r; });

                bindDetail(box);
            })
            .catch(function () {
                box.innerHTML = '<div class="text-danger py-2" style="font-size:0.8125rem">' +
                    esc(MSG.action_failed) + '</div>';
            });
    }

    function buildDetail(records) {
        if (!records.length) {
            return '<div class="text-muted py-2" style="font-size:0.8125rem">' +
                esc(I18N.consumable_no_records) + '</div>';
        }

        return '<div class="border-top pt-2">' + records.map(function (r) {
            var badge = r.is_issue
                ? '<span class="badge bg-success">' + esc(I18N.consumable_type_issue) + '</span>'
                : '<span class="badge bg-secondary">' + esc(I18N.consumable_type_use) + '</span>';

            // 自己的隨時能改；別人的要有管理權限
            var canTouch = CAN_LOG && (r.user_id === ME || CAN_EDIT);

            var actions = canTouch
                ? '<button class="btn btn-sm btn-link text-muted p-0 ms-2 js-consumable-edit" data-id="' + r.id + '">' +
                  '<i class="fas fa-pen"></i></button>' +
                  '<button class="btn btn-sm btn-link text-danger p-0 ms-2 js-consumable-del" data-id="' + r.id + '">' +
                  '<i class="fas fa-trash"></i></button>'
                : '';

            return '<div class="d-flex align-items-start justify-content-between py-1" style="font-size:0.8125rem">' +
                '<div>' +
                '<span class="text-muted me-2">' + esc(r.happened_at) + '</span>' +
                badge +
                ' <strong class="ms-1">' + r.quantity + '</strong>' +
                (r.note ? '<span class="ms-2">' + esc(r.note) + '</span>' : '') +
                '</div>' +
                '<div class="text-nowrap">' +
                '<span class="text-muted">' + esc(r.created_by) + ' ' + esc(I18N.consumable_recorded_by) + '</span>' +
                actions +
                '</div></div>';
        }).join('') + '</div>';
    }

    // ---------------------------------------------------------------
    //  登記 / 編輯
    // ---------------------------------------------------------------

    function openRecordModal(record) {
        var userOptions = CAN_EDIT
            ? state.users.map(function (u) {
                return '<option value="' + u.id + '">' + esc(u.nickname) + '</option>';
            }).join('')
            : '';

        var html =
            '<div class="mb-3">' +
            '<label class="form-label">' + esc(I18N.consumable_user) + '</label>' +
            (CAN_EDIT
                ? '<select class="form-select" id="consumable-f-user">' + userOptions + '</select>'
                : '<input class="form-control" value="' + esc(myName()) + '" disabled>') +
            '</div>' +

            /*
             * 品項直接輸入。list 掛既有名稱當建議，但輸入框本身不限制 ——
             * 打新的就是新的品項。
             */
            '<div class="mb-3">' +
            '<label class="form-label">' + esc(I18N.consumable_item) + '</label>' +
            '<input class="form-control" id="consumable-f-item" list="consumable-item-options" maxlength="50" autocomplete="off">' +
            '<datalist id="consumable-item-options">' +
            state.itemNames.map(function (name) {
                return '<option value="' + esc(name) + '"></option>';
            }).join('') +
            '</datalist></div>' +

            '<div class="row g-2 mb-3">' +
            '<div class="col-6"><label class="form-label">' + esc(I18N.consumable_type) + '</label>' +
            '<select class="form-select" id="consumable-f-type">' +
            '<option value="' + TYPE_ISSUE + '">' + esc(I18N.consumable_type_issue) + '</option>' +
            '<option value="' + TYPE_USE + '">' + esc(I18N.consumable_type_use) + '</option>' +
            '</select></div>' +
            '<div class="col-6"><label class="form-label">' + esc(I18N.consumable_quantity) + '</label>' +
            '<input type="number" min="1" class="form-control" id="consumable-f-qty" value="1"></div>' +
            '</div>' +

            '<div class="mb-3"><label class="form-label">' + esc(I18N.consumable_date) + '</label>' +
            '<input type="date" class="form-control" id="consumable-f-date"></div>' +

            '<div class="mb-3"><label class="form-label">' + esc(I18N.consumable_note) + '</label>' +
            '<input type="text" class="form-control" id="consumable-f-note" maxlength="255"></div>' +

            '<div id="consumable-form-msg"></div>';

        showModal(esc(I18N.consumable_action_log), html, function () {
            submitRecord(record);
        });

        // 預設今天；編輯時帶入原值
        document.getElementById('consumable-f-date').value = record ? record.happened_at : today();

        if (record) {
            setValue('consumable-f-user', record.user_id);
            setValue('consumable-f-item', record.item_name);
            setValue('consumable-f-type', record.type);
            setValue('consumable-f-qty', record.quantity);
            setValue('consumable-f-note', record.note || '');
        } else {
            if (CAN_EDIT) { setValue('consumable-f-user', ME); }

            // 從展開中的那一組進來時，品項先帶好 —— 多半就是要登記它
            if (state.expanded) { setValue('consumable-f-item', state.expanded.itemName); }
        }
    }

    function submitRecord(record) {
        var payload = {
            user_id: CAN_EDIT ? getValue('consumable-f-user') : ME,
            item_name: getValue('consumable-f-item'),
            type: getValue('consumable-f-type'),
            quantity: getValue('consumable-f-qty'),
            happened_at: getValue('consumable-f-date'),
            note: getValue('consumable-f-note'),
        };

        var url = record
            ? '/admin/staff-manage/ajax-consumable-update/' + record.id
            : '/admin/staff-manage/ajax-consumable-store';

        apiSend(url, record ? 'PUT' : 'POST', payload)
            .then(function (body) {
                hideModal();
                toast(body.message || MSG.consumable_saved);
                load();
            })
            .catch(function (body) {
                formError(pickError(body));
            });
    }

    function deleteRecord(id) {
        confirmModal(I18N.consumable_delete_confirm, function () {
            apiSend('/admin/staff-manage/ajax-consumable-delete/' + id, 'DELETE', {})
                .then(function (body) {
                    hideModal();
                    toast(body.message || MSG.consumable_deleted);
                    load();
                })
                .catch(function (body) {
                    // 刪不掉的理由（會讓餘額變負）要留在畫面上給人看
                    formError(pickError(body));
                });
        });
    }

    // ---------------------------------------------------------------
    //  事件綁定
    // ---------------------------------------------------------------

    function bindToolbar() {
        var user = document.getElementById('consumable-filter-user');
        var item = document.getElementById('consumable-filter-item');
        var add = document.getElementById('consumable-btn-add');

        if (user) {
            user.addEventListener('change', function () {
                state.filterUser = user.value;
                state.expanded = null;
                render();
            });
        }

        if (item) {
            item.addEventListener('change', function () {
                state.filterItem = item.value;
                state.expanded = null;
                render();
            });
        }

        if (add) { add.addEventListener('click', function () { openRecordModal(null); }); }
    }

    function bindRows() {
        document.querySelectorAll('.js-consumable-row').forEach(function (el) {
            el.addEventListener('click', function (e) {
                // 點到明細裡的按鈕時不要收合整列
                if (e.target.closest('.js-consumable-detail')) { return; }

                var row = state.visible[parseInt(el.dataset.idx, 10)];

                if (!row) { return; }

                state.expanded = isExpanded(row)
                    ? null
                    : { userId: row.user_id, itemName: row.item_name };

                render();
            });
        });
    }

    function bindDetail(box) {
        box.querySelectorAll('.js-consumable-edit').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                openRecordModal(box.__records ? box.__records[btn.dataset.id] : null);
            });
        });

        box.querySelectorAll('.js-consumable-del').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                deleteRecord(btn.dataset.id);
            });
        });
    }

    // ---------------------------------------------------------------
    //  小工具
    // ---------------------------------------------------------------

    /**
     * 自己的名字（沒有管理權限時，登記視窗的人員欄顯示它）
     *
     * 從 state.users 取而不是 balances —— 後者只有「已經有紀錄的人」，
     * 第一次登記時那裡還是空的。
     */
    function myName() {
        var mine = state.users.filter(function (u) { return u.id === ME; })[0];

        return mine ? mine.nickname : '';
    }

    function setBody(html) {
        document.getElementById('consumable-body').innerHTML = html;
    }

    function esc(text) {
        var div = document.createElement('div');
        div.textContent = text === undefined || text === null ? '' : text;

        return div.innerHTML;
    }

    function today() {
        var d = new Date();
        var m = String(d.getMonth() + 1).padStart(2, '0');
        var day = String(d.getDate()).padStart(2, '0');

        return d.getFullYear() + '-' + m + '-' + day;
    }

    function getValue(id) {
        var el = document.getElementById(id);

        return el ? el.value : '';
    }

    function setValue(id, value) {
        var el = document.getElementById(id);

        if (el) { el.value = value; }
    }

    function formError(message) {
        var box = document.getElementById('consumable-form-msg');

        if (!box) { return; }

        box.innerHTML = message
            ? '<div class="alert alert-danger py-2 mb-0" style="font-size:0.8125rem">' + esc(message) + '</div>'
            : '';
    }

    function pickError(body) {
        if (body && body.message) { return body.message; }
        if (body && body.errors) {
            var first = Object.keys(body.errors)[0];

            return body.errors[first][0];
        }

        return MSG.action_failed;
    }

    // ---------------------------------------------------------------
    //  API
    // ---------------------------------------------------------------

    function apiGet(url) {
        return fetch(url, { headers: { Accept: 'application/json' } }).then(handle);
    }

    function apiSend(url, method, payload) {
        return fetch(url, {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify(payload),
        }).then(handle);
    }

    function handle(res) {
        return res.json().then(function (body) {
            if (!res.ok) { return Promise.reject(body); }

            return body;
        });
    }

    // ---------------------------------------------------------------
    //  Modal（一律用 modal，不用 alert / confirm）
    // ---------------------------------------------------------------

    function ensureModal() {
        var el = document.getElementById('modal-consumable');

        if (el) { return el; }

        el = document.createElement('div');
        el.className = 'modal fade';
        el.id = 'modal-consumable';
        el.tabIndex = -1;
        el.innerHTML =
            '<div class="modal-dialog modal-dialog-scrollable">' +
            '<div class="modal-content">' +
            '<div class="modal-header"><h5 class="modal-title"></h5>' +
            '<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>' +
            '<div class="modal-body"></div>' +
            '<div class="modal-footer"></div>' +
            '</div></div>';
        document.body.appendChild(el);

        return el;
    }

    function showModal(title, html, onConfirm) {
        var el = ensureModal();

        el.querySelector('.modal-title').innerHTML = title;
        el.querySelector('.modal-body').innerHTML = html;

        var footer = el.querySelector('.modal-footer');
        footer.innerHTML = onConfirm
            ? '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">' +
              esc(I18N.action_cancel || 'Cancel') + '</button>' +
              '<button type="button" class="btn btn-primary" id="consumable-modal-ok">' +
              esc(I18N.action_save || 'OK') + '</button>'
            : '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">' +
              esc(I18N.action_cancel || 'Close') + '</button>';

        if (onConfirm) {
            footer.querySelector('#consumable-modal-ok').addEventListener('click', onConfirm);
        }

        new bootstrap.Modal(el).show();
    }

    function hideModal() {
        var el = document.getElementById('modal-consumable');

        if (!el) { return; }

        var instance = bootstrap.Modal.getInstance(el);

        if (instance) { instance.hide(); }
    }

    function confirmModal(text, onYes) {
        showModal('', '<p class="mb-0">' + esc(text) + '</p><div id="consumable-form-msg" class="mt-2"></div>', onYes);
    }

    function toast(message) {
        if (window.showMessage) {
            window.showMessage(message);

            return;
        }

        // 這頁原本的訊息 modal；沒有就退回 showModal
        var box = document.getElementById('modal-staff-msg-text');

        if (box && window.showBsModal) {
            box.textContent = message;
            window.showBsModal('modal-staff-msg');

            return;
        }

        showModal('', '<p class="mb-0">' + esc(message) + '</p>', null);
    }

    // ---------------------------------------------------------------
    //  啟動：切到這個分頁才載入
    // ---------------------------------------------------------------

    var tabBtn = document.getElementById('tab-btn-consumable');

    if (tabBtn) {
        tabBtn.addEventListener('shown.bs.tab', function () {
            if (!state.loaded) { load(); }
        });
    }
})();
