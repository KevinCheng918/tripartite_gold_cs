/**
 * 站台點數報表（餘點曲線圖）
 *
 * 資料來自 `station_credit_log` —— 每次同步寫一筆
 * （排程、列表的同步按鈕、小幫手查餘點、餘點告警、虛擬機試算都算），
 * 補點審核通過也寫一筆（那幾筆才有「原本／異動／現在」）。
 *
 * 系統點數與商城點數是**兩條線**：主系統補點 API 回的是「被補的那一種」的
 * 餘額，混在一條線上會變成憑空的大跳（需求方 2026-10-30）。
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

    /*
     * 每條線對齊到共用時間軸之後的原始點（`chartSeries[datasetIndex][dataIndex]`）。
     *
     * ⚠ tooltip 需要的是**後端那一包原始資料**（來源、跟前一點差多少、補點的
     * 原本／異動／現在），Chart.js 的 `item.parsed.y` 只有數字 —— 所以得自己
     * 留一份，並且跟 dataset 的順序一致。
     */
    var chartSeries = [];

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
        var shopPoints = data.shop_points || [];
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
        renderChart(points, shopPoints);
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

        box.innerHTML = [
            card(i18n.summary_range, escapeHtml(range.start + ' ~ ' + range.end), ''),
            card(i18n.summary_first, formatNumber(summary.first), ''),
            card(i18n.summary_last, formatNumber(summary.last), ''),
            card(i18n.summary_diff, signed(diff), diffClass)
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
     * 兩條線共用的時間軸
     *
     * ⚠ **兩條線的時間點不一樣**：系統點數是每小時取樣，商城點數只有補點那
     * 幾筆。各自用自己的 labels 畫的話，同一個 x 位置在兩條線上代表不同時間 ——
     * 所以先取聯集當共用軸，每條線缺的位置補 null（配 `spanGaps` 跨過去）。
     *
     * ⚠ 字串排序就是時間排序：`YYYY-MM-DD HH:mm` 是零補位的固定格式。
     * 不要 `new Date()` 再比 —— 那會把時區問題帶進來。
     *
     * @param {Array} points
     * @param {Array} shopPoints
     * @return {Array}
     */
    function buildLabels(points, shopPoints) {
        var seen = {};
        var labels = [];

        points.concat(shopPoints).forEach(function (p) {
            if (seen[p.at]) { return; }

            seen[p.at] = true;
            labels.push(p.at);
        });

        return labels.sort();
    }

    /**
     * 把一條線的點對齊到共用時間軸
     *
     * ⚠ 同一分鐘同一種點數有兩筆時（補點剛好撞上同步）只會留**後面那一筆** ——
     * 時間標籤只到分鐘，兩個點會疊在同一個 x 上。後端是照時間由舊到新排的，
     * 所以留下的是較新的那個餘額，剛好是對的那一個。
     *
     * @param {Array} series
     * @param {Array} labels
     * @return {Array} 跟 labels 等長；沒有資料的位置是 null
     */
    function align(series, labels) {
        var byLabel = {};

        series.forEach(function (p) { byLabel[p.at] = p; });

        return labels.map(function (label) {
            return byLabel[label] || null;
        });
    }

    /**
     * 一條線
     *
     * ⚠ **補點的點要畫得比較大**：那幾個點是人為異動，跟取樣到的點意義不同；
     * 大小一樣的話，圖上那一跳看起來就像客戶自己的變化。
     *
     * ⚠ **兩條線各自一個 y 軸**（`yAxisID`）：系統點數與商城點數的量級可能
     * 差很多，共用一個軸的話小的那條會被壓成一條平貼底部的直線，看不出走勢。
     *
     * @param {string} label
     * @param {Array}  aligned
     * @param {string} color
     * @param {string} background
     * @param {string} axis   y／yShop
     * @return {object}
     */
    function dataset(label, aligned, color, background, axis) {
        return {
            label: label,
            yAxisID: axis,
            data: aligned.map(function (p) { return p ? p.credits : null; }),
            borderColor: color,
            backgroundColor: background,
            borderWidth: 2,
            pointRadius: aligned.map(function (p) { return isTopup(p) ? 6 : 3; }),
            pointStyle: aligned.map(function (p) { return isTopup(p) ? 'rectRot' : 'circle'; }),
            fill: true,
            stepped: true,
            // 商城那條線中間大半是 null，不跨過去的話整條線都連不起來
            spanGaps: true
        };
    }

    /**
     * 這個點是補點來的嗎（有異動金額的才是）
     *
     * @param {object} point
     * @return {boolean}
     */
    function isTopup(point) {
        return !!point && point.change !== null && point.change !== undefined;
    }

    /**
     * 畫曲線
     *
     * ⚠ **用 stepped 而不是直線**：我們只在同步的那一刻取樣，兩點之間實際
     * 發生什麼並不知道。直線會讓人以為中間是線性消耗 —— 階梯線表達的才是
     * 「上次同步到這次同步之間，我們只知道這兩個值」。
     *
     * ⚠ **商城沒有資料時不要畫那條線**：主系統的站台資訊沒有回商城餘額，
     * 同步取樣不到，所以沒補過商城點數的站台 `shop_points` 一定是空的 ——
     * 空 dataset 會在圖例上留一條什麼都沒有的線，看的人會以為壞了。
     *
     * @param {Array} points     系統點數
     * @param {Array} shopPoints 商城點數
     */
    function renderChart(points, shopPoints) {
        var ctx = document.getElementById('cr-chart');
        var labels = buildLabels(points, shopPoints);

        if (chart) {
            chart.destroy();
        }

        chartSeries = [align(points, labels)];

        var datasets = [dataset(i18n.chart_label, chartSeries[0], '#3f6ad8', 'rgba(63,106,216,0.08)', 'y')];
        var hasShop = shopPoints.length > 0;

        if (hasShop) {
            chartSeries.push(align(shopPoints, labels));
            datasets.push(dataset(i18n.chart_label_shop, chartSeries[1], '#f7b924', 'rgba(247,185,36,0.08)', 'yShop'));
        }

        chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    // 只有一條線時圖例是廢話，兩條線就必須標出哪條是哪條
                    legend: { display: datasets.length > 1 },
                    tooltip: {
                        callbacks: {
                            label: tooltipLabel,
                            afterBody: tooltipAfterBody
                        }
                    }
                },
                scales: {
                    y: {
                        ticks: {
                            /*
                             * 兩個軸的時候刻度要染成線的顏色，不然看的人不知道
                             * 左邊那排數字是哪一條線的。只有一條線時維持預設色
                             * （沒有別的軸要分辨，染色只是雜訊）。
                             */
                            color: hasShop ? '#3f6ad8' : undefined,
                            callback: function (value) { return formatNumber(value); }
                        }
                    },
                    /*
                     * 商城點數的右側軸。
                     *
                     * ⚠ `display` 跟著商城有沒有資料走：沒有的時候留一個空軸
                     * 在右邊，看的人會以為那條線沒畫出來。
                     *
                     * ⚠ `grid.drawOnChartArea: false`：兩個軸各畫一次格線的話，
                     * 圖上會出現兩套對不起來的橫線。
                     */
                    yShop: {
                        display: hasShop,
                        position: 'right',
                        grid: { drawOnChartArea: false },
                        ticks: {
                            color: '#f7b924',
                            callback: function (value) { return formatNumber(value); }
                        }
                    }
                }
            }
        });
    }

    /**
     * tooltip 主體：餘額 ＋ 跟前一點差多少
     *
     * 需求方 2026-10-30：「每一天的點，都要顯示跟昨天正負多少。」同步是一小時
     * 一次，所以比的是前一個點 —— 差多少由後端算（它才知道中間有幾筆同步失敗）。
     *
     * @param {object} item
     * @return {string}
     */
    function tooltipLabel(item) {
        var text = item.dataset.label + '：' + formatNumber(item.parsed.y);
        var point = pointOf(item);

        // 區間第一個點沒有「前一點」可比，diff 是 null —— 不要顯示成「±0」
        if (point && point.diff !== null && point.diff !== undefined) {
            text += '（' + i18n.tooltip_diff + ' ' + signed(point.diff) + '）';
        }

        return text;
    }

    /**
     * tooltip 下半：來源，補點的再加上原本／異動／現在
     *
     * ⚠ **「原本」是後端用「現在 − 異動」算的，不是前一個點**：上次同步到補點
     * 之間客戶照樣在消耗，前一個點不是補點前一刻的餘額。
     *
     * @param {Array} items
     * @return {Array}
     */
    function tooltipAfterBody(items) {
        var lines = [];

        items.forEach(function (item) {
            var point = pointOf(item);

            if (!point) { return; }

            // 滑過去要看得到「怎麼觸發的」—— 凹下去是真的掉點
            // 還是剛好有人手動同步，分得出來才有意義
            lines.push(i18n.tooltip_source + '：' + sourceLabel(point.source));

            if (!isTopup(point)) { return; }

            lines.push(i18n.tooltip_before + '：' + formatNumber(point.before));
            lines.push(i18n.tooltip_change + '：' + signed(point.change));
            lines.push(i18n.tooltip_after + '：' + formatNumber(point.credits));
        });

        return lines;
    }

    /**
     * tooltip 項目對應的原始資料點
     *
     * @param {object} item
     * @return {object|null}
     */
    function pointOf(item) {
        var series = chartSeries[item.datasetIndex];

        return series ? series[item.dataIndex] : null;
    }

    /**
     * 帶正負號的數字（0 不加號）
     *
     * @param {number} value
     * @return {string}
     */
    function signed(value) {
        return (value > 0 ? '+' : '') + formatNumber(value);
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
