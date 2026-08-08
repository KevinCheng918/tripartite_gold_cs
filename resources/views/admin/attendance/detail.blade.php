@extends('layouts.app')

@section('title', trans('attendance.detail_title'))
@section('subtitle', trans('attendance.detail_subtitle'))

@section('content')

    <div id="attendance-detail-app"
         data-i18n='@json(trans("attendance"))'
         data-target-user-id="{{ $targetUserId }}"
         data-is-admin="{{ Auth::user()->isAdmin() ? '1' : '0' }}">
        <p>Loading…</p>
    </div>

@endsection

@section('scripts')
    <script src="{{ asset('js/attendance-detail.js') }}?v={{ filemtime(public_path('js/attendance-detail.js')) }}"></script>
@endsection
