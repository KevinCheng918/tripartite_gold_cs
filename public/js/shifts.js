(function () {
    var root = document.getElementById('shift-app');
    if (!root) {
        return;
    }

    var i18n = JSON.parse(root.dataset.i18n);
    var coverI18n = JSON.parse(root.dataset.coverI18n || '{}');
    var currentUserId = parseInt(root.dataset.userId, 10);
    var isAdmin = root.dataset.isAdmin === '1';
    var csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    var userPermissions = JSON.parse(root.dataset.permissions || '[]');

    /**
     * 檢查使用者是否擁有指定權限
     *
     * @param {string} keyword
     * @return {boolean}
     */
    function hasPerm(keyword) {
        if (userPermissions.indexOf('all') !== -1) { return true; }
        return userPermissions.indexOf(keyword) !== -1;
    }

    /** 客服帳號資料（供 Admin 排班選單） */
    var csUsersData = [];

    /** 星期名稱 */
    var weekdayNames = ['週一', '週二', '週三', '週四', '週五', '週六', '週日'];

    /** 人員色盤（柔和色系，自動循環分配） */
    var userPalette = [
        { bg: '#fef3c7', border: '#f59e0b', text: '#92400e' },
        { bg: '#dbeafe', border: '#3b82f6', text: '#1e40af' },
        { bg: '#ede9fe', border: '#8b5cf6', text: '#5b21b6' },
        { bg: '#dcfce7', border: '#22c55e', text: '#166534' },
        { bg: '#ffe4e6', border: '#f43f5e', text: '#9f1239' },
        { bg: '#e0f2fe', border: '#0ea5e9', text: '#0c4a6e' },
        { bg: '#fce7f3', border: '#ec4899', text: '#9d174d' },
        { bg: '#f0fdf4', border: '#4ade80', text: '#15803d' },
        { bg: '#fef9c3', border: '#eab308', text: '#854d0e' },
        { bg: '#e0e7ff', border: '#6366f1', text: '#3730a3' }
    ];
    var userColorMap = {};
    var userColorIndex = 0;

    /**
     * 依 user_id 取得專屬顏色（同一人永遠同色）
     *
     * @param {number} userId
     * @return {{ bg: string, border: string, text: string }}
     */
    function getUserColor(userId) {
        if (!userColorMap[userId]) {
            userColorMap[userId] = userPalette[userColorIndex % userPalette.length];
            userColorIndex++;
        }
        return userColorMap[userId];
    }

    /** 預設色塊 */
    var defaultColor = { bg: '#f3f4f6', border: '#9ca3af', text: '#374151' };

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

    function openModal(id) {
        var el = document.getElementById(id);
        if (!el) { return; }
        showBsModal(el);
    }

    function closeModal(id) {
        var el = document.getElementById(id);
        if (el) { hideBsModal(el); }
    }

    function showMessage(message) {
        // 先關閉所有已開啟的 modal，避免疊加
        document.querySelectorAll('.modal.show').forEach(function (m) {
            if (m.id !== 'modal-message') {
                hideBsModal(m);
            }
        });
        var textEl = document.getElementById('modal-message-text');
        var headerEl = document.querySelector('#modal-message .modal-header h3');
        if (textEl) { textEl.textContent = message; }
        if (headerEl) { headerEl.textContent = ''; }
        // 檢查是否有 backdrop 殘留或 modal 正在關閉中
        var hasBackdrop = document.querySelectorAll('.modal-backdrop').length > 0;
        if (hasBackdrop) {
            setTimeout(function () { openModal('modal-message'); }, 400);
        } else {
            openModal('modal-message');
        }
    }

    function getErrorMessage(error) {
        if (error.errors) {
            var keys = Object.keys(error.errors);
            return error.errors[keys[0]][0];
        }
        return error.message || 'Failed';
    }


    // ---------------------------------------------------------------
    //  狀態
    // ---------------------------------------------------------------

    var activeTab = 'timetable';
    var shiftsData = [];
    var assignmentsData = [];

    /** 目前課表顯示的週一日期（用於上下週切換） */
    var currentMonday = getMonday();

    var swapStatusMap = {};
    swapStatusMap[0] = { text: i18n.status_pending, css: 'bg-warning text-dark' };
    swapStatusMap[1] = { text: i18n.status_approved, css: 'bg-success' };
    swapStatusMap[2] = { text: i18n.status_rejected, css: 'bg-danger' };

    // ---------------------------------------------------------------
    //  日期工具
    // ---------------------------------------------------------------

    /**
     * 取得本週一的日期
     *
     * @return {Date}
     */
    function getMonday() {
        var now = new Date();
        var day = now.getDay();
        var diff = (day === 0) ? -6 : (1 - day);
        var monday = new Date(now);
        monday.setDate(now.getDate() + diff);
        monday.setHours(0, 0, 0, 0);
        return monday;
    }

    /**
     * 格式化日期為 Y-m-d
     *
     * @param {Date} date
     * @return {string}
     */
    function formatDate(date) {
        var y = date.getFullYear();
        var m = String(date.getMonth() + 1).padStart(2, '0');
        var d = String(date.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + d;
    }

    /**
     * 取得指定週一起算的七天日期陣列
     *
     * @param {Date} monday 該週的週一
     * @return {Array<{date: string, weekday: string}>}
     */
    function getWeekDays(monday) {
        var days = [];
        for (var i = 0; i < 7; i++) {
            var d = new Date(monday);
            d.setDate(monday.getDate() + i);
            days.push({
                date: formatDate(d),
                weekday: weekdayNames[i]
            });
        }
        return days;
    }

    /**
     * 取得週範圍顯示文字（如 07/21 ~ 07/27）
     *
     * @param {Array} weekDays
     * @return {string}
     */
    function getWeekRangeLabel(weekDays) {
        return weekDays[0].date.substring(5) + ' ~ ' + weekDays[6].date.substring(5);
    }

    /**
     * 解析時間字串為小時數（支援跨日）
     *
     * @param {string} timeStr "HH:mm" 或 "HH:mm:ss"
     * @return {number}
     */
    function parseHour(timeStr) {
        if (!timeStr) { return 0; }
        return parseInt(timeStr.split(':')[0], 10);
    }

    // ---------------------------------------------------------------
    //  Tabs
    // ---------------------------------------------------------------

    function renderTabs() {
        var allTabs = [
            { key: 'timetable', label: i18n.tab_assignments || '排班課表', perm: 'shift.view' },
            { key: 'shifts', label: i18n.tab_shifts, perm: 'shift.update' },
            { key: 'swaps', label: i18n.tab_swaps, perm: 'shift.swap' },
            { key: 'covers', label: coverI18n.nav_label || '代班管理', perm: 'shift.cover' },
        ];

        // 只顯示有權限的 Tab
        var tabs = allTabs.filter(function (t) { return hasPerm(t.perm); });

        var html = '<ul class="nav nav-tabs mb-3">';
        tabs.forEach(function (tab) {
            var cls = tab.key === activeTab ? 'active' : '';
            html += '<li class="nav-item"><button class="nav-link ' + cls + '" data-tab="' + tab.key + '">' + tab.label + '</button></li>';
        });
        html += '</ul><div id="tab-content"></div>';

        root.innerHTML = html;

        root.querySelectorAll('.nav-tabs .nav-link').forEach(function (btn) {
            btn.addEventListener('click', function () {
                activeTab = btn.dataset.tab;
                renderTabs();
                loadTabContent();
            });
        });

        loadTabContent();
    }

    function loadTabContent() {
        if (activeTab === 'timetable') {
            loadTimetable();
        } else if (activeTab === 'shifts') {
            loadShifts();
        } else if (activeTab === 'swaps') {
            loadSwaps();
        } else if (activeTab === 'covers') {
            loadCovers();
        }
    }

    // ---------------------------------------------------------------
    //  排班課表 Tab（24 小時 × 7 天）
    // ---------------------------------------------------------------

    /** 已核准的代班資料 */
    var approvedCovers = [];

    /** 載入指定週排班並渲染課表（同時載入已核准代班） */
    function loadTimetable() {
        var weekDays = getWeekDays(currentMonday);
        var dateFrom = weekDays[0].date;
        var dateTo = weekDays[6].date;

        Promise.all([
            apiFetch('/admin/shifts/ajax-assignment-list?date_from=' + dateFrom + '&date_to=' + dateTo + '&per_page=200'),
            apiFetch('/admin/covers/ajax-approved?date_from=' + dateFrom + '&date_to=' + dateTo)
        ])
            .then(function (results) {
                assignmentsData = results[0].data;
                approvedCovers = results[1].data || [];
                renderTimetable(weekDays, assignmentsData);
            })
            .catch(function () {
                document.getElementById('tab-content').innerHTML = '<p>Failed to load timetable.</p>';
            });
    }

    /**
     * 渲染 24 小時 × 7 天課表
     *
     * @param {Array} weekDays  七天日期資料
     * @param {Array} assignments 排班紀錄
     */
    function renderTimetable(weekDays, assignments) {
        // 建立 lookup: date → [{shift, user}]
        var lookup = {};
        assignments.forEach(function (a) {
            if (!lookup[a.date]) { lookup[a.date] = []; }
            lookup[a.date].push(a);
        });

        // 表頭
        var headerHtml = '<th class="tt-time-col">時間</th>';
        weekDays.forEach(function (day) {
            var today = formatDate(new Date()) === day.date ? ' tt-today' : '';
            headerHtml += '<th class="tt-day-col' + today + '">' + day.weekday + '<br><span class="tt-date">' + day.date.substring(5) + '</span></th>';
        });

        // 建立代班 lookup: assignment_id → [{cover_start, cover_end, cover_user}]
        var coverLookup = {};
        approvedCovers.forEach(function (c) {
            if (!coverLookup[c.assignment_id]) { coverLookup[c.assignment_id] = []; }
            coverLookup[c.assignment_id].push(c);
        });

        /**
         * 判斷某時間範圍在指定小時是否在範圍內
         *
         * @param {string} startTime "HH:mm" 或 "HH:mm:ss"
         * @param {string} endTime
         * @param {number} hour
         * @return {boolean}
         */
        function isTimeInRange(startTime, endTime, hour) {
            var sH = parseHour(startTime);
            var eH = parseHour(endTime);

            if (eH > sH) { return hour >= sH && hour < eH; }
            if (eH === sH) { return true; }
            return hour >= sH || hour < eH;
        }

        // 記錄標籤已渲染
        var labelRendered = {};

        /**
         * 為某日某小時建立色塊
         * 欄位固定為原班人員（userId），代班時改顏色和名稱但佔原班人的位置
         *
         * @param {string} date
         * @param {number} hour
         * @param {Array} dayUserIds 該天固定的人員順序
         * @return {Array}
         */
        function buildCellBlocks(date, hour, dayUserIds) {
            // userId → { color, label, dataId, cls }
            var slotMap = {};
            if (!lookup[date]) { return []; }

            lookup[date].forEach(function (a) {
                if (!a.shift) { return; }
                if (!isTimeInRange(a.shift.start_time, a.shift.end_time, hour)) { return; }

                var originalUserId = a.user ? a.user.id : 0;
                var covers = coverLookup[a.id] || [];
                var coveredBy = null;
                covers.forEach(function (c) {
                    if (isTimeInRange(c.cover_start, c.cover_end, hour)) {
                        coveredBy = c;
                    }
                });

                if (coveredBy) {
                    // 代班：佔原班人的欄位，但用代班人的顏色
                    var coverUserId = coveredBy.cover_user ? coveredBy.cover_user.id : 0;
                    var coverColor = getUserColor(coverUserId);
                    var coverName = coveredBy.cover_user ? coveredBy.cover_user.nickname : '';
                    var originalName = a.user ? a.user.nickname : '';
                    var coverKey = date + '_cover_' + coveredBy.id;
                    var coverLabel = '';
                    if (!labelRendered[coverKey]) {
                        labelRendered[coverKey] = true;
                        coverLabel = '<div class="tt-block-info">' +
                            '<div class="tt-block-name" style="color:' + coverColor.text + '">' + coverName + '</div>' +
                            '<div class="tt-block-user">代 ' + originalName + '</div>' +
                            '</div>';
                    }
                    slotMap[originalUserId] = { cls: 'tt-shift-user', label: coverLabel, dataId: a.id, color: coverColor };
                } else {
                    // 正常班
                    if (!slotMap[originalUserId]) {
                        slotMap[originalUserId] = { cls: 'tt-shift-user', shifts: [], assignmentIds: [], userName: a.user ? a.user.nickname : '', userId: originalUserId };
                    }
                    var s = slotMap[originalUserId];
                    var shiftName = a.shift.display_name || '';
                    if (shiftName && (!s.shifts || s.shifts.indexOf(shiftName) === -1)) {
                        if (!s.shifts) { s.shifts = []; }
                        s.shifts.push(shiftName);
                    }
                    if (!s.assignmentIds) { s.assignmentIds = []; }
                    s.assignmentIds.push(a.id);
                }
            });

            // 轉成 blocks 陣列，按固定順序
            var blocks = [];
            dayUserIds.forEach(function (uid) {
                var s = slotMap[uid];
                if (!s) {
                    blocks.push({ empty: true });
                    return;
                }
                // 正常班需要最終組裝 label
                if (s.shifts) {
                    var color = getUserColor(uid);
                    var labelKey = date + '_user_' + uid;
                    var label = '';
                    if (!labelRendered[labelKey]) {
                        labelRendered[labelKey] = true;
                        label = '<div class="tt-block-info">' +
                            '<div class="tt-block-name" style="color:' + color.text + '">' + s.userName + '</div>' +
                            '<div class="tt-block-user">' + s.shifts.join('、') + '</div>' +
                            '</div>';
                    }
                    blocks.push({ cls: 'tt-shift-user', label: label, dataId: s.assignmentIds[0], color: color });
                } else {
                    // 代班已組裝好
                    blocks.push(s);
                }
            });

            return blocks;
        }

        // 算出每天的原班人員，按班別開始時間排序（早→午→晚），同班再按 userId
        var dayUserOrder = {};
        weekDays.forEach(function (day) {
            var seen = {};
            var userEntries = [];  // { userId, shiftStart }
            if (!lookup[day.date]) { dayUserOrder[day.date] = []; return; }

            lookup[day.date].forEach(function (a) {
                if (!a.user) { return; }
                var uid = a.user.id;
                if (seen[uid]) { return; }
                seen[uid] = true;
                var shiftStart = a.shift ? parseHour(a.shift.start_time) : 99;
                userEntries.push({ userId: uid, shiftStart: shiftStart });
            });
            userEntries.sort(function (a, b) {
                if (a.shiftStart !== b.shiftStart) { return a.shiftStart - b.shiftStart; }
                return a.userId - b.userId;
            });
            dayUserOrder[day.date] = userEntries.map(function (e) { return e.userId; });
        });

        // 25 行（00:00 ~ 24:00）
        var bodyHtml = '';
        for (var hour = 0; hour <= 24; hour++) {
            var hourLabel = String(hour).padStart(2, '0') + ':00';
            bodyHtml += '<tr>';
            bodyHtml += '<td class="tt-time-cell">' + hourLabel + '</td>';

            weekDays.forEach(function (day) {
                var dayUserIds = dayUserOrder[day.date] || [];
                var colCount = dayUserIds.length || 1;

                if (hour === 24 || dayUserIds.length === 0) {
                    bodyHtml += '<td class="tt-cell"></td>';
                    return;
                }

                var blocks = buildCellBlocks(day.date, hour, dayUserIds);

                // 檢查是否全部都是空的
                var hasContent = false;
                blocks.forEach(function (b) { if (!b.empty) { hasContent = true; } });

                if (!hasContent) {
                    bodyHtml += '<td class="tt-cell"></td>';
                    return;
                }

                var cellClass = 'tt-cell tt-cell-multi';
                var innerHtml = '<div class="tt-multi-wrap" style="grid-template-columns: repeat(' + colCount + ', 1fr)">';

                blocks.forEach(function (b) {
                    if (b.empty) {
                        innerHtml += '<div></div>';
                    } else {
                        var style = '';
                        if (b.color) {
                            style = ' style="background:' + b.color.bg + ';border-left:3px solid ' + b.color.border + '"';
                        }
                        innerHtml += '<div class="tt-block ' + b.cls + ' js-assignment-cell" data-assignment-id="' + b.dataId + '"' + style + '>' + b.label + '</div>';
                    }
                });

                innerHtml += '</div>';
                bodyHtml += '<td class="' + cellClass + '">' + innerHtml + '</td>';
            });

            bodyHtml += '</tr>';
        }

        var rangeLabel = getWeekRangeLabel(weekDays);

        // 週切換導航（點擊日期範圍彈出日曆）
        var weekNav =
            '<div class="tt-week-nav">' +
            '<button class="btn-sm" id="js-prev-week">&lsaquo; 上一週</button>' +
            '<span class="tt-week-label" id="js-week-label">' + rangeLabel + '</span>' +
            '<button class="btn-sm" id="js-next-week">下一週 &rsaquo;</button>' +
            '<button class="btn-sm" id="js-current-week">本週</button>' +
            '</div>';

        // 操作按鈕
        var actionBar = '<div class="tt-actions">';
        if (hasPerm('shift.assign')) {
            actionBar += '<button class="btn-primary" id="js-open-assign">' + i18n.action_assign + '</button> ';
        }
        if (!isAdmin && hasPerm('shift.swap')) {
            actionBar += '<button class="btn-primary" id="js-open-swap">' + i18n.action_swap + '</button>';
        }
        actionBar += '</div>';

        var toolbar =
            '<div class="tt-toolbar">' +
            '<div class="tt-toolbar__left">' + actionBar + '</div>' +
            '<div class="tt-toolbar__center">' + weekNav + '</div>' +
            '</div>';

        var html = toolbar +
            '<div class="timetable-wrap">' +
            '<table class="timetable"><thead><tr>' + headerHtml + '</tr></thead>' +
            '<tbody>' + bodyHtml + '</tbody></table>' +
            '</div>';

        document.getElementById('tab-content').innerHTML = html;

        // 綁定按鈕
        var assignBtn = document.getElementById('js-open-assign');
        if (assignBtn) {
            assignBtn.addEventListener('click', function () {
                // 重置全天班 checkbox
                var alldayCb = document.getElementById('assign-allday');
                if (alldayCb) { alldayCb.checked = false; }
                var shiftGroup = document.getElementById('assign-shift-group');
                if (shiftGroup) { shiftGroup.style.display = ''; }

                populateAssignShiftSelect();
                if (isAdmin) { populateAssignUserSelect(); }
                openModal('modal-assign');
            });
        }
        var swapBtn = document.getElementById('js-open-swap');
        if (swapBtn) {
            swapBtn.addEventListener('click', function () {
                // 清空日期和班別
                var myDate = document.getElementById('swap-my-date');
                var tgtDate = document.getElementById('swap-target-date');
                if (myDate) { myDate.value = ''; }
                if (tgtDate) { tgtDate.value = ''; }
                var myShift = document.getElementById('swap-my-shift');
                var tgtShift = document.getElementById('swap-target-shift');
                if (myShift) { myShift.innerHTML = ''; }
                if (tgtShift) { tgtShift.innerHTML = ''; }
                openModal('modal-swap');
            });
        }

        // 綁定週切換
        document.getElementById('js-prev-week').addEventListener('click', function () {
            currentMonday.setDate(currentMonday.getDate() - 7);
            loadTimetable();
        });
        document.getElementById('js-next-week').addEventListener('click', function () {
            currentMonday.setDate(currentMonday.getDate() + 7);
            loadTimetable();
        });
        document.getElementById('js-current-week').addEventListener('click', function () {
            currentMonday = getMonday();
            loadTimetable();
        });

        // 點擊日期範圍文字 → 彈出日曆跳轉到該週
        var labelEl = document.getElementById('js-week-label');
        var jumpPicker = flatpickr(labelEl, {
            dateFormat: 'Y-m-d',
            disableMobile: true,
            defaultDate: formatDate(currentMonday),
            onChange: function (selectedDates) {
                if (selectedDates.length === 0) { return; }
                var picked = selectedDates[0];
                var day = picked.getDay();
                var diff = (day === 0) ? -6 : (1 - day);
                var monday = new Date(picked);
                monday.setDate(picked.getDate() + diff);
                monday.setHours(0, 0, 0, 0);
                currentMonday = monday;
                loadTimetable();
            }
        });

        // 綁定色塊點擊 → 彈出操作 modal
        root.querySelectorAll('.js-assignment-cell').forEach(function (cell) {
            cell.addEventListener('click', function () {
                var assignmentId = cell.dataset.assignmentId;
                if (assignmentId) {
                    openAssignmentActionModal(parseInt(assignmentId, 10));
                }
            });
        });
    }

    // ---------------------------------------------------------------
    //  班別設定 Tab
    // ---------------------------------------------------------------

    function loadShifts() {
        apiFetch('/admin/shifts/ajax-shift-list')
            .then(function (body) {
                shiftsData = body.data;
                renderShiftsTable(body.data);
            })
            .catch(function () {
                document.getElementById('tab-content').innerHTML = '<p>Failed to load shifts.</p>';
            });
    }

    function renderShiftsTable(shifts) {
        var rows = shifts.map(function (shift) {
            return (
                '<tr data-id="' + shift.id + '">' +
                '<td><span class="tt-legend" style="background:' + defaultColor.bg + ';border-color:' + defaultColor.border + ';color:' + defaultColor.text + '">' + shift.display_name + '</span></td>' +
                '<td>' + shift.start_time + '</td>' +
                '<td>' + shift.end_time + '</td>' +
                '<td>' + (shift.is_active ? '<span class="badge bg-success">' + i18n.field_is_active + '</span>' : '<span class="badge bg-secondary">-</span>') + '</td>' +
                '<td><button class="btn-sm js-edit-shift">' + i18n.modal_edit_shift_title + '</button></td>' +
                '</tr>'
            );
        }).join('');

        var cards = shifts.map(function (shift) {
            return (
                '<div class="shift-card" data-id="' + shift.id + '">' +
                '<div class="shift-card__header">' +
                '<span class="tt-legend" style="background:' + defaultColor.bg + ';border-color:' + defaultColor.border + ';color:' + defaultColor.text + '">' + shift.display_name + '</span>' +
                (shift.is_active ? '<span class="badge bg-success">' + i18n.field_is_active + '</span>' : '<span class="badge bg-secondary">-</span>') +
                '</div>' +
                '<div class="shift-card__row"><span class="shift-card__label">' + i18n.field_start_time + '</span><span>' + shift.start_time + '</span></div>' +
                '<div class="shift-card__row"><span class="shift-card__label">' + i18n.field_end_time + '</span><span>' + shift.end_time + '</span></div>' +
                '<div class="shift-card__actions"><button class="btn-sm js-edit-shift">' + i18n.modal_edit_shift_title + '</button></div>' +
                '</div>'
            );
        }).join('');

        var html =
            '<table><thead><tr>' +
            '<th>' + i18n.field_display_name + '</th>' +
            '<th>' + i18n.field_start_time + '</th>' +
            '<th>' + i18n.field_end_time + '</th>' +
            '<th>' + i18n.field_is_active + '</th>' +
            '<th></th>' +
            '</tr></thead><tbody>' + rows + '</tbody></table>' +
            '<div class="shift-cards">' + cards + '</div>';

        document.getElementById('tab-content').innerHTML = html;

        root.querySelectorAll('.js-edit-shift').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var row = btn.closest('tr') || btn.closest('.shift-card');
                var id = parseInt(row.dataset.id, 10);
                var shift = shiftsData.filter(function (s) { return s.id === id; })[0];
                if (shift) { openEditShiftModal(shift); }
            });
        });
    }

    function openEditShiftModal(shift) {
        document.getElementById('edit-shift-id').value = shift.id;
        document.getElementById('edit-display-name').value = shift.display_name;
        document.getElementById('edit-start-time').value = shift.start_time.substring(0, 5);
        document.getElementById('edit-end-time').value = shift.end_time.substring(0, 5);
        openModal('modal-edit-shift');
    }

    // ---------------------------------------------------------------
    //  換班請求 Tab
    // ---------------------------------------------------------------

    function loadSwaps() {
        apiFetch('/admin/shifts/ajax-my-swaps')
            .then(function (body) {
                renderSwapsTable(body.data);
            })
            .catch(function () {
                document.getElementById('tab-content').innerHTML = '<p>Failed to load swaps.</p>';
            });
    }

    function renderSwapsTable(swaps) {
        var rows = swaps.map(function (swap) {
            var requester = swap.requester ? swap.requester.nickname : '-';
            var target = swap.target ? swap.target.nickname : '-';
            var reqDate = swap.requester_assignment ? swap.requester_assignment.date : '-';
            var reqShift = swap.requester_assignment && swap.requester_assignment.shift
                ? swap.requester_assignment.shift.display_name : '-';
            var tgtDate = swap.target_assignment ? swap.target_assignment.date : '-';
            var tgtShift = swap.target_assignment && swap.target_assignment.shift
                ? swap.target_assignment.shift.display_name : '-';
            var statusInfo = swapStatusMap[swap.status] || { text: '-', css: '' };
            var actions = '';

            if (swap.status === 0 && swap.target_id === currentUserId) {
                actions =
                    '<button class="btn btn-primary js-respond-swap" data-id="' + swap.id + '" data-status="1">' + i18n.action_approve + '</button> ' +
                    '<button class="btn btn-secondary js-respond-swap" data-id="' + swap.id + '" data-status="2">' + i18n.action_reject + '</button>';
            }

            return (
                '<tr>' +
                '<td>' + requester + '</td>' +
                '<td>' + reqDate + '</td>' +
                '<td>' + reqShift + '</td>' +
                '<td>' + target + '</td>' +
                '<td>' + tgtDate + '</td>' +
                '<td>' + tgtShift + '</td>' +
                '<td><span class="badge ' + statusInfo.css + '">' + statusInfo.text + '</span></td>' +
                '<td>' + actions + '</td>' +
                '</tr>'
            );
        }).join('');

        var swapCards = swaps.map(function (swap) {
            var requester = swap.requester ? swap.requester.nickname : '-';
            var target = swap.target ? swap.target.nickname : '-';
            var reqDate = swap.requester_assignment ? swap.requester_assignment.date : '-';
            var reqShift = swap.requester_assignment && swap.requester_assignment.shift
                ? swap.requester_assignment.shift.display_name : '-';
            var tgtDate = swap.target_assignment ? swap.target_assignment.date : '-';
            var tgtShift = swap.target_assignment && swap.target_assignment.shift
                ? swap.target_assignment.shift.display_name : '-';
            var statusInfo = swapStatusMap[swap.status] || { text: '-', css: '' };

            var actions = '';
            if (swap.status === 0 && swap.target_id === currentUserId) {
                actions =
                    '<div class="shift-card__actions">' +
                    '<button class="btn btn-primary btn-sm js-respond-swap" data-id="' + swap.id + '" data-status="1">' + i18n.action_approve + '</button>' +
                    '<button class="btn btn-secondary btn-sm js-respond-swap" data-id="' + swap.id + '" data-status="2">' + i18n.action_reject + '</button>' +
                    '</div>';
            }

            return (
                '<div class="shift-card">' +
                '<div class="shift-card__header">' +
                '<span class="shift-card__title">' + requester + ' ↔ ' + target + '</span>' +
                '<span class="badge ' + statusInfo.css + '">' + statusInfo.text + '</span>' +
                '</div>' +
                '<div class="shift-card__row"><span class="shift-card__label">' + i18n.field_user + '（發起）</span><span>' + requester + '</span></div>' +
                '<div class="shift-card__row"><span class="shift-card__label"></span><span>' + reqDate + ' ' + reqShift + '</span></div>' +
                '<div class="shift-card__row"><span class="shift-card__label">' + i18n.field_user + '（對方）</span><span>' + target + '</span></div>' +
                '<div class="shift-card__row"><span class="shift-card__label"></span><span>' + tgtDate + ' ' + tgtShift + '</span></div>' +
                actions +
                '</div>'
            );
        }).join('');

        var html =
            '<table><thead><tr>' +
            '<th>' + i18n.field_user + '（發起方）</th><th>' + (i18n.field_date || '日期') + '</th><th>' + i18n.field_shift + '</th>' +
            '<th>' + i18n.field_user + '（對方）</th><th>' + (i18n.field_date || '日期') + '</th><th>' + i18n.field_shift + '</th>' +
            '<th>狀態</th><th></th>' +
            '</tr></thead><tbody>' + rows + '</tbody></table>' +
            '<div class="shift-cards">' + swapCards + '</div>';

        document.getElementById('tab-content').innerHTML = html;

        root.querySelectorAll('.js-respond-swap').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var swapId = parseInt(btn.dataset.id, 10);
                var status = parseInt(btn.dataset.status, 10);
                // 從表格行取得詳細資訊
                var row = btn.closest('tr') || btn.closest('.shift-card');
                var cells = row ? row.querySelectorAll('td') : [];
                var requester = cells[0] ? cells[0].textContent.trim() : '-';
                var reqDate = cells[1] ? cells[1].textContent.trim() : '-';
                var reqShift = cells[2] ? cells[2].textContent.trim() : '-';
                var target = cells[3] ? cells[3].textContent.trim() : '-';
                var tgtDate = cells[4] ? cells[4].textContent.trim() : '-';
                var tgtShift = cells[5] ? cells[5].textContent.trim() : '-';

                var actionWord = status === 1 ? '同意' : '拒絕';
                var confirmHtml =
                    '<p><strong>確定要' + actionWord + '此換班請求？</strong></p>' +
                    '<table class="table table-sm mt-2"><tbody>' +
                    '<tr><th>發起方</th><td>' + requester + '</td></tr>' +
                    '<tr><th>日期 / 班別</th><td>' + reqDate + ' ' + reqShift + '</td></tr>' +
                    '<tr><th>對方</th><td>' + target + '</td></tr>' +
                    '<tr><th>日期 / 班別</th><td>' + tgtDate + ' ' + tgtShift + '</td></tr>' +
                    '</tbody></table>';

                var confirmBody = document.getElementById('modal-cover-confirm-body');
                if (confirmBody) { confirmBody.innerHTML = confirmHtml; }
                var confirmOk = document.getElementById('btn-cover-confirm-ok');
                if (confirmOk) {
                    var newBtn = confirmOk.cloneNode(true);
                    confirmOk.parentNode.replaceChild(newBtn, confirmOk);
                    newBtn.id = 'btn-cover-confirm-ok';
                    newBtn.addEventListener('click', function () {
                        closeModal('modal-cover-confirm');
                        respondSwap(swapId, status);
                    });
                }
                openModal('modal-cover-confirm');
            });
        });
    }

    // ---------------------------------------------------------------
    //  報班選單填充
    // ---------------------------------------------------------------

    function populateAssignShiftSelect() {
        var select = document.getElementById('assign-shift');
        var activeShifts = shiftsData.filter(function (s) { return s.is_active; });

        if (activeShifts.length === 0) {
            apiFetch('/admin/shifts/ajax-shift-list').then(function (body) {
                shiftsData = body.data;
                fillSelect(select, shiftsData.filter(function (s) { return s.is_active; }));
            });
        } else {
            fillSelect(select, activeShifts);
        }
    }

    function fillSelect(select, shifts) {
        select.innerHTML = shifts.map(function (s) {
            return '<option value="' + s.id + '">' + s.display_name + '（' + s.start_time + ' - ' + s.end_time + '）</option>';
        }).join('');
    }

    /** 填充 Admin 排班 Modal 的客服 checkbox 列表 */
    function populateAssignUserSelect() {
        var container = document.getElementById('assign-user-list');
        if (!container) { return; }

        if (csUsersData.length > 0) {
            fillUserCheckboxes(container, csUsersData);
            return;
        }

        apiFetch('/admin/shifts/ajax-cs-users')
            .then(function (body) {
                csUsersData = body;
                fillUserCheckboxes(container, csUsersData);
            });
    }

    /**
     * 填充客服 checkbox 列表（多選）
     *
     * @param {HTMLElement} container
     * @param {Array} users
     */
    function fillUserCheckboxes(container, users) {
        container.innerHTML = users.map(function (u) {
            var label = u.nickname + '（' + u.account + '）';
            var lockTag = u.status === 2 ? ' <span style="color:#dc2626">— 鎖定</span>' : '';
            return '<label class="assign-user-cb">' +
                '<input type="checkbox" name="user_ids[]" value="' + u.id + '">' +
                '<span>' + label + lockTag + '</span>' +
                '</label>';
        }).join('');
    }

    /**
     * 填充客服 select 選單
     *
     * @param {HTMLSelectElement} select
     * @param {Array} users
     */
    /**
     * 填充客服 select 選單
     * 鎖定帳號（status=2）加上「🔒 鎖定」標注
     *
     * @param {HTMLSelectElement} select
     * @param {Array} users
     */
    function fillUserSelect(select, users) {
        select.innerHTML = users.map(function (u) {
            var label = u.nickname + '（' + u.account + '）';
            if (u.status === 2) {
                label += ' — 鎖定';
            }
            return '<option value="' + u.id + '"' + (u.status === 2 ? ' class="opt-locked"' : '') + '>' + label + '</option>';
        }).join('');
    }

    // ---------------------------------------------------------------
    //  API 操作
    // ---------------------------------------------------------------

    /**
     * 開啟排班操作 modal（點擊色塊觸發）
     *
     * @param {number} assignmentId
     */
    function openAssignmentActionModal(assignmentId) {
        // 從 assignmentsData 找出該筆排班
        var assignment = assignmentsData.filter(function (a) { return a.id === assignmentId; })[0];
        if (!assignment) { return; }

        var userName = assignment.user ? assignment.user.nickname : '-';
        var shiftName = assignment.shift ? assignment.shift.display_name : '-';
        var date = assignment.date || '-';
        var isOwnAssignment = assignment.user_id === currentUserId;

        // 顯示排班資訊
        var infoHtml =
            '<div class="assignment-info">' +
            '<p><strong>' + (i18n.field_user || '員工') + '：</strong>' + userName + '</p>' +
            '<p><strong>' + (i18n.field_shift || '班別') + '：</strong>' + shiftName + '</p>' +
            '<p><strong>' + (i18n.field_date || '日期') + '：</strong>' + date + '</p>' +
            '</div>';

        document.getElementById('modal-assignment-action-body').innerHTML = infoHtml;
        document.getElementById('modal-assignment-action-id').value = assignmentId;

        // 代班按鈕：客服只能點自己的班申請代班，admin 不顯示
        var btnCover = document.getElementById('btn-cover-assignment');
        if (btnCover) {
            btnCover.style.display = (!isAdmin && isOwnAssignment) ? '' : 'none';
        }

        openModal('modal-assignment-action');
    }

    /**
     * 刪除排班紀錄
     *
     * @param {number} assignmentId
     */
    function deleteAssignment(assignmentId) {
        apiFetch('/admin/shifts/ajax-delete-assignment/' + assignmentId, { method: 'DELETE' })
            .then(function () {
                closeModal('modal-assignment-action');
                showMessage(i18n.assignment_deleted);
                loadTimetable();
            })
            .catch(function (error) { showMessage(getErrorMessage(error)); });
    }

    function submitAssign(e) {
        e.preventDefault();
        var form = document.getElementById('form-assign');
        var date = form.querySelector('[name="date"]').value;
        var isAllday = document.getElementById('assign-allday') && document.getElementById('assign-allday').checked;

        // 全天班：取得所有啟用班別的 ID
        var shiftIds = [];
        if (isAllday) {
            shiftIds = shiftsData.filter(function (s) { return s.is_active; }).map(function (s) { return s.id; });
        } else {
            shiftIds = [parseInt(form.querySelector('[name="shift_id"]').value, 10)];
        }

        // Admin 多選：收集勾選的 user_id
        var checkboxes = form.querySelectorAll('[name="user_ids[]"]:checked');
        var userIds = [];
        checkboxes.forEach(function (cb) { userIds.push(parseInt(cb.value, 10)); });

        var promises = [];

        if (userIds.length > 0) {
            // Admin：每個人 × 每個班別
            userIds.forEach(function (uid) {
                shiftIds.forEach(function (sid) {
                    promises.push(apiFetch('/admin/shifts/ajax-assign', {
                        method: 'POST',
                        body: JSON.stringify({ shift_id: sid, date: date, user_id: uid }),
                    }));
                });
            });

            Promise.all(promises)
                .then(function () {
                    closeModal('modal-assign');
                    var msg = i18n.assigned + '（' + userIds.length + ' 人' + (isAllday ? '，全天班' : '') + '）';
                    showMessage(msg);
                    if (activeTab === 'timetable') { loadTimetable(); }
                })
                .catch(function (error) { showMessage(getErrorMessage(error)); });
            return;
        }

        // 客服自己報班
        var selfPromises = shiftIds.map(function (sid) {
            return apiFetch('/admin/shifts/ajax-assign', {
                method: 'POST',
                body: JSON.stringify({ shift_id: sid, date: date }),
            });
        });

        Promise.all(selfPromises)
            .then(function () {
                closeModal('modal-assign');
                showMessage(i18n.assigned);
                if (activeTab === 'timetable') { loadTimetable(); }
            })
            .catch(function (error) { showMessage(getErrorMessage(error)); });
    }

    /**
     * 根據日期查詢排班，填充到 select
     *
     * @param {string} date    日期 Y-m-d
     * @param {string} selectId select 元素 ID
     * @param {number|null} filterUserId 只顯示此員工的排班（null = 全部）
     */
    function loadAssignmentsByDate(date, selectId, filterUserId) {
        var select = document.getElementById(selectId);
        select.innerHTML = '<option value="">載入中...</option>';

        apiFetch('/admin/shifts/ajax-assignment-list?date_from=' + date + '&date_to=' + date + '&per_page=100')
            .then(function (body) {
                var list = body.data || [];

                if (filterUserId) {
                    list = list.filter(function (a) { return a.user_id === filterUserId; });
                }

                if (list.length === 0) {
                    select.innerHTML = '<option value="">' + (i18n.swap_no_assignment || '無排班') + '</option>';
                    return;
                }

                select.innerHTML = list.map(function (a) {
                    var userName = a.user ? a.user.nickname : '';
                    var shiftName = a.shift ? a.shift.display_name : '';
                    return '<option value="' + a.id + '">' + shiftName + ' — ' + userName + '</option>';
                }).join('');
            })
            .catch(function () {
                select.innerHTML = '<option value="">查詢失敗</option>';
            });
    }

    function submitSwap(e) {
        e.preventDefault();

        var myAssignmentId = document.getElementById('swap-my-shift').value;
        var targetAssignmentId = document.getElementById('swap-target-shift').value;

        if (!myAssignmentId || !targetAssignmentId) {
            showMessage(i18n.swap_no_assignment || '請選擇排班');
            return;
        }

        var data = {
            requester_assignment_id: parseInt(myAssignmentId, 10),
            target_assignment_id: parseInt(targetAssignmentId, 10),
        };

        apiFetch('/admin/shifts/ajax-request-swap', { method: 'POST', body: JSON.stringify(data) })
            .then(function () {
                closeModal('modal-swap');
                showMessage(i18n.swap_requested);
                loadSwaps();
            })
            .catch(function (error) { showMessage(getErrorMessage(error)); });
    }

    function submitEditShift(e) {
        e.preventDefault();
        var id = document.getElementById('edit-shift-id').value;
        var data = {
            display_name: document.getElementById('edit-display-name').value,
            start_time: document.getElementById('edit-start-time').value,
            end_time: document.getElementById('edit-end-time').value,
        };

        apiFetch('/admin/shifts/ajax-update-shift/' + id, { method: 'PUT', body: JSON.stringify(data) })
            .then(function () {
                closeModal('modal-edit-shift');
                showMessage(i18n.shift_updated);
                loadShifts();
            })
            .catch(function (error) { showMessage(getErrorMessage(error)); });
    }

    function respondSwap(swapId, status) {
        apiFetch('/admin/shifts/ajax-respond-swap/' + swapId, { method: 'PUT', body: JSON.stringify({ status: status }) })
            .then(function () {
                showMessage(status === 1 ? i18n.swap_approved : i18n.swap_rejected);
                loadSwaps();
            })
            .catch(function (error) { showMessage(getErrorMessage(error)); });
    }

    // ---------------------------------------------------------------
    //  代班
    // ---------------------------------------------------------------

    /** 代班狀態 badge 對應 */
    var coverStatusMap = {};
    coverStatusMap[0] = { text: coverI18n.status_pending || '待確認', css: 'bg-warning text-dark' };
    coverStatusMap[1] = { text: coverI18n.status_approved || '已同意', css: 'bg-success' };
    coverStatusMap[2] = { text: coverI18n.status_rejected || '已拒絕', css: 'bg-danger' };

    var adminStatusMap = {};
    adminStatusMap[0] = { text: coverI18n.admin_pending || '待審核', css: 'bg-warning text-dark' };
    adminStatusMap[1] = { text: coverI18n.admin_approved || '已核准', css: 'bg-success' };
    adminStatusMap[2] = { text: coverI18n.admin_rejected || '已駁回', css: 'bg-danger' };

    /**
     * 開啟代班申請 Modal
     *
     * @param {number} assignmentId
     */
    function openCoverRequestModal(assignmentId) {
        document.getElementById('cover-assignment-id').value = assignmentId;
        document.getElementById('cover-reason').value = '';

        // 預填班別時段
        var assignment = assignmentsData.filter(function (a) { return a.id === assignmentId; })[0];
        if (assignment && assignment.shift) {
            var startStr = assignment.shift.start_time ? assignment.shift.start_time.substring(0, 5) : '';
            var endStr = assignment.shift.end_time ? assignment.shift.end_time.substring(0, 5) : '';
            document.getElementById('cover-start').value = startStr;
            document.getElementById('cover-end').value = endStr;
        }

        // 載入客服選單
        var select = document.getElementById('cover-user-id');
        if (csUsersData.length > 0) {
            fillUserSelect(select, csUsersData.filter(function (u) { return u.id !== currentUserId; }));
        } else {
            apiFetch('/admin/shifts/ajax-cs-users').then(function (body) {
                csUsersData = body;
                fillUserSelect(select, csUsersData.filter(function (u) { return u.id !== currentUserId; }));
            });
        }

        openModal('modal-cover-request');
    }

    /** 提交代班申請 */
    function submitCoverRequest(e) {
        e.preventDefault();
        var data = {
            assignment_id: parseInt(document.getElementById('cover-assignment-id').value, 10),
            cover_user_id: parseInt(document.getElementById('cover-user-id').value, 10),
            cover_start: document.getElementById('cover-start').value,
            cover_end: document.getElementById('cover-end').value,
            reason: document.getElementById('cover-reason').value || null,
        };

        apiFetch('/admin/covers/ajax-request', { method: 'POST', body: JSON.stringify(data) })
            .then(function () {
                closeModal('modal-cover-request');
                showMessage(coverI18n.requested || '代班申請已送出');
            })
            .catch(function (error) { showMessage(getErrorMessage(error)); });
    }

    /** 載入代班紀錄 Tab */
    function loadCovers() {
        var url = isAdmin ? '/admin/covers/ajax-all' : '/admin/covers/ajax-my-covers';

        apiFetch(url)
            .then(function (body) {
                renderCoversTable(body.data);
            })
            .catch(function () {
                document.getElementById('tab-content').innerHTML = '<p>Failed to load covers.</p>';
            });
    }

    /**
     * 渲染代班紀錄表格
     *
     * @param {Array} covers
     */
    function renderCoversTable(covers) {
        var rows = covers.map(function (c) {
            var requester = c.requester ? c.requester.nickname : '-';
            var coverUser = c.cover_user ? c.cover_user.nickname : '-';
            var shiftName = c.assignment && c.assignment.shift ? c.assignment.shift.display_name : '-';
            var shiftDate = c.assignment ? c.assignment.date : '-';
            var coverTime = c.cover_start + ' - ' + c.cover_end;
            var coverStatus = coverStatusMap[c.cover_user_status] || { text: '-', css: '' };
            var aStatus = adminStatusMap[c.admin_status] || { text: '-', css: '' };
            var reason = c.reason || '-';

            var actions = '';

            // 代班人可以回應（待確認時）
            if (c.cover_user_status === 0 && c.cover_user_id === currentUserId) {
                actions =
                    '<button class="btn btn-primary btn-sm js-cover-respond" data-id="' + c.id + '" data-status="1">' + (coverI18n.action_approve || '同意') + '</button> ' +
                    '<button class="btn btn-secondary btn-sm js-cover-respond" data-id="' + c.id + '" data-status="2">' + (coverI18n.action_reject || '拒絕') + '</button>';
            }

            // 管理者可以審核（代班人已同意、待審核時）
            if (isAdmin && c.cover_user_status === 1 && c.admin_status === 0) {
                actions =
                    '<button class="btn btn-primary btn-sm js-cover-admin" data-id="' + c.id + '" data-status="1">' + (coverI18n.action_admin_approve || '核准') + '</button> ' +
                    '<button class="btn btn-secondary btn-sm js-cover-admin" data-id="' + c.id + '" data-status="2">' + (coverI18n.action_admin_reject || '駁回') + '</button>';
            }

            return (
                '<tr>' +
                '<td>' + shiftDate + '</td>' +
                '<td>' + shiftName + '</td>' +
                '<td>' + requester + '</td>' +
                '<td>' + coverUser + '</td>' +
                '<td>' + coverTime + '</td>' +
                '<td>' + reason + '</td>' +
                '<td><span class="badge ' + coverStatus.css + '">' + coverStatus.text + '</span></td>' +
                '<td><span class="badge ' + aStatus.css + '">' + aStatus.text + '</span></td>' +
                '<td>' + actions + '</td>' +
                '</tr>'
            );
        }).join('');

        var coverCards = covers.map(function (c) {
            var requester = c.requester ? c.requester.nickname : '-';
            var coverUser = c.cover_user ? c.cover_user.nickname : '-';
            var shiftName = c.assignment && c.assignment.shift ? c.assignment.shift.display_name : '-';
            var shiftDate = c.assignment ? c.assignment.date : '-';
            var coverTime = c.cover_start + ' - ' + c.cover_end;
            var coverStatus = coverStatusMap[c.cover_user_status] || { text: '-', css: '' };
            var aStatus = adminStatusMap[c.admin_status] || { text: '-', css: '' };

            var actions = '';
            if (c.cover_user_status === 0 && c.cover_user_id === currentUserId) {
                actions =
                    '<div class="shift-card__actions">' +
                    '<button class="btn btn-primary btn-sm js-cover-respond" data-id="' + c.id + '" data-status="1">' + (coverI18n.action_approve || '同意') + '</button>' +
                    '<button class="btn btn-secondary btn-sm js-cover-respond" data-id="' + c.id + '" data-status="2">' + (coverI18n.action_reject || '拒絕') + '</button>' +
                    '</div>';
            }
            if (isAdmin && c.cover_user_status === 1 && c.admin_status === 0) {
                actions =
                    '<div class="shift-card__actions">' +
                    '<button class="btn btn-primary btn-sm js-cover-admin" data-id="' + c.id + '" data-status="1">' + (coverI18n.action_admin_approve || '核准') + '</button>' +
                    '<button class="btn btn-secondary btn-sm js-cover-admin" data-id="' + c.id + '" data-status="2">' + (coverI18n.action_admin_reject || '駁回') + '</button>' +
                    '</div>';
            }

            return (
                '<div class="shift-card">' +
                '<div class="shift-card__header">' +
                '<span class="shift-card__title">' + shiftDate + ' ' + shiftName + '</span>' +
                '</div>' +
                '<div class="shift-card__row"><span class="shift-card__label">' + (coverI18n.field_requester || '原班人') + '</span><span>' + requester + '</span></div>' +
                '<div class="shift-card__row"><span class="shift-card__label">' + (coverI18n.field_cover_user || '代班人') + '</span><span>' + coverUser + '</span></div>' +
                '<div class="shift-card__row"><span class="shift-card__label">' + (coverI18n.field_cover_time || '代班時段') + '</span><span>' + coverTime + '</span></div>' +
                (c.reason ? '<div class="shift-card__row"><span class="shift-card__label">' + (coverI18n.field_reason || '原因') + '</span><span>' + c.reason + '</span></div>' : '') +
                '<div class="shift-card__badges">' +
                '<span class="badge ' + coverStatus.css + '">' + (coverI18n.field_cover_status || '代班人') + '：' + coverStatus.text + '</span>' +
                '<span class="badge ' + aStatus.css + '">' + (coverI18n.field_admin_status || '管理者') + '：' + aStatus.text + '</span>' +
                '</div>' +
                actions +
                '</div>'
            );
        }).join('');

        var html =
            '<table><thead><tr>' +
            '<th>' + (coverI18n.field_date || '日期') + '</th>' +
            '<th>' + (coverI18n.field_shift || '班別') + '</th>' +
            '<th>' + (coverI18n.field_requester || '原班人') + '</th>' +
            '<th>' + (coverI18n.field_cover_user || '代班人') + '</th>' +
            '<th>' + (coverI18n.field_cover_time || '代班時段') + '</th>' +
            '<th>' + (coverI18n.field_reason || '原因') + '</th>' +
            '<th>' + (coverI18n.field_cover_status || '代班人') + '</th>' +
            '<th>' + (coverI18n.field_admin_status || '管理者') + '</th>' +
            '<th></th>' +
            '</tr></thead><tbody>' + rows + '</tbody></table>' +
            '<div class="shift-cards">' + coverCards + '</div>';

        document.getElementById('tab-content').innerHTML = html;

        // 從表格行取得代班詳細資訊
        function getCoverDetailFromRow(btn) {
            var row = btn.closest('tr');
            if (!row) { return {}; }
            var cells = row.querySelectorAll('td');
            return {
                date: cells[0] ? cells[0].textContent.trim() : '-',
                shift: cells[1] ? cells[1].textContent.trim() : '-',
                requester: cells[2] ? cells[2].textContent.trim() : '-',
                coverUser: cells[3] ? cells[3].textContent.trim() : '-',
                time: cells[4] ? cells[4].textContent.trim() : '-',
                reason: cells[5] ? cells[5].textContent.trim() : '-'
            };
        }

        function buildCoverConfirmHtml(actionWord, detail) {
            return '<p><strong>確定要' + actionWord + '此代班請求？</strong></p>' +
                '<table class="table table-sm mt-2"><tbody>' +
                '<tr><th>日期</th><td>' + detail.date + '</td></tr>' +
                '<tr><th>班別</th><td>' + detail.shift + '</td></tr>' +
                '<tr><th>原班人</th><td>' + detail.requester + '</td></tr>' +
                '<tr><th>代班人</th><td>' + detail.coverUser + '</td></tr>' +
                '<tr><th>代班時段</th><td>' + detail.time + '</td></tr>' +
                '<tr><th>原因</th><td>' + detail.reason + '</td></tr>' +
                '</tbody></table>';
        }

        // 綁定代班人回應按鈕（含二次確認）
        root.querySelectorAll('.js-cover-respond').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var coverId = parseInt(btn.dataset.id, 10);
                var status = parseInt(btn.dataset.status, 10);
                var actionWord = status === 1 ? '同意' : '拒絕';
                var detail = getCoverDetailFromRow(btn);
                var confirmBody = document.getElementById('modal-cover-confirm-body');
                if (confirmBody) { confirmBody.innerHTML = buildCoverConfirmHtml(actionWord, detail); }
                pendingCoverAction = function () { respondCoverUser(coverId, status); };
                openModal('modal-cover-confirm');
            });
        });

        // 綁定管理者審核按鈕（含二次確認）
        root.querySelectorAll('.js-cover-admin').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var coverId = parseInt(btn.dataset.id, 10);
                var status = parseInt(btn.dataset.status, 10);
                var actionWord = status === 1 ? '核准' : '駁回';
                var detail = getCoverDetailFromRow(btn);
                var confirmBody = document.getElementById('modal-cover-confirm-body');
                if (confirmBody) { confirmBody.innerHTML = buildCoverConfirmHtml(actionWord, detail); }
                pendingCoverAction = function () { respondCoverAdmin(coverId, status); };
                openModal('modal-cover-confirm');
            });
        });
    }

    // ---------------------------------------------------------------
    //  代班二次確認
    // ---------------------------------------------------------------

    var pendingCoverAction = null;

    /**
     * 顯示代班操作二次確認彈窗
     *
     * @param {string}   actionLabel 操作名稱（如「同意」「駁回」）
     * @param {boolean}  isDanger    是否為拒絕/駁回類操作
     * @param {function} onConfirm   確認後執行的 callback
     */
    function showCoverConfirm(actionLabel, isDanger, onConfirm) {
        var body = document.getElementById('modal-cover-confirm-body');
        var actionCls = isDanger ? 'att-confirm-action--out' : 'att-confirm-action--in';

        body.innerHTML =
            '<div class="att-confirm-content">' +
            '<div class="att-confirm-action ' + actionCls + '">' + actionLabel + '</div>' +
            '<p class="att-confirm-hint">' + (coverI18n.confirm_hint || '此操作無法撤回，請確認') + '</p>' +
            '</div>';

        pendingCoverAction = onConfirm;
        openModal('modal-cover-confirm');
    }

    /**
     * 代班人回應
     *
     * @param {number} coverId
     * @param {number} status
     */
    function respondCoverUser(coverId, status) {
        apiFetch('/admin/covers/ajax-respond-cover-user/' + coverId, { method: 'PUT', body: JSON.stringify({ status: status }) })
            .then(function () {
                var msg = status === 1 ? (coverI18n.cover_user_approved || '已同意') : (coverI18n.cover_user_rejected || '已拒絕');
                showMessage(msg);
                loadCovers();
            })
            .catch(function (error) { showMessage(getErrorMessage(error)); });
    }

    /**
     * 管理者審核
     *
     * @param {number} coverId
     * @param {number} status
     */
    function respondCoverAdmin(coverId, status) {
        apiFetch('/admin/covers/ajax-respond-admin/' + coverId, { method: 'PUT', body: JSON.stringify({ status: status }) })
            .then(function () {
                var msg = status === 1 ? (coverI18n.admin_review_approved || '已核准') : (coverI18n.admin_review_rejected || '已駁回');
                showMessage(msg);
                loadCovers();
            })
            .catch(function (error) { showMessage(getErrorMessage(error)); });
    }

    // ---------------------------------------------------------------
    //  初始化
    // ---------------------------------------------------------------


    // 綁定代班確認 Modal 的確認按鈕
    var coverConfirmBtn = document.getElementById('btn-cover-confirm-ok');
    if (coverConfirmBtn) {
        coverConfirmBtn.addEventListener('click', function () {
            closeModal('modal-cover-confirm');
            if (pendingCoverAction) {
                pendingCoverAction();
                pendingCoverAction = null;
            }
        });
    }

    document.getElementById('form-assign').addEventListener('submit', submitAssign);

    // 全天班 checkbox — 勾選時隱藏班別選單
    var alldayCheckbox = document.getElementById('assign-allday');
    if (alldayCheckbox) {
        alldayCheckbox.addEventListener('change', function () {
            var shiftGroup = document.getElementById('assign-shift-group');
            if (shiftGroup) {
                shiftGroup.style.display = alldayCheckbox.checked ? 'none' : '';
            }
        });
    }
    document.getElementById('form-swap').addEventListener('submit', submitSwap);
    document.getElementById('form-edit-shift').addEventListener('submit', submitEditShift);

    // 初始化 flatpickr — 報班日期
    flatpickr('#assign-date', {
        dateFormat: 'Y-m-d',
        defaultDate: 'today',
        disableMobile: true
    });

    // 初始化 flatpickr — 班別時間
    flatpickr('#edit-start-time', {
        enableTime: true,
        noCalendar: true,
        dateFormat: 'H:i',
        time_24hr: true,
        disableMobile: true
    });

    flatpickr('#edit-end-time', {
        enableTime: true,
        noCalendar: true,
        dateFormat: 'H:i',
        time_24hr: true,
        disableMobile: true
    });

    // 排班操作 modal — 刪除按鈕（沒權限時按鈕不存在）
    var btnDelete = document.getElementById('btn-delete-assignment');
    if (btnDelete) {
        btnDelete.addEventListener('click', function () {
            var id = parseInt(document.getElementById('modal-assignment-action-id').value, 10);
            deleteAssignment(id);
        });
    }

    // 排班操作 modal — 申請代班按鈕（沒權限時按鈕不存在）
    var btnCover = document.getElementById('btn-cover-assignment');
    if (btnCover) {
        btnCover.addEventListener('click', function () {
            var assignmentId = parseInt(document.getElementById('modal-assignment-action-id').value, 10);
            closeModal('modal-assignment-action');
            setTimeout(function () {
                openCoverRequestModal(assignmentId);
            }, 350);
        });
    }

    // 代班申請表單（沒權限時表單不存在）
    var formCover = document.getElementById('form-cover-request');
    if (formCover) {
        formCover.addEventListener('submit', submitCoverRequest);
    }

    // 初始化換班 Modal 的 flatpickr — 選日期後自動載入排班
    flatpickr('#swap-my-date', {
        dateFormat: 'Y-m-d',
        disableMobile: true,
        onChange: function (selectedDates) {
            if (selectedDates.length === 0) { return; }
            loadAssignmentsByDate(formatDate(selectedDates[0]), 'swap-my-shift', currentUserId);
        }
    });

    flatpickr('#swap-target-date', {
        dateFormat: 'Y-m-d',
        disableMobile: true,
        onChange: function (selectedDates) {
            if (selectedDates.length === 0) { return; }
            // 對方排班不限員工，顯示全部
            loadAssignmentsByDate(formatDate(selectedDates[0]), 'swap-target-shift', null);
        }
    });

    // 初始化代班 Modal 的 flatpickr
    flatpickr('#cover-start', { enableTime: true, noCalendar: true, dateFormat: 'H:i', time_24hr: true, disableMobile: true });
    flatpickr('#cover-end', { enableTime: true, noCalendar: true, dateFormat: 'H:i', time_24hr: true, disableMobile: true });

    // 載入班別後渲染 tabs（預設顯示課表）
    apiFetch('/admin/shifts/ajax-shift-list')
        .then(function (body) {
            shiftsData = body.data;
            renderTabs();
        })
        .catch(function () {
            root.innerHTML = '<p>Failed to initialize.</p>';
        });
})();
