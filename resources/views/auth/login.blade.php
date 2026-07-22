<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('login.title') }}</title>
    <link rel="stylesheet" href="{{ mix('css/app.css') }}">
</head>
<body>
    <main class="login-page">
        <form method="POST" action="{{ route('login') }}" class="login-form">
            @csrf

            <h1>{{ __('login.title') }}</h1>

            @if ($errors->any())
                <div class="login-form__errors">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <label for="email">{{ __('login.email') }}</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>

            <label for="password">{{ __('login.password') }}</label>
            <input id="password" type="password" name="password" required>

            <label class="login-form__remember">
                <input type="checkbox" name="remember"> {{ __('login.remember') }}
            </label>

            <button type="submit">{{ __('login.submit') }}</button>
        </form>
    </main>
</body>
</html>
