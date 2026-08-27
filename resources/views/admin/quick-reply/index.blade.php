@extends('layouts.app')

@section('title', trans('quick_reply.page_title'))
@section('icon', 'bolt')
@section('subtitle', trans('quick_reply.subtitle'))

@section('content')

    <div id="quick-reply-app"
         data-i18n='@json(trans("quick_reply"))'
         data-can-edit="{{ Auth::user()->hasPermission('quick_reply.edit') ? '1' : '0' }}">

        <div class="alert alert-light border d-flex align-items-center mb-3" style="font-size:0.875rem">
            <i class="fas fa-info-circle me-2 text-muted"></i>{{ trans('quick_reply.disabled_hint') }}
        </div>

        <div class="row g-3">
            {{-- 左：類別 --}}
            <div class="col-md-4">
                <div class="main-card card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <strong>{{ trans('quick_reply.field_category') }}</strong>
                        @if(Auth::user()->hasPermission('quick_reply.edit'))
                            <button class="btn btn-sm btn-primary" id="btn-add-category">
                                <i class="fas fa-plus me-1"></i>{{ trans('quick_reply.action_add_category') }}
                            </button>
                        @endif
                    </div>
                    <div class="card-body p-0">
                        <div id="qr-category-list">
                            <div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin"></i></div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- 右：問答 --}}
            <div class="col-md-8">
                <div class="main-card card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <strong id="qr-item-title">{{ trans('quick_reply.select_category') }}</strong>
                        @if(Auth::user()->hasPermission('quick_reply.edit'))
                            <button class="btn btn-sm btn-primary" id="btn-add-item" style="display:none">
                                <i class="fas fa-plus me-1"></i>{{ trans('quick_reply.action_add_item') }}
                            </button>
                        @endif
                    </div>
                    <div class="card-body p-0">
                        <div id="qr-item-list">
                            <div class="text-center text-muted py-4">{{ trans('quick_reply.select_category') }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- 類別 Modal --}}
    <div class="modal fade" id="modal-qr-category" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-qr-category-title">{{ trans('quick_reply.action_add_category') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="form-qr-category">
                    <div class="modal-body">
                        <input type="hidden" id="qr-category-id">
                        <div class="mb-3">
                            <label class="form-label" for="qr-category-label">{{ trans('quick_reply.field_category') }}</label>
                            <input type="text" class="form-control" id="qr-category-label" maxlength="100" required>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="qr-category-status" checked>
                            <label class="form-check-label" for="qr-category-status">{{ trans('quick_reply.status_active') }}</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ trans('quick_reply.action_cancel') }}</button>
                        <button type="submit" class="btn btn-primary">{{ trans('quick_reply.action_save') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- 問答 Modal --}}
    <div class="modal fade" id="modal-qr-item" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-qr-item-title">{{ trans('quick_reply.action_add_item') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="form-qr-item">
                    <div class="modal-body">
                        <input type="hidden" id="qr-item-id">
                        <div class="mb-3">
                            <label class="form-label" for="qr-item-category">{{ trans('quick_reply.field_category') }}</label>
                            <select class="form-select" id="qr-item-category" required></select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="qr-item-label">{{ trans('quick_reply.field_label') }}</label>
                            <input type="text" class="form-control" id="qr-item-label" maxlength="200" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="qr-item-answer">{{ trans('quick_reply.field_answer') }}</label>
                            <textarea class="form-control" id="qr-item-answer" rows="8" maxlength="4000" required style="font-size:0.875rem"></textarea>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="qr-item-status" checked>
                            <label class="form-check-label" for="qr-item-status">{{ trans('quick_reply.status_active') }}</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ trans('quick_reply.action_cancel') }}</button>
                        <button type="submit" class="btn btn-primary">{{ trans('quick_reply.action_save') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- 訊息 Modal --}}
    <div class="modal fade" id="modal-qr-msg" tabindex="-1">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body text-center py-4" id="modal-qr-msg-text"></div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
                </div>
            </div>
        </div>
    </div>

    {{-- 刪除確認 Modal --}}
    <div class="modal fade" id="modal-qr-confirm" tabindex="-1">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body text-center py-4" id="modal-qr-confirm-text"></div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ trans('quick_reply.action_cancel') }}</button>
                    <button type="button" class="btn btn-danger" id="btn-qr-confirm-ok">{{ trans('quick_reply.action_delete') }}</button>
                </div>
            </div>
        </div>
    </div>

@endsection

@section('scripts')
    <script src="{{ asset('js/quick-reply-admin.js') }}?v={{ filemtime(public_path('js/quick-reply-admin.js')) }}"></script>
@endsection
