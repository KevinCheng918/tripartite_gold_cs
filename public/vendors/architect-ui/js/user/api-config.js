// BS5 + ClipboardJS
document.addEventListener('DOMContentLoaded', function () {
    // 沒載到 ClipboardJS 時避免報錯
    if (!window.ClipboardJS) {
        console.warn('ClipboardJS not found: .btn-copy disabled');
        return;
    }

    // 記錄每顆按鈕原本的 btn-outline-* 顏色，供成功後還原
    document.querySelectorAll('.btn-copy').forEach(function (btn) {
        const cls = Array.from(btn.classList).find(c => /^btn-outline-/.test(c));
        if (cls) btn.dataset.originalOutline = cls;

        // 預先建好 BS5 tooltip（手動觸發）
        if (!btn.getAttribute('data-bs-toggle')) btn.setAttribute('data-bs-toggle', 'tooltip');
        if (!btn.getAttribute('data-bs-title'))  btn.setAttribute('data-bs-title', 'Copied!');
        new bootstrap.Tooltip(btn, { trigger: 'manual', placement: 'top' });
    });

    const clipboard = new ClipboardJS('.btn-copy');

    clipboard.on('success', function (e) {
        const btn = e.trigger;

        // 換成成功樣式
        const original = btn.dataset.originalOutline || 'btn-outline-dark';
        btn.classList.remove(original, 'btn-outline-danger', 'btn-outline-dark');
        btn.classList.add('btn-outline-success');

        // 顯示 tooltip（BS5）
        const tip = bootstrap.Tooltip.getOrCreateInstance(btn, { trigger: 'manual', placement: 'top' });
        btn.setAttribute('data-bs-title', '已複製');
        // 5.2+ 支援 setContent；若沒有也能正確顯示
        if (typeof tip.setContent === 'function') tip.setContent({ '.tooltip-inner': '已複製' });
        tip.show();

        // 1 秒後還原
        setTimeout(function () {
            btn.classList.remove('btn-outline-success');
            btn.classList.add(original);
            tip.hide();
        }, 1000);

        // 清掉選取
        if (typeof e.clearSelection === 'function') e.clearSelection();
    });
});
