@extends('layouts.app')

@section('title', trans('account.action_assign_permissions'))
@section('icon', 'key')
@section('subtitle', $targetUser->nickname . '（' . $targetUser->account . '）')

@section('content')

    <div id="permissions-app"
         data-user-id="{{ $targetUser->id }}"
         data-i18n='@json(trans("account"))'
         data-permission-i18n='@json(trans("permission"))'>
        <p>Loading…</p>
    </div>

    {{-- 訊息提示 Modal --}}
    @component('components.modal', ['id' => 'modal-perm-message', 'title' => ''])
        <p id="modal-perm-message-text"></p>
        <div class="modal-actions">
            <button type="button" data-bs-dismiss="modal" class="btn-primary">OK</button>
        </div>
    @endcomponent

@endsection

@section('scripts')
    <script src="{{ asset('js/permissions.js') }}?v={{ filemtime(public_path('js/permissions.js')) }}"></script>
@endsection
