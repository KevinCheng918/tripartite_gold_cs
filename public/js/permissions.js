(function () {
    var root = document.getElementById('permissions-app');
    if (!root) { return; }

    var userId = parseInt(root.dataset.userId, 10);
    var i18n = JSON.parse(root.dataset.i18n);
    var permI18n = JSON.parse(root.dataset.permissionI18n || '{}');
    var csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    /** 權限地圖資料 */
    var permissionMapData = [];

    /** 目前已有的權限 */
    var currentKeywords = [];

    // ---------------------------------------------------------------
    //  共用工具
    // ---------------------------------------------------------------

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

    function openModal(id) {
        var el = document.getElementById(id);
        if (el) { new bootstrap.Modal(el).show(); }
    }

    function showMessage(message) {
        var el = document.getElementById('modal-perm-message-text');
        if (el) { el.textContent = message; }
        openModal('modal-perm-message');
    }

    function getErrorMessage(error) {
        if (error.errors) {
            var keys = Object.keys(error.errors);
            return error.errors[keys[0]][0];
        }
        return error.message || 'Failed';
    }

    // 綁定 modal 關閉
    document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var overlay = btn.closest('.modal-overlay');
            if (overlay) { overlay.style.display = 'none'; }
        });
    });
    document.querySelectorAll('.modal-overlay').forEach(function (overlay) {
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) { overlay.style.display = 'none'; }
        });
    });

    // ---------------------------------------------------------------
    //  載入資料
    // ---------------------------------------------------------------

    function loadData() {
        Promise.all([
            apiFetch('/admin/accounts/ajax-permission-map'),
            apiFetch('/admin/accounts/ajax-list?per_page=1000')
        ])
            .then(function (results) {
                permissionMapData = Array.isArray(results[0]) ? results[0] : (results[0].data || []);

                // 找到目標帳號的權限
                var accounts = results[1].data || [];
                var target = accounts.filter(function (a) { return a.id === userId; })[0];
                currentKeywords = target ? (target.permission_keywords || []) : [];

                renderTree();
            })
            .catch(function () {
                root.innerHTML = '<p>Failed to load data.</p>';
            });
    }

    // ---------------------------------------------------------------
    //  渲染權限樹
    // ---------------------------------------------------------------

    /**
     * 判斷一個 keyword 是否為 view 類（父節點）
     *
     * @param {string} keyword
     * @return {boolean}
     */
    function isViewKeyword(keyword) {
        return keyword.indexOf('.view') !== -1;
    }

    function renderTree() {
        var html = '<div class="perm-tree">';
        html += '<div class="perm-tree__header">';
        html += '<a href="/admin/accounts" class="btn-sm">&lsaquo; ' + (i18n.nav_label || '返回') + '</a>';
        html += '<button class="btn-primary" id="btn-save-perms">' + (i18n.action_save || '儲存') + '</button>';
        html += '</div>';

        permissionMapData.forEach(function (group, gIdx) {
            var groupId = 'tree-grp-' + gIdx;

            // 找出 view keyword（父節點）和其他（子節點）
            var viewKw = null;
            var childKws = [];
            group.keywords.forEach(function (item) {
                if (isViewKeyword(item.keyword)) {
                    viewKw = item;
                } else {
                    childKws.push(item);
                }
            });

            var viewChecked = viewKw && currentKeywords.indexOf(viewKw.keyword) !== -1;

            html += '<div class="perm-tree-group" id="' + groupId + '">';

            // 群組標題 + 全選
            html += '<div class="perm-tree-group__header">';
            html += '<span class="perm-tree-group__title">' + group.label + '</span>';
            html += '<button type="button" class="perm-group__toggle-all" data-group="' + groupId + '">全選</button>';
            html += '</div>';

            // 父節點（view）
            if (viewKw) {
                html += '<div class="perm-tree-item perm-tree-item--parent">';
                html += '<label class="perm-tree-label">';
                html += '<input type="checkbox" class="perm-tree-checkbox perm-tree-parent" data-group="' + groupId + '" name="permissions[]" value="' + viewKw.keyword + '"' + (viewChecked ? ' checked' : '') + '>';
                html += '<span class="perm-tree-icon">👁</span>';
                html += '<span>' + viewKw.label + '</span>';
                html += '</label>';
                html += '</div>';
            }

            // 子節點容器（view 沒勾就隱藏）
            var childDisplay = viewKw && !viewChecked ? 'none' : 'flex';
            html += '<div class="perm-tree-children" id="' + groupId + '-children" style="display:' + childDisplay + '">';

            childKws.forEach(function (item) {
                var checked = currentKeywords.indexOf(item.keyword) !== -1;

                html += '<div class="perm-tree-card' + (checked ? ' perm-tree-card--checked' : '') + '">';
                html += '<label class="perm-tree-card-label">';
                html += '<input type="checkbox" class="perm-tree-checkbox perm-tree-child" data-parent-group="' + groupId + '" name="permissions[]" value="' + item.keyword + '"' + (checked ? ' checked' : '') + '>';
                html += '<span>' + item.label + '</span>';
                html += '</label>';
                html += '</div>';
            });

            html += '</div>';

            html += '</div>';
        });

        html += '</div>';
        root.innerHTML = html;

        // 綁定事件
        bindTreeEvents();
    }

    function bindTreeEvents() {
        // 父節點（view）切換 → 顯示/隱藏子節點
        root.querySelectorAll('.perm-tree-parent').forEach(function (parentCb) {
            parentCb.addEventListener('change', function () {
                var groupId = parentCb.dataset.group;
                var childrenContainer = document.getElementById(groupId + '-children');

                if (parentCb.checked) {
                    childrenContainer.style.display = 'flex';
                } else {
                    // 取消所有子節點勾選並隱藏
                    childrenContainer.querySelectorAll('.perm-tree-child').forEach(function (cb) {
                        cb.checked = false;
                        cb.closest('.perm-tree-card').classList.remove('perm-tree-card--checked');
                    });
                    childrenContainer.style.display = 'none';
                }
            });
        });

        // 子節點 checkbox 變化 → 切換卡片樣式
        root.querySelectorAll('.perm-tree-child').forEach(function (cb) {
            cb.addEventListener('change', function () {
                var card = cb.closest('.perm-tree-card');
                if (cb.checked) {
                    card.classList.add('perm-tree-card--checked');
                } else {
                    card.classList.remove('perm-tree-card--checked');
                }
            });
        });

        // 全選按鈕
        root.querySelectorAll('.perm-group__toggle-all').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var group = document.getElementById(btn.dataset.group);
                var parentCb = group.querySelector('.perm-tree-parent');
                var childrenContainer = document.getElementById(btn.dataset.group + '-children');
                var children = childrenContainer ? childrenContainer.querySelectorAll('.perm-tree-child') : [];
                var allCbs = group.querySelectorAll('.perm-tree-checkbox');
                var allChecked = Array.prototype.every.call(allCbs, function (cb) { return cb.checked; });
                var newState = !allChecked;

                // 父節點
                if (parentCb) {
                    parentCb.checked = newState;
                    parentCb.dispatchEvent(new Event('change'));
                }

                // 子節點
                children.forEach(function (cb) {
                    cb.checked = newState;
                    var card = cb.closest('.perm-tree-card');
                    if (newState) {
                        card.classList.add('perm-tree-card--checked');
                    } else {
                        card.classList.remove('perm-tree-card--checked');
                    }
                });

                btn.textContent = newState ? '取消全選' : '全選';
            });
        });

        // 儲存按鈕
        document.getElementById('btn-save-perms').addEventListener('click', savePermissions);
    }

    function savePermissions() {
        var checkboxes = root.querySelectorAll('.perm-tree-checkbox:checked');
        var keywords = [];
        checkboxes.forEach(function (cb) { keywords.push(cb.value); });

        apiFetch('/admin/accounts/ajax-assign-permissions/' + userId, {
            method: 'POST',
            body: JSON.stringify({ permissions: keywords }),
        })
            .then(function () {
                showMessage(i18n.updated || '權限已儲存');
            })
            .catch(function (error) {
                showMessage(getErrorMessage(error));
            });
    }

    // 初始化
    loadData();
})();
