@extends('layouts.app')

@section('title', trans('reply_template.page_title'))
@section('icon', 'comment-dots')
@section('subtitle', trans('reply_template.subtitle'))

@section('content')

    {{-- 初始資料隨頁面送出，避免載入時先空一拍 --}}
    {{-- JSON_HEX_APOS：話術與語系裡的單引號會提早結束 data 屬性，整份 JSON 就解析不了 --}}
    <div id="reply-template-app"
         data-i18n='@json(trans("reply_template"), JSON_HEX_APOS | JSON_HEX_QUOT)'
         data-initial='@json($initialTemplates, JSON_HEX_APOS | JSON_HEX_QUOT)'
         data-can-manage="{{ Auth::user()->hasPermission('telegram_chat.template_manage') ? '1' : '0' }}"
         data-signature="{{ config('constants.AUTO_REPLY.SIGNATURE') }}"
         data-greeting-gap="{{ config('constants.AUTO_REPLY.GREETING_GAP_MINUTES') }}">

        <div class="main-card mb-3 card">
            <div class="card-body">
                <p class="mb-1">{{ trans('reply_template.intro') }}</p>
                <p class="text-muted mb-0" style="font-size:0.875rem" id="signature-hint"></p>
            </div>
        </div>

        <form id="form-templates">
            <div id="template-fields"></div>
            <div class="mb-4">
                <button type="submit" class="btn btn-primary js-manage-only">{{ trans('reply_template.action_save') }}</button>
            </div>
        </form>
    </div>

    {{-- 訊息 Modal（不用 alert） --}}
    <div class="modal fade" id="modal-template-msg" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-body text-center py-4"></div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
                </div>
            </div>
        </div>
    </div>

@endsection

@section('scripts')
    <script src="{{ asset('js/reply-template-admin.js') }}?v={{ filemtime(public_path('js/reply-template-admin.js')) }}"></script>
@endsection
