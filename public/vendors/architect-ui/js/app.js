// ArchitectUI – Bootstrap 5 only
(function () {
    // 等 DOM 解析完成（不依賴 jQuery）
    document.addEventListener('DOMContentLoaded', function () {
        // ====== Sidebar Menu (metisMenu 仍可用，若你保留 jQuery) ======
        if (window.jQuery && typeof jQuery.fn.metisMenu === 'function') {
            setTimeout(function () {
                jQuery('.vertical-nav-menu').metisMenu();
            }, 100);
        }

        // ====== Search wrapper trigger ======
        var searchIcons = document.querySelectorAll('.search-icon');
        searchIcons.forEach(function (icon) {
            icon.addEventListener('click', function () {
                var p = icon.closest('.search-wrapper');
                if (p) p.classList.add('active');
            });
        });

        var searchCloseBtns = document.querySelectorAll('.search-wrapper .btn-close');
        searchCloseBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var p = btn.closest('.search-wrapper');
                if (p) p.classList.remove('active');
            });
        });

        // ====== Popover / Tooltip（全部用 BS5 原生 API）======
        // 一般 popover：<button data-bs-toggle="popover" title="..." data-bs-content="...">
        var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
        popoverTriggerList.forEach(function (el) {
            new bootstrap.Popover(el, {
                html: true
            });
        });

        // 自訂內容 popover（原本 data-toggle="popover-custom-content"）
        // 改成 data-bs-toggle="popover" 並加上 data-popover-id="xxx"，內容從 #popover-content-xxx 讀取
        var customContentPopovers = document.querySelectorAll('[data-popover-id]');
        customContentPopovers.forEach(function (el) {
            var id = el.getAttribute('data-popover-id');
            var tmpl = document.getElementById('popover-content-' + id);
            if (!tmpl) return;
            new bootstrap.Popover(el, {
                html: true,
                placement: 'auto',
                template:
                    '<div class="popover popover-custom" role="tooltip">' +
                    '<div class="popover-arrow"></div>' +
                    '<h3 class="popover-header"></h3>' +
                    '<div class="popover-body"></div>' +
                    '</div>',
                content: function () {
                    return tmpl.innerHTML;
                }
            });
        });

        // 自訂背景樣式 popover（原本 data-toggle="popover-custom-bg"、data-bg-class）
        // 請將觸發元素改為 data-bs-toggle="popover" 並加 data-popover-bg-class
        var customBgPopovers = document.querySelectorAll('[data-popover-bg-class]');
        customBgPopovers.forEach(function (el) {
            var popClass = el.getAttribute('data-popover-bg-class') || '';
            new bootstrap.Popover(el, {
                trigger: 'focus',
                placement: 'top',
                html: true,
                template:
                    '<div class="popover popover-bg ' + popClass + '" role="tooltip">' +
                    '<div class="popover-arrow"></div>' +
                    '<h3 class="popover-header"></h3>' +
                    '<div class="popover-body"></div>' +
                    '</div>'
            });
        });

        // Tooltip：<button data-bs-toggle="tooltip" title="...">
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.forEach(function (el) {
            new bootstrap.Tooltip(el);
        });

        // 若需要「亮色版」tooltip（原本 data-toggle="tooltip-light"）
        // 改成 data-bs-toggle="tooltip" 並加 data-tooltip-light
        var lightTooltips = document.querySelectorAll('[data-tooltip-light]');
        lightTooltips.forEach(function (el) {
            new bootstrap.Tooltip(el, {
                template: '<div class="tooltip tooltip-light" role="tooltip"><div class="tooltip-arrow"></div><div class="tooltip-inner"></div></div>'
            });
        });

        // ====== Dropdown：點擊內部不關閉 → 直接用 data-bs-auto-close="outside/inside" ======
        // 在 HTML 上加屬性即可，不需要 jQuery hack：
        // <div class="dropdown" data-bs-auto-close="outside"> ... </div>
        // 這裡不再攔截事件

        // ====== Drawer（沿用你原本的 class 切換）======
        var openRight = document.querySelectorAll('.open-right-drawer');
        openRight.forEach(function (btn) {
            btn.addEventListener('click', function () {
                btn.classList.add('is-active');
                document.querySelector('.app-drawer-wrapper')?.classList.add('drawer-open');
                document.querySelector('.app-drawer-overlay')?.classList.remove('d-none');
            });
        });

        var drawerNav = document.querySelectorAll('.drawer-nav-btn');
        drawerNav.forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.querySelector('.app-drawer-wrapper')?.classList.remove('drawer-open');
                document.querySelector('.app-drawer-overlay')?.classList.add('d-none');
                document.querySelector('.open-right-drawer')?.classList.remove('is-active');
            });
        });

        var drawerOverlay = document.querySelector('.app-drawer-overlay');
        if (drawerOverlay) {
            drawerOverlay.addEventListener('click', function () {
                drawerOverlay.classList.add('d-none');
                document.querySelector('.app-drawer-wrapper')?.classList.remove('drawer-open');
                document.querySelector('.open-right-drawer')?.classList.remove('is-active');
            });
        }

        var mobileToggleNav = document.querySelectorAll('.mobile-toggle-nav');
        mobileToggleNav.forEach(function (btn) {
            btn.addEventListener('click', function () {
                btn.classList.toggle('is-active');
                document.querySelector('.app-container')?.classList.toggle('sidebar-mobile-open');
            });
        });

        var mobileToggleHeader = document.querySelectorAll('.mobile-toggle-header-nav');
        mobileToggleHeader.forEach(function (btn) {
            btn.addEventListener('click', function () {
                btn.classList.toggle('active');
                document.querySelector('.app-header__content')?.classList.toggle('header-mobile-open');
            });
        });

        var mobileAppMenuBtn = document.querySelectorAll('.mobile-app-menu-btn');
        mobileAppMenuBtn.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var hamb = btn.querySelector('.hamburger');
                if (hamb) hamb.classList.toggle('is-active');
                document.querySelector('.app-inner-layout')?.classList.toggle('open-mobile-menu');
            });
        });

        // ====== Responsive（沿用）======
        function resizeClass() {
            var win = document.body.clientWidth;
            var app = document.querySelector('.app-container');
            if (!app) return;
            if (win < 1250) {
                app.classList.add('closed-sidebar-mobile', 'closed-sidebar');
            } else {
                app.classList.remove('closed-sidebar-mobile', 'closed-sidebar');
            }
        }
        window.addEventListener('resize', resizeClass);
        resizeClass();
    });
})();
