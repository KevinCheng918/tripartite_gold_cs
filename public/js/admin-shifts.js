/******/ (() => { // webpackBootstrap
var __webpack_exports__ = {};
/*!**************************************!*\
  !*** ./resources/js/admin/shifts.js ***!
  \**************************************/
(function () {
  var root = document.getElementById('shift-app');
  if (!root) {
    return;
  }
  var i18n = JSON.parse(root.dataset.i18n);
  var currentUserId = parseInt(root.dataset.userId, 10);
  var csrfToken = document.querySelector('meta[name="csrf-token"]').content;

  // ---------------------------------------------------------------
  //  共用工具
  // ---------------------------------------------------------------

  /**
   * API 請求封裝（含 CSRF token）
   *
   * @param {string} url
   * @param {object} options
   * @return {Promise}
   */
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

  /**
   * 開啟 Modal
   *
   * @param {string} id Modal element ID
   */
  function openModal(id) {
    var el = document.getElementById(id);
    if (el) {
      el.style.display = 'flex';
    }
  }

  /**
   * 關閉 Modal
   *
   * @param {string} id Modal element ID
   */
  function closeModal(id) {
    var el = document.getElementById(id);
    if (el) {
      el.style.display = 'none';
    }
  }

  /**
   * 顯示訊息提示 Modal
   *
   * @param {string} message
   */
  function showMessage(message) {
    var textEl = document.getElementById('modal-message-text');
    var headerEl = document.querySelector('#modal-message .modal-header h3');
    if (textEl) {
      textEl.textContent = message;
    }
    if (headerEl) {
      headerEl.textContent = '';
    }
    openModal('modal-message');
  }

  /** 綁定所有 modal 的關閉按鈕 */
  function bindModalCloseButtons() {
    document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var overlay = btn.closest('.modal-overlay');
        if (overlay) {
          overlay.style.display = 'none';
        }
      });
    });

    // 點擊遮罩關閉
    document.querySelectorAll('.modal-overlay').forEach(function (overlay) {
      overlay.addEventListener('click', function (e) {
        if (e.target === overlay) {
          overlay.style.display = 'none';
        }
      });
    });
  }

  // ---------------------------------------------------------------
  //  狀態
  // ---------------------------------------------------------------

  var activeTab = 'shifts';
  var shiftsData = [];

  /** 換班狀態文字對應 */
  var swapStatusMap = {};
  swapStatusMap[0] = {
    text: i18n.status_pending,
    css: 'badge--pending'
  };
  swapStatusMap[1] = {
    text: i18n.status_approved,
    css: 'badge--approved'
  };
  swapStatusMap[2] = {
    text: i18n.status_rejected,
    css: 'badge--rejected'
  };

  // ---------------------------------------------------------------
  //  Tabs
  // ---------------------------------------------------------------

  /** 渲染 Tab 列 */
  function renderTabs() {
    var tabs = [{
      key: 'shifts',
      label: i18n.tab_shifts
    }, {
      key: 'assignments',
      label: i18n.tab_assignments
    }, {
      key: 'swaps',
      label: i18n.tab_swaps
    }];
    var html = '<div class="tabs">';
    tabs.forEach(function (tab) {
      var cls = tab.key === activeTab ? 'active' : '';
      html += '<button class="' + cls + '" data-tab="' + tab.key + '">' + tab.label + '</button>';
    });
    html += '</div><div id="tab-content"></div>';
    root.innerHTML = html;
    root.querySelectorAll('.tabs button').forEach(function (btn) {
      btn.addEventListener('click', function () {
        activeTab = btn.dataset.tab;
        renderTabs();
        loadTabContent();
      });
    });
    loadTabContent();
  }

  /** 依當前 tab 載入內容 */
  function loadTabContent() {
    if (activeTab === 'shifts') {
      loadShifts();
    } else if (activeTab === 'assignments') {
      loadAssignments();
    } else if (activeTab === 'swaps') {
      loadSwaps();
    }
  }

  // ---------------------------------------------------------------
  //  班別設定 Tab
  // ---------------------------------------------------------------

  /** 載入班別列表 */
  function loadShifts() {
    apiFetch('/admin/shifts/ajax-shift-list').then(function (body) {
      shiftsData = body.data;
      renderShiftsTable(body.data);
    })["catch"](function () {
      document.getElementById('tab-content').innerHTML = '<p>Failed to load shifts.</p>';
    });
  }

  /**
   * 渲染班別表格
   *
   * @param {Array} shifts
   */
  function renderShiftsTable(shifts) {
    var rows = shifts.map(function (shift) {
      return '<tr data-id="' + shift.id + '">' + '<td>' + shift.display_name + '</td>' + '<td>' + shift.start_time + '</td>' + '<td>' + shift.end_time + '</td>' + '<td>' + (shift.is_active ? i18n.field_is_active : '-') + '</td>' + '<td><button class="js-edit-shift">' + i18n.modal_edit_shift_title + '</button></td>' + '</tr>';
    }).join('');
    var html = '<table><thead><tr>' + '<th>' + i18n.field_display_name + '</th>' + '<th>' + i18n.field_start_time + '</th>' + '<th>' + i18n.field_end_time + '</th>' + '<th>' + i18n.field_is_active + '</th>' + '<th></th>' + '</tr></thead><tbody>' + rows + '</tbody></table>';
    document.getElementById('tab-content').innerHTML = html;

    // 綁定編輯按鈕
    root.querySelectorAll('.js-edit-shift').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var tr = btn.closest('tr');
        var id = parseInt(tr.dataset.id, 10);
        var shift = shiftsData.filter(function (s) {
          return s.id === id;
        })[0];
        if (shift) {
          openEditShiftModal(shift);
        }
      });
    });
  }

  /**
   * 開啟編輯班別 Modal
   *
   * @param {object} shift
   */
  function openEditShiftModal(shift) {
    document.getElementById('edit-shift-id').value = shift.id;
    document.getElementById('edit-display-name').value = shift.display_name;
    document.getElementById('edit-start-time').value = shift.start_time.substring(0, 5);
    document.getElementById('edit-end-time').value = shift.end_time.substring(0, 5);
    openModal('modal-edit-shift');
  }

  // ---------------------------------------------------------------
  //  排班紀錄 Tab
  // ---------------------------------------------------------------

  /** 載入排班紀錄 */
  function loadAssignments() {
    apiFetch('/admin/shifts/ajax-assignment-list').then(function (body) {
      renderAssignmentsTable(body.data);
    })["catch"](function () {
      document.getElementById('tab-content').innerHTML = '<p>Failed to load assignments.</p>';
    });
  }

  /**
   * 渲染排班紀錄表格
   *
   * @param {Array} assignments
   */
  function renderAssignmentsTable(assignments) {
    var rows = assignments.map(function (a) {
      var userName = a.user ? a.user.nickname : '-';
      var shiftName = a.shift ? a.shift.display_name : '-';
      return '<tr data-id="' + a.id + '">' + '<td>' + a.date + '</td>' + '<td>' + userName + '</td>' + '<td>' + shiftName + '</td>' + '<td>' + a.created_at + '</td>' + '</tr>';
    }).join('');
    var html = '<button id="js-open-assign">' + i18n.action_assign + '</button>' + ' <button id="js-open-swap">' + i18n.action_swap + '</button>' + '<table><thead><tr>' + '<th>' + i18n.field_date + '</th>' + '<th>' + i18n.field_user + '</th>' + '<th>' + i18n.field_shift + '</th>' + '<th></th>' + '</tr></thead><tbody>' + rows + '</tbody></table>';
    document.getElementById('tab-content').innerHTML = html;
    document.getElementById('js-open-assign').addEventListener('click', function () {
      populateAssignShiftSelect();
      openModal('modal-assign');
    });
    document.getElementById('js-open-swap').addEventListener('click', function () {
      openModal('modal-swap');
    });
  }

  /** 填充報班 Modal 的班別選單 */
  function populateAssignShiftSelect() {
    var select = document.getElementById('assign-shift');
    var activeShifts = shiftsData.filter(function (s) {
      return s.is_active;
    });
    if (activeShifts.length === 0) {
      // 先取一次班別資料
      apiFetch('/admin/shifts/ajax-shift-list').then(function (body) {
        shiftsData = body.data;
        fillSelect(select, shiftsData.filter(function (s) {
          return s.is_active;
        }));
      });
    } else {
      fillSelect(select, activeShifts);
    }
  }

  /**
   * 填充 select 選單
   *
   * @param {HTMLSelectElement} select
   * @param {Array} shifts
   */
  function fillSelect(select, shifts) {
    select.innerHTML = shifts.map(function (s) {
      return '<option value="' + s.id + '">' + s.display_name + '（' + s.start_time + ' - ' + s.end_time + '）</option>';
    }).join('');
  }

  // ---------------------------------------------------------------
  //  換班請求 Tab
  // ---------------------------------------------------------------

  /** 載入我的換班請求 */
  function loadSwaps() {
    apiFetch('/admin/shifts/ajax-my-swaps').then(function (body) {
      renderSwapsTable(body.data);
    })["catch"](function () {
      document.getElementById('tab-content').innerHTML = '<p>Failed to load swaps.</p>';
    });
  }

  /**
   * 渲染換班請求表格
   *
   * @param {Array} swaps
   */
  function renderSwapsTable(swaps) {
    var rows = swaps.map(function (swap) {
      var requester = swap.requester ? swap.requester.nickname : '-';
      var target = swap.target ? swap.target.nickname : '-';
      var reqShift = swap.requester_assignment && swap.requester_assignment.shift ? swap.requester_assignment.shift.display_name : '-';
      var tgtShift = swap.target_assignment && swap.target_assignment.shift ? swap.target_assignment.shift.display_name : '-';
      var statusInfo = swapStatusMap[swap.status] || {
        text: '-',
        css: ''
      };
      var actions = '';

      // 只有被換班方且狀態為待確認時可以回應
      if (swap.status === 0 && swap.target_id === currentUserId) {
        actions = '<button class="js-respond-swap btn-primary" data-id="' + swap.id + '" data-status="1">' + i18n.action_approve + '</button> ' + '<button class="js-respond-swap" data-id="' + swap.id + '" data-status="2">' + i18n.action_reject + '</button>';
      }
      return '<tr>' + '<td>' + requester + '</td>' + '<td>' + reqShift + '</td>' + '<td>' + target + '</td>' + '<td>' + tgtShift + '</td>' + '<td><span class="badge ' + statusInfo.css + '">' + statusInfo.text + '</span></td>' + '<td>' + actions + '</td>' + '</tr>';
    }).join('');
    var html = '<table><thead><tr>' + '<th>發起方</th><th>發起方班別</th>' + '<th>對方</th><th>對方班別</th>' + '<th>狀態</th><th></th>' + '</tr></thead><tbody>' + rows + '</tbody></table>';
    document.getElementById('tab-content').innerHTML = html;

    // 綁定回應按鈕
    root.querySelectorAll('.js-respond-swap').forEach(function (btn) {
      btn.addEventListener('click', function () {
        respondSwap(parseInt(btn.dataset.id, 10), parseInt(btn.dataset.status, 10));
      });
    });
  }

  // ---------------------------------------------------------------
  //  API 操作
  // ---------------------------------------------------------------

  /** 提交報班 */
  function submitAssign(e) {
    e.preventDefault();
    var form = document.getElementById('form-assign');
    var data = {
      shift_id: parseInt(form.querySelector('[name="shift_id"]').value, 10),
      date: form.querySelector('[name="date"]').value
    };
    apiFetch('/admin/shifts/ajax-assign', {
      method: 'POST',
      body: JSON.stringify(data)
    }).then(function () {
      closeModal('modal-assign');
      showMessage(i18n.assigned);
      loadAssignments();
    })["catch"](function (error) {
      var msg = error.message || '';
      if (error.errors) {
        var keys = Object.keys(error.errors);
        msg = error.errors[keys[0]][0];
      }
      showMessage(msg || 'Failed');
    });
  }

  /** 提交換班請求 */
  function submitSwap(e) {
    e.preventDefault();
    var form = document.getElementById('form-swap');
    var data = {
      requester_assignment_id: parseInt(form.querySelector('[name="requester_assignment_id"]').value, 10),
      target_assignment_id: parseInt(form.querySelector('[name="target_assignment_id"]').value, 10)
    };
    apiFetch('/admin/shifts/ajax-request-swap', {
      method: 'POST',
      body: JSON.stringify(data)
    }).then(function () {
      closeModal('modal-swap');
      showMessage(i18n.swap_requested);
      loadSwaps();
    })["catch"](function (error) {
      var msg = error.message || '';
      if (error.errors) {
        var keys = Object.keys(error.errors);
        msg = error.errors[keys[0]][0];
      }
      showMessage(msg || 'Failed');
    });
  }

  /** 提交編輯班別 */
  function submitEditShift(e) {
    e.preventDefault();
    var id = document.getElementById('edit-shift-id').value;
    var data = {
      display_name: document.getElementById('edit-display-name').value,
      start_time: document.getElementById('edit-start-time').value,
      end_time: document.getElementById('edit-end-time').value
    };
    apiFetch('/admin/shifts/ajax-update-shift/' + id, {
      method: 'PUT',
      body: JSON.stringify(data)
    }).then(function () {
      closeModal('modal-edit-shift');
      showMessage(i18n.shift_updated);
      loadShifts();
    })["catch"](function (error) {
      var msg = error.message || '';
      if (error.errors) {
        var keys = Object.keys(error.errors);
        msg = error.errors[keys[0]][0];
      }
      showMessage(msg || 'Failed');
    });
  }

  /**
   * 回應換班請求
   *
   * @param {number} swapId
   * @param {number} status 1=同意, 2=拒絕
   */
  function respondSwap(swapId, status) {
    apiFetch('/admin/shifts/ajax-respond-swap/' + swapId, {
      method: 'PUT',
      body: JSON.stringify({
        status: status
      })
    }).then(function () {
      var msg = status === 1 ? i18n.swap_approved : i18n.swap_rejected;
      showMessage(msg);
      loadSwaps();
    })["catch"](function (error) {
      var msg = error.message || '';
      if (error.errors) {
        var keys = Object.keys(error.errors);
        msg = error.errors[keys[0]][0];
      }
      showMessage(msg || 'Failed');
    });
  }

  // ---------------------------------------------------------------
  //  初始化
  // ---------------------------------------------------------------

  bindModalCloseButtons();

  // 綁定表單提交
  document.getElementById('form-assign').addEventListener('submit', submitAssign);
  document.getElementById('form-swap').addEventListener('submit', submitSwap);
  document.getElementById('form-edit-shift').addEventListener('submit', submitEditShift);

  // 載入班別資料後渲染 tabs
  apiFetch('/admin/shifts/ajax-shift-list').then(function (body) {
    shiftsData = body.data;
    renderTabs();
  })["catch"](function () {
    root.innerHTML = '<p>Failed to initialize.</p>';
  });
})();
/******/ })()
;