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
})();
