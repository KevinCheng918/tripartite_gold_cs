/******/ (() => { // webpackBootstrap
var __webpack_exports__ = {};
/*!****************************************!*\
  !*** ./resources/js/admin/accounts.js ***!
  \****************************************/
(function () {
  var root = document.getElementById('account-app');
  if (!root) {
    return;
  }
  var i18n = JSON.parse(root.dataset.i18n);
  var csrfToken = document.querySelector('meta[name="csrf-token"]').content;
  function apiFetch(url, options) {
    options = options || {};
    options.headers = Object.assign({
      'X-CSRF-TOKEN': csrfToken,
      'Content-Type': 'application/json',
      Accept: 'application/json'
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
  function renderTable(accounts) {
    var rows = accounts.map(function (account) {
      var roles = account.roles.map(function (role) {
        return role.display_name;
      }).join(', ');
      return '<tr data-id="' + account.id + '">' + '<td>' + account.name + '</td>' + '<td>' + account.email + '</td>' + '<td>' + (account.status === 1 ? i18n.status_active : i18n.status_disabled) + '</td>' + '<td>' + roles + '</td>' + '<td>' + '<button class="js-assign-roles">' + i18n.action_assign_roles + '</button> ' + '<button class="js-delete">' + i18n.action_delete + '</button>' + '</td>' + '</tr>';
    }).join('');
    root.innerHTML = '<button id="js-create-account">' + i18n.action_create + '</button>' + '<table><thead><tr>' + '<th>' + i18n.field_name + '</th><th>' + i18n.field_email + '</th>' + '<th>' + i18n.field_status + '</th><th>' + i18n.field_roles + '</th><th></th>' + '</tr></thead><tbody>' + rows + '</tbody></table>';
    document.getElementById('js-create-account').addEventListener('click', createAccount);
    root.querySelectorAll('.js-delete').forEach(function (button) {
      button.addEventListener('click', function () {
        deleteAccount(button.closest('tr').dataset.id);
      });
    });
    root.querySelectorAll('.js-assign-roles').forEach(function (button) {
      button.addEventListener('click', function () {
        assignRoles(button.closest('tr').dataset.id);
      });
    });
  }
  function loadAccounts() {
    apiFetch('/admin/accounts/ajax-list').then(function (body) {
      renderTable(body.data);
    })["catch"](function () {
      root.innerHTML = '<p>Failed to load accounts.</p>';
    });
  }
  function createAccount() {
    var name = prompt(i18n.field_name);
    if (!name) {
      return;
    }
    var email = prompt(i18n.field_email);
    var password = prompt(i18n.field_password);
    apiFetch('/admin/accounts/ajax-store', {
      method: 'POST',
      body: JSON.stringify({
        name: name,
        email: email,
        password: password
      })
    }).then(loadAccounts)["catch"](function (error) {
      alert(error.message || 'Failed to create account.');
    });
  }
  function deleteAccount(id) {
    if (!confirm(i18n.action_delete + '?')) {
      return;
    }
    apiFetch('/admin/accounts/ajax-delete/' + id, {
      method: 'DELETE'
    }).then(loadAccounts)["catch"](function (error) {
      alert(error.message || 'Failed to delete account.');
    });
  }
  function assignRoles(id) {
    var input = prompt('Role IDs, comma separated');
    if (input === null) {
      return;
    }
    var roleIds = input.split(',').map(function (value) {
      return parseInt(value.trim(), 10);
    }).filter(function (value) {
      return !isNaN(value);
    });
    apiFetch('/admin/accounts/ajax-assign-roles/' + id, {
      method: 'POST',
      body: JSON.stringify({
        role_ids: roleIds
      })
    }).then(loadAccounts)["catch"](function (error) {
      alert(error.message || 'Failed to assign roles.');
    });
  }
  loadAccounts();
})();
/******/ })()
;