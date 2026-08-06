<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <script>if(localStorage.getItem('theme')==='dark')document.documentElement.setAttribute('data-theme','dark');</script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('img/favicon-180.png') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/flatpickr/flatpickr.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/flatpickr/airbnb.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/flatpickr/monthSelect.css') }}">
</head>
<body class="app-body">

    {{-- 側邊欄 --}}
    <aside class="sidebar">
        <div class="sidebar__brand">
            <img src="{{ asset('img/logo.png') }}" alt="Logo" class="sidebar__logo-img">
            <span class="sidebar__brand-name">{{ config('app.name') }}</span>
            <span class="sidebar__brand-sub">技術客服管理系統</span>
        </div>

        <nav class="sidebar__nav">
            <a href="{{ route('admin.dashboard') }}"
               class="{{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
                <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"/></svg>
                {{ trans('dashboard.nav_label') }}
            </a>
        </nav>

        <div class="sidebar__section-label">主要功能</div>
        <nav class="sidebar__nav">
            @if(Auth::user()->hasPermission('account.view'))
            <a href="{{ route('admin.accounts.index') }}"
               class="{{ request()->routeIs('admin.accounts.*') ? 'active' : '' }}">
                <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z"/></svg>
                {{ trans('account.nav_label') }}
            </a>
            @endif
            @if(Auth::user()->hasPermission('shift.view'))
            <a href="{{ route('admin.shifts.index') }}"
               class="{{ request()->routeIs('admin.shifts.*') ? 'active' : '' }}">
                <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/></svg>
                {{ trans('shift.nav_label') }}
            </a>
            @endif
            @if(Auth::user()->hasPermission('attendance.view'))
            <a href="{{ route('admin.attendance.index') }}"
               class="{{ request()->routeIs('admin.attendance.*') ? 'active' : '' }}">
                <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/></svg>
                {{ trans('attendance.nav_label') }}
            </a>
            @endif
            <a href="{{ route('admin.telegram-chat.index') }}"
               class="{{ request()->routeIs('admin.telegram-chat.*') ? 'active' : '' }}">
                <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path fill-rule="evenodd" d="M18 10c0 3.866-3.582 7-8 7a8.841 8.841 0 01-4.083-.98L2 17l1.338-3.123C2.493 12.767 2 11.434 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7zM7 9H5v2h2V9zm8 0h-2v2h2V9zM9 9h2v2H9V9z" clip-rule="evenodd"/></svg>
                {{ trans('telegram_chat.nav_label') }}
            </a>
        </nav>

        <div class="sidebar__spacer"></div>

        <div class="sidebar__footer">
            <form action="{{ route('logout') }}" method="POST">
                @csrf
                <button type="submit" class="sidebar__logout-btn">
                    <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path fill-rule="evenodd" d="M3 3a1 1 0 00-1 1v12a1 1 0 001 1h5a1 1 0 100-2H4V5h4a1 1 0 100-2H3zm11.707 3.293a1 1 0 010 1.414L13.414 9H17a1 1 0 110 2h-3.586l1.293 1.293a1 1 0 01-1.414 1.414l-3-3a1 1 0 010-1.414l3-3a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                    {{ trans('auth.logout') }}
                </button>
            </form>
        </div>
    </aside>

    {{-- 右側主區域 --}}
    <div class="app-main">
        {{-- 頂部列 --}}
        <header class="topbar">
            <div class="topbar__left">
                <h1 class="topbar__title">@yield('title', config('app.name'))</h1>
                <p class="topbar__subtitle">@yield('subtitle', '')</p>
            </div>
            <div class="topbar__right">
                <span class="topbar__clock" id="topbar-clock"></span>
                <button class="theme-toggle" id="theme-toggle" title="切換深色/淺色模式">
                    <svg class="theme-toggle__sun" viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path fill-rule="evenodd" d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z" clip-rule="evenodd"/></svg>
                    <svg class="theme-toggle__moon" viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"/></svg>
                </button>
                <div class="topbar__user" id="btn-open-profile" style="cursor:pointer" title="個人設定">
                    <span class="topbar__avatar">{{ mb_substr(Auth::user()->nickname, 0, 1) }}</span>
                    <div class="topbar__user-info">
                        <span class="topbar__user-name">{{ Auth::user()->nickname }}</span>
                        <span class="topbar__user-account">{{ Auth::user()->account }}</span>
                    </div>
                </div>
            </div>
        </header>

        {{-- 內容區 --}}
        <main class="app-content">
            @yield('content')
        </main>
    </div>

    {{-- 個人資訊 Modal --}}
    @component('components.modal', ['id' => 'modal-profile', 'title' => trans('profile.title')])
        <form id="form-profile">
            <div class="form-group">
                <label>{{ trans('profile.field_account') }}</label>
                <input type="text" value="{{ Auth::user()->account }}" disabled style="opacity:0.6">
            </div>
            <div class="form-group">
                <label for="profile-nickname">{{ trans('profile.field_nickname') }}</label>
                <input id="profile-nickname" type="text" name="nickname" value="{{ Auth::user()->nickname }}" required>
            </div>
            <div class="form-group">
                <label for="profile-password">{{ trans('profile.field_password') }}（{{ trans('profile.password_hint') }}）</label>
                <input id="profile-password" type="password" name="password" minlength="4" autocomplete="new-password">
            </div>
            <div class="modal-actions">
                <button type="button" data-modal-close>{{ trans('shift.modal_cancel') }}</button>
                <button type="submit" class="btn-primary">{{ trans('shift.modal_confirm') }}</button>
            </div>
        </form>
    @endcomponent

    {{-- 個人資訊訊息 Modal --}}
    @component('components.modal', ['id' => 'modal-profile-msg', 'title' => ''])
        <p id="modal-profile-msg-text"></p>
        <div class="modal-actions">
            <button type="button" data-modal-close class="btn-primary">OK</button>
        </div>
    @endcomponent

    <script src="{{ asset('vendor/flatpickr/flatpickr.min.js') }}"></script>
    <script src="{{ asset('vendor/flatpickr/zh-tw.js') }}"></script>
    <script src="{{ asset('vendor/flatpickr/monthSelect.js') }}"></script>
    <script>flatpickr.localize(flatpickr.l10ns.zh_tw);</script>
    <script>
    (function () {
        var el = document.getElementById('topbar-clock');
        if (!el) { return; }
        var weekdays = ['日', '一', '二', '三', '四', '五', '六'];
        function update() {
            var now = new Date();
            var y = now.getFullYear();
            var m = String(now.getMonth() + 1).padStart(2, '0');
            var d = String(now.getDate()).padStart(2, '0');
            var w = weekdays[now.getDay()];
            var h = String(now.getHours()).padStart(2, '0');
            var min = String(now.getMinutes()).padStart(2, '0');
            var s = String(now.getSeconds()).padStart(2, '0');
            el.textContent = y + '/' + m + '/' + d + '（' + w + '）' + h + ':' + min + ':' + s;
        }
        update();
        setInterval(update, 1000);
    })();
    </script>
    <script>
    (function () {
        var btn = document.getElementById('theme-toggle');
        if (!btn) { return; }

        // 從 localStorage 讀取偏好
        var saved = localStorage.getItem('theme');
        if (saved === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
        }

        btn.addEventListener('click', function () {
            var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            if (isDark) {
                document.documentElement.removeAttribute('data-theme');
                localStorage.setItem('theme', 'light');
            } else {
                document.documentElement.setAttribute('data-theme', 'dark');
                localStorage.setItem('theme', 'dark');
            }
        });
    })();
    </script>
    <script>
    (function () {
        var csrfToken = document.querySelector('meta[name="csrf-token"]').content;

        // 開啟個人資訊 modal
        var profileBtn = document.getElementById('btn-open-profile');
        if (profileBtn) {
            profileBtn.addEventListener('click', function () {
                var modal = document.getElementById('modal-profile');
                if (modal) { modal.style.display = 'flex'; }
            });
        }

        // modal 關閉
        document.querySelectorAll('#modal-profile [data-modal-close], #modal-profile-msg [data-modal-close]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                btn.closest('.modal-overlay').style.display = 'none';
            });
        });

        ['modal-profile', 'modal-profile-msg'].forEach(function (id) {
            var overlay = document.getElementById(id);
            if (overlay) {
                overlay.addEventListener('click', function (e) {
                    if (e.target === overlay) { overlay.style.display = 'none'; }
                });
            }
        });

        // 提交個人資訊
        var form = document.getElementById('form-profile');
        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var data = {};
                var nickname = document.getElementById('profile-nickname').value.trim();
                var password = document.getElementById('profile-password').value;

                if (nickname) { data.nickname = nickname; }
                if (password) { data.password = password; }

                fetch('/admin/profile', {
                    method: 'PUT',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                    },
                    body: JSON.stringify(data),
                })
                    .then(function (r) { return r.json(); })
                    .then(function (body) {
                        document.getElementById('modal-profile').style.display = 'none';
                        document.getElementById('modal-profile-msg-text').textContent = body.message || '已更新';
                        document.getElementById('modal-profile-msg').style.display = 'flex';

                        // 更新頁面上的暱稱
                        if (nickname) {
                            document.querySelector('.topbar__user-name').textContent = nickname;
                            var avatar = document.querySelector('.topbar__avatar');
                            if (avatar) { avatar.textContent = nickname.substring(0, 1); }
                        }
                    })
                    .catch(function () {
                        document.getElementById('modal-profile-msg-text').textContent = '更新失敗';
                        document.getElementById('modal-profile-msg').style.display = 'flex';
                    });
            });
        }
    })();
    </script>
    @yield('scripts')
</body>
</html>
