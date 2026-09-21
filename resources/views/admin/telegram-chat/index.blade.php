@extends('layouts.app')

@section('title', trans('telegram_chat.page_title'))
@section('icon', 'comments')
@section('subtitle', trans('telegram_chat.subtitle'))

@section('content')

    <div id="telegram-chat-app"
         {{-- JSON_HEX_APOS：語系若出現單引號（英文縮寫），會提早結束這個 data 屬性 --}}
         data-i18n='@json(trans("telegram_chat"), JSON_HEX_APOS | JSON_HEX_QUOT)'
         data-user-id="{{ Auth::id() }}"
         data-user-nickname="{{ Auth::user()->nickname }}"
         data-can-reply="{{ Auth::user()->hasPermission('telegram_chat.reply') ? '1' : '0' }}"
         data-can-delete="{{ Auth::user()->hasPermission('telegram_chat.delete') ? '1' : '0' }}"
         data-can-ignore="{{ Auth::user()->hasPermission('telegram_chat.ignore_manage') ? '1' : '0' }}"
         data-auto-reply-available="{{ $autoReplyAvailable ? '1' : '0' }}"
         data-ws-key="{{ config('broadcasting.connections.pusher.key') }}"
         data-ws-host="{{ config('broadcasting.connections.pusher.options.host') }}"
         data-ws-port="{{ config('broadcasting.connections.pusher.options.port') }}"
         data-ws-scheme="{{ config('broadcasting.connections.pusher.options.scheme') }}">
        <p>Loading…</p>
    </div>

@endsection

@section('scripts')
    <script src="{{ asset('vendor/pusher.min.js') }}"></script>
    <script src="{{ asset('js/telegram-chat/state.js') }}?v={{ filemtime(public_path('js/telegram-chat/state.js')) }}"></script>
    <script src="{{ asset('js/telegram-chat/layout.js') }}?v={{ filemtime(public_path('js/telegram-chat/layout.js')) }}"></script>
    <script src="{{ asset('js/telegram-chat/messages.js') }}?v={{ filemtime(public_path('js/telegram-chat/messages.js')) }}"></script>
    <script src="{{ asset('js/telegram-chat/reactions.js') }}?v={{ filemtime(public_path('js/telegram-chat/reactions.js')) }}"></script>
    <script src="{{ asset('js/telegram-chat/swipe-reply.js') }}?v={{ filemtime(public_path('js/telegram-chat/swipe-reply.js')) }}"></script>
    <script src="{{ asset('js/telegram-chat/emoji.js') }}?v={{ filemtime(public_path('js/telegram-chat/emoji.js')) }}"></script>
    <script src="{{ asset('js/telegram-chat/input.js') }}?v={{ filemtime(public_path('js/telegram-chat/input.js')) }}"></script>
    <script src="{{ asset('js/telegram-chat/quick-reply.js') }}?v={{ filemtime(public_path('js/telegram-chat/quick-reply.js')) }}"></script>
    {{-- 必須排在 main.js 之前：renderHeader 實際執行時要看得到 T.openIgnorePanel --}}
    <script src="{{ asset('js/telegram-chat/ignore-member.js') }}?v={{ filemtime(public_path('js/telegram-chat/ignore-member.js')) }}"></script>
    <script src="{{ asset('js/telegram-chat/alert.js') }}?v={{ filemtime(public_path('js/telegram-chat/alert.js')) }}"></script>
    <script src="{{ asset('js/telegram-chat/main.js') }}?v={{ filemtime(public_path('js/telegram-chat/main.js')) }}"></script>
@endsection
