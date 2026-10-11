/**
 * 報表頁（內務管理 → 報表）
 *
 * 兩個分頁各自一組「起訖日期 ＋ 快捷鈕」，版面與行為比照補點紀錄。
 *
 * ⚠ **數字一律由後端算**，這裡只負責畫。打卡報表與超時統計的統計邏輯
 * 跟 Telegram 通知共用同一支 Service —— 前端再算一次就會有兩套答案。
 *
 * ⚠ 快捷鈕走共用的 `window.DateRange`（common.js），不要自己算週一是哪天：
 * 補點紀錄、財務、這一頁各寫一份的話，「本週」遲早會有三種答案。
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
     * 把後端的驗證錯誤挑一句出來顯示
     *
     * 422 的 body 是 `{message, errors: {end: ['…']}}`。直接用 `message`
     * 拿到的是 Laravel 那句「給定的資料無效」，對使用者沒有意義 ——
     * 要的是 errors 裡那一句（期間太長、結束日早於開始日）。
     *
     * @param {Object} body
     * @returns {string}
     */
    function errorText(body) {
        var errors = body && body.errors;

        if (errors) {
            var first = Object.keys(errors)[0];

            if (first && errors[first] && errors[first].length) {
                return errors[first][0];
            }
        }

        return (body && body.message) || i18n.msg.load_failed;
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

    /**
     * 綁一組「起訖日期 ＋ 快捷鈕 ＋ 查詢」
     *
     * 兩個分頁的互動一模一樣，只有 id 前綴與要呼叫的載入函式不同。
     *
     * @param {string} prefix 元素 id / class 的前綴（att、rmd）
     * @param {Function} load 查詢函式，收 (from, to)
     * @param {{from: string, to: string}} preset 預設帶入的期間
     * @returns {void}
     */
    function bindPeriod(prefix, load, preset) {
        var from = document.getElementById(prefix + '-date-from');
        var to = document.getElementById(prefix + '-date-to');

        function search() {
            load(from.value, to.value);
        }

        document.querySelectorAll('.js-' + prefix + '-range').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var build = window.DateRange[btn.dataset.range];

                // data-range 打錯字時安靜跳過，不要讓整頁的 JS 掛掉
                if (typeof build !== 'function') { return; }

                var range = build();
                from.value = range.from;
                to.value = range.to;

                // 快捷鈕按下去直接查，省掉再按一次「查詢」
                search();
            });
        });

        document.querySelector('.js-' + prefix + '-search').addEventListener('click', search);

        // Enter 也要能查 —— 改完日期的下一個動作就是查，手不該被迫離開鍵盤
        [from, to].forEach(function (input) {
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { search(); }
            });
        });

        from.value = preset.from;
        to.value = preset.to;
        search();
    }

    // ===== 打卡報表 =====

    /**
     * @param {string} from Y-m-d
     * @param {string} to Y-m-d
     * @returns {void}
     */
    function loadAttendance(from, to) {
        apiFetch('/admin/report/ajax-attendance?start=' + from + '&end=' + to)
            .then(renderAttendance)
            .catch(function (body) {
                document.getElementById('att-range').textContent = '';
                document.getElementById('att-hint').textContent = '';
                document.getElementById('att-table').innerHTML =
                    '<p class="text-danger mb-0">' + escapeHtml(errorText(body)) + '</p>';
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

    // ===== 超時提醒統計 =====

    /**
     * @param {string} from Y-m-d
     * @param {string} to Y-m-d
     * @returns {void}
     */
    function loadRemind(from, to) {
        apiFetch('/admin/report/ajax-remind?start=' + from + '&end=' + to)
            .then(renderRemind)
            .catch(function (body) {
                document.getElementById('rmd-range').textContent = '';
                document.getElementById('rmd-summary').textContent = '';
                document.getElementById('rmd-body').innerHTML =
                    '<p class="text-danger mb-0">' + escapeHtml(errorText(body)) + '</p>';
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

    // ===== 啟動 =====

    /*
     * 預設期間刻意跟 Telegram 那兩則對齊：打卡報表的通知報的是「上週」、
     * 超時統計的日報報的是「昨天」。一進來看到的就是同仁剛收到的那一份。
     */
    if (canAttendance) {
        bindPeriod('att', loadAttendance, window.DateRange.lastWeek());
    }

    if (canRemind) {
        bindPeriod('rmd', loadRemind, window.DateRange.yesterday());
    }
});
