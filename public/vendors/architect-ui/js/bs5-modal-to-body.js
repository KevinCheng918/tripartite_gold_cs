(function () {
    function toBody(m) {
        if (m && m.parentNode !== document.body) document.body.appendChild(m);
    }
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.modal').forEach(toBody);
    });
    document.addEventListener('show.bs.modal', function (e) {
        toBody(e.target);
    });
    document.addEventListener('hide.bs.modal', function (e) {
        var m = e.target, a = document.activeElement;
        if (a && m.contains(a) && typeof a.blur === 'function') {
            try { a.blur(); } catch (_) {}
        }
    });
})();
