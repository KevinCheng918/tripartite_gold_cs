/**
 * 報表頁（內務管理 → 報表）
 *
 * 兩個分頁各自一套「期間切換 + 上下一期」，資料從後端拿結構化 JSON 再畫表。
 *
 * ⚠ **數字一律由後端算**，這裡只負責畫。打卡報表與超時統計的統計邏輯
 * 跟 Telegram 通知共用同一支 Service —— 前端再算一次就會有兩套答案。
 *
 * 權限與語系由 #report-app 的 data-* 帶進來。
 */
$(function () {
    var root = document.getElementById('report-app');
    if (!root) { return; }

    var i18n = JSON.parse(root.dataset.i18n);
    var canAttendance = root.dataset.canAttendance === '1';
    var canRemind = root.dataset.canRemind === '1';
    var canDetail = root.dataset.canDetail === '1';
    var csrfToken = $('meta[name="csrf-token"]').attr('content');

    /**
     * 代入語系字串的參數（:name 這類）
     *
     * @param {string} template
     * @param {Object} params
     * @returns {string}
     */
    function trans(template, params) {
        var text = template || '';

        Object.keys(params || {}).forEach(function (key) {
            text = text.split(':' + key).join(params[key]);
        });

        return text;
    }

    /**
     * @param {string} value
     * @returns {string}
     */
    function escapeHtml(value) {
        return $('<span>').text(value === null || value === undefined ? '' : value).html();
    }

    /**
     * @param {string} url
     * @returns {Promise}
     */
    function apiFetch(url) {
        return fetch(url, {
            headers: { 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
            credentials: 'same-origin'
        }).then(function (response) {
            // session 過期交給 AuthGuard 統一處理，理由見 public/js/common.js
            var intercepted = window.AuthGuard && window.AuthGuard.intercept(response);

            if (intercepted) { return intercepted; }

            return response.json().then(function (body) {
                if (!response.ok) { throw body; }

                return body;
            });
        });
    }

    /**
     * 期間往前／往後推一期
     *
     * ⚠ 後端收的是「以哪一天往回推」，算出來的是**上一期**。所以要看更早的
     * 一期就把基準日往前推一期的長度，看更晚的就往後推。
     *
     * ⚠ 月要用「先回到當月 1 號再加減月份」—— 直接對 31 號加一個月，
     * JS 的 Date 會溢位到下個月（1/31 + 1 月 = 3/3）。
     *
     * @param {string} date Y-m-d
     * @param {string} type daily / weekly / monthly
     * @param {number} step -1 往前、+1 往後
     * @returns {string} Y-m-d
     */
    function shiftDate(date, type, step) {
        var parts = date.split('-');
        var d = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));

        if (type === 'monthly') {
            d.setDate(1);
            d.setMonth(d.getMonth() + step);
        } else if (type === 'weekly') {
            d.setDate(d.getDate() + step * 7);
        } else {
            d.setDate(d.getDate() + step);
        }

        return d.getFullYear() + '-' +
            String(d.getMonth() + 1).padStart(2, '0') + '-' +
            String(d.getDate()).padStart(2, '0');
    }

    /**
     * 今天（基準日的初始值）
     *
     * @returns {string} Y-m-d
     */
    function today() {
        var d = new Date();

        return d.getFullYear() + '-' +
            String(d.getMonth() + 1).padStart(2, '0') + '-' +
            String(d.getDate()).padStart(2, '0');
    }

    /**
     * 把期間切換那一排按鈕的 active 換到被按的那顆
     *
     * @param {NodeList} buttons
     * @param {HTMLElement} active
     */
    function setActive(buttons, active) {
        buttons.forEach(function (btn) { btn.classList.remove('active'); });
        active.classList.add('active');
    }

    /**
     * 空狀態那一列
     *
     * @param {number} colspan
     * @returns {string}
     */
    function emptyRow(colspan) {
        return '<tr><td colspan="' + colspan + '" class="table-empty">' +
            '<i class="fas fa-inbox"></i>' + escapeHtml(i18n.empty) + '</td></tr>';
    }

    // ===== 打卡報表 =====

    var att = { type: 'weekly', date: today() };

    function loadAttendance() {
        apiFetch('/admin/report/ajax-attendance?type=' + att.type + '&date=' + att.date)
            .then(renderAttendance)
            .catch(function () {
                document.getElementById('att-table').innerHTML =
                    '<p class="text-danger mb-0">' + escapeHtml(i18n.msg.load_failed) + '</p>';
            });
    }

    function renderAttendance(body) {
        var range = body.range || {};
        document.getElementById('att-range').textContent = range.start + ' ～ ' + range.end;

        var rows = (body.rows || []).map(function (r) {
            // 只有看得到出勤明細的人，那一列才做成可點
            var clickable = canDetail ? ' class="js-att-row" style="cursor:pointer"' : '';

            return '<tr' + clickable + ' data-user-id="' + r.user_id + '">' +
                '<td><span class="cell-stack__main">' + escapeHtml(r.name) + '</span></td>' +
                '<td class="col-num">' + r.total_days + '</td>' +
                '<td class="col-num">' + r.normal_days + '</td>' +
                '<td class="col-num">' + r.late_count +
                '<div class="cell-stack__sub">' + trans(i18n.unit_min, { n: r.late_minutes }) + '</div></td>' +
                '<td class="col-num">' + r.early_count +
                '<div class="cell-stack__sub">' + trans(i18n.unit_min, { n: r.early_minutes }) + '</div></td>' +
                '<td class="col-num">' + r.absent_count + '</td>' +
                '<td class="col-num">' + r.amend_count + '</td>' +
                '<td class="col-num">' + trans(i18n.unit_day, { n: r.leave_days }) +
                '<div class="cell-stack__sub">' + trans(i18n.unit_hour, { n: r.leave_hours }) + '</div></td>' +
                '<td class="col-num">' + trans(i18n.unit_min, { n: r.overtime_minutes }) + '</td>' +
                '</tr>';
        }).join('');

        document.getElementById('att-table').innerHTML =
            '<table class="table table-hover align-middle data-table"><thead class="thead-gold"><tr>' +
            '<th>' + escapeHtml(i18n.att_user) + '</th>' +
            '<th class="col-num">' + escapeHtml(i18n.att_days) + '</th>' +
            '<th class="col-num">' + escapeHtml(i18n.att_normal) + '</th>' +
            '<th class="col-num">' + escapeHtml(i18n.att_late) + '</th>' +
            '<th class="col-num">' + escapeHtml(i18n.att_early) + '</th>' +
            '<th class="col-num">' + escapeHtml(i18n.att_absent) + '</th>' +
            '<th class="col-num">' + escapeHtml(i18n.att_amend) + '</th>' +
            '<th class="col-num">' + escapeHtml(i18n.att_leave) + '</th>' +
            '<th class="col-num">' + escapeHtml(i18n.att_overtime) + '</th>' +
            '</tr></thead><tbody>' + (rows || emptyRow(9)) + '</tbody></table>';

        document.getElementById('att-hint').textContent = canDetail ? i18n.att_detail_hint : '';

        document.querySelectorAll('.js-att-row').forEach(function (row) {
            row.addEventListener('click', function () {
                window.location.href = '/admin/attendance/detail/' + row.dataset.userId;
            });
        });
    }

    function bindAttendance() {
        var typeButtons = document.querySelectorAll('.js-att-type');

        typeButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                att.type = btn.dataset.type;
                // 換期間類型時回到最近一期，否則基準日會停在上一種期間推出來的位置
                att.date = today();
                setActive(typeButtons, btn);
                loadAttendance();
            });
        });

        document.querySelector('.js-att-prev').addEventListener('click', function () {
            att.date = shiftDate(att.date, att.type, -1);
            loadAttendance();
        });

        document.querySelector('.js-att-next').addEventListener('click', function () {
            att.date = shiftDate(att.date, att.type, 1);
            loadAttendance();
        });
    }

    // ===== 超時提醒統計 =====

    var rmd = { type: 'daily', date: today() };

    function loadRemind() {
        apiFetch('/admin/report/ajax-remind?type=' + rmd.type + '&date=' + rmd.date)
            .then(renderRemind)
            .catch(function () {
                document.getElementById('rmd-body').innerHTML =
                    '<p class="text-danger mb-0">' + escapeHtml(i18n.msg.load_failed) + '</p>';
            });
    }

    function renderRemind(body) {
        var range = body.range || {};
        var summary = body.summary || {};

        document.getElementById('rmd-range').textContent = range.start === range.end
            ? range.start
            : range.start + ' ～ ' + range.end;

        document.getElementById('rmd-summary').textContent = trans(i18n.remind_summary, {
            tickets: summary.tickets || 0,
            times: summary.times || 0,
            escalated: summary.escalated || 0,
            escalate_at: summary.escalate_at || 0
        });

        var ticketRows = (body.by_ticket || []).map(function (t) {
            return '<tr>' +
                '<td><span class="cell-stack__main">' + escapeHtml(t.question) + '</span></td>' +
                '<td>' + escapeHtml(t.group) + '</td>' +
                '<td class="col-num">' + trans(i18n.unit_times, { n: t.times }) + '</td>' +
                '<td>' + escapeHtml(t.status_label) + '</td>' +
                '</tr>';
        }).join('');

        var userRows = (body.by_user || []).map(function (u) {
            // name 是 null 代表那次提醒沒 tag 到任何人 —— 要看得出來，不能留白
            var name = u.name === null
                ? '<span class="text-muted">' + escapeHtml(i18n.remind_no_target) + '</span>'
                : '<span class="cell-stack__main">' + escapeHtml(u.name) + '</span>';

            return '<tr>' +
                '<td>' + name + '</td>' +
                '<td class="col-num">' + trans(i18n.unit_times, { n: u.times }) + '</td>' +
                '<td class="col-num">' + u.tickets + '</td>' +
                '</tr>';
        }).join('');

        document.getElementById('rmd-body').innerHTML =
            '<h6 class="mt-3">' + escapeHtml(i18n.remind_by_ticket) + '</h6>' +
            '<div class="table-responsive">' +
            '<table class="table table-hover align-middle data-table"><thead class="thead-gold"><tr>' +
            '<th>' + escapeHtml(i18n.remind_question) + '</th>' +
            '<th>' + escapeHtml(i18n.remind_group) + '</th>' +
            '<th class="col-num">' + escapeHtml(i18n.remind_times) + '</th>' +
            '<th>' + escapeHtml(i18n.remind_status) + '</th>' +
            '</tr></thead><tbody>' + (ticketRows || emptyRow(4)) + '</tbody></table></div>' +

            '<h6 class="mt-4">' + escapeHtml(i18n.remind_by_user) + '</h6>' +
            '<div class="table-responsive">' +
            '<table class="table table-hover align-middle data-table"><thead class="thead-gold"><tr>' +
            '<th>' + escapeHtml(i18n.remind_user) + '</th>' +
            '<th class="col-num">' + escapeHtml(i18n.remind_times) + '</th>' +
            '<th class="col-num">' + escapeHtml(i18n.remind_tickets) + '</th>' +
            '</tr></thead><tbody>' + (userRows || emptyRow(3)) + '</tbody></table></div>' +
            '<p class="text-muted mb-0 mt-2" style="font-size:0.875rem">' +
            '<i class="fas fa-info-circle me-1"></i>' + escapeHtml(i18n.remind_manager_note) + '</p>';
    }

    function bindRemind() {
        var typeButtons = document.querySelectorAll('.js-rmd-type');

        typeButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                rmd.type = btn.dataset.type;
                rmd.date = today();
                setActive(typeButtons, btn);
                loadRemind();
            });
        });

        document.querySelector('.js-rmd-prev').addEventListener('click', function () {
            rmd.date = shiftDate(rmd.date, rmd.type, -1);
            loadRemind();
        });

        document.querySelector('.js-rmd-next').addEventListener('click', function () {
            rmd.date = shiftDate(rmd.date, rmd.type, 1);
            loadRemind();
        });
    }

    // ===== 啟動 =====

    if (canAttendance) {
        bindAttendance();
        loadAttendance();
    }

    if (canRemind) {
        bindRemind();
        loadRemind();
    }
});
