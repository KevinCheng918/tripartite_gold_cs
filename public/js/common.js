/**
 * 全站共用的前端行為
 *
 * 由 layouts/app.blade.php 載入，所有頁面都會套用。
 */
(function () {
    'use strict';

    /**
     * 是否為觸控為主的裝置
     *
     * 拖曳排序用來決定要不要加長按延遲。不依賴 SortableJS 的
     * delayOnTouchOnly 判斷 —— 部分 Windows 環境（尤其虛擬機）會回報
     * 具備觸控能力，導致滑鼠拖曳被當成觸控，必須長按且中途不能移動，
     * 結果就是完全拖不動。
     *
     * @returns {boolean}
     */
    window.isCoarsePointer = function () {
        return !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches);
    };

    /** @type {string[]} 星期簡稱，索引對應 JS 的 getDay()（0=日 … 6=六） */
    var WEEKDAYS = ['日', '一', '二', '三', '四', '五', '六'];

    /**
     * 在日期後面加上星期，例如 2026-09-08 → 2026-09-08 (二)
     *
     * 用 new Date(y, m-1, d) 而非直接 parse 字串：
     * 「2026-09-08」會被當成 UTC 午夜解析，在 UTC+8 顯示仍是同一天沒問題，
     * 但負時區會倒退一天而算出錯誤的星期。拆開組件建立則一律是本地時間。
     *
     * @param {string} dateStr 日期字串，開頭需為 YYYY-MM-DD
     * @returns {string} 加上星期的字串；無法解析時原樣回傳
     */
    window.withWeekday = function (dateStr) {
        if (!dateStr) { return ''; }

        var m = String(dateStr).match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (!m) { return dateStr; }

        var d = new Date(parseInt(m[1], 10), parseInt(m[2], 10) - 1, parseInt(m[3], 10));
        if (isNaN(d.getTime())) { return dateStr; }

        return m[0] + ' (' + WEEKDAYS[d.getDay()] + ')';
    };

    /**
     * 停用 input[type=number] 的滾輪改值
     *
     * 數字欄位取得焦點後，滑鼠滾過去就會把金額、天數這類值改掉，
     * 使用者往往沒察覺就送出。
     *
     * 這裡用 blur 而不是 preventDefault：
     * preventDefault 雖然擋得住改值，但會連頁面捲動一起擋掉，
     * 滑到一半卡住更難用。blur 之後值不會變，頁面照常捲。
     */
    document.addEventListener('wheel', function (e) {
        var el = document.activeElement;
        if (!el || el !== e.target) { return; }
        if (el.tagName !== 'INPUT' || el.type !== 'number') { return; }

        el.blur();
    }, { passive: true });

    // ---------------------------------------------------------------
    //  時間選擇（時、分兩個下拉）
    // ---------------------------------------------------------------

    /*
     * 為什麼不用原生 <input type="time">：
     *
     * 它的顯示是 12 還是 24 小時制**由瀏覽器／系統的 locale 決定，網頁端控制不了**。
     * 試過在 input 上掛 lang="en-GB"（24 小時制的 locale）—— HTML 有正確輸出，
     * 但 Chromium 看的是瀏覽器的 UI 語言，不吃這個屬性，照樣顯示「上午／下午」。
     * iOS Safari 更是只跟著裝置的「24 小時制」開關走。
     *
     * 客服看到「下午 01:05」、系統其他地方寫「13:05」，很容易看錯時間，
     * 所以改成自己畫：兩個 select 就完全可控，而且手機上 select 是系統原生的
     * 底部滾輪，比 flatpickr 那組小箭頭好按得多。
     *
     * 用法：把原本的 input 標上 class="js-time-select"，
     * 元件會把它轉成 hidden 並在後面插入兩個下拉。
     * **input 的 id 與 value 都維持原樣**，所以既有的
     * `getElementById(id).value` 讀取完全不用改；
     * 用程式設值則要改走 window.TimeSelect.set()。
     */

    /** @type {string} 已初始化的標記，避免同一個 input 被轉兩次 */
    var TS_READY = 'tsReady';

    /**
     * 產生一個補零的兩位數字串
     *
     * @param {number} n
     * @returns {string}
     */
    function pad2(n) {
        return n < 10 ? '0' + n : String(n);
    }

    /**
     * 建立時或分的下拉
     *
     * @param {number}  total       選項數（時 24、分 60）
     * @param {boolean} required
     * @param {string}  placeholder
     * @returns {HTMLSelectElement}
     */
    function buildSelect(total, required, placeholder) {
        var select = document.createElement('select');
        var i;

        select.className = 'form-select ts-select';
        select.required = required;

        // 空選項要留著：沒有它，下拉一渲染就等於已經選了 00，
        // 使用者沒碰過的欄位會被當成 00:00 送出去
        select.appendChild(new Option(placeholder, ''));

        for (i = 0; i < total; i++) {
            select.appendChild(new Option(pad2(i), pad2(i)));
        }

        return select;
    }

    /**
     * 把一個 input 轉成時分下拉
     *
     * @param {HTMLInputElement} input
     * @returns {void}
     */
    function buildTimeSelect(input) {
        if (input.dataset[TS_READY]) { return; }

        input.dataset[TS_READY] = '1';

        // hidden input 不參與 HTML5 驗證，required 要移到兩個下拉上，
        // 否則表單會在一個看不見的欄位上報錯而且沒有任何提示
        var required = input.required;

        input.required = false;
        input.type = 'hidden';

        var wrap = document.createElement('div');
        var hour = buildSelect(24, required, '時');
        var minute = buildSelect(60, required, '分');
        var separator = document.createElement('span');

        wrap.className = 'ts-wrap';
        separator.className = 'ts-separator';
        separator.textContent = ':';

        wrap.appendChild(hour);
        wrap.appendChild(separator);
        wrap.appendChild(minute);
        input.parentNode.insertBefore(wrap, input.nextSibling);

        /**
         * 兩個下拉 → input.value
         *
         * @returns {void}
         */
        function pull() {
            var value = (hour.value && minute.value) ? hour.value + ':' + minute.value : '';

            if (input.value === value) { return; }

            input.value = value;
            // bubbles 一定要開：請假時長那類計算是掛在 document 上的事件委派，
            // 不冒泡的話它永遠收不到
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }

        /**
         * input.value → 兩個下拉
         *
         * @param {string} value HH:mm；空字串表示清空
         * @returns {void}
         */
        function push(value) {
            var parts = String(value || '').split(':');

            hour.value = parts.length === 2 ? parts[0] : '';
            minute.value = parts.length === 2 ? parts[1] : '';
        }

        hour.addEventListener('change', pull);
        minute.addEventListener('change', pull);

        // 從外部設值時要走這裡，光改 input.value 下拉不會跟著動
        input._timeSelectPush = push;

        push(input.value);
    }

    window.TimeSelect = {
        /**
         * 掃描並初始化時間下拉
         *
         * @param {Element} [root] 預設整份文件
         * @returns {void}
         */
        init: function (root) {
            (root || document).querySelectorAll('input.js-time-select').forEach(buildTimeSelect);
        },

        /**
         * 設定時間並同步下拉
         *
         * 取代 `getElementById(id).value = '08:00'` —— 直接改 value 的話
         * 兩個下拉不會跟著變，畫面會停在上一次的選擇。
         *
         * @param {string} id
         * @param {string} value HH:mm 或 HH:mm:ss；空字串清空
         * @returns {void}
         */
        set: function (id, value) {
            var input = document.getElementById(id);

            if (!input) { return; }

            // 後端的 time 欄位有的帶秒（08:00:00），下拉只認 HH:mm
            input.value = String(value || '').substring(0, 5);

            if (input._timeSelectPush) { input._timeSelectPush(input.value); }
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        window.TimeSelect.init();
    });
})();
