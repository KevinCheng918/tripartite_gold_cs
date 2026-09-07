/**
 * 全站共用的前端行為
 *
 * 由 layouts/app.blade.php 載入，所有頁面都會套用。
 */
(function () {
    'use strict';

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
