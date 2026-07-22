@extends('layouts.app')

@section('title', __('account.page_title'))

@section('content')
    <h1>{{ __('account.page_title') }}</h1>

    <div id="account-app" data-i18n='@json(__('account'))'>
        <p>Loading…</p>
    </div>
@endsection

@section('scripts')
    <script src="{{ mix('js/admin-accounts.js') }}"></script>
@endsection
