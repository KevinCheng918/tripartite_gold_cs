(function () {
    const root = document.getElementById('role-app');
    if (!root) {
        return;
    }

    const i18n = JSON.parse(root.dataset.i18n);
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    function apiFetch(url, options) {
        options = options || {};
        options.headers = Object.assign({
            'X-CSRF-TOKEN': csrfToken,
            'Content-Type': 'application/json',
            Accept: 'application/json',
        }, options.headers || {});

        return fetch(url, options).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok) {
                    throw body;
                }
                return body;
            });
        });
    }

    function renderTable(roles) {
        const rows = roles
            .map(function (role) {
                return (
                    '<tr data-id="' + role.id + '">' +
                    '<td>' + role.name + '</td>' +
                    '<td>' + role.display_name + '</td>' +
                    '<td>' + (role.is_active ? i18n.field_is_active : '') + '</td>' +
                    '<td>' + (role.permission_keywords || []).join(', ') + '</td>' +
                    '<td>' +
                    '<button class="js-assign-permissions">' + i18n.action_assign_permissions + '</button> ' +
                    '<button class="js-delete">' + i18n.action_delete + '</button>' +
                    '</td>' +
                    '</tr>'
                );
            })
            .join('');

        root.innerHTML =
            '<button id="js-create-role">' + i18n.action_create + '</button>' +
            '<table><thead><tr>' +
            '<th>' + i18n.field_name + '</th><th>' + i18n.field_display_name + '</th>' +
            '<th>' + i18n.field_is_active + '</th><th>Permissions</th><th></th>' +
            '</tr></thead><tbody>' + rows + '</tbody></table>';

        document.getElementById('js-create-role').addEventListener('click', createRole);
        root.querySelectorAll('.js-delete').forEach(function (button) {
            button.addEventListener('click', function () {
                deleteRole(button.closest('tr').dataset.id);
            });
        });
        root.querySelectorAll('.js-assign-permissions').forEach(function (button) {
            button.addEventListener('click', function () {
                assignPermissions(button.closest('tr').dataset.id);
            });
        });
    }

    function loadRoles() {
        apiFetch('/admin/roles/ajax-list')
            .then(function (body) {
                renderTable(body.data);
            })
            .catch(function () {
                root.innerHTML = '<p>Failed to load roles.</p>';
            });
    }

    function createRole() {
        const name = prompt(i18n.field_name + ' (slug)');
        if (!name) {
            return;
        }
        const displayName = prompt(i18n.field_display_name);

        apiFetch('/admin/roles/ajax-store', {
            method: 'POST',
            body: JSON.stringify({ name: name, display_name: displayName }),
        })
            .then(loadRoles)
            .catch(function (error) {
                alert(error.message || 'Failed to create role.');
            });
    }

    function deleteRole(id) {
        if (!confirm(i18n.action_delete + '?')) {
            return;
        }

        apiFetch('/admin/roles/ajax-delete/' + id, { method: 'DELETE' })
            .then(loadRoles)
            .catch(function (error) {
                alert(error.message || i18n.delete_blocked_in_use);
            });
    }

    function assignPermissions(id) {
        apiFetch('/admin/roles/ajax-permission-map')
            .then(function (body) {
                const allKeywords = [];
                body.forEach(function (group) {
                    group.keywords.forEach(function (item) {
                        allKeywords.push(item.keyword);
                    });
                });

                const input = prompt('Permission keywords, comma separated:\n' + allKeywords.join(', '));
                if (input === null) {
                    return;
                }

                const keywords = input
                    .split(',')
                    .map(function (value) {
                        return value.trim();
                    })
                    .filter(Boolean);

                return apiFetch('/admin/roles/ajax-assign-permissions/' + id, {
                    method: 'POST',
                    body: JSON.stringify({ permissions: keywords }),
                });
            })
            .then(loadRoles)
            .catch(function (error) {
                if (error) {
                    alert(error.message || 'Failed to assign permissions.');
                }
            });
    }

    loadRoles();
})();
