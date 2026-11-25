@extends('layouts.app')

@section('title', trans('telegram_chat.page_title'))
@section('icon', 'comments')
@section('subtitle', trans('telegram_chat.subtitle'))

@section('content')

    {{-- ⚠ layout 的 @yield('css') 位在 <style> 標籤**內**，放 <link> 會整個失效
         （第一版踩到：編輯器變成沒有樣式的 static 區塊，長在頁面最底下看不到）。
         比照 shared-file 與 task-board，樣式表載在 content 開頭。 --}}
    <link rel="stylesheet"
          href="{{ asset('css/telegram-chat-image-editor.css') }}?v={{ filemtime(public_path('css/telegram-chat-image-editor.css')) }}">
    <link rel="stylesheet"
          href="{{ asset('css/telegram-chat-mention.css') }}?v={{ filemtime(public_path('css/telegram-chat-mention.css')) }}">

    <div id="telegram-chat-app"
         {{-- JSON_HEX_APOS：語系若出現單引號（英文縮寫），會提早結束這個 data 屬性 --}}
         data-i18n='@json(trans("telegram_chat"), JSON_HEX_APOS | JSON_HEX_QUOT)'
         data-user-id="{{ Auth::id() }}"
         data-user-nickname="{{ Auth::user()->nickname }}"
         data-can-reply="{{ Auth::user()->hasPermission('telegram_chat.reply') ? '1' : '0' }}"
         data-can-delete="{{ Auth::user()->hasPermission('telegram_chat.delete') ? '1' : '0' }}"
         data-can-ignore="{{ Auth::user()->hasPermission('telegram_chat.ignore_manage') ? '1' : '0' }}"
         {{-- ⚠ 只管「動別人送出的訊息」；改自己的有 can-reply 就夠 --}}
         data-can-edit-others="{{ Auth::user()->hasPermission('telegram_chat.edit_others') ? '1' : '0' }}"
         data-auto-reply-available="{{ $autoReplyAvailable ? '1' : '0' }}"
         {{--
             取消勾選時那個 modal 的設定。
             `enabled` 關著時不問意圖、直接關掉 —— 沒有排程會幫他勾回來，問了也沒用。
         --}}
         data-resume-enabled="{{ Arr::get($autoReplyResume, 'enabled') ? '1' : '0' }}"
         data-resume-default="{{ Arr::get($autoReplyResume, 'default') }}"
         data-resume-presets="{{ implode(',', Arr::get($autoReplyResume, 'presets', [])) }}"
         data-resume-min="{{ Arr::get($autoReplyResume, 'min') }}"
         data-resume-max="{{ Arr::get($autoReplyResume, 'max') }}"
         data-resume-mode-temporary="{{ Arr::get($autoReplyResume, 'modes.TEMPORARY') }}"
         data-resume-mode-permanent="{{ Arr::get($autoReplyResume, 'modes.PERMANENT') }}"
         data-ws-key="{{ config('broadcasting.connections.pusher.key') }}"
         data-ws-host="{{ config('broadcasting.connections.pusher.options.host') }}"
         data-ws-port="{{ config('broadcasting.connections.pusher.options.port') }}"
         data-ws-scheme="{{ config('broadcasting.connections.pusher.options.scheme') }}">
        <p>Loading…</p>
    </div>

@endsection

@section('scripts')
    {{-- pusher.min.js 由 layout 載（sidebar 的未讀數也要用），這裡不重複載入 --}}
    <script src="{{ asset('js/telegram-chat/state.js') }}?v={{ filemtime(public_path('js/telegram-chat/state.js')) }}"></script>
    <script src="{{ asset('js/telegram-chat/layout.js') }}?v={{ filemtime(public_path('js/telegram-chat/layout.js')) }}"></script>
    <script src="{{ asset('js/telegram-chat/messages.js') }}?v={{ filemtime(public_path('js/telegram-chat/messages.js')) }}"></script>
    <script src="{{ asset('js/telegram-chat/reactions.js') }}?v={{ filemtime(public_path('js/telegram-chat/reactions.js')) }}"></script>
    <script src="{{ asset('js/telegram-chat/swipe-reply.js') }}?v={{ filemtime(public_path('js/telegram-chat/swipe-reply.js')) }}"></script>
    <script src="{{ asset('js/telegram-chat/emoji.js') }}?v={{ filemtime(public_path('js/telegram-chat/emoji.js')) }}"></script>
    {{-- 必須排在 input.js 之前：點圖片時要看得到 T.openImageEditor --}}
    <script src="{{ asset('js/telegram-chat/image-editor.js') }}?v={{ filemtime(public_path('js/telegram-chat/image-editor.js')) }}"></script>
    {{-- 同樣要排在 input.js 之前：輸入區建好時要能呼叫 T.bindMention --}}
    <script src="{{ asset('js/telegram-chat/mention.js') }}?v={{ filemtime(public_path('js/telegram-chat/mention.js')) }}"></script>
    <script src="{{ asset('js/telegram-chat/input.js') }}?v={{ filemtime(public_path('js/telegram-chat/input.js')) }}"></script>
    <script src="{{ asset('js/telegram-chat/quick-reply.js') }}?v={{ filemtime(public_path('js/telegram-chat/quick-reply.js')) }}"></script>
    {{-- 必須排在 main.js 之前：renderHeader 實際執行時要看得到 T.openIgnorePanel --}}
    <script src="{{ asset('js/telegram-chat/ignore-member.js') }}?v={{ filemtime(public_path('js/telegram-chat/ignore-member.js')) }}"></script>
    {{-- 改掉／撤回已送出的訊息（2026-11-25）。⚠ 要排在 messages.js 之後 --}}
    <script src="{{ asset('js/telegram-chat/edit-recall.js') }}?v={{ filemtime(public_path('js/telegram-chat/edit-recall.js')) }}"></script>
    <script src="{{ asset('js/telegram-chat/main.js') }}?v={{ filemtime(public_path('js/telegram-chat/main.js')) }}"></script>
@endsection
