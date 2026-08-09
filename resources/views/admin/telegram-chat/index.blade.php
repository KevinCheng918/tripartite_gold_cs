@extends('layouts.app')

@section('title', trans('telegram_chat.page_title'))
@section('icon', 'comments')
@section('subtitle', trans('telegram_chat.subtitle'))

@section('content')

    <div id="telegram-chat-app"
         data-i18n='@json(trans("telegram_chat"))'
         data-user-id="{{ Auth::id() }}"
         data-user-nickname="{{ Auth::user()->nickname }}"
         data-can-reply="{{ Auth::user()->hasPermission('telegram_chat.reply') ? '1' : '0' }}"
         data-ws-key="{{ config('broadcasting.connections.pusher.key') }}"
         data-ws-host="{{ config('broadcasting.connections.pusher.options.host') }}"
         data-ws-port="{{ config('broadcasting.connections.pusher.options.port') }}"
         data-ws-scheme="{{ config('broadcasting.connections.pusher.options.scheme') }}">
        <p>Loading…</p>
    </div>

@endsection

@section('scripts')
    <script src="{{ asset('vendor/pusher.min.js') }}"></script>
    <script src="{{ asset('js/telegram-chat.js') }}?v={{ filemtime(public_path('js/telegram-chat.js')) }}"></script>
@endsection
