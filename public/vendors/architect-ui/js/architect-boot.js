(function (d) {
    'use strict';

    // 標記頁面已載入（可用來做淡入或隱藏 loading）
    if (d.readyState !== 'loading') { d.body.classList.add('app-loaded'); }
    else d.addEventListener('DOMContentLoaded', function () { d.body.classList.add('app-loaded'); });

    // 帳號搜尋後橘色底 -> 改用 BS5 的 bg-warning
    function replaceIn(root) {
        var list = root.querySelectorAll('.bg-orange');
        for (var i = 0; i < list.length; i++) {
            var el = list[i];
            el.classList.remove('bg-orange');
            el.classList.add('bg-warning');
        }
    }

    if (d.readyState !== 'loading') replaceIn(d);
    else d.addEventListener('DOMContentLoaded', function () { replaceIn(d); });

    try {
        new MutationObserver(function (muts) {
            for (var m of muts) {
                for (var n of m.addedNodes) {
                    if (n.nodeType !== 1) continue;
                    if (n.classList && n.classList.contains('bg-orange')) {
                        n.classList.remove('bg-orange');
                        n.classList.add('bg-warning');
                    }
                    replaceIn(n);
                }
            }
        }).observe(d.documentElement, { childList: true, subtree: true });
    } catch (e) {
        // IE 不支援 MutationObserver，可忽略
    }
})(document);
