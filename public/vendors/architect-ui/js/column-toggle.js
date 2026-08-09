/**
 * 欄位顯示切換（localStorage 記憶）
 *
 * @param {string} storageKey   localStorage key
 * @param {string} tableId      table 的 id
 * @param {string} menuId       dropdown menu 的 id
 * @param {string} checkboxClass checkbox 的 class
 */
function initColumnToggle(storageKey, tableId, menuId, checkboxClass) {
    var table = document.getElementById(tableId);
    var menu  = document.getElementById(menuId);
    if (!table || !menu) return;

    var saved = {};
    try { saved = JSON.parse(localStorage.getItem(storageKey)) || {}; } catch(e) {}

    var checkboxes = menu.querySelectorAll('.' + checkboxClass);
    checkboxes.forEach(function (cb) {
        var col = cb.dataset.col;
        if (saved.hasOwnProperty(col)) {
            cb.checked = saved[col];
        }
        applyColumn(col, cb.checked);

        cb.addEventListener('change', function () {
            applyColumn(this.dataset.col, this.checked);
            saveState();
        });
    });

    function applyColumn(col, visible) {
        var display = visible ? '' : 'none';
        table.querySelectorAll('[data-col="' + col + '"]').forEach(function (el) {
            el.style.display = display;
        });
    }

    function saveState() {
        var state = {};
        checkboxes.forEach(function (cb) {
            state[cb.dataset.col] = cb.checked;
        });
        localStorage.setItem(storageKey, JSON.stringify(state));
    }

    // 手機版：改用 overlay 底部彈出
    var isMobile = window.innerWidth < 768;
    if (isMobile) {
        var btn = menu.previousElementSibling;
        var overlay = document.createElement('div');
        overlay.className = 'col-toggle-overlay';
        overlay.innerHTML = '<div class="col-toggle-sheet">' +
            '<div class="col-toggle-sheet-header">' +
            '<span class="col-toggle-sheet-title">顯示欄位</span>' +
            '<button type="button" class="col-toggle-sheet-close">&times;</button>' +
            '</div>' +
            '<div class="col-toggle-sheet-body"></div>' +
            '</div>';
        document.body.appendChild(overlay);

        // 搬移 checkbox 到 sheet
        var sheetBody = overlay.querySelector('.col-toggle-sheet-body');
        Array.from(menu.children).forEach(function (child) {
            sheetBody.appendChild(child.cloneNode(true));
        });

        // 綁定 sheet 內的 checkbox
        sheetBody.querySelectorAll('.' + checkboxClass).forEach(function (cb) {
            var col = cb.dataset.col;
            if (saved.hasOwnProperty(col)) {
                cb.checked = saved[col];
            }
            cb.addEventListener('change', function () {
                // 同步原始 menu 的 checkbox
                var original = menu.querySelector('[data-col="' + this.dataset.col + '"]');
                if (original) {
                    original.checked = this.checked;
                    original.dispatchEvent(new Event('change'));
                }
            });
        });

        // 阻止原始 dropdown 開啟，改用 overlay
        btn.removeAttribute('data-bs-toggle');
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            overlay.classList.add('show');
        });

        // 關閉
        overlay.querySelector('.col-toggle-sheet-close').addEventListener('click', function () {
            overlay.classList.remove('show');
        });
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) overlay.classList.remove('show');
        });
    }
}

// 注入手機版樣式
(function () {
    var style = document.createElement('style');
    style.textContent =
        '.col-toggle-overlay {' +
        '  display:none; position:fixed; inset:0; background:rgba(0,0,0,0.4);' +
        '  z-index:1060; align-items:flex-end; justify-content:center;' +
        '}' +
        '.col-toggle-overlay.show { display:flex; }' +
        '.col-toggle-sheet {' +
        '  background:#fff; border-radius:16px 16px 0 0; width:100%;' +
        '  max-height:70vh; overflow-y:auto; animation:colToggleSlideUp .25s ease;' +
        '}' +
        '.col-toggle-sheet-header {' +
        '  display:flex; align-items:center; justify-content:space-between;' +
        '  padding:14px 16px 10px; border-bottom:1px solid #eee; position:sticky; top:0; background:#fff;' +
        '}' +
        '.col-toggle-sheet-title { font-size:.95rem; font-weight:700; }' +
        '.col-toggle-sheet-close {' +
        '  background:none; border:none; font-size:1.4rem; color:#999; cursor:pointer; padding:0 4px;' +
        '}' +
        '.col-toggle-sheet-body { padding:8px 12px 20px; }' +
        '.col-toggle-sheet-body label {' +
        '  display:flex; align-items:center; padding:10px 4px; font-size:.9rem;' +
        '  border-bottom:1px solid #f5f5f5; cursor:pointer;' +
        '}' +
        '.col-toggle-sheet-body label:last-child { border-bottom:none; }' +
        '@keyframes colToggleSlideUp {' +
        '  from { transform:translateY(100%); } to { transform:translateY(0); }' +
        '}';
    document.head.appendChild(style);
})();
