/**
 * Telegram Chat — 手機版滑動引用
 *
 * 在訊息上左右滑動即可引用該則訊息，行為比照 Telegram。
 * 只在觸控裝置啟用；桌機用訊息上的引用鈕。
 */
(function () {
    var T = window.TgChat;
    if (!T) { return; }

    /** 滑超過這個距離才算數（px） */
    var TRIGGER_DISTANCE = 60;

    /** 氣泡最多跟著手指移動的距離，避免整則被拖出畫面 */
    var MAX_TRANSLATE = 80;

    /** 橫向要比縱向多這麼多才判定為滑動，否則視為捲動頁面 */
    var DIRECTION_BIAS = 1.5;

    var startX = 0;
    var startY = 0;
    var activeWrapper = null;
    var swiping = false;

    /**
     * 找出這則訊息可不可以引用
     *
     * 直接沿用引用鈕的存在與否 —— 沒有 telegram_message_id 的訊息
     * 不會渲染那顆按鈕，滑動自然也不該有反應
     *
     * @param {HTMLElement} wrapper
     * @returns {HTMLElement|null}
     */
    function quoteButtonOf(wrapper) {
        return wrapper ? wrapper.querySelector('.js-quote-msg') : null;
    }

    /**
     * @param {HTMLElement} wrapper
     * @param {number}      offset
     */
    function translate(wrapper, offset) {
        var box = wrapper.querySelector('.chat-box');
        if (!box) { return; }

        box.style.transform = offset ? 'translateX(' + offset + 'px)' : '';
        box.style.transition = offset ? 'none' : 'transform 0.2s';
    }

    function handleTouchStart(e) {
        if (!T.canReply || e.touches.length !== 1) { return; }

        var wrapper = e.target.closest ? e.target.closest('.chat-box-wrapper') : null;
        if (!wrapper || !quoteButtonOf(wrapper)) { return; }

        activeWrapper = wrapper;
        swiping = false;
        startX = e.touches[0].clientX;
        startY = e.touches[0].clientY;
    }

    function handleTouchMove(e) {
        if (!activeWrapper || e.touches.length !== 1) { return; }

        var dx = e.touches[0].clientX - startX;
        var dy = e.touches[0].clientY - startY;

        // 縱向為主就是要捲動頁面，讓瀏覽器自己處理
        if (!swiping && Math.abs(dx) < Math.abs(dy) * DIRECTION_BIAS) {
            activeWrapper = null;
            return;
        }

        if (Math.abs(dx) < 10) { return; }

        swiping = true;

        // 阻止頁面跟著橫向捲動
        if (e.cancelable) { e.preventDefault(); }

        var capped = Math.max(-MAX_TRANSLATE, Math.min(MAX_TRANSLATE, dx));
        translate(activeWrapper, capped);
    }

    function handleTouchEnd(e) {
        if (!activeWrapper) { return; }

        var wrapper = activeWrapper;
        var dx = (e.changedTouches && e.changedTouches.length)
            ? e.changedTouches[0].clientX - startX
            : 0;

        activeWrapper = null;
        translate(wrapper, 0);

        if (!swiping || Math.abs(dx) < TRIGGER_DISTANCE) { return; }

        // 左右都接受：Telegram 在 iOS 與 Android 的方向不同，
        // 限定單一方向反而違背使用者既有的手感
        var btn = quoteButtonOf(wrapper);
        if (!btn || !T.setQuote) { return; }

        T.setQuote(
            parseInt(btn.getAttribute('data-id'), 10),
            btn.getAttribute('data-sender'),
            btn.getAttribute('data-text')
        );
    }

    /**
     * 綁定滑動引用。每次重新渲染訊息後呼叫
     */
    T.bindSwipeReply = function () {
        // 桌機沒有觸控，綁了只是多掛事件
        if (!window.isCoarsePointer || !window.isCoarsePointer()) { return; }

        var container = document.getElementById('tg-messages');
        if (!container) { return; }

        // 綁在容器上用委派，訊息重繪不必重綁；先移除避免重複疊加
        container.removeEventListener('touchstart', handleTouchStart);
        container.removeEventListener('touchmove', handleTouchMove);
        container.removeEventListener('touchend', handleTouchEnd);

        container.addEventListener('touchstart', handleTouchStart, { passive: true });
        // passive: false 才能 preventDefault 擋掉橫向捲動
        container.addEventListener('touchmove', handleTouchMove, { passive: false });
        container.addEventListener('touchend', handleTouchEnd);
    };
})();
