<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('login.title') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('img/pwa-apple-icon.png') }}">
    <link rel="manifest" href="{{ asset('manifest.json') }}">
    <meta name="theme-color" content="#a67c00">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body>
    <main class="login-page">
        <form method="POST" action="{{ route('login') }}" class="login-form">
            @csrf

            <div class="login-form__brand">
                <img src="{{ asset('img/logo.png') }}" alt="Logo" class="login-form__logo">
                <span>{{ config('app.name') }}</span>
            </div>

            <h1>{{ __('login.title') }}</h1>

            @if ($errors->any())
                <div class="login-form__errors">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <div class="form-group">
                <label for="account">{{ __('login.account') }}</label>
                <input id="account" type="text" name="account" value="{{ old('account') }}" required autofocus autocomplete="username">
            </div>

            <div class="form-group">
                <label for="password">{{ __('login.password') }}</label>
                <input id="password" type="password" name="password" required autocomplete="current-password">
            </div>

            <button type="submit">{{ __('login.submit') }}</button>
        </form>
    </main>
</body>
</html>
