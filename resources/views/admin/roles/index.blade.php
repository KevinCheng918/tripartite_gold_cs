@extends('layouts.app')

@section('title', __('role.page_title'))

@section('content')
    <h1>{{ __('role.page_title') }}</h1>

    <div id="role-app" data-i18n='@json(__('role'))'>
        <p>Loading…</p>
    </div>
@endsection

@section('scripts')
    <script src="{{ mix('js/admin-roles.js') }}"></script>
@endsection
