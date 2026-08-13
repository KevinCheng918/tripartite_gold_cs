<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('img/pwa-apple-icon.png') }}">
    <link rel="manifest" href="{{ asset('manifest.json') }}">
    <meta name="theme-color" content="#1e3a5f">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">

    {{-- 防止 dark mode 閃白：在 CSS 載入前立刻套用主題 --}}
    <script>
    (function () {
        var theme = localStorage.getItem('theme');
        if (theme === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
        }
    })();
    </script>

    {{-- Architect UI CSS --}}
    <link rel="stylesheet" href="{{ asset('vendors/architect-ui/styles/css/base.css') }}">
    <link rel="stylesheet" href="{{ asset('vendors/architect-ui/vendors/@fortawesome/fontawesome-free/css/all.min.css') }}">

    {{-- Flatpickr --}}
    <link rel="stylesheet" href="{{ asset('vendor/flatpickr/flatpickr.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/flatpickr/airbnb.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/flatpickr/monthSelect.css') }}">

    {{-- 客服系統自訂樣式（覆寫 Architect） --}}
    <link rel="stylesheet" href="{{ asset('css/custom.css') }}?v={{ filemtime(public_path('css/custom.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">

    <style>
        body .app-container { opacity: 1 !important; visibility: visible !important; }
        .app-sidebar__inner, .app-main__inner { opacity: 0; transition: opacity 0.3s ease-in-out !important; }
        .page-loaded .app-sidebar__inner, .page-loaded .app-main__inner { opacity: 1; }
        .app-sidebar { transition: all .3s cubic-bezier(0.4, 0, 0.2, 1) !important; will-change: width, flex; }
        .closed-sidebar:not(.sidebar-mobile-open) .app-sidebar__heading,
        .closed-sidebar:not(.sidebar-mobile-open) .metismenu-link span,
        .closed-sidebar:not(.sidebar-mobile-open) .sidebar-brand-sub { opacity: 0; visibility: hidden; white-space: nowrap; }
        .app-main__outer, .app-main__inner { overflow-x: hidden; }
        @yield('css')
    </style>
</head>

<body>
    {{-- Sidebar 狀態同步 --}}
    <script>
        (function() {
            var state = localStorage.getItem('sidebar-state');
            if (state === 'closed') {
                document.documentElement.classList.add('is-sidebar-closed');
            }
        })();
    </script>

    <div class="app-container app-theme-white body-tabs-shadow fixed-header fixed-sidebar fixed-footer">

        {{-- ==================== Header ==================== --}}
        <div class="app-header header-shadow">
            <div class="app-header__logo">
                <a href="{{ route('admin.dashboard') }}" class="ms-2 fw-semibold fs-5 lh-1 d-flex align-items-center text-decoration-none" style="color:#a67c00">
                    <span class="brand-logo-text">{{ config('app.name') }}</span>
                </a>
                <div class="header__pane ms-auto">
                    <button type="button" class="hamburger close-sidebar-btn hamburger--elastic" data-class="closed-sidebar">
                        <span class="hamburger-box"><span class="hamburger-inner"></span></span>
                    </button>
                </div>
            </div>
            <div class="app-header__mobile-menu">
                <button type="button" class="hamburger hamburger--elastic mobile-toggle-nav">
                    <span class="hamburger-box"><span class="hamburger-inner"></span></span>
                </button>
            </div>
            <div class="app-header__menu">
                <span>
                    <button type="button" class="btn-icon btn-icon-only btn btn-primary btn-sm mobile-toggle-header-nav">
                        <span class="btn-icon-wrapper"><i class="fa fa-ellipsis-v fa-w-6"></i></span>
                    </button>
                </span>
            </div>
            <div class="app-header__content">
                <div class="app-header-left">
                    <span class="fw-bold me-3" id="topbar-clock" style="font-size:1.25rem; white-space:nowrap;"></span>
                </div>
                <div class="app-header-right">
                    <div class="d-flex align-items-center">
                        <a href="javascript:void(0)" class="p-0 btn btn-link me-2 d-md-none" title="推播通知" onclick="window.requestPushPermission && window.requestPushPermission()">
                            <span class="icon-wrapper icon-wrapper-alt rounded-circle">
                                <span class="icon-wrapper-bg bg-focus"></span>
                                <i class="fas fa-bell text-focus"></i>
                            </span>
                        </a>
                        <a href="javascript:void(0)" class="p-0 btn btn-link me-2" id="theme-toggle" title="切換深色/淺色模式">
                            <span class="icon-wrapper icon-wrapper-alt rounded-circle">
                                <span class="icon-wrapper-bg bg-focus"></span>
                                <i class="fas fa-moon text-focus theme-icon-dark"></i>
                                <i class="fas fa-sun text-focus theme-icon-light" style="display:none"></i>
                            </span>
                        </a>
                        <form action="{{ route('logout') }}" method="POST" class="m-0 ms-2">
                            @csrf
                            <button type="submit" class="btn btn-outline-danger" style="font-size:0.9375rem">
                                <i class="fas fa-sign-out-alt me-1"></i><span class="logout-text">{{ trans('auth.logout') }}</span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        {{-- ==================== Main ==================== --}}
        <div class="app-main">
            {{-- Sidebar --}}
            <div class="app-sidebar sidebar-shadow">
                <div class="app-header__logo">
                    <a href="{{ route('admin.dashboard') }}" class="ms-2 fw-semibold fs-5 lh-1 d-flex align-items-center text-decoration-none" style="color:#a67c00">
                        <span class="brand-logo-text">{{ config('app.name') }}</span>
                    </a>
                    <div class="header__pane ms-auto">
                        <button type="button" class="hamburger close-sidebar-btn hamburger--elastic" data-class="closed-sidebar">
                            <span class="hamburger-box"><span class="hamburger-inner"></span></span>
                        </button>
                    </div>
                </div>
                <div class="app-header__mobile-menu">
                    <button type="button" class="hamburger hamburger--elastic mobile-toggle-nav">
                        <span class="hamburger-box"><span class="hamburger-inner"></span></span>
                    </button>
                </div>
                <div class="app-header__menu">
                    <span>
                        <button type="button" class="btn-icon btn-icon-only btn btn-primary btn-sm mobile-toggle-header-nav">
                            <span class="btn-icon-wrapper"><i class="fa fa-ellipsis-v fa-w-6"></i></span>
                        </button>
                    </span>
                </div>
                <div class="scrollbar-sidebar">
                    <div class="app-sidebar__inner">
                        {{-- 使用者資訊（點擊開啟個人設定） --}}
                        <a href="javascript:void(0)" class="d-flex align-items-center px-3 py-3 border-bottom mb-2 text-decoration-none" id="btn-open-profile-sidebar" title="{{ trans('profile.title') }}">
                            <div class="rounded-circle d-flex align-items-center justify-content-center me-2" style="width:36px;height:36px;background:linear-gradient(135deg,#d4af37,#a67c00);color:#fff;font-weight:700;font-size:0.875rem;flex-shrink:0">
                                {{ mb_substr(Auth::user()->nickname, 0, 1) }}
                            </div>
                            <div style="min-width:0">
                                <div class="fw-bold text-truncate text-dark" style="font-size:0.9375rem">{{ Auth::user()->nickname }}</div>
                                <div class="text-muted text-truncate" style="font-size:0.75rem">{{ Auth::user()->account }}</div>
                            </div>
                            <i class="fas fa-edit ms-auto text-muted" style="font-size:0.75rem"></i>
                        </a>

                        <ul class="vertical-nav-menu">
                            <li class="app-sidebar__heading">{{ config('app.name') }}</li>
                            <li>
                                <a href="{{ route('admin.dashboard') }}" class="{{ request()->routeIs('admin.dashboard') ? 'mm-active' : '' }}">
                                    <i class="metismenu-icon fas fa-home"></i>
                                    {{ trans('dashboard.nav_label') }}
                                </a>
                            </li>
                            <li class="app-sidebar__heading">主要功能</li>
                            @if(Auth::user()->hasPermission('account.view'))
                            <li>
                                <a href="{{ route('admin.accounts.index') }}" class="{{ request()->routeIs('admin.accounts.*') ? 'mm-active' : '' }}">
                                    <i class="metismenu-icon fas fa-users"></i>
                                    {{ trans('account.nav_label') }}
                                </a>
                            </li>
                            @endif
                            @if(Auth::user()->hasPermission('shift.view'))
                            <li>
                                <a href="{{ route('admin.shifts.index') }}" class="{{ request()->routeIs('admin.shifts.*') ? 'mm-active' : '' }}">
                                    <i class="metismenu-icon fas fa-calendar-alt"></i>
                                    {{ trans('shift.nav_label') }}
                                </a>
                            </li>
                            @endif
                            @if(Auth::user()->hasPermission('attendance.view'))
                            <li>
                                <a href="{{ route('admin.attendance.index') }}" class="{{ request()->routeIs('admin.attendance.*') ? 'mm-active' : '' }}">
                                    <i class="metismenu-icon fas fa-clock"></i>
                                    {{ trans('attendance.nav_label') }}
                                </a>
                            </li>
                            @endif
                            <li>
                                <a href="{{ route('admin.telegram-chat.index') }}" class="{{ request()->routeIs('admin.telegram-chat.*') ? 'mm-active' : '' }}">
                                    <i class="metismenu-icon fas fa-comments"></i>
                                    {{ trans('telegram_chat.nav_label') }}
                                </a>
                            </li>
                            @if(Auth::user()->hasPermission('station.view') || Auth::user()->hasPermission('telegram_chat.broadcast') || Auth::user()->hasPermission('vm.view') || Auth::user()->hasPermission('vm.billing_view') || Auth::user()->hasPermission('payment_config.view'))
                            <li class="app-sidebar__heading">{{ trans('station.section_label') }}</li>
                            @if(Auth::user()->hasPermission('station.view'))
                            <li>
                                <a href="{{ route('admin.stations.index') }}" class="{{ request()->routeIs('admin.stations.*') ? 'mm-active' : '' }}">
                                    <i class="metismenu-icon fas fa-server"></i>
                                    {{ trans('station.nav_label') }}
                                </a>
                            </li>
                            @endif
                            @if(Auth::user()->hasPermission('vm.view') || Auth::user()->hasPermission('vm.billing_view'))
                            <li>
                                <a href="{{ route('admin.vm.index') }}" class="{{ request()->routeIs('admin.vm.*') ? 'mm-active' : '' }}">
                                    <i class="metismenu-icon fas fa-hdd"></i>
                                    {{ trans('vm.nav_label') }}
                                </a>
                            </li>
                            @endif
                            @if(Auth::user()->hasPermission('payment_config.view'))
                            <li>
                                <a href="{{ route('admin.payment-config.index') }}" class="{{ request()->routeIs('admin.payment-config.*') ? 'mm-active' : '' }}">
                                    <i class="metismenu-icon fas fa-credit-card"></i>
                                    {{ trans('payment_config.nav_label') }}
                                </a>
                            </li>
                            @endif
                            @if(Auth::user()->hasPermission('telegram_chat.broadcast'))
                            <li>
                                <a href="{{ route('admin.telegram-broadcast.index') }}" class="{{ request()->routeIs('admin.telegram-broadcast.*') ? 'mm-active' : '' }}">
                                    <i class="metismenu-icon fas fa-bullhorn"></i>
                                    {{ trans('broadcast.nav_label') }}
                                </a>
                            </li>
                            @endif
                            @endif
                        </ul>
                    </div>
                </div>
            </div>

            {{-- 手機 sidebar 遮罩 --}}
            <div class="sidebar-mobile-overlay" id="sidebarMobileOverlay"></div>

            {{-- Content --}}
            <div class="app-main__outer">
                <div class="app-main__inner">
                    {{-- Page Title --}}
                    <div class="app-page-title">
                        <div class="page-title-wrapper">
                            <div class="page-title-heading">
                                <div class="page-title-icon" style="background:linear-gradient(135deg,#d4af37,#a67c00);color:#fff;">
                                    <i class="fas fa-@yield('icon', 'tachometer-alt')"></i>
                                </div>
                                <div>
                                    @yield('title', config('app.name'))
                                    <div class="page-title-subheading">@yield('subtitle', '')</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Main Content --}}
                    @yield('content')
                </div>
            </div>
        </div>
    </div>

    {{-- ==================== Modals ==================== --}}
    {{-- 個人資訊 Modal --}}
    <div class="modal fade" id="modal-profile" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ trans('profile.title') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="form-profile">
                        <div class="mb-3">
                            <label class="form-label">{{ trans('profile.field_account') }}</label>
                            <input type="text" class="form-control" value="{{ Auth::user()->account }}" disabled>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="profile-nickname">{{ trans('profile.field_nickname') }}</label>
                            <input id="profile-nickname" type="text" class="form-control" name="nickname" value="{{ Auth::user()->nickname }}" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="profile-password">{{ trans('profile.field_password') }}（{{ trans('profile.password_hint') }}）</label>
                            <input id="profile-password" type="password" class="form-control" name="password" minlength="4" autocomplete="new-password">
                        </div>
                        <div class="text-end">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ trans('shift.modal_cancel') }}</button>
                            <button type="submit" class="btn btn-primary">{{ trans('shift.modal_confirm') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- 訊息 Modal --}}
    <div class="modal fade" id="modal-profile-msg" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-body text-center py-4">
                    <p id="modal-profile-msg-text" class="mb-3"></p>
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ==================== Scripts ==================== --}}
    {{-- Architect UI 核心 --}}
    <script src="{{ asset('vendors/architect-ui/vendors/jquery/dist/jquery.min.js') }}"></script>
    <script src="{{ asset('vendors/architect-ui/vendors/bootstrap/dist/js/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('vendors/architect-ui/vendors/metismenu/dist/metisMenu.min.js') }}"></script>
    <script src="{{ asset('vendors/architect-ui/vendors/perfect-scrollbar/dist/perfect-scrollbar.min.js') }}"></script>
    <script src="{{ asset('vendors/architect-ui/js/app.js') }}"></script>
    <script src="{{ asset('vendors/architect-ui/js/scrollbar.js') }}"></script>
    <script src="{{ asset('vendors/architect-ui/js/bs5-modal-to-body.js') }}"></script>

    {{-- Flatpickr --}}
    <script src="{{ asset('vendor/flatpickr/flatpickr.min.js') }}"></script>
    <script src="{{ asset('vendor/flatpickr/zh-tw.js') }}"></script>
    <script src="{{ asset('vendor/flatpickr/monthSelect.js') }}"></script>
    <script>flatpickr.localize(flatpickr.l10ns.zh_tw);</script>

    <script>
    $(function () {
        var $appContainer = $('.app-container');

        // Sidebar 狀態同步
        var sidebarState = localStorage.getItem('sidebar-state');
        if (sidebarState === 'closed') {
            $appContainer.addClass('closed-sidebar');
        }

        requestAnimationFrame(function () {
            $('body').addClass('page-loaded');
            $appContainer.addClass('page-loaded');
        });

        // Sidebar 切換記憶
        $('.hamburger.close-sidebar-btn').on('click', function () {
            setTimeout(function () {
                var isClosed = $appContainer.hasClass('closed-sidebar');
                localStorage.setItem('sidebar-state', isClosed ? 'closed' : 'open');
            }, 150);
        });

        // 手機 sidebar 遮罩
        $('#sidebarMobileOverlay').on('click', function () {
            $appContainer.removeClass('sidebar-mobile-open');
            $('.mobile-toggle-nav').removeClass('is-active');
        });

        // 時鐘
        var weekdays = ['日', '一', '二', '三', '四', '五', '六'];
        function updateClock() {
            var now = new Date();
            var y = now.getFullYear();
            var m = String(now.getMonth() + 1).padStart(2, '0');
            var d = String(now.getDate()).padStart(2, '0');
            var w = weekdays[now.getDay()];
            var h = String(now.getHours()).padStart(2, '0');
            var min = String(now.getMinutes()).padStart(2, '0');
            var s = String(now.getSeconds()).padStart(2, '0');
            $('#topbar-clock').text(y + '/' + m + '/' + d + '（' + w + '）' + h + ':' + min + ':' + s);
        }
        updateClock();
        setInterval(updateClock, 1000);

        // 深色模式
        var saved = localStorage.getItem('theme');
        if (saved === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
            $('.theme-icon-dark').hide();
            $('.theme-icon-light').show();
        }

        $('#theme-toggle').on('click', function () {
            var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            if (isDark) {
                document.documentElement.removeAttribute('data-theme');
                localStorage.setItem('theme', 'light');
                $('.theme-icon-dark').show();
                $('.theme-icon-light').hide();
            } else {
                document.documentElement.setAttribute('data-theme', 'dark');
                localStorage.setItem('theme', 'dark');
                $('.theme-icon-dark').hide();
                $('.theme-icon-light').show();
            }
        });

        // 個人資訊 Modal
        $('#btn-open-profile, #btn-open-profile-dropdown, #btn-open-profile-sidebar').on('click', function () {
            showBsModal('modal-profile');
        });

        // 個人資訊提交
        $('#form-profile').on('submit', function (e) {
            e.preventDefault();
            var data = {};
            var nickname = $('#profile-nickname').val().trim();
            var password = $('#profile-password').val();
            if (nickname) { data.nickname = nickname; }
            if (password) { data.password = password; }

            $.ajax({
                url: '/admin/profile',
                method: 'PUT',
                headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                contentType: 'application/json',
                data: JSON.stringify(data),
                success: function (body) {
                    hideBsModal('modal-profile');
                    $('#modal-profile-msg-text').text(body.message || '已更新');
                    showBsModal('modal-profile-msg');
                    if (nickname) {
                        $('.header-user-info .widget-heading').text(nickname);
                    }
                },
                error: function () {
                    $('#modal-profile-msg-text').text('更新失敗');
                    showBsModal('modal-profile-msg');
                }
            });
        });
    });
    </script>

    {{-- Service Worker + Web Push --}}
    <script>
    (function () {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) { return; }

        navigator.serviceWorker.register('/sw.js').then(function (reg) {
            window._swReg = reg;
            if ('Notification' in window && Notification.permission === 'granted') {
                subscribePush(reg);
            }
        }).catch(function () {});

        function urlBase64ToUint8Array(base64String) {
            var padding = '='.repeat((4 - base64String.length % 4) % 4);
            var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
            var rawData = window.atob(base64);
            var outputArray = new Uint8Array(rawData.length);
            for (var i = 0; i < rawData.length; ++i) {
                outputArray[i] = rawData.charCodeAt(i);
            }
            return outputArray;
        }

        function subscribePush(reg) {
            var vapidKey = '{{ config("services.vapid.public_key") }}';
            if (!vapidKey) { return; }

            reg.pushManager.getSubscription().then(function (sub) {
                if (sub) { return sub; }
                return reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(vapidKey)
                });
            }).then(function (sub) {
                if (!sub) { return; }
                fetch('/admin/push/ajax-subscribe', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify(sub.toJSON())
                });
            }).catch(function (e) {
                console.warn('Push subscribe failed:', e);
            });
        }

        window.requestPushPermission = function () {
            if (!('Notification' in window)) { return; }
            Notification.requestPermission().then(function (result) {
                if (result === 'granted' && window._swReg) {
                    subscribePush(window._swReg);
                }
            });
        };
    })();
    </script>

    {{-- 全域 Modal helper：避免重複建立 instance --}}
    <script>
    window.showBsModal = function (el) {
        if (typeof el === 'string') { el = document.getElementById(el); }
        if (!el) { return; }
        // 先清除所有殘留 backdrop
        document.querySelectorAll('.modal-backdrop').forEach(function (b) { b.remove(); });
        bootstrap.Modal.getOrCreateInstance(el).show();
    };
    window.hideBsModal = function (el) {
        if (typeof el === 'string') { el = document.getElementById(el); }
        if (!el) { return; }
        var inst = bootstrap.Modal.getInstance(el);
        if (inst) { inst.hide(); }
    };

    // Modal 關閉後清除殘留 backdrop + body class + 重設表單
    document.addEventListener('hidden.bs.modal', function (e) {
        var backdrops = document.querySelectorAll('.modal-backdrop');
        var openModals = document.querySelectorAll('.modal.show');
        if (openModals.length === 0) {
            backdrops.forEach(function (b) { b.remove(); });
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('padding-right');
            document.body.style.removeProperty('overflow');
        }
        // 清空關閉的 modal 內的表單
        var modal = e.target;
        if (modal && modal.classList.contains('modal')) {
            var form = modal.querySelector('form');
            if (form) { form.reset(); }
        }
    });
    </script>

    {{-- 相容層 --}}
    <script>
    (function () {
        window.openModalCompat = function (id) {
            window.showBsModal(id);
        };

        // 攔截舊的 data-modal-close 按鈕
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-modal-close]');
            if (!btn) { return; }
            var modal = btn.closest('.modal');
            if (modal) {
                var instance = bootstrap.Modal.getInstance(modal);
                if (instance) { instance.hide(); }
            } else {
                var overlay = btn.closest('.modal-overlay');
                if (overlay) { overlay.style.display = 'none'; }
            }
        });
    })();
    </script>

    @yield('scripts')
</body>
</html>
