(function () {
    var root = document.getElementById('attendance-detail-app');
    if (!root) { return; }

    var i18n = JSON.parse(root.dataset.i18n);
    var targetUserId = parseInt(root.dataset.targetUserId, 10);
    var csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    var currentMonth = new Date().getFullYear() + '-' + String(new Date().getMonth() + 1).padStart(2, '0');

    /** 出勤狀態對應 */
    var statusMap = {};
    statusMap[0] = { text: i18n.status_incomplete, css: 'badge--pending' };
    statusMap[1] = { text: i18n.status_normal, css: 'badge--active' };
    statusMap[2] = { text: i18n.status_late, css: 'badge--rejected' };
    statusMap[3] = { text: i18n.status_early_leave, css: 'badge--pending' };
    statusMap[4] = { text: i18n.status_late_early, css: 'badge--rejected' };
    statusMap[5] = { text: i18n.status_absent, css: 'badge--rejected' };

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

    function loadDetail(month) {
        apiFetch('/admin/attendance/ajax-user-monthly?user_id=' + targetUserId + '&month=' + month)
            .then(function (body) {
                renderDetail(body.data || [], month);
            })
            .catch(function () {
                root.innerHTML = '<p>Failed to load records.</p>';
            });
    }

    function renderDetail(records, month) {
        // 返回按鈕 + 月份選擇
        var header =
            '<div class="att-detail-header">' +
            '<a href="/admin/attendance" class="btn-sm">&lsaquo; ' + i18n.back_to_report + '</a>' +
            '<input type="text" id="detail-month-picker" class="att-month-input" value="' + month + '" readonly autocomplete="off">' +
            '</div>';

        // 統計卡片
        var totalDays = records.length;
        var normalDays = 0, lateCnt = 0, earlyCnt = 0, absentCnt = 0;
        var lateMin = 0, earlyMin = 0, otMin = 0;

        records.forEach(function (r) {
            if (r.status === 1) { normalDays++; }
            if (r.status === 2 || r.status === 4) { lateCnt++; }
            if (r.status === 3 || r.status === 4) { earlyCnt++; }
            if (r.status === 5) { absentCnt++; }
            lateMin += r.late_minutes || 0;
            earlyMin += r.early_leave_minutes || 0;
            otMin += r.overtime_minutes || 0;
        });

        var summary =
            '<div class="dash-cards">' +
            '<div class="dash-card"><div class="dash-card__label">' + i18n.field_total_days + '</div><div class="dash-card__value">' + totalDays + '</div></div>' +
            '<div class="dash-card dash-card--green"><div class="dash-card__label">' + i18n.field_normal_days + '</div><div class="dash-card__value">' + normalDays + '</div></div>' +
            '<div class="dash-card dash-card--red"><div class="dash-card__label">' + i18n.field_late_count + '</div><div class="dash-card__value">' + lateCnt + '</div><div class="dash-card__sub">' + lateMin + ' ' + i18n.unit_minutes + '</div></div>' +
            '<div class="dash-card dash-card--yellow"><div class="dash-card__label">' + i18n.field_early_count + '</div><div class="dash-card__value">' + earlyCnt + '</div><div class="dash-card__sub">' + earlyMin + ' ' + i18n.unit_minutes + '</div></div>' +
            '<div class="dash-card dash-card--red"><div class="dash-card__label">' + i18n.field_absent_count + '</div><div class="dash-card__value">' + absentCnt + '</div></div>' +
            '<div class="dash-card dash-card--green"><div class="dash-card__label">' + i18n.field_overtime_total + '</div><div class="dash-card__value">' + otMin + '</div><div class="dash-card__sub">' + i18n.unit_minutes + '</div></div>' +
            '</div>';

        // 每日明細表格
        var rows = records.map(function (r) {
            var st = statusMap[r.status] || { text: '-', css: '' };
            var clockIn = r.clock_in ? r.clock_in.substring(11, 19) : '-';
            var clockOut = r.clock_out ? r.clock_out.substring(11, 19) : '-';

            return (
                '<tr>' +
                '<td>' + r.date + '</td>' +
                '<td>' + clockIn + '</td>' +
                '<td>' + clockOut + '</td>' +
                '<td>' + (r.late_minutes > 0 ? '<span class="att-warn">' + r.late_minutes + ' ' + i18n.unit_minutes + '</span>' : '-') + '</td>' +
                '<td>' + (r.early_leave_minutes > 0 ? '<span class="att-warn">' + r.early_leave_minutes + ' ' + i18n.unit_minutes + '</span>' : '-') + '</td>' +
                '<td>' + (r.overtime_minutes > 0 ? '<span class="att-good">' + r.overtime_minutes + ' ' + i18n.unit_minutes + '</span>' : '-') + '</td>' +
                '<td><span class="badge ' + st.css + '">' + st.text + '</span></td>' +
                '<td class="att-detail-meta">' + (r.clock_in_ip || '-') + '</td>' +
                '<td class="att-detail-meta">' + (r.clock_in_device ? r.clock_in_device.substring(0, 40) : '-') + '</td>' +
                '</tr>'
            );
        }).join('');

        var table =
            '<table><thead><tr>' +
            '<th>' + i18n.field_date + '</th>' +
            '<th>' + i18n.field_clock_in + '</th>' +
            '<th>' + i18n.field_clock_out + '</th>' +
            '<th>' + i18n.field_late + '</th>' +
            '<th>' + i18n.field_early_leave + '</th>' +
            '<th>' + i18n.field_overtime + '</th>' +
            '<th>' + i18n.field_status + '</th>' +
            '<th>' + i18n.field_ip + '</th>' +
            '<th>' + i18n.field_device + '</th>' +
            '</tr></thead><tbody>' + rows + '</tbody></table>';

        root.innerHTML = header + summary + table;

        // flatpickr 月份選擇器
        flatpickr('#detail-month-picker', {
            plugins: [new monthSelectPlugin({ shorthand: true, dateFormat: 'Y-m', altFormat: 'Y-m' })],
            disableMobile: true,
            onChange: function (selectedDates) {
                if (selectedDates.length === 0) { return; }
                var d = selectedDates[0];
                currentMonth = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
                loadDetail(currentMonth);
            }
        });
    }

    // 初始化
    loadDetail(currentMonth);
})();
