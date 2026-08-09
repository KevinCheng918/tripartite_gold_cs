@extends('layouts.app')

@section('title', trans('broadcast.page_title'))
@section('icon', 'bullhorn')
@section('subtitle', trans('broadcast.subtitle'))

@section('content')

    <div id="broadcast-app"
         data-i18n='@json(trans("broadcast"))'>
        <p>Loading…</p>
    </div>

    {{-- 訊息提示 Modal --}}
    @component('components.modal', ['id' => 'modal-broadcast-msg', 'title' => ''])
        <p id="modal-broadcast-msg-text"></p>
        <div class="text-end mt-3">
            <button type="button" data-bs-dismiss="modal" class="btn-primary">OK</button>
        </div>
    @endcomponent

@endsection

@section('scripts')
    <script src="{{ asset('js/broadcast.js') }}?v={{ filemtime(public_path('js/broadcast.js')) }}"></script>
@endsection
