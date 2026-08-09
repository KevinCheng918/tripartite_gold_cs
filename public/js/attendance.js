(function () {
    var root = document.getElementById('attendance-app');
    if (!root) { return; }

    var i18n = JSON.parse(root.dataset.i18n);
    var currentUserId = parseInt(root.dataset.userId, 10);
    var isAdmin = root.dataset.isAdmin === '1';
    var userPermissions = JSON.parse(root.dataset.permissions || '[]');
    var csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    function hasPerm(keyword) {
        if (userPermissions.indexOf('all') !== -1) { return true; }
        return userPermissions.indexOf(keyword) !== -1;
    }

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
        if (el) { showBsModal(el); }
    }

    function showMessage(message) {
        var el = document.getElementById('modal-attendance-message-text');
        if (el) { el.textContent = message; }
        openModal('modal-attendance-message');
    }

    function getErrorMessage(error) {
        if (error.errors) {
            var keys = Object.keys(error.errors);
            return error.errors[keys[0]][0];
        }
        return error.message || 'Failed';
    }


    /** 出勤狀態對應 */
    var statusMap = {};
    statusMap[0] = { text: i18n.status_incomplete, css: 'bg-warning text-dark' };
    statusMap[1] = { text: i18n.status_normal, css: 'bg-success' };
    statusMap[2] = { text: i18n.status_late, css: 'bg-danger' };
    statusMap[3] = { text: i18n.status_early_leave, css: 'bg-warning text-dark' };
    statusMap[4] = { text: i18n.status_late_early, css: 'bg-danger' };
    statusMap[5] = { text: i18n.status_absent, css: 'bg-danger' };

    // ---------------------------------------------------------------
    //  打卡狀態 + 按鈕
    // ---------------------------------------------------------------

    var activeTab = isAdmin ? 'report' : 'clock';

    function renderPage() {
        var allTabs = [];

        if (!isAdmin && hasPerm('attendance.clock')) {
            allTabs.push({ key: 'clock', label: i18n.btn_clock_in || '打卡' });
        }
        if (!isAdmin && hasPerm('attendance.view')) {
            allTabs.push({ key: 'my_records', label: i18n.tab_my_records });
        }
        if (isAdmin && hasPerm('attendance.report')) {
            allTabs.push({ key: 'report', label: i18n.tab_report });
        }

        var tabs = allTabs.filter(function (t) { return t; });

        var html = '<ul class="nav nav-tabs mb-3">';
        tabs.forEach(function (tab) {
            var cls = tab.key === activeTab ? 'active' : '';
            html += '<li class="nav-item"><button class="nav-link ' + cls + '" data-tab="' + tab.key + '">' + tab.label + '</button></li>';
        });
        html += '</ul><div id="att-content"></div>';

        root.innerHTML = html;

        root.querySelectorAll('.nav-tabs .nav-link').forEach(function (btn) {
            btn.addEventListener('click', function () {
                activeTab = btn.dataset.tab;
                renderPage();
            });
        });

        loadTabContent();
    }

    function loadTabContent() {
        stopLiveClock();
        if (activeTab === 'clock') {
            loadClockStatus();
        } else if (activeTab === 'my_records') {
            loadMyRecords(currentMonth);
        } else if (activeTab === 'report') {
            loadReport(currentMonth);
        }
    }

    // ---------------------------------------------------------------
    //  打卡 Tab（客服用）
    // ---------------------------------------------------------------

    function loadClockStatus() {
        apiFetch('/admin/attendance/ajax-today-status')
            .then(function (body) {
                // Resource 包在 data 裡，未打卡時直接回傳 { status: 'not_clocked' }
                var record = body.data || body;
                renderClockPanel(record);
            })
            .catch(function () {
                document.getElementById('att-content').innerHTML = '<p>Failed to load status.</p>';
            });
    }

    function renderClockPanel(record) {
        var content = document.getElementById('att-content');
        var hasRecord = record && record.id && record.status !== 'not_clocked';
        var clockedIn = hasRecord && record.clock_in !== null && record.clock_in !== undefined;
        var clockedOut = hasRecord && record.clock_out !== null && record.clock_out !== undefined;

        var statusText = '';
        var statusCss = '';

        if (!clockedIn) {
            statusText = i18n.not_clocked;
            statusCss = 'att-status--none';
        } else if (!clockedOut) {
            statusText = i18n.clocked_in;
            statusCss = 'att-status--in';
        } else {
            statusText = i18n.clocked_out;
            statusCss = 'att-status--out';
        }

        var html =
            '<div class="att-clock-panel">' +
            '<div class="att-clock-date" id="att-live-clock"></div>' +
            '<div class="att-clock-status ' + statusCss + '">' +
            '<div class="att-clock-status__icon"></div>' +
            '<div class="att-clock-status__label">' + (i18n.current_status || '目前狀態') + '</div>' +
            '<div class="att-clock-status__text">' + statusText + '</div>' +
            '</div>';

        if (clockedIn) {
            html += '<div class="att-clock-detail">' +
                '<p>' + i18n.field_clock_in + '：' + record.clock_in + '</p>';
            if (record.late_minutes > 0) {
                html += '<p class="att-warn">' + i18n.field_late + '：' + record.late_minutes + ' ' + i18n.unit_minutes + '</p>';
            }
            if (clockedOut) {
                html += '<p>' + i18n.field_clock_out + '：' + record.clock_out + '</p>';
                if (record.early_leave_minutes > 0) {
                    html += '<p class="att-warn">' + i18n.field_early_leave + '：' + record.early_leave_minutes + ' ' + i18n.unit_minutes + '</p>';
                }
                if (record.overtime_minutes > 0) {
                    html += '<p class="att-good">' + i18n.field_overtime + '：' + record.overtime_minutes + ' ' + i18n.unit_minutes + '</p>';
                }
            }
            html += '</div>';
        }

        // 打卡按鈕
        html += '<div class="att-clock-actions">';
        if (!clockedIn) {
            html += '<button class="btn-primary att-clock-btn" id="btn-clock-in">' + i18n.btn_clock_in + '</button>';
        } else if (!clockedOut) {
            html += '<button class="btn-danger-full att-clock-btn" id="btn-clock-out">' + i18n.btn_clock_out + '</button>';
        }
        html += '</div></div>';

        content.innerHTML = html;

        var btnIn = document.getElementById('btn-clock-in');
        if (btnIn) {
            btnIn.addEventListener('click', function () { showClockConfirm('in'); });
        }

        var btnOut = document.getElementById('btn-clock-out');
        if (btnOut) {
            btnOut.addEventListener('click', function () { showClockConfirm('out'); });
        }

        // 即時時鐘
        startLiveClock();
    }

    var liveClockTimer = null;

    function startLiveClock() {
        stopLiveClock();
        updateLiveClock();
        liveClockTimer = setInterval(updateLiveClock, 1000);
    }

    function stopLiveClock() {
        if (liveClockTimer) {
            clearInterval(liveClockTimer);
            liveClockTimer = null;
        }
    }

    function updateLiveClock() {
        var el = document.getElementById('att-live-clock');
        if (!el) {
            stopLiveClock();
            return;
        }
        var now = new Date();
        var dateStr = now.getFullYear() + '-' +
            String(now.getMonth() + 1).padStart(2, '0') + '-' +
            String(now.getDate()).padStart(2, '0');
        var timeStr = String(now.getHours()).padStart(2, '0') + ':' +
            String(now.getMinutes()).padStart(2, '0') + ':' +
            String(now.getSeconds()).padStart(2, '0');
        el.textContent = dateStr + ' ' + timeStr;
    }

    // ---------------------------------------------------------------
    //  二次確認彈窗
    // ---------------------------------------------------------------

    var pendingClockAction = null;

    function showClockConfirm(action) {
        var now = new Date();
        var dateStr = now.getFullYear() + '-' +
            String(now.getMonth() + 1).padStart(2, '0') + '-' +
            String(now.getDate()).padStart(2, '0');
        var timeStr = String(now.getHours()).padStart(2, '0') + ':' +
            String(now.getMinutes()).padStart(2, '0') + ':' +
            String(now.getSeconds()).padStart(2, '0');

        var isIn = action === 'in';
        var actionLabel = isIn ? i18n.btn_clock_in : i18n.btn_clock_out;
        var statusLabel = isIn ? (i18n.not_clocked || '尚未打卡') : (i18n.clocked_in || '已上班打卡');
        var statusCss = isIn ? 'att-confirm-status--none' : 'att-confirm-status--in';

        var body = document.getElementById('modal-attendance-confirm-body');
        body.innerHTML =
            '<div class="att-confirm-content">' +
            '<div class="att-confirm-action ' + (isIn ? 'att-confirm-action--in' : 'att-confirm-action--out') + '">' + actionLabel + '</div>' +
            '<div class="att-confirm-info">' +
            '<div class="att-confirm-row"><span class="att-confirm-label">' + (i18n.current_status || '目前狀態') + '</span><span class="att-confirm-value ' + statusCss + '">' + statusLabel + '</span></div>' +
            '<div class="att-confirm-row"><span class="att-confirm-label">' + (i18n.field_date || '日期') + '</span><span class="att-confirm-value">' + dateStr + '</span></div>' +
            '<div class="att-confirm-row"><span class="att-confirm-label">' + (i18n.msg.clock_time || '打卡時間') + '</span><span class="att-confirm-value">' + timeStr + '</span></div>' +
            '</div>' +
            '<p class="att-confirm-hint">' + (i18n.msg.confirm_hint || '確認後將無法修改，請確認資訊正確') + '</p>' +
            '</div>';

        pendingClockAction = action;
        openModal('modal-attendance-confirm');
    }

    // 綁定確認按鈕
    var confirmBtn = document.getElementById('btn-confirm-clock');
    if (confirmBtn) {
        confirmBtn.addEventListener('click', function () {
            var overlay = document.getElementById('modal-attendance-confirm');
            if (overlay) { overlay.style.display = 'none'; }

            if (pendingClockAction === 'in') {
                clockIn();
            } else if (pendingClockAction === 'out') {
                clockOut();
            }
            pendingClockAction = null;
        });
    }

    function clockIn() {
        apiFetch('/admin/attendance/ajax-clock-in', { method: 'POST' })
            .then(function () {
                showMessage(i18n.msg.clock_in_success);
                loadClockStatus();
            })
            .catch(function (error) { showMessage(getErrorMessage(error)); });
    }

    function clockOut() {
        apiFetch('/admin/attendance/ajax-clock-out', { method: 'POST' })
            .then(function () {
                showMessage(i18n.msg.clock_out_success);
                loadClockStatus();
            })
            .catch(function (error) { showMessage(getErrorMessage(error)); });
    }

    // ---------------------------------------------------------------
    //  我的出勤 Tab
    // ---------------------------------------------------------------

    var currentMonth = new Date().getFullYear() + '-' + String(new Date().getMonth() + 1).padStart(2, '0');

    function getLastMonth() {
        var d = new Date();
        d.setMonth(d.getMonth() - 1);
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
    }

    function loadMyRecords(month) {
        apiFetch('/admin/attendance/ajax-my-monthly?month=' + month)
            .then(function (body) {
                renderMyRecords(body.data || [], month);
            })
            .catch(function () {
                document.getElementById('att-content').innerHTML = '<p>Failed to load records.</p>';
            });
    }

    function renderMyRecords(records, month) {
        var thisMonth = new Date().getFullYear() + '-' + String(new Date().getMonth() + 1).padStart(2, '0');
        var lastMonth = getLastMonth();

        // 月份切換
        var nav =
            '<div class="att-month-nav">' +
            '<button class="btn btn-sm btn-outline-secondary' + (month === lastMonth ? ' active' : '') + '" data-month="' + lastMonth + '">' + i18n.last_month + '（' + lastMonth + '）</button>' +
            '<button class="btn btn-sm btn-outline-secondary' + (month === thisMonth ? ' active' : '') + '" data-month="' + thisMonth + '">' + i18n.this_month + '（' + thisMonth + '）</button>' +
            '</div>';

        // 統計
        var totalDays = records.length;
        var lateCnt = 0, earlyClnt = 0, lateMin = 0, earlyMin = 0, otMin = 0;
        records.forEach(function (r) {
            if (r.status === 2 || r.status === 4) { lateCnt++; }
            if (r.status === 3 || r.status === 4) { earlyClnt++; }
            lateMin += r.late_minutes || 0;
            earlyMin += r.early_leave_minutes || 0;
            otMin += r.overtime_minutes || 0;
        });

        var summary =
            '<div class="dash-cards" style="margin-bottom:1rem">' +
            '<div class="dash-card"><div class="dash-card__label">' + i18n.field_total_days + '</div><div class="dash-card__value">' + totalDays + '</div></div>' +
            '<div class="dash-card dash-card--red"><div class="dash-card__label">' + i18n.field_late_count + '</div><div class="dash-card__value">' + lateCnt + '</div><div class="dash-card__sub">' + lateMin + ' ' + i18n.unit_minutes + '</div></div>' +
            '<div class="dash-card dash-card--yellow"><div class="dash-card__label">' + i18n.field_early_count + '</div><div class="dash-card__value">' + earlyClnt + '</div><div class="dash-card__sub">' + earlyMin + ' ' + i18n.unit_minutes + '</div></div>' +
            '<div class="dash-card dash-card--green"><div class="dash-card__label">' + i18n.field_overtime_total + '</div><div class="dash-card__value">' + otMin + '</div><div class="dash-card__sub">' + i18n.unit_minutes + '</div></div>' +
            '</div>';

        // 每日明細（表格）
        var rows = records.map(function (r) {
            var st = statusMap[r.status] || { text: '-', css: '' };
            return (
                '<tr>' +
                '<td>' + r.date + '</td>' +
                '<td>' + (r.clock_in || '-') + '</td>' +
                '<td>' + (r.clock_out || '-') + '</td>' +
                '<td>' + (r.late_minutes > 0 ? r.late_minutes + ' ' + i18n.unit_minutes : '-') + '</td>' +
                '<td>' + (r.early_leave_minutes > 0 ? r.early_leave_minutes + ' ' + i18n.unit_minutes : '-') + '</td>' +
                '<td>' + (r.overtime_minutes > 0 ? r.overtime_minutes + ' ' + i18n.unit_minutes : '-') + '</td>' +
                '<td><span class="badge ' + st.css + '">' + st.text + '</span></td>' +
                '</tr>'
            );
        }).join('');

        var tableHtml =
            '<table class="att-table-desktop"><thead><tr>' +
            '<th>' + i18n.field_date + '</th>' +
            '<th>' + i18n.field_clock_in + '</th>' +
            '<th>' + i18n.field_clock_out + '</th>' +
            '<th>' + i18n.field_late + '</th>' +
            '<th>' + i18n.field_early_leave + '</th>' +
            '<th>' + i18n.field_overtime + '</th>' +
            '<th>' + i18n.field_status + '</th>' +
            '</tr></thead><tbody>' + rows + '</tbody></table>';

        // 手機版卡片
        var cards = records.map(function (r) {
            var st = statusMap[r.status] || { text: '-', css: '' };
            return (
                '<div class="shift-card">' +
                '<div class="shift-card__header">' +
                '<span class="shift-card__title">' + r.date + '</span>' +
                '<span class="badge ' + st.css + '">' + st.text + '</span>' +
                '</div>' +
                '<div class="shift-card__row"><span class="shift-card__label">' + i18n.field_clock_in + '</span><span>' + (r.clock_in || '-') + '</span></div>' +
                '<div class="shift-card__row"><span class="shift-card__label">' + i18n.field_clock_out + '</span><span>' + (r.clock_out || '-') + '</span></div>' +
                (r.late_minutes > 0 ? '<div class="shift-card__row"><span class="shift-card__label">' + i18n.field_late + '</span><span style="color:#dc2626;font-weight:600">' + r.late_minutes + ' ' + i18n.unit_minutes + '</span></div>' : '') +
                (r.early_leave_minutes > 0 ? '<div class="shift-card__row"><span class="shift-card__label">' + i18n.field_early_leave + '</span><span style="color:#dc2626;font-weight:600">' + r.early_leave_minutes + ' ' + i18n.unit_minutes + '</span></div>' : '') +
                (r.overtime_minutes > 0 ? '<div class="shift-card__row"><span class="shift-card__label">' + i18n.field_overtime + '</span><span style="color:#059669;font-weight:600">' + r.overtime_minutes + ' ' + i18n.unit_minutes + '</span></div>' : '') +
                '</div>'
            );
        }).join('');

        document.getElementById('att-content').innerHTML = nav + summary + table +
            '<div class="shift-cards">' + cards + '</div>';

        // 綁定月份切換
        document.querySelectorAll('.att-month-nav button').forEach(function (btn) {
            btn.addEventListener('click', function () {
                currentMonth = btn.dataset.month;
                loadMyRecords(currentMonth);
            });
        });
    }

    // ---------------------------------------------------------------
    //  月報表 Tab（管理者用）
    // ---------------------------------------------------------------

    function loadReport(month) {
        apiFetch('/admin/attendance/ajax-monthly-report?month=' + month)
            .then(function (body) {
                renderReport(body, month);
            })
            .catch(function () {
                document.getElementById('att-content').innerHTML = '<p>Failed to load report.</p>';
            });
    }

    function renderReport(report, month) {
        // Admin 用 flatpickr 月份選擇器，不限制月份
        var nav =
            '<div class="att-month-nav">' +
            '<input type="text" id="report-month-picker" class="att-month-input" value="' + month + '" readonly autocomplete="off">' +
            '</div>';

        var rows = report.map(function (r) {
            var userName = r.user ? r.user.nickname : '-';
            var userId = r.user ? r.user.id : '';
            return (
                '<tr class="att-report-row js-detail-link" data-user-id="' + userId + '" style="cursor:pointer">' +
                '<td><strong>' + userName + '</strong></td>' +
                '<td>' + r.total_days + '</td>' +
                '<td>' + r.normal_days + '</td>' +
                '<td>' + r.late_count + '<span class="att-sub">' + r.late_total_minutes + ' ' + i18n.unit_minutes + '</span></td>' +
                '<td>' + r.early_count + '<span class="att-sub">' + r.early_total_minutes + ' ' + i18n.unit_minutes + '</span></td>' +
                '<td>' + r.absent_count + '</td>' +
                '<td>' + r.overtime_total_minutes + ' ' + i18n.unit_minutes + '</td>' +
                '</tr>'
            );
        }).join('');

        var tableHtml =
            '<h3>' + i18n.report_title + '（' + month + '）</h3>' +
            '<table class="att-table-desktop"><thead><tr>' +
            '<th>' + i18n.field_user + '</th>' +
            '<th>' + i18n.field_total_days + '</th>' +
            '<th>' + i18n.field_normal_days + '</th>' +
            '<th>' + i18n.field_late_count + '</th>' +
            '<th>' + i18n.field_early_count + '</th>' +
            '<th>' + i18n.field_absent_count + '</th>' +
            '<th>' + i18n.field_overtime_total + '</th>' +
            '</tr></thead><tbody>' + rows + '</tbody></table>';

        // 手機版卡片
        var reportCards = report.map(function (r) {
            var userName = r.user ? r.user.nickname : '-';
            var userId = r.user ? r.user.id : '';
            return (
                '<div class="shift-card js-detail-link" data-user-id="' + userId + '" style="cursor:pointer">' +
                '<div class="shift-card__header">' +
                '<span class="shift-card__title">' + userName + '</span>' +
                '<span class="badge bg-success">' + i18n.field_total_days + ' ' + r.total_days + '</span>' +
                '</div>' +
                '<div class="shift-card__row"><span class="shift-card__label">' + i18n.field_normal_days + '</span><span>' + r.normal_days + '</span></div>' +
                '<div class="shift-card__row"><span class="shift-card__label">' + i18n.field_late_count + '</span><span>' + r.late_count + '（' + r.late_total_minutes + ' ' + i18n.unit_minutes + '）</span></div>' +
                '<div class="shift-card__row"><span class="shift-card__label">' + i18n.field_early_count + '</span><span>' + r.early_count + '（' + r.early_total_minutes + ' ' + i18n.unit_minutes + '）</span></div>' +
                '<div class="shift-card__row"><span class="shift-card__label">' + i18n.field_absent_count + '</span><span>' + r.absent_count + '</span></div>' +
                '<div class="shift-card__row"><span class="shift-card__label">' + i18n.field_overtime_total + '</span><span>' + r.overtime_total_minutes + ' ' + i18n.unit_minutes + '</span></div>' +
                '</div>'
            );
        }).join('');

        document.getElementById('att-content').innerHTML = nav + table +
            '<div class="shift-cards">' + reportCards + '</div>';

        // 綁定 flatpickr 月份選擇器
        flatpickr('#report-month-picker', {
            plugins: [new monthSelectPlugin({ shorthand: true, dateFormat: 'Y-m', altFormat: 'Y-m' })],
            disableMobile: true,
            onChange: function (selectedDates) {
                if (selectedDates.length === 0) { return; }
                var d = selectedDates[0];
                currentMonth = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
                loadReport(currentMonth);
            }
        });

        // 點擊員工行跳轉到詳細頁面
        root.querySelectorAll('.js-detail-link').forEach(function (row) {
            row.addEventListener('click', function () {
                var uid = row.dataset.userId;
                if (uid) {
                    window.location.href = '/admin/attendance/detail/' + uid;
                }
            });
        });
    }

    // ---------------------------------------------------------------
    //  初始化
    // ---------------------------------------------------------------

    renderPage();
})();
