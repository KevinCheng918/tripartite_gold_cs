@extends('layouts.app')

@section('title', trans('shared_file.page_title'))
@section('icon', 'file-alt')
@section('subtitle', trans('shared_file.subtitle'))

@section('content')

    {{-- layout 的 @yield('css') 位在 <style> 標籤內，放 <link> 會失效，
         因此與 task-board 一樣把樣式表載在 content 開頭 --}}
    <link rel="stylesheet" href="{{ asset('css/shared-file.css') }}?v={{ filemtime(public_path('css/shared-file.css')) }}">

    @php $canViewShared = Auth::user()->isAdmin() || Auth::user()->hasPermission('shared_file.view'); @endphp

    {{-- 權限與語系由 data-* 帶給 public/js/shared-file.js --}}
    <div id="shared-file-app"
         data-i18n='@json(trans("shared_file"))'
         data-can-upload="{{ Auth::user()->hasPermission('shared_file.upload') ? '1' : '0' }}"
         data-can-delete="{{ Auth::user()->hasPermission('shared_file.delete') ? '1' : '0' }}"
         data-is-admin="{{ Auth::user()->isAdmin() ? '1' : '0' }}"
         data-user-id="{{ Auth::id() }}">

        {{-- Tabs --}}
        <ul class="nav nav-tabs mb-3">
            @if($canViewShared)
            <li class="nav-item">
                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-shared">
                    <i class="fas fa-globe me-1"></i>{{ trans('shared_file.tab_shared') }}
                </button>
            </li>
            @endif
            <li class="nav-item">
                <button class="nav-link{{ !$canViewShared ? ' active' : '' }}" data-bs-toggle="tab" data-bs-target="#tab-personal">
                    <i class="fas fa-user me-1"></i>{{ trans('shared_file.tab_personal') }}
                </button>
            </li>
        </ul>

        <div class="tab-content">
            {{-- 共用文件 Tab --}}
            @if($canViewShared)
            <div class="tab-pane fade show active" id="tab-shared">
                <div class="row g-3">
                    <div class="col-md-3">
                        <div class="main-card card">
                            <div class="card-header d-flex justify-content-between align-items-center py-2">
                                <span class="fw-bold" style="font-size:0.875rem">{{ trans('shared_file.field_folder') }}</span>
                                @if(Auth::user()->hasPermission('shared_file.upload'))
                                <button class="btn btn-sm btn-primary js-add-folder" data-type="shared"
                                        title="{{ trans('shared_file.action_add_folder') }}"><i class="fas fa-plus"></i></button>
                                @endif
                            </div>
                            <div class="card-body p-0 sf-folder-list" id="shared-folder-list">
                                <div class="text-center text-muted py-3">{{ trans('shared_file.loading') }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-9">
                        <div class="main-card card">
                            <div class="card-header d-flex justify-content-between align-items-center py-2">
                                <span class="fw-bold" style="font-size:0.875rem" id="shared-folder-title">{{ trans('shared_file.select_folder') }}</span>
                                <div class="d-flex gap-1" id="shared-file-actions" style="display:none !important"></div>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover align-middle mb-0 sf-file-table">
                                        <thead class="thead-gold">
                                            <tr>
                                                <th>{{ trans('shared_file.field_filename') }}</th>
                                                <th>{{ trans('shared_file.field_size') }}</th>
                                                <th>{{ trans('shared_file.field_uploader') }}</th>
                                                <th>{{ trans('shared_file.field_time') }}</th>
                                                <th>{{ trans('shared_file.field_action') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody id="shared-file-body">
                                            <tr><td colspan="5" class="text-center text-muted py-3">{{ trans('shared_file.select_folder') }}</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            @endif

            {{-- 個人文件 Tab --}}
            <div class="tab-pane fade{{ !$canViewShared ? ' show active' : '' }}" id="tab-personal">
                @if(Auth::user()->isAdmin())
                <div class="mb-3">
                    <label class="form-label fw-bold" style="font-size:0.875rem">{{ trans('shared_file.view_user') }}</label>
                    <select id="personal-user-select" class="form-select form-select-sm" style="width:200px">
                        <option value="">{{ trans('shared_file.self') }}</option>
                        @foreach($allUsers as $u)
                            <option value="{{ $u->id }}">{{ $u->nickname }}（{{ $u->account }}）</option>
                        @endforeach
                    </select>
                </div>
                @endif
                <div class="row g-3">
                    <div class="col-md-3">
                        <div class="main-card card">
                            <div class="card-header d-flex justify-content-between align-items-center py-2">
                                <span class="fw-bold" style="font-size:0.875rem">{{ trans('shared_file.field_folder') }}</span>
                                <button class="btn btn-sm btn-primary js-add-folder" data-type="personal"
                                        title="{{ trans('shared_file.action_add_folder') }}"><i class="fas fa-plus"></i></button>
                            </div>
                            <div class="card-body p-0 sf-folder-list" id="personal-folder-list">
                                <div class="text-center text-muted py-3">{{ trans('shared_file.loading') }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-9">
                        <div class="main-card card">
                            <div class="card-header d-flex justify-content-between align-items-center py-2">
                                <span class="fw-bold" style="font-size:0.875rem" id="personal-folder-title">{{ trans('shared_file.select_folder') }}</span>
                                <div class="d-flex gap-1" id="personal-file-actions" style="display:none !important"></div>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover align-middle mb-0 sf-file-table">
                                        <thead class="thead-gold">
                                            <tr>
                                                <th>{{ trans('shared_file.field_filename') }}</th>
                                                <th>{{ trans('shared_file.field_size') }}</th>
                                                <th>{{ trans('shared_file.field_uploader') }}</th>
                                                <th>{{ trans('shared_file.field_time') }}</th>
                                                <th>{{ trans('shared_file.field_action') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody id="personal-file-body">
                                            <tr><td colspan="5" class="text-center text-muted py-3">{{ trans('shared_file.select_folder') }}</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- 新增資料夾 Modal --}}
    <div class="modal fade" id="modal-add-folder" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ trans('shared_file.action_add_folder') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="form-add-folder">
                        <input type="hidden" id="folder-type">
                        <input type="hidden" id="folder-parent-id">
                        <div class="text-muted mb-2" id="folder-parent-hint" style="display:none;font-size:0.8125rem"></div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('shared_file.field_name') }} <span class="text-danger">*</span></label>
                            <input type="text" id="folder-name" class="form-control" required maxlength="100">
                        </div>
                        <div class="text-end">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ trans('shared_file.action_cancel') }}</button>
                            <button type="submit" class="btn btn-primary">{{ trans('shared_file.action_confirm') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- 搬移檔案 Modal --}}
    <div class="modal fade" id="modal-sf-move" tabindex="-1">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h5 class="modal-title" style="font-size:0.9375rem">{{ trans('shared_file.action_move') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-1 text-truncate fw-bold" id="sf-move-filename" style="font-size:0.875rem"></div>
                    <div class="text-muted mb-3 text-truncate" id="sf-move-origin" style="font-size:0.8125rem"></div>
                    <input type="hidden" id="sf-move-file-id">
                    <div id="sf-move-folders" class="border rounded"></div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-primary w-100 js-move-target" id="sf-move-confirm" disabled></button>
                </div>
            </div>
        </div>
    </div>

    {{-- 檔案預覽 Modal
         不用 modal-dialog-centered：它的 min-height 會把 dialog 撐成全高，
         看起來像上下多一個框（同 quick-reply 的訊息 Modal） --}}
    <div class="modal fade" id="modal-sf-preview" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h5 class="modal-title text-truncate" id="sf-preview-title" style="font-size:0.9375rem"></h5>
                    {{-- 不放下載鈕：檔案列表那列本來就有一顆，重複提供沒有意義 --}}
                    <button type="button" class="btn btn-sm btn-outline-secondary flex-shrink-0" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>{{ trans('shared_file.action_close') }}
                    </button>
                </div>
                <div class="modal-body text-center p-0" id="sf-preview-body"></div>
            </div>
        </div>
    </div>

    {{-- 訊息 Modal --}}
    <div class="modal fade" id="modal-sf-msg" tabindex="-1">
        <div class="modal-dialog modal-sm"><div class="modal-content"><div class="modal-body text-center py-4">
            <p id="modal-sf-msg-text" class="mb-3"></p>
            <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
        </div></div></div>
    </div>

    {{-- 刪除確認 Modal --}}
    <div class="modal fade" id="modal-sf-delete" tabindex="-1">
        <div class="modal-dialog modal-sm"><div class="modal-content"><div class="modal-body text-center py-4">
            <p class="mb-3" id="modal-sf-delete-text">{{ trans('shared_file.confirm_delete') }}</p>
            <input type="hidden" id="sf-delete-url">
            <div class="d-flex justify-content-center gap-2">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ trans('shared_file.action_cancel') }}</button>
                <button type="button" class="btn btn-danger" id="btn-sf-delete-ok">{{ trans('shared_file.action_confirm_delete') }}</button>
            </div>
        </div></div></div>
    </div>

@endsection

@section('scripts')
    <script src="{{ asset('js/shared-file.js') }}?v={{ filemtime(public_path('js/shared-file.js')) }}"></script>
@endsection
