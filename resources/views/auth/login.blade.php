<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('login.title') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('img/pwa-apple-icon.png') }}">
    <link rel="manifest" href="{{ asset('manifest.json') }}">
    <meta name="theme-color" content="#1e3a5f">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">
    <link rel="stylesheet" href="{{ asset('vendors/architect-ui/styles/css/base.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body>
    <div class="app-container">
        <div class="h-100">
            <div class="h-100 no-gutters row">
                <div class="d-none d-lg-block col-lg-4" style="background: linear-gradient(135deg, #1a1d27 0%, #252733 100%);">
                    <div class="d-flex flex-column justify-content-center align-items-center h-100 p-4">
                        <img src="{{ asset('img/logo.png') }}" alt="Logo" style="max-width: 280px; width: 100%; height: auto;">
                        <h4 class="mt-3 text-white fw-light">{{ config('app.name') }}</h4>
                        <p class="text-white-50">技術客服管理系統</p>
                    </div>
                </div>
                <div class="h-100 d-flex justify-content-center align-items-center col-md-12 col-lg-8" style="background: #f2f4f6;">
                    <div class="mx-auto app-login-box col-sm-12 col-md-8 col-lg-6">
                        <div class="d-lg-none text-center mb-4">
                            <img src="{{ asset('img/logo.png') }}" alt="Logo" style="max-width: 200px; width: 60%;">
                        </div>
                        <h4 class="mb-0">
                            <span class="d-block mb-1 text-muted" style="font-size: 0.875rem;">歡迎回來</span>
                            {{ __('login.title') }}
                        </h4>
                        <div class="divider row"></div>

                        @if ($errors->any())
                            <div class="alert alert-danger">
                                @foreach ($errors->all() as $error)
                                    <p class="mb-0">{{ $error }}</p>
                                @endforeach
                            </div>
                        @endif

                        <form method="POST" action="{{ route('login') }}">
                            @csrf
                            <div class="mb-3">
                                <label for="account" class="form-label">{{ __('login.account') }}</label>
                                <input id="account" type="text" class="form-control" name="account" value="{{ old('account') }}" required autofocus autocomplete="username">
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">{{ __('login.password') }}</label>
                                <input id="password" type="password" class="form-control" name="password" required autocomplete="current-password">
                            </div>
                            <div class="divider row"></div>
                            <div class="d-flex align-items-center">
                                <div class="ms-auto">
                                    <button type="submit" class="btn btn-primary btn-lg">{{ __('login.submit') }}</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
