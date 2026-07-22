<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>
    <link rel="stylesheet" href="{{ mix('css/app.css') }}">
</head>
<body>
    <nav class="app-nav">
        <a href="{{ route('admin.accounts.index') }}">{{ __('account.nav_label') }}</a>
        <a href="{{ route('admin.roles.index') }}">{{ __('role.nav_label') }}</a>
        <form action="{{ route('logout') }}" method="POST" class="app-nav__logout">
            @csrf
            <button type="submit">{{ __('auth.logout') }}</button>
        </form>
    </nav>

    <main>
        @yield('content')
    </main>

    <script src="{{ mix('js/app.js') }}"></script>
    @yield('scripts')
</body>
</html>
