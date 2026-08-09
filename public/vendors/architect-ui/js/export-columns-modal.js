/**
 * 通用匯出欄位選擇 modal — 全選 / 取消全選
 * 使用 data-target 指定 modalId，對應 .export-col-check-{modalId}
 */
document.addEventListener('DOMContentLoaded', function () {
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-export-check-all, .js-export-uncheck-all');
        if (!btn) return;

        var modalId = btn.getAttribute('data-target');
        var checked = btn.classList.contains('js-export-check-all');
        document.querySelectorAll('.export-col-check-' + modalId).forEach(function (c) {
            c.checked = checked;
        });
    });
});
