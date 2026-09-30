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
     * 把後端回的時間字串顯示成台北時間，例如 2026-09-30 15:58
     *
     * ⚠ 不要自己 substring API 回傳的時間字串。
     *
     * Laravel 的 Model 轉 JSON 時會把 Carbon 序列化成 **UTC** 的 ISO 字串
     * （`serializeDate()` 預設走 `toJSON()`）—— DB 裡存的是台北時間
     * 「2026-09-07 22:05:31」，前端收到的卻是「2026-09-07T14:05:31.000000Z」。
     * 直接 `substring(0, 16).replace('T', ' ')` 會顯示 14:05，**少 8 小時**。
     *
     * 這裡明確指定 Asia/Taipei 而不是依賴瀏覽器本地時區：系統的時間基準是
     * 台北（config/app.php 的 timezone），客服在別的時區登入時看到的
     * 「上傳時間」也該跟同事講的是同一個時間。
     *
     * ⚠ 用 formatToParts 自己組，不要借某個 locale 的預設格式
     * （例如 sv-SE 剛好輸出 YYYY-MM-DD HH:mm:ss）—— **locale 不一定存在**。
     * small-icu 的環境只認得 en-US，傳 sv-SE 會被無聲忽略而吐出
     * 「9/7/2026, 10:05:31 PM」。formatToParts 拿到的是結構化欄位，
     * 跟 locale 的排版無關，時區轉換照樣正確。
     *
     * @param {string|null} value   後端回的時間（ISO 含 Z，或 'Y-m-d H:i:s'）
     * @param {boolean}     seconds 要不要顯示秒，預設不顯示
     * @returns {string} 格式化後的台北時間；空值或解析不出來時回 '-'
     */
    window.formatDateTime = function (value, seconds) {
        if (!value) { return '-'; }

        var raw = String(value).trim();
        var length = seconds ? 19 : 16;

        /*
         * 沒有時區標記的字串（'2026-09-30 15:58:20'）已經是台北時間了 ——
         * 走 Resource 明確 format 過的欄位都是這種。
         *
         * 這種**不能**再丟給 new Date() 轉一次：它會被當成「瀏覽器本地時間」
         * 解析，客服在別的時區登入就會整個偏掉。原樣顯示才是對的。
         */
        if (!/([Zz]|[+-]\d{2}:?\d{2})$/.test(raw)) {
            return raw.replace('T', ' ').substring(0, length);
        }

        // 到這裡才是帶時區的 ISO 字串（Model 直接序列化的 UTC），需要轉成台北
        var d = new Date(raw);

        if (isNaN(d.getTime())) {
            return raw.replace('T', ' ').substring(0, length);
        }

        // 環境太舊沒有 Intl 就退回原字串，至少不是壞掉的畫面
        if (!window.Intl || !window.Intl.DateTimeFormat) {
            return raw.replace('T', ' ').substring(0, length);
        }

        var parts = {};
        new Intl.DateTimeFormat('en-US', {
            timeZone: 'Asia/Taipei',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false
        }).formatToParts(d).forEach(function (p) {
            parts[p.type] = p.value;
        });

        // hour12:false 在部分實作會把午夜給成 24 而不是 00
        var hour = parts.hour === '24' ? '00' : parts.hour;
        var text = parts.year + '-' + parts.month + '-' + parts.day
            + ' ' + hour + ':' + parts.minute + ':' + parts.second;

        return text.substring(0, length);
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
        /*
         * 「時」「分」寫死中文，理由同 WEEKDAYS：common.js 讀不到 PHP 語系檔。
         * 但留了 data-hour-label / data-minute-label 可以從 Blade 覆寫，
         * 之後要三語系化時不必改這支。
         */
        var hour = buildSelect(24, required, input.dataset.hourLabel || '時');
        var minute = buildSelect(60, required, input.dataset.minuteLabel || '分');
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

    // ---------------------------------------------------------------
    //  登入過期
    // ---------------------------------------------------------------

    /*
     * 以前每一支 apiFetch 都只是把後端的訊息原樣顯示出來，session 一過期，
     * 使用者看到的是「Unauthenticated.」或「CSRF token mismatch.」——
     * 看不懂，也不知道要重新登入，更不會被導到登入頁。
     *
     * 打卡最容易踩到：早上打完上班卡就把頁面掛著，下班要打卡時 session
     * 早就過期了。
     *
     * 兩件事一起做：
     *   1. 任何 AJAX 收到 401／419 就明講「登入已過期」並導向登入頁
     *   2. 頁面開著時定期心跳，讓 session 不會在使用中過期（見下方 keepAlive）
     */

    /** @type {number[]} 代表「這個 session 不能再用了」的狀態碼 */
    var EXPIRED_STATUS = [401, 419];

    /** @type {boolean} 已經提示過就不再重複 —— 一個畫面同時發好幾支 AJAX 是常態 */
    var expiredNotified = false;

    /**
     * 登入頁網址
     *
     * 提示視窗裡的按鈕本身就是連結，這支只在 Bootstrap 沒載入時的退路用得到。
     *
     * @returns {string}
     */
    function loginUrl() {
        var link = document.querySelector('#auth-expired-modal a');

        return link ? link.getAttribute('href') : '/login';
    }

    window.AuthGuard = {
        /**
         * 這個回應是不是「登入已過期」
         *
         * 401 是沒登入，419 是 CSRF token 對不上 —— session 換掉之後
         * 頁面上那份 token 就失效了，兩種都是同一件事。
         *
         * @param {Response} response
         * @returns {boolean}
         */
        isExpired: function (response) {
            return EXPIRED_STATUS.indexOf(response.status) !== -1;
        },

        /**
         * 提示並準備導向登入頁
         *
         * @returns {void}
         */
        notify: function () {
            if (expiredNotified) { return; }

            expiredNotified = true;

            // 先關掉畫面上其他 Modal：留著的話會疊兩層 backdrop，
            // 而且那些視窗上的按鈕按了也沒用
            document.querySelectorAll('.modal.show').forEach(function (opened) {
                var instance = window.bootstrap && window.bootstrap.Modal.getInstance(opened);

                if (instance) { instance.hide(); }
            });

            // 視窗本身寫在 layouts/app.blade.php，跟其他 Modal 同一種結構。
            // 早期是用 JS 動態建的，但那份多了 modal-dialog-centered，
            // 跟 custom.css 那條全域的 `.modal { align-items: flex-start !important }`
            // 打架，畫面上會多出一條整頁高的白色長條
            var el = document.getElementById('auth-expired-modal');

            // 登入頁沒有這個視窗（它不吃 app layout），直接轉走
            if (!el || !window.bootstrap) {
                window.location.href = loginUrl();

                return;
            }

            // 等前面那些 Modal 的關閉動畫跑完，否則 backdrop 會殘留
            setTimeout(function () {
                window.bootstrap.Modal.getOrCreateInstance(el).show();
            }, 300);
        },

        /**
         * 給各頁面的 apiFetch 用的攔截
         *
         * 回傳 null 表示「不是過期，照原本流程走」；
         * 回傳 Promise 表示「已經接手了，呼叫端不要再處理」。
         *
         * ⚠ 接手時回的是**永遠不 settle 的 Promise**，這是故意的 ——
         * 讓呼叫端的 then/catch 都不會跑，畫面上就只有這個提示視窗，
         * 不會再疊一個「Unauthenticated.」的錯誤訊息。反正下一步是離開頁面。
         *
         * @param {Response} response
         * @returns {Promise|null}
         */
        intercept: function (response) {
            if (!this.isExpired(response)) { return null; }

            this.notify();

            return new Promise(function () {});
        }
    };

    /*
     * jQuery 的 $.ajax 也要攔。
     *
     * 任務看板、帳號、站台、虛擬機等十幾個頁面走的是 jQuery 而不是各自的
     * apiFetch —— 只改 apiFetch 的話，那些頁面 session 過期時依然是靜悄悄的。
     *
     * ajaxError 是全域事件，一次涵蓋所有 $.ajax，不必去改每一支呼叫。
     * jqXHR 有 status 屬性，isExpired() 只看 status，所以直接吃得下。
     */
    if (window.jQuery) {
        window.jQuery(document).ajaxError(function (event, xhr) {
            if (window.AuthGuard.isExpired(xhr)) { window.AuthGuard.notify(); }
        });
    }

    // ---------------------------------------------------------------
    //  Session 心跳
    // ---------------------------------------------------------------

    /** @type {number} 心跳間隔。要明顯小於 SESSION_LIFETIME（120 分鐘） */
    var PING_INTERVAL = 15 * 60 * 1000;

    /**
     * 戳一下後端，把 session 的有效期往後推
     *
     * 續期的效果來自「有發出這個請求」本身，回傳什麼不重要。
     *
     * @returns {void}
     */
    function ping() {
        if (expiredNotified) { return; }

        fetch('/admin/ajax-ping', {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin'
        }).then(function (response) {
            window.AuthGuard.intercept(response);
        }).catch(function () {
            // 網路斷線不提示 —— 那不是登入過期，下一輪再試就好
        });
    }

    // 登入頁與錯誤頁不需要心跳，只有後台頁面要
    if (window.location.pathname.indexOf('/admin') === 0) {
        setInterval(ping, PING_INTERVAL);

        /*
         * 分頁切到背景時瀏覽器會把 setInterval 壓到最慢一分鐘一次，
         * 電腦休眠更是完全停住 —— 回到頁面時先補一次，
         * 使用者才不會在「看起來正常」的畫面上按下去才發現已經過期。
         */
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) { ping(); }
        });
    }
})();
