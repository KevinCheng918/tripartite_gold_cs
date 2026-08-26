<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('login.title') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('img/pwa-apple-icon.png') }}">
    <link rel="manifest" href="{{ asset('manifest.json') }}">
    <meta name="theme-color" content="#0a0a0a">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">
    <link rel="stylesheet" href="{{ asset('vendors/architect-ui/vendors/@fortawesome/fontawesome-free/css/all.min.css') }}">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@400;500;700;900&display=swap');

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: #0a0a0a;
            font-family: 'Noto Sans TC', sans-serif;
            position: relative;
            overflow: hidden;
            color: #fff;
        }

        /* ---- 背景裝飾 ---- */

        /* 右上角金色圓弧 */
        .bg-arc-1 {
            position: absolute;
            top: -180px;
            right: -180px;
            width: 500px;
            height: 500px;
            border: 1px solid rgba(212, 175, 55, 0.25);
            border-radius: 50%;
            pointer-events: none;
        }
        .bg-arc-2 {
            position: absolute;
            top: -120px;
            right: -120px;
            width: 380px;
            height: 380px;
            border: 1px solid rgba(212, 175, 55, 0.12);
            border-radius: 50%;
            pointer-events: none;
        }
        /* 左下角圓弧 */
        .bg-arc-3 {
            position: absolute;
            bottom: -100px;
            left: -100px;
            width: 350px;
            height: 350px;
            border: 1px solid rgba(212, 175, 55, 0.1);
            border-radius: 50%;
            pointer-events: none;
        }
        /* 左下角金色光暈 */
        .bg-glow-left {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 40%;
            height: 30%;
            background: radial-gradient(ellipse at 15% 100%, rgba(212, 175, 55, 0.15) 0%, rgba(212, 175, 55, 0.04) 30%, transparent 55%);
            pointer-events: none;
        }
        /* 左側半透明 Logo 浮水印 */
        .bg-watermark {
            position: absolute;
            top: 15%;
            left: -8%;
            width: 350px;
            height: 450px;
            background: url('{{ asset("img/logo.png") }}') no-repeat center;
            background-size: contain;
            opacity: 0.03;
            pointer-events: none;
        }
        /* 右下角金色光暈 + 波浪 */
        .bg-glow {
            position: absolute;
            bottom: 0;
            right: 0;
            width: 55%;
            height: 35%;
            background:
                radial-gradient(ellipse at 85% 100%, rgba(212, 175, 55, 0.25) 0%, rgba(212, 175, 55, 0.1) 25%, transparent 55%),
                radial-gradient(ellipse at 50% 90%, rgba(212, 175, 55, 0.08) 0%, transparent 40%);
            pointer-events: none;
        }

        /* 亮晶晶粒子 */
        .sparkle {
            position: absolute;
            width: 3px;
            height: 3px;
            background: #d4af37;
            border-radius: 50%;
            box-shadow: 0 0 8px 3px rgba(212, 175, 55, 0.7);
            animation: sparkleAnim linear infinite;
            opacity: 0;
            pointer-events: none;
        }
        @keyframes sparkleAnim {
            0% { opacity: 0; transform: translateY(0) scale(0); }
            15% { opacity: 1; transform: translateY(-10px) scale(1); }
            85% { opacity: 0.5; }
            100% { opacity: 0; transform: translateY(-60px) scale(0.3); }
        }

        /* ---- 頂部標題 ---- */
        .login-header {
            text-align: center;
            margin-bottom: 2.5rem;
            position: relative;
            z-index: 1;
        }
        .login-header__crown {
            display: block;
            margin-bottom: 0.75rem;
        }
        .login-header__crown i {
            font-size: 1.75rem;
            color: #d4af37;
        }
        .login-header__crown::before,
        .login-header__crown::after {
            content: '';
            display: inline-block;
            width: 80px;
            height: 1px;
            vertical-align: middle;
        }
        .login-header__crown::before {
            background: linear-gradient(to right, transparent, #d4af37);
            margin-right: 1.25rem;
        }
        .login-header__crown::after {
            background: linear-gradient(to left, transparent, #d4af37);
            margin-left: 1.25rem;
        }
        .login-header h1 {
            font-size: 2.25rem;
            font-weight: 700;
            color: #f0e6c8;
            letter-spacing: 0.06em;
            margin-bottom: 0.5rem;
            text-shadow: 0 2px 8px rgba(212, 175, 55, 0.15);
        }
        .login-header p {
            color: rgba(255,255,255,0.35);
            font-size: 0.9375rem;
            letter-spacing: 0.15em;
            font-weight: 400;
        }

        /* ---- 登入卡片 ---- */
        .login-card {
            position: relative;
            z-index: 1;
            background: rgba(12, 12, 10, 0.92);
            border: 1px solid rgba(212, 175, 55, 0.2);
            border-radius: 18px;
            display: flex;
            width: 90%;
            max-width: 900px;
            overflow: hidden;
            backdrop-filter: blur(16px);
            box-shadow: 0 24px 80px rgba(0,0,0,0.5), 0 0 1px rgba(212, 175, 55, 0.3);
        }

        /* 左側 Logo 區 */
        .login-card__left {
            flex: 1.1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 3rem 2rem;
            position: relative;
            background:
                radial-gradient(ellipse at 50% 40%, rgba(212, 175, 55, 0.06) 0%, transparent 60%),
                radial-gradient(ellipse at 30% 70%, rgba(212, 175, 55, 0.03) 0%, transparent 50%);
        }
        /* 金色水平光線 */
        .login-card__left::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 5%;
            right: 5%;
            height: 1px;
            background: linear-gradient(to right, transparent, rgba(212, 175, 55, 0.25), transparent);
        }
        .login-card__left img {
            max-width: 88%;
            max-height: 320px;
            object-fit: contain;
            position: relative;
            z-index: 1;
            filter: drop-shadow(0 0 40px rgba(212, 175, 55, 0.15));
            margin-bottom: 1.5rem;
        }
        .login-card__left h3 {
            color: #f0e6c8;
            font-size: 1.25rem;
            font-weight: 700;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            position: relative;
            z-index: 1;
        }
        .login-card__left .left-sub {
            color: rgba(255,255,255,0.3);
            font-size: 0.6875rem;
            letter-spacing: 0.25em;
            text-transform: uppercase;
            position: relative;
            z-index: 1;
            margin-top: 0.25rem;
        }

        /* 右側表單區 */
        .login-card__right {
            flex: 1;
            padding: 3rem 2.5rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: rgba(18, 16, 12, 0.95);
            border-left: 1px solid rgba(212, 175, 55, 0.12);
        }
        .login-card__right h2 {
            font-size: 1.625rem;
            font-weight: 700;
            color: #fff;
            margin-bottom: 0.375rem;
        }
        .login-card__right .form-subtitle {
            color: rgba(255,255,255,0.4);
            font-size: 0.875rem;
            margin-bottom: 2rem;
            font-weight: 400;
        }

        /* 輸入框 */
        .login-input-group {
            position: relative;
            margin-bottom: 1.5rem;
        }
        .login-input-group i.input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255,255,255,0.3);
            font-size: 1rem;
        }
        .login-input-group .toggle-pw {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255,255,255,0.3);
            cursor: pointer;
            background: none;
            border: none;
            font-size: 1rem;
        }
        .login-input-group input {
            width: 100%;
            padding: 1rem 1rem 1rem 3rem;
            font-size: 1rem;
            font-family: inherit;
            border: 1px solid rgba(212, 175, 55, 0.25);
            border-radius: 12px;
            outline: none;
            background: rgba(255,255,255,0.04);
            color: #fff;
            transition: border-color 0.2s, background 0.2s;
        }
        .login-input-group input:focus {
            border-color: rgba(212, 175, 55, 0.6);
            background: rgba(255,255,255,0.06);
        }
        .login-input-group input::placeholder {
            color: rgba(255,255,255,0.3);
            font-weight: 400;
        }

        /* 登入按鈕 */
        .login-btn {
            width: 100%;
            padding: 1rem;
            font-size: 1.0625rem;
            font-weight: 700;
            font-family: inherit;
            color: #1a1200;
            background: linear-gradient(135deg, #e8c84a, #d4af37, #b8960c);
            border: none;
            border-radius: 12px;
            cursor: pointer;
            transition: box-shadow 0.2s, transform 0.1s;
            letter-spacing: 0.08em;
            margin-top: 0.5rem;
        }
        .login-btn:hover {
            box-shadow: 0 8px 28px rgba(212, 175, 55, 0.35);
            transform: translateY(-1px);
        }
        .login-btn:active { transform: translateY(0); }
        .login-btn i { margin-left: 0.625rem; }

        /* 錯誤提示 */
        .login-error {
            background: rgba(220, 38, 38, 0.12);
            border: 1px solid rgba(220, 38, 38, 0.25);
            border-radius: 12px;
            padding: 0.75rem 1rem;
            margin-bottom: 1.25rem;
            color: #fca5a5;
            font-size: 0.875rem;
        }
        .login-error p { margin: 0; }

        /* 全形字元提示（僅提醒，不阻擋送出） */
        .login-warning {
            background: rgba(212, 175, 55, 0.1);
            border: 1px solid rgba(212, 175, 55, 0.3);
            border-radius: 12px;
            padding: 0.75rem 1rem;
            margin-bottom: 1.25rem;
            color: #f0e6c8;
            font-size: 0.875rem;
        }
        .login-warning i { margin-right: 0.5rem; color: #d4af37; }
        .login-input-group input.has-full-width {
            border-color: rgba(212, 175, 55, 0.7);
            background: rgba(212, 175, 55, 0.06);
        }

        /* 底部 */
        .login-footer {
            margin-top: 2.5rem;
            color: rgba(255,255,255,0.2);
            font-size: 0.75rem;
            position: relative;
            z-index: 1;
            letter-spacing: 0.05em;
        }

        /* ---- 手機版 ---- */
        @media (max-width: 767.98px) {
            .login-card__left { display: none; }
            .login-card { max-width: 420px; border-radius: 14px; }
            .login-card__right { padding: 2rem 1.5rem; border-left: none; }
            .login-header h1 { font-size: 1.625rem; }
            .login-header__crown::before, .login-header__crown::after { width: 50px; }
            .bg-watermark { display: none; }
        }
    </style>
</head>
<body>

    {{-- 背景裝飾 --}}
    <div class="bg-arc-1"></div>
    <div class="bg-arc-2"></div>
    <div class="bg-arc-3"></div>
    <div class="bg-watermark"></div>
    <div class="bg-glow"></div>
    <div class="bg-glow-left"></div>

    {{-- 亮晶晶粒子 --}}
    <div class="sparkle" style="bottom:12%;right:6%;animation-duration:2.5s;animation-delay:0s;width:4px;height:4px"></div>
    <div class="sparkle" style="bottom:22%;right:14%;animation-duration:3s;animation-delay:0.6s"></div>
    <div class="sparkle" style="bottom:8%;right:22%;animation-duration:2.2s;animation-delay:1.1s;width:2px;height:2px"></div>
    <div class="sparkle" style="bottom:28%;right:4%;animation-duration:3.4s;animation-delay:1.6s"></div>
    <div class="sparkle" style="bottom:18%;right:28%;animation-duration:2.8s;animation-delay:0.9s;width:4px;height:4px"></div>
    <div class="sparkle" style="bottom:32%;right:10%;animation-duration:2.1s;animation-delay:2.1s"></div>
    <div class="sparkle" style="bottom:5%;right:34%;animation-duration:3.2s;animation-delay:0.4s;width:2px;height:2px"></div>
    <div class="sparkle" style="bottom:15%;right:38%;animation-duration:2.7s;animation-delay:1.3s"></div>
    <div class="sparkle" style="bottom:38%;right:16%;animation-duration:3s;animation-delay:1.9s;width:3px;height:3px"></div>
    <div class="sparkle" style="bottom:10%;left:8%;animation-duration:2.6s;animation-delay:0.7s;width:3px;height:3px"></div>
    <div class="sparkle" style="bottom:20%;left:15%;animation-duration:3.3s;animation-delay:1.4s"></div>
    <div class="sparkle" style="bottom:6%;left:22%;animation-duration:2.4s;animation-delay:2.3s;width:4px;height:4px"></div>
    <div class="sparkle" style="bottom:28%;left:6%;animation-duration:2.9s;animation-delay:0.5s;width:2px;height:2px"></div>

    {{-- 標題 --}}
    <div class="login-header">
        <span class="login-header__crown"><i class="fas fa-crown"></i></span>
        <h1>{{ config('app.name') }}</h1>
        <p>技術客服管理系統</p>
    </div>

    {{-- 卡片 --}}
    <div class="login-card">
        <div class="login-card__left">
            <img src="{{ asset('img/logo.png') }}" alt="Logo">
            <h3>LV SYSTEM</h3>
            <div class="left-sub">CUSTOMER SERVICE MANAGEMENT</div>
        </div>
        <div class="login-card__right">
            <h2>歡迎登入</h2>
            <div class="form-subtitle">請輸入您的帳號與密碼</div>

            @if ($errors->any())
                <div class="login-error">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <div id="login-fw-warning" class="login-warning" style="display:none">
                <i class="fas fa-keyboard"></i><span>{{ __('login.full_width_hint') }}</span>
            </div>

            <form method="POST" action="{{ route('login') }}">
                @csrf
                <div class="login-input-group">
                    <i class="far fa-user input-icon"></i>
                    <input id="account" type="text" name="account" value="{{ old('account') }}" required autofocus autocomplete="username" placeholder="{{ __('login.account') }}">
                </div>
                <div class="login-input-group">
                    <i class="fas fa-lock input-icon"></i>
                    <input id="password" type="password" name="password" required autocomplete="current-password" placeholder="{{ __('login.password') }}">
                    <button type="button" class="toggle-pw" onclick="var p=document.getElementById('password');p.type=p.type==='password'?'text':'password';this.querySelector('i').classList.toggle('fa-eye');this.querySelector('i').classList.toggle('fa-eye-slash');">
                        <i class="far fa-eye"></i>
                    </button>
                </div>
                <button type="submit" class="login-btn">{{ __('login.submit') }} <i class="fas fa-arrow-right"></i></button>
            </form>
        </div>
    </div>

    <div class="login-footer">
        &copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
    </div>

    <script src="{{ asset('js/login.js') }}"></script>

</body>
</html>
