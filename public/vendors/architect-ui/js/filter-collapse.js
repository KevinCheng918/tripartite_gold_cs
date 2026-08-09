$(function () {
    var $c   = $('#filterCollapse');
    var $txt = $('#filterToggleText');
    if (!$c.length || !$txt.length) return;

    var STORAGE_KEY = 'filterCollapse_' + window.location.pathname;

    function syncLabel() {
        var isShow = $c.hasClass('show');
        $txt.text(isShow ? '— 折疊 —' : '＋ 展開 ＋');
        localStorage.setItem(STORAGE_KEY, isShow ? 'show' : 'hide');
    }

    // 讀取記憶狀態
    var saved = localStorage.getItem(STORAGE_KEY);
    if (saved === 'hide') {
        $c.removeClass('show');
    } else if (saved === 'show') {
        $c.addClass('show');
    }

    // 初始同步
    syncLabel();

    // 監聯 Bootstrap 5 的折疊事件
    $c.on('shown.bs.collapse hidden.bs.collapse', syncLabel);
});
