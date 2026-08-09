// 依觸發鈕帶入的資料，統一在顯示前更新內容與按鈕色
document.addEventListener('show.bs.modal', function (e) {
    const modal = e.target;
    if (!/^modal-update-status-/.test(modal.id)) return;

    const btn = e.relatedTarget;
    if (!btn) return;

    const name = btn.getAttribute('data-name') || '';
    const word = btn.getAttribute('data-word') || '';
    const variant = btn.getAttribute('data-variant') || 'primary'; // warning | success

    const nameNode = modal.querySelector('.js-name');
    if (nameNode) nameNode.textContent = name;

    const textNode = modal.querySelector('.js-status-text');
    if (textNode && word) textNode.textContent = word;

    const actionBtn = modal.querySelector('.js-status-btn');
    if (actionBtn) {
        actionBtn.textContent = word || actionBtn.textContent;
        actionBtn.classList.remove('btn-warning', 'btn-success', 'btn-primary');
        actionBtn.classList.add('btn-' + variant);
    }
});

// 關閉時復原主按鈕樣式，避免殘留影響下一顆
document.addEventListener('hidden.bs.modal', function (e) {
    const modal = e.target;
    if (!/^modal-update-status-/.test(modal.id)) return;
    const actionBtn = modal.querySelector('.js-status-btn');
    if (actionBtn) {
        actionBtn.classList.remove('btn-warning', 'btn-success');
        if (!actionBtn.classList.contains('btn-primary')) {
            actionBtn.classList.add('btn-primary');
        }
    }
});
