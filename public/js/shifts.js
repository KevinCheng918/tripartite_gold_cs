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

    /** 班別色塊對應（依班別 name） */
    var shiftColors = {
        morning:   { bg: '#fef3c7', border: '#f59e0b', text: '#92400e' },
        afternoon: { bg: '#dbeafe', border: '#3b82f6', text: '#1e40af' },
        night:     { bg: '#ede9fe', border: '#8b5cf6', text: '#5b21b6' }
    };

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
        if (el) { new bootstrap.Modal(el).show(); }
    }

    function closeModal(id) {
        var el = document.getElementById(id);
        if (el) { var inst = bootstrap.Modal.getInstance(el); if (inst) { inst.hide(); } }
    }

    function showMessage(message) {
        var textEl = document.getElementById('modal-message-text');
        var headerEl = document.querySelector('#modal-message .modal-header h3');
        if (textEl) { textEl.textContent = message; }
        if (headerEl) { headerEl.textContent = ''; }
        openModal('modal-message');
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
                if (overlay) { overlay.style.display = 'none'; }
            });
        });
        document.querySelectorAll('.modal-overlay').forEach(function (overlay) {
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) { overlay.style.display = 'none'; }
            });
        });
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
    swapStatusMap[0] = { text: i18n.status_pending, css: 'badge--pending' };
    swapStatusMap[1] = { text: i18n.status_approved, css: 'badge--approved' };
    swapStatusMap[2] = { text: i18n.status_rejected, css: 'badge--rejected' };

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
         * 為某日某小時建立所有需要顯示的色塊
         * 同班別合併成一個色塊，人名用頓號連接
         */
        function buildCellBlocks(date, hour) {
            var blocks = [];
            if (!lookup[date]) { return blocks; }

            // 先收集這個小時所有匹配的排班，按 shift.name 分組
            var shiftGroups = {};  // key: shift.name → { shift, users: [], assignmentIds: [], covers: [] }

            lookup[date].forEach(function (a) {
                if (!a.shift) { return; }
                var inRange = isTimeInRange(a.shift.start_time, a.shift.end_time, hour);
                if (!inRange) { return; }

                var covers = coverLookup[a.id] || [];
                var coveredBy = null;
                covers.forEach(function (c) {
                    if (isTimeInRange(c.cover_start, c.cover_end, hour)) {
                        coveredBy = c;
                    }
                });

                if (coveredBy) {
                    // 代班獨立處理
                    var coverName = coveredBy.cover_user ? coveredBy.cover_user.nickname : '';
                    var originalName = a.user ? a.user.nickname : '';
                    var coverKey = date + '_cover_' + coveredBy.id;
                    var coverLabel = '';
                    if (!labelRendered[coverKey]) {
                        labelRendered[coverKey] = true;
                        coverLabel = '<div class="tt-block-info">' +
                            '<div class="tt-block-name">' + coverName + '</div>' +
                            '<div class="tt-block-user">代 ' + originalName + ' 的班</div>' +
                            '</div>';
                    }
                    blocks.push({ cls: 'tt-shift-cover', label: coverLabel, dataId: a.id });
                } else {
                    // 按班別分組
                    var key = a.shift.name;
                    if (!shiftGroups[key]) {
                        shiftGroups[key] = { shift: a.shift, users: [], assignmentIds: [] };
                    }
                    var userName = a.user ? a.user.nickname : '';
                    if (userName && shiftGroups[key].users.indexOf(userName) === -1) {
                        shiftGroups[key].users.push(userName);
                    }
                    shiftGroups[key].assignmentIds.push(a.id);
                }
            });

            // 把分組後的班別轉成色塊（同班別一個色塊）
            Object.keys(shiftGroups).forEach(function (key) {
                var group = shiftGroups[key];
                var shiftCls = 'tt-shift-' + group.shift.name;
                var shiftName = group.shift.display_name || '';
                var usersStr = group.users.join('、');
                var labelKey = date + '_shift_' + key;
                var label = '';

                if (!labelRendered[labelKey]) {
                    labelRendered[labelKey] = true;
                    label = '<div class="tt-block-info">' +
                        '<div class="tt-block-name">' + shiftName + '</div>' +
                        '<div class="tt-block-user">' + usersStr + '</div>' +
                        '</div>';
                }

                // dataId 用第一個 assignment 的 ID（點擊用）
                blocks.push({ cls: shiftCls, label: label, dataId: group.assignmentIds[0] });
            });

            return blocks;
        }

        // 25 行（00:00 ~ 24:00）
        var bodyHtml = '';
        for (var hour = 0; hour <= 24; hour++) {
            var hourLabel = String(hour).padStart(2, '0') + ':00';
            bodyHtml += '<tr>';
            bodyHtml += '<td class="tt-time-cell">' + hourLabel + '</td>';

            weekDays.forEach(function (day) {
                if (hour === 24) {
                    bodyHtml += '<td class="tt-cell"></td>';
                    return;
                }

                var blocks = buildCellBlocks(day.date, hour);

                if (blocks.length === 0) {
                    bodyHtml += '<td class="tt-cell"></td>';
                    return;
                }

                var cellClass = 'tt-cell tt-cell-multi';
                var innerHtml = '<div class="tt-multi-wrap" style="grid-template-columns: repeat(' + blocks.length + ', 1fr)">';

                blocks.forEach(function (b) {
                    innerHtml += '<div class="tt-block ' + b.cls + ' js-assignment-cell" data-assignment-id="' + b.dataId + '">' + b.label + '</div>';
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
            actionBar += '<button class="btn-sm" id="js-open-swap">' + i18n.action_swap + '</button>';
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
            var color = shiftColors[shift.name] || defaultColor;
            return (
                '<tr data-id="' + shift.id + '">' +
                '<td><span class="tt-legend" style="background:' + color.bg + ';border-color:' + color.border + ';color:' + color.text + '">' + shift.display_name + '</span></td>' +
                '<td>' + shift.start_time + '</td>' +
                '<td>' + shift.end_time + '</td>' +
                '<td>' + (shift.is_active ? '<span class="badge badge--active">' + i18n.field_is_active + '</span>' : '<span class="badge badge--disabled">-</span>') + '</td>' +
                '<td><button class="btn-sm js-edit-shift">' + i18n.modal_edit_shift_title + '</button></td>' +
                '</tr>'
            );
        }).join('');

        var cards = shifts.map(function (shift) {
            var color = shiftColors[shift.name] || defaultColor;
            return (
                '<div class="shift-card" data-id="' + shift.id + '">' +
                '<div class="shift-card__header">' +
                '<span class="tt-legend" style="background:' + color.bg + ';border-color:' + color.border + ';color:' + color.text + '">' + shift.display_name + '</span>' +
                (shift.is_active ? '<span class="badge badge--active">' + i18n.field_is_active + '</span>' : '<span class="badge badge--disabled">-</span>') +
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
                    '<button class="btn-primary js-respond-swap" data-id="' + swap.id + '" data-status="1">' + i18n.action_approve + '</button> ' +
                    '<button class="btn-sm js-respond-swap" data-id="' + swap.id + '" data-status="2">' + i18n.action_reject + '</button>';
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
                    '<button class="btn-primary btn-sm js-respond-swap" data-id="' + swap.id + '" data-status="1">' + i18n.action_approve + '</button>' +
                    '<button class="btn-sm js-respond-swap" data-id="' + swap.id + '" data-status="2">' + i18n.action_reject + '</button>' +
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
                respondSwap(parseInt(btn.dataset.id, 10), parseInt(btn.dataset.status, 10));
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
    coverStatusMap[0] = { text: coverI18n.status_pending || '待確認', css: 'badge--pending' };
    coverStatusMap[1] = { text: coverI18n.status_approved || '已同意', css: 'badge--approved' };
    coverStatusMap[2] = { text: coverI18n.status_rejected || '已拒絕', css: 'badge--rejected' };

    var adminStatusMap = {};
    adminStatusMap[0] = { text: coverI18n.admin_pending || '待審核', css: 'badge--pending' };
    adminStatusMap[1] = { text: coverI18n.admin_approved || '已核准', css: 'badge--approved' };
    adminStatusMap[2] = { text: coverI18n.admin_rejected || '已駁回', css: 'badge--rejected' };

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
                    '<button class="btn-primary btn-sm js-cover-respond" data-id="' + c.id + '" data-status="1">' + (coverI18n.action_approve || '同意') + '</button> ' +
                    '<button class="btn-sm js-cover-respond" data-id="' + c.id + '" data-status="2">' + (coverI18n.action_reject || '拒絕') + '</button>';
            }

            // 管理者可以審核（代班人已同意、待審核時）
            if (isAdmin && c.cover_user_status === 1 && c.admin_status === 0) {
                actions =
                    '<button class="btn-primary btn-sm js-cover-admin" data-id="' + c.id + '" data-status="1">' + (coverI18n.action_admin_approve || '核准') + '</button> ' +
                    '<button class="btn-sm js-cover-admin" data-id="' + c.id + '" data-status="2">' + (coverI18n.action_admin_reject || '駁回') + '</button>';
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
                    '<button class="btn-primary btn-sm js-cover-respond" data-id="' + c.id + '" data-status="1">' + (coverI18n.action_approve || '同意') + '</button>' +
                    '<button class="btn-sm js-cover-respond" data-id="' + c.id + '" data-status="2">' + (coverI18n.action_reject || '拒絕') + '</button>' +
                    '</div>';
            }
            if (isAdmin && c.cover_user_status === 1 && c.admin_status === 0) {
                actions =
                    '<div class="shift-card__actions">' +
                    '<button class="btn-primary btn-sm js-cover-admin" data-id="' + c.id + '" data-status="1">' + (coverI18n.action_admin_approve || '核准') + '</button>' +
                    '<button class="btn-sm js-cover-admin" data-id="' + c.id + '" data-status="2">' + (coverI18n.action_admin_reject || '駁回') + '</button>' +
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

        // 綁定代班人回應按鈕（含二次確認）
        root.querySelectorAll('.js-cover-respond').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var coverId = parseInt(btn.dataset.id, 10);
                var status = parseInt(btn.dataset.status, 10);
                var actionLabel = status === 1 ? (coverI18n.action_approve || '同意') : (coverI18n.action_reject || '拒絕');
                showCoverConfirm(actionLabel, status === 2, function () {
                    respondCoverUser(coverId, status);
                });
            });
        });

        // 綁定管理者審核按鈕（含二次確認）
        root.querySelectorAll('.js-cover-admin').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var coverId = parseInt(btn.dataset.id, 10);
                var status = parseInt(btn.dataset.status, 10);
                var actionLabel = status === 1 ? (coverI18n.action_admin_approve || '核准') : (coverI18n.action_admin_reject || '駁回');
                showCoverConfirm(actionLabel, status === 2, function () {
                    respondCoverAdmin(coverId, status);
                });
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

    bindModalCloseButtons();

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
            openCoverRequestModal(assignmentId);
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
