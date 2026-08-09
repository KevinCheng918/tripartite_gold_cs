(function () {
    var root = document.getElementById('broadcast-app');
    if (!root) { return; }

    var i18n = JSON.parse(root.dataset.i18n);
    var csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    var activeTab = 'send';
    var groupsData = [];

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

    function showMessage(message) {
        var el = document.getElementById('modal-broadcast-msg-text');
        if (el) { el.textContent = message; }
        showBsModal('modal-broadcast-msg');
    }

    // modal 關閉
    });
    });

    // ---------------------------------------------------------------
    //  Tabs
    // ---------------------------------------------------------------

    function renderPage() {
        var tabs = [
            { key: 'send', label: i18n.tab_send },
            { key: 'history', label: i18n.tab_history },
        ];

        var html = '<div class="tabs">';
        tabs.forEach(function (t) {
            html += '<button class="' + (t.key === activeTab ? 'active' : '') + '" data-tab="' + t.key + '">' + t.label + '</button>';
        });
        html += '</div><div id="bc-content"></div>';

        root.innerHTML = html;

        root.querySelectorAll('.tabs button').forEach(function (btn) {
            btn.addEventListener('click', function () {
                activeTab = btn.dataset.tab;
                renderPage();
            });
        });

        if (activeTab === 'send') { renderSendForm(); }
        else { loadHistory(); }
    }

    // ---------------------------------------------------------------
    //  發送公告
    // ---------------------------------------------------------------

    function renderSendForm() {
        var content = document.getElementById('bc-content');

        var html =
            '<div class="bc-form">' +
            '<div class="form-group">' +
            '<label>' + i18n.field_content + '</label>' +
            '<textarea id="bc-content-input" rows="5" placeholder="' + i18n.field_content + '..." style="width:100%;resize:vertical;padding:0.5rem 0.75rem;font-size:0.9375rem;border:1px solid #ebedf0;border-radius:0.5rem;font-family:inherit;"></textarea>' +
            '</div>' +
            '<div class="form-group">' +
            '<label>' + i18n.field_target + '</label>' +
            '<div class="bc-target-options">' +
            '<label class="assign-user-cb"><input type="radio" name="target_type" value="1" checked> ' + i18n.target_all + '</label>' +
            '<label class="assign-user-cb"><input type="radio" name="target_type" value="2"> ' + i18n.target_selected + '</label>' +
            '</div>' +
            '</div>' +
            '<div class="form-group" id="bc-group-list-wrap" style="display:none">' +
            '<label>' + i18n.field_groups + '</label>' +
            '<div id="bc-group-checkboxes" class="assign-user-checkboxes"></div>' +
            '</div>' +
            '<button class="btn-primary" id="btn-bc-send">' + i18n.btn_send + '</button>' +
            '</div>';

        content.innerHTML = html;

        // target_type 切換
        content.querySelectorAll('[name="target_type"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                var wrap = document.getElementById('bc-group-list-wrap');
                if (radio.value === '2') {
                    wrap.style.display = 'block';
                    loadGroups();
                } else {
                    wrap.style.display = 'none';
                }
            });
        });

        document.getElementById('btn-bc-send').addEventListener('click', sendBroadcast);
    }

    function loadGroups() {
        if (groupsData.length > 0) {
            renderGroupCheckboxes(groupsData);
            return;
        }

        apiFetch('/admin/telegram-broadcast/ajax-groups')
            .then(function (body) {
                groupsData = body;
                renderGroupCheckboxes(body);
            });
    }

    function renderGroupCheckboxes(groups) {
        var container = document.getElementById('bc-group-checkboxes');
        container.innerHTML = groups.map(function (g) {
            return '<label class="assign-user-cb">' +
                '<input type="checkbox" name="group_ids[]" value="' + g.id + '">' +
                '<span>' + g.title + '</span>' +
                '</label>';
        }).join('');
    }

    function sendBroadcast() {
        var contentInput = document.getElementById('bc-content-input');
        var content = contentInput.value.trim();

        if (!content) {
            showMessage(i18n.msg.content_required);
            return;
        }

        var targetType = parseInt(document.querySelector('[name="target_type"]:checked').value, 10);
        var data = { content: content, target_type: targetType };

        if (targetType === 2) {
            var checked = document.querySelectorAll('[name="group_ids[]"]:checked');
            if (checked.length === 0) {
                showMessage(i18n.msg.no_group_selected);
                return;
            }
            data.group_ids = [];
            checked.forEach(function (cb) { data.group_ids.push(parseInt(cb.value, 10)); });
        }

        var btn = document.getElementById('btn-bc-send');
        btn.disabled = true;
        btn.textContent = '發送中...';

        apiFetch('/admin/telegram-broadcast/ajax-send', {
            method: 'POST',
            body: JSON.stringify(data),
        })
            .then(function (body) {
                btn.disabled = false;
                btn.textContent = i18n.btn_send;
                contentInput.value = '';
                showMessage(body.message || '已發送');
            })
            .catch(function (error) {
                btn.disabled = false;
                btn.textContent = i18n.btn_send;
                showMessage(error.message || i18n.msg.send_failed);
            });
    }

    // ---------------------------------------------------------------
    //  歷史紀錄
    // ---------------------------------------------------------------

    function loadHistory() {
        apiFetch('/admin/telegram-broadcast/ajax-history')
            .then(function (body) {
                renderHistory(body.data || []);
            })
            .catch(function () {
                document.getElementById('bc-content').innerHTML = '<p>Failed to load history.</p>';
            });
    }

    function renderHistory(records) {
        var content = document.getElementById('bc-content');

        if (records.length === 0) {
            content.innerHTML = '<p class="dash-empty">' + i18n.no_history + '</p>';
            return;
        }

        var rows = records.map(function (r) {
            var sender = r.sender ? r.sender.nickname : '-';
            var time = r.sent_at ? r.sent_at.substring(0, 16) : '-';
            var target = r.target_type === 1 ? i18n.target_all : i18n.target_selected;
            var preview = r.content.length > 50 ? r.content.substring(0, 50) + '...' : r.content;

            return (
                '<tr>' +
                '<td>' + time + '</td>' +
                '<td>' + sender + '</td>' +
                '<td>' + target + '</td>' +
                '<td>' + preview + '</td>' +
                '<td>' + r.total_count + '</td>' +
                '<td><span class="badge bg-success">' + r.success_count + '</span></td>' +
                '<td>' + (r.fail_count > 0 ? '<span class="badge bg-danger">' + r.fail_count + '</span>' : '0') + '</td>' +
                '</tr>'
            );
        }).join('');

        content.innerHTML =
            '<table><thead><tr>' +
            '<th>' + i18n.field_time + '</th>' +
            '<th>' + i18n.field_sender + '</th>' +
            '<th>' + i18n.field_target + '</th>' +
            '<th>' + i18n.field_content + '</th>' +
            '<th>' + i18n.field_total + '</th>' +
            '<th>' + i18n.field_success + '</th>' +
            '<th>' + i18n.field_fail + '</th>' +
            '</tr></thead><tbody>' + rows + '</tbody></table>';
    }

    // 初始化
    renderPage();
})();
