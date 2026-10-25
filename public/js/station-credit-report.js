/**
 * 站台點數報表（餘點曲線圖）
 *
 * 資料來自 `station_credit_log` —— 每次同步寫一筆
 * （排程、列表的同步按鈕、小幫手查餘點、餘點告警、虛擬機試算都算）。
 */
(function () {
    'use strict';

    var app = document.getElementById('credit-report-app');

    if (!app) {
        return;
    }

    var i18n = JSON.parse(app.dataset.i18n || '{}');
    var sourceLabels = JSON.parse(app.dataset.sourceLabels || '{}');
    var url = app.dataset.url;
    var csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    var fromInput = document.getElementById('cr-date-from');
    var toInput = document.getElementById('cr-date-to');
    var chart = null;

    /**
     * @param {string} target
     * @return {Promise}
     */
    function apiFetch(target) {
        return fetch(target, {
            credentials: 'same-origin',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'Content-Type': 'application/json',
                Accept: 'application/json'
            }
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
     * 顯示訊息
     *
     * ⚠ 不用 `alert()` —— 這個專案一律用 modal。
     *
     * @param {string} message
     * @return {void}
     */
    function showMessage(message) {
        var modalEl = document.getElementById('modal-cr-msg');

        if (!modalEl) { return; }

        modalEl.querySelector('.modal-body').textContent = message;
        new bootstrap.Modal(modalEl).show();
    }

    /**
     * 抓資料並重畫
     */
    function load() {
        var query = [];

        if (fromInput.value && toInput.value) {
            query.push('start=' + encodeURIComponent(fromInput.value));
            query.push('end=' + encodeURIComponent(toInput.value));
        }

        apiFetch(url + (query.length ? '?' + query.join('&') : ''))
            .then(function (data) {
                render(data);
            })
            .catch(function () {
                showMessage(i18n.load_failed);
            });
    }

    /**
     * @param {object} data
     */
    function render(data) {
        var points = data.points || [];
        var failures = data.failures || [];
        var range = data.range || {};

        /*
         * 第一次進來沒帶期間，後端用預設的一週 —— 把它回填到輸入框，
         * 不然畫面上兩個欄位是空的，看的人不知道圖上是哪幾天。
         */
        if (!fromInput.value && range.start) {
            fromInput.value = range.start;
            toInput.value = range.end;
        }

        renderSummary(data.summary || {}, range);
        renderChart(points);
        renderFailures(failures);
    }

    /**
     * 上方那排數字
     *
     * @param {object} summary
     * @param {object} range
     */
    function renderSummary(summary, range) {
        var box = document.getElementById('cr-summary');

        // 這段期間一筆都沒有 —— 講清楚，不要只留一張空圖
        if (!summary.count) {
            // 「每次都同步失敗」跟「根本沒人同步」是兩回事，不能講同一句
            var emptyText = summary.failed ? i18n.empty_only_failed : i18n.empty;

            box.innerHTML = '<div class="col-12"><div class="alert alert-light mb-0">'
                + escapeHtml(emptyText) + '</div></div>';

            return;
        }

        var diff = summary.diff || 0;
        var diffClass = diff > 0 ? 'text-success' : (diff < 0 ? 'text-danger' : 'text-muted');
        var diffSign = diff > 0 ? '+' : '';

        box.innerHTML = [
            card(i18n.summary_range, escapeHtml(range.start + ' ~ ' + range.end), ''),
            card(i18n.summary_first, formatNumber(summary.first), ''),
            card(i18n.summary_last, formatNumber(summary.last), ''),
            card(i18n.summary_diff, diffSign + formatNumber(diff), diffClass)
        ].join('');
    }

    /**
     * 一張摘要小卡
     *
     * ⚠ **欄寬要寫 `col-md-3`，不能寫裸的 `col-md`**：Bootstrap 5 的 grid 把
     * 不帶數字的 `.col-md`（`@media md` 內）排在 `.col-6` 之前，兩者
     * specificity 相同 —— 所以 `col-md col-6` 寫在一起時永遠是 `.col-6` 贏，
     * 四張卡會變成兩兩一行各佔一半。`.col-md-3` 在更後面的 media query，
     * 才壓得過 `.col-6`。
     *
     * ⚠ **等高用 `d-flex` + `flex-fill`，不要用 `h-100`**：父層 row 沒有確定
     * 高度時，`height:100%` 會跟 flex 的 stretch 互相要高度，實測會撐出
     * 將近 1000px 的空白格。站台列表那邊也踩過同一件事
     * （見 `admin/station/index.blade.php` 的註解）。
     *
     * @param {string} label
     * @param {string} value
     * @param {string} valueClass
     * @return {string}
     */
    function card(label, value, valueClass) {
        return '<div class="col-md-3 col-6 d-flex">'
            + '<div class="border rounded p-2 flex-fill">'
            + '<div class="text-muted" style="font-size:0.8rem">' + escapeHtml(label) + '</div>'
            + '<div class="fw-bold ' + valueClass + '">' + value + '</div>'
            + '</div></div>';
    }

    /**
     * 畫曲線
     *
     * ⚠ **用 stepped 而不是直線**：我們只在同步的那一刻取樣，兩點之間實際
     * 發生什麼並不知道。直線會讓人以為中間是線性消耗 —— 階梯線表達的才是
     * 「上次同步到這次同步之間，我們只知道這兩個值」。
     *
     * @param {Array} points
     */
    function renderChart(points) {
        var ctx = document.getElementById('cr-chart');

        if (chart) {
            chart.destroy();
        }

        chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: points.map(function (p) { return p.at; }),
                datasets: [{
                    label: i18n.chart_label,
                    data: points.map(function (p) { return p.credits; }),
                    borderColor: '#3f6ad8',
                    backgroundColor: 'rgba(63,106,216,0.08)',
                    borderWidth: 2,
                    pointRadius: 3,
                    fill: true,
                    stepped: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            // 滑過去要看得到「怎麼觸發的」—— 凹下去是真的掉點
                            // 還是剛好有人手動同步，分得出來才有意義
                            afterBody: function (items) {
                                var point = points[items[0].dataIndex];

                                return point ? (i18n.tooltip_source + '：' + sourceLabel(point.source)) : '';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        ticks: {
                            callback: function (value) { return formatNumber(value); }
                        }
                    }
                }
            }
        });
    }

    /**
     * 同步失敗的那幾筆
     *
     * @param {Array} failures
     */
    function renderFailures(failures) {
        var cardBox = document.getElementById('cr-failure-card');
        var box = document.getElementById('cr-failures');

        if (!failures.length) {
            cardBox.style.display = 'none';

            return;
        }

        cardBox.style.display = '';

        var rows = failures.map(function (f) {
            return '<tr><td>' + escapeHtml(f.at) + '</td><td>' + escapeHtml(sourceLabel(f.source)) + '</td></tr>';
        }).join('');

        box.innerHTML = '<table class="table table-sm mb-0"><thead><tr>'
            + '<th>' + escapeHtml(i18n.failure_at) + '</th>'
            + '<th>' + escapeHtml(i18n.failure_source) + '</th>'
            + '</tr></thead><tbody>' + rows + '</tbody></table>';
    }

    /**
     * @param {string} source
     * @return {string}
     */
    function sourceLabel(source) {
        return sourceLabels[source] || source;
    }

    /**
     * ⚠ 千分位用 toLocaleString，不要自己寫正則 —— 小數位與負號都有坑
     *
     * @param {number} value
     * @return {string}
     */
    function formatNumber(value) {
        return Number(value || 0).toLocaleString('en-US', { maximumFractionDigits: 2 });
    }

    /**
     * @param {string} text
     * @return {string}
     */
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text == null ? '' : text;

        return div.innerHTML;
    }

    document.getElementById('cr-search').addEventListener('click', load);

    /*
     * 快捷鈕走共用的 window.DateRange（common.js），不要自己算週一是哪天 ——
     * 補點紀錄與報表頁都用同一份定義。
     */
    Array.prototype.forEach.call(document.querySelectorAll('.js-cr-range'), function (btn) {
        btn.addEventListener('click', function () {
            var range = window.DateRange[btn.dataset.range]();

            fromInput.value = range.start;
            toInput.value = range.end;
            load();
        });
    });

    load();
}());
