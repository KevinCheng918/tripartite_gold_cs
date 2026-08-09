/**
 * Telegram Chat — 告警通知
 */
(function () {
    var T = window.TgChat;
    if (!T) { return; }

    T.showAlert = function (data) {
        var bar = document.getElementById('tg-alert-bar');
        if (!bar) { return; }
        bar.style.display = 'block';
        bar.className = 'alert alert-danger d-flex align-items-center mb-0';
        bar.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i>' +
            data.groupTitle + ' — ' + T.i18n.alert_unreplied.replace(':minutes', data.minutes);
        setTimeout(function () { bar.style.display = 'none'; }, 5000);
    };
})();
