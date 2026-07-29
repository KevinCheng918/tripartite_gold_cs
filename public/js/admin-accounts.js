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
  var permI18n = JSON.parse(root.dataset.permissionI18n);
  var csrfToken = document.querySelector('meta[name="csrf-token"]').content;

  /** 所有帳號資料 */
  var accountsData = [];

  /** 權限地圖資料 */
  var permissionMapData = [];

  // ---------------------------------------------------------------
  //  共用工具
  // ---------------------------------------------------------------

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
  function openModal(id) {
    var el = document.getElementById(id);
    if (el) {
      el.style.display = 'flex';
    }
  }
  function closeModal(id) {
    var el = document.getElementById(id);
    if (el) {
      el.style.display = 'none';
    }
  }
  function showMessage(message) {
    var textEl = document.getElementById('modal-account-message-text');
    if (textEl) {
      textEl.textContent = message;
    }
    openModal('modal-account-message');
  }
  function getErrorMessage(error) {
    if (error.errors) {
      var keys = Object.keys(error.errors);
      return error.errors[keys[0]][0];
    }
    return error.message || 'Failed';
  }
  function bindModalCloseButtons() {
    document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var overlay = btn.closest('.modal-overlay');
        if (overlay) {
          overlay.style.display = 'none';
        }
      });
    });
    document.querySelectorAll('.modal-overlay').forEach(function (overlay) {
      overlay.addEventListener('click', function (e) {
        if (e.target === overlay) {
          overlay.style.display = 'none';
        }
      });
    });
  }

  /**
   * 將 keyword 轉換為翻譯名稱
   *
   * @param {string} keyword
   * @return {string}
   */
  function translateKeyword(keyword) {
    var parts = keyword.split('.');
    if (parts.length === 2 && permI18n[parts[0]] && permI18n[parts[0]][parts[1]]) {
      return permI18n[parts[0]][parts[1]];
    }
    return keyword;
  }

  // ---------------------------------------------------------------
  //  帳號列表
  // ---------------------------------------------------------------

  function loadAccounts() {
    apiFetch('/admin/accounts/ajax-list').then(function (body) {
      accountsData = body.data;
      renderTable(body.data);
    })["catch"](function () {
      root.innerHTML = '<p>Failed to load accounts.</p>';
    });
  }

  /** 狀態對應的 badge */
  var statusMap = {};
  statusMap[1] = {
    text: i18n.status_normal,
    css: 'badge--active'
  };
  statusMap[2] = {
    text: i18n.status_lock,
    css: 'badge--pending'
  };
  statusMap[0] = {
    text: i18n.status_deactivate,
    css: 'badge--rejected'
  };
  function renderTable(accounts) {
    var rows = accounts.map(function (account) {
      var levelLabel = account.level === 0 ? '<span class="badge badge--approved">' + i18n.level_admin + '</span>' : '<span class="badge badge--pending">' + i18n.level_cs + '</span>';
      var statusInfo = statusMap[account.status] || {
        text: '-',
        css: ''
      };
      var perms = (account.permission_keywords || []).map(function (kw) {
        return '<span class="badge badge--active">' + translateKeyword(kw) + '</span>';
      }).join(' ');

      // 管理者不顯示操作按鈕
      var actions = '';
      if (account.level !== 0) {
        actions = '<button class="btn-sm js-edit">' + i18n.action_edit + '</button> ' + '<button class="btn-sm js-change-status">' + i18n.action_change_status + '</button> ' + '<button class="btn-sm js-assign-perms">' + i18n.action_assign_permissions + '</button>';
      }
      return '<tr data-id="' + account.id + '">' + '<td>' + account.account + '</td>' + '<td>' + account.nickname + '</td>' + '<td><span class="badge ' + statusInfo.css + '">' + statusInfo.text + '</span></td>' + '<td>' + levelLabel + '</td>' + '<td class="td-permissions">' + (account.level === 0 ? '-' : perms || '-') + '</td>' + '<td class="td-actions">' + actions + '</td>' + '</tr>';
    }).join('');
    root.innerHTML = '<button class="btn-primary" id="js-create-account">' + i18n.action_create + '</button>' + '<table><thead><tr>' + '<th>' + i18n.field_account + '</th>' + '<th>' + i18n.field_nickname + '</th>' + '<th>' + i18n.field_status + '</th>' + '<th>' + i18n.field_level + '</th>' + '<th>' + i18n.action_assign_permissions + '</th>' + '<th></th>' + '</tr></thead><tbody>' + rows + '</tbody></table>';
    document.getElementById('js-create-account').addEventListener('click', function () {
      document.getElementById('create-account').value = '';
      document.getElementById('create-nickname').value = '';
      document.getElementById('create-password').value = '';
      openModal('modal-create-account');
    });
    root.querySelectorAll('.js-edit').forEach(function (btn) {
      btn.addEventListener('click', function () {
        openEditModal(parseInt(btn.closest('tr').dataset.id, 10));
      });
    });
    root.querySelectorAll('.js-change-status').forEach(function (btn) {
      btn.addEventListener('click', function () {
        openChangeStatusModal(parseInt(btn.closest('tr').dataset.id, 10));
      });
    });
    root.querySelectorAll('.js-assign-perms').forEach(function (btn) {
      btn.addEventListener('click', function () {
        openAssignPermissionsModal(parseInt(btn.closest('tr').dataset.id, 10));
      });
    });
  }

  // ---------------------------------------------------------------
  //  新增帳號
  // ---------------------------------------------------------------

  function submitCreateAccount(e) {
    e.preventDefault();
    var data = {
      account: document.getElementById('create-account').value,
      nickname: document.getElementById('create-nickname').value,
      password: document.getElementById('create-password').value
    };
    apiFetch('/admin/accounts/ajax-store', {
      method: 'POST',
      body: JSON.stringify(data)
    }).then(function () {
      closeModal('modal-create-account');
      showMessage(i18n.created);
      loadAccounts();
    })["catch"](function (error) {
      showMessage(getErrorMessage(error));
    });
  }

  // ---------------------------------------------------------------
  //  編輯帳號
  // ---------------------------------------------------------------

  function openEditModal(id) {
    var account = accountsData.filter(function (a) {
      return a.id === id;
    })[0];
    if (!account) {
      return;
    }
    document.getElementById('edit-account-id').value = id;
    document.getElementById('edit-nickname').value = account.nickname;
    document.getElementById('edit-password').value = '';
    openModal('modal-edit-account');
  }
  function submitEditAccount(e) {
    e.preventDefault();
    var id = document.getElementById('edit-account-id').value;
    var data = {
      nickname: document.getElementById('edit-nickname').value
    };
    var password = document.getElementById('edit-password').value;
    if (password) {
      data.password = password;
    }
    apiFetch('/admin/accounts/ajax-update/' + id, {
      method: 'PUT',
      body: JSON.stringify(data)
    }).then(function () {
      closeModal('modal-edit-account');
      showMessage(i18n.updated);
      loadAccounts();
    })["catch"](function (error) {
      showMessage(getErrorMessage(error));
    });
  }

  // ---------------------------------------------------------------
  //  調整狀態
  // ---------------------------------------------------------------

  function openChangeStatusModal(id) {
    var account = accountsData.filter(function (a) {
      return a.id === id;
    })[0];
    if (!account) {
      return;
    }
    document.getElementById('status-user-id').value = id;

    // 預選當前狀態
    var radios = document.querySelectorAll('#form-change-status input[name="status"]');
    radios.forEach(function (radio) {
      radio.checked = parseInt(radio.value, 10) === account.status;
    });
    openModal('modal-change-status');
  }
  function submitChangeStatus(e) {
    e.preventDefault();
    var userId = document.getElementById('status-user-id').value;
    var selected = document.querySelector('#form-change-status input[name="status"]:checked');
    if (!selected) {
      return;
    }
    apiFetch('/admin/accounts/ajax-update/' + userId, {
      method: 'PUT',
      body: JSON.stringify({
        status: parseInt(selected.value, 10)
      })
    }).then(function () {
      closeModal('modal-change-status');
      showMessage(i18n.updated);
      loadAccounts();
    })["catch"](function (error) {
      showMessage(getErrorMessage(error));
    });
  }

  // ---------------------------------------------------------------
  //  設定權限（Checkbox）
  // ---------------------------------------------------------------

  function openAssignPermissionsModal(userId) {
    document.getElementById('assign-perm-user-id').value = userId;
    var account = accountsData.filter(function (a) {
      return a.id === userId;
    })[0];
    var currentKeywords = account ? account.permission_keywords || [] : [];
    if (permissionMapData.length > 0) {
      renderPermissionCheckboxes(currentKeywords);
      openModal('modal-assign-permissions');
    } else {
      apiFetch('/admin/accounts/ajax-permission-map').then(function (body) {
        permissionMapData = Array.isArray(body) ? body : body.data || [];
        renderPermissionCheckboxes(currentKeywords);
        openModal('modal-assign-permissions');
      })["catch"](function () {
        showMessage('Failed to load permission map.');
      });
    }
  }
  function renderPermissionCheckboxes(currentKeywords) {
    var container = document.getElementById('permission-checkbox-list');
    var html = '';
    permissionMapData.forEach(function (group) {
      html += '<div class="perm-group">';
      html += '<h4 class="perm-group__title">' + group.label + '</h4>';
      group.keywords.forEach(function (item) {
        var checked = currentKeywords.indexOf(item.keyword) !== -1 ? 'checked' : '';
        html += '<label class="perm-checkbox">';
        html += '<input type="checkbox" name="permissions[]" value="' + item.keyword + '" ' + checked + '>';
        html += '<span>' + item.label + '</span>';
        html += '</label>';
      });
      html += '</div>';
    });
    container.innerHTML = html;
  }
  function submitAssignPermissions(e) {
    e.preventDefault();
    var userId = document.getElementById('assign-perm-user-id').value;
    var checkboxes = document.querySelectorAll('#permission-checkbox-list input[type="checkbox"]:checked');
    var keywords = [];
    checkboxes.forEach(function (cb) {
      keywords.push(cb.value);
    });
    apiFetch('/admin/accounts/ajax-assign-permissions/' + userId, {
      method: 'POST',
      body: JSON.stringify({
        permissions: keywords
      })
    }).then(function () {
      closeModal('modal-assign-permissions');
      showMessage(i18n.updated);
      loadAccounts();
    })["catch"](function (error) {
      showMessage(getErrorMessage(error));
    });
  }

  // ---------------------------------------------------------------
  //  初始化
  // ---------------------------------------------------------------

  bindModalCloseButtons();
  document.getElementById('form-create-account').addEventListener('submit', submitCreateAccount);
  document.getElementById('form-edit-account').addEventListener('submit', submitEditAccount);
  document.getElementById('form-change-status').addEventListener('submit', submitChangeStatus);
  document.getElementById('form-assign-permissions').addEventListener('submit', submitAssignPermissions);
  loadAccounts();
})();
/******/ })()
;