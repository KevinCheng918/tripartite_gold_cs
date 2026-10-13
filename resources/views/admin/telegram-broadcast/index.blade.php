@extends('layouts.app')

@section('title', trans('broadcast.page_title'))
@section('icon', 'bullhorn')
@section('subtitle', trans('broadcast.subtitle'))

@section('content')

    {{-- Tab 切換 --}}
    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-broadcast-send">
                <i class="fas fa-paper-plane me-1"></i>{{ trans('broadcast.tab_send') }}
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-broadcast-template">
                <i class="fas fa-file-alt me-1"></i>{{ trans('broadcast.tab_template') }}
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-broadcast-history">
                <i class="fas fa-history me-1"></i>{{ trans('broadcast.tab_history') }}
            </button>
        </li>
    </ul>

    <div class="tab-content">
        {{-- 發送公告 Tab --}}
        <div class="tab-pane fade show active" id="tab-broadcast-send">
            <form id="form-broadcast">
            <div class="row">
                {{-- 左側：公告內容 --}}
                <div class="col-md-7 mb-3 d-flex">
                    <div class="main-card card w-100">
                        <div class="card-header fw-bold">
                            <i class="fas fa-paper-plane me-2 text-muted"></i>{{ trans('broadcast.tab_send') }}
                        </div>
                        <div class="card-body">
                            {{-- 公告來源：自己打，或挑一則建檔的帶進來（帶進來之後照常可以改） --}}
                            <div class="mb-3">
                                <label class="form-label">{{ trans('broadcast.source_label') }}</label>
                                <div class="d-flex flex-wrap gap-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="bc_source" value="manual" id="bc-source-manual" checked>
                                        <label class="form-check-label" for="bc-source-manual">{{ trans('broadcast.source_manual') }}</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="bc_source" value="template" id="bc-source-template">
                                        <label class="form-check-label" for="bc-source-template">{{ trans('broadcast.source_template') }}</label>
                                    </div>
                                </div>
                                <div id="bc-template-wrap" class="mt-2 d-none">
                                    <select id="bc-template-id" class="form-select"></select>
                                    <small class="form-text text-muted">{{ trans('broadcast.source_hint') }}</small>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">{{ trans('broadcast.field_content') }} <span class="text-danger">*</span></label>
                                <textarea id="bc-content" class="form-control" rows="10" required maxlength="4096" placeholder="{{ trans('broadcast.field_content') }}..."></textarea>
                                <div class="text-end mt-1" style="font-size:0.8125rem"><span id="bc-content-count">0</span> / 4096</div>
                            </div>

                            {{-- 範本帶來的附件：可以逐個移除，移除只影響這一次發送，不動範本 --}}
                            <div class="mb-3 d-none" id="bc-template-attachments">
                                <label class="form-label">{{ trans('broadcast.template_attachments') }}</label>
                                <div id="bc-template-attachment-list" class="d-flex flex-wrap gap-2"></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">{{ trans('broadcast.field_attachment') }}</label>
                                {{-- 不設 accept：圖片與一般檔案都從這裡選，送出前再依 MIME 拆成兩個欄位 --}}
                                <input id="bc-images" type="file" class="form-control" multiple>
                                <div id="bc-image-preview" class="mt-2 d-flex flex-wrap gap-2" style="display:none"></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">{{ trans('broadcast.field_target') }} <span class="text-danger">*</span></label>
                                <div class="d-flex gap-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="target_type" value="1" id="target-all" checked>
                                        <label class="form-check-label" for="target-all">{{ trans('broadcast.target_all') }}</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="target_type" value="2" id="target-selected">
                                        <label class="form-check-label" for="target-selected">{{ trans('broadcast.target_selected') }}</label>
                                    </div>
                                </div>
                            </div>
                            {{-- 預約傳送：勾了才出現時間欄位，平常不佔版面 --}}
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="bc-schedule-enable">
                                    <label class="form-check-label" for="bc-schedule-enable">
                                        <i class="far fa-clock me-1 text-muted"></i>{{ trans('broadcast.schedule_enable') }}
                                    </label>
                                </div>
                                <div id="bc-schedule-wrap" class="mt-2 d-none">
                                    <label class="form-label">{{ trans('broadcast.field_schedule') }} <span class="text-danger">*</span></label>
                                    <input type="datetime-local" id="bc-scheduled-at" class="form-control" step="60">
                                    <div class="form-text">{{ trans('broadcast.schedule_hint') }}</div>
                                </div>
                            </div>
                            <div class="text-end">
                                <button type="submit" class="btn btn-primary" id="btn-send">
                                    <i class="fas fa-paper-plane me-1"></i>{{ trans('broadcast.btn_send') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- 右側：發送範圍 --}}
                <div class="col-md-5 mb-3 d-flex">
                    <div class="main-card card w-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span class="fw-bold"><i class="fas fa-users me-2 text-muted"></i>{{ trans('broadcast.field_scope') }}</span>
                            <div class="d-flex gap-2">
                                <select id="bc-system-filter" class="form-select form-select-sm" style="width:auto">
                                    <option value="">{{ trans('broadcast.all_systems') }}</option>
                                    @foreach($systems as $sys)
                                        <option value="{{ $sys->id }}">{{ $sys->name }}</option>
                                    @endforeach
                                </select>
                                <input type="text" id="bc-search" class="form-control form-control-sm" placeholder="{{ trans('broadcast.search_placeholder') }}" style="width:120px">
                            </div>
                        </div>
                        <div class="card-body" id="group-list-wrap" style="overflow-y:auto;flex:1">
                            {{-- 全部群組時顯示提示 --}}
                            <div id="bc-all-hint" class="text-center text-muted py-4">
                                <i class="fas fa-users fa-2x mb-2 d-block"></i>
                                {{ trans('broadcast.all_hint') }}
                            </div>
                            {{-- 指定群組時顯示勾選列表 --}}
                            <div id="bc-group-checkboxes" style="display:none">
                                <div class="mb-2">
                                    <label class="form-check d-inline-block me-3">
                                        <input type="checkbox" class="form-check-input" id="bc-select-all">
                                        <span class="form-check-label fw-bold">{{ trans('broadcast.select_all') }}</span>
                                    </label>
                                    <small class="text-muted" id="bc-selected-count">{{ trans('broadcast.selected_count', ['count' => 0]) }}</small>
                                </div>
                                <div id="bc-group-list">
                                    @foreach($groups as $g)
                                        <div class="form-check bc-group-item" data-system-id="{{ $g['system_id'] ?? '' }}" data-name="{{ strtolower($g['title']) }}">
                                            <input class="form-check-input bc-group-cb" type="checkbox" name="group_ids[]" value="{{ $g['id'] }}" id="grp-{{ $g['id'] }}">
                                            <label class="form-check-label" for="grp-{{ $g['id'] }}">
                                                {{ $g['title'] }}
                                                @if(!empty($g['system']))
                                                    <small class="text-muted">({{ $g['system'] }})</small>
                                                @endif
                                            </label>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            </form>
        </div>

        {{-- 公告管理 Tab --}}
        <div class="tab-pane fade" id="tab-broadcast-template">
            <div class="main-card mb-3 card">
                <div class="card-header d-flex align-items-center flex-wrap gap-2">
                    <span class="fw-bold"><i class="fas fa-file-alt me-2 text-muted"></i>{{ trans('broadcast.tab_template') }}</span>
                    {{-- 搜尋不包在 @can 裡：沒有建檔權限的人也看得到清單（清單綁 broadcast） --}}
                    <div class="input-group input-group-sm ms-auto" style="width:16rem">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" class="form-control" id="template-search"
                               placeholder="{{ trans('broadcast.template_search') }}" autocomplete="off">
                        <button type="button" class="btn btn-outline-secondary d-none" id="btn-template-search-clear"
                                title="{{ trans('broadcast.template_search_clear') }}">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    @can('telegram_chat.broadcast_template')
                        <button type="button" class="btn btn-primary btn-sm" id="btn-template-add">
                            <i class="fas fa-plus me-1"></i>{{ trans('broadcast.template_add') }}
                        </button>
                    @endcan
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle data-table">
                            <thead class="thead-gold">
                                <tr>
                                    <th class="col-tight">{{ trans('broadcast.template_code') }}</th>
                                    <th>{{ trans('broadcast.template_title') }}</th>
                                    <th>{{ trans('broadcast.field_content') }}</th>
                                    <th class="col-num">{{ trans('broadcast.template_attachments') }}</th>
                                    <th>{{ trans('broadcast.template_creator') }}</th>
                                    <th>{{ trans('broadcast.template_updated_at') }}</th>
                                    @can('telegram_chat.broadcast_template')
                                        <th>{{ trans('broadcast.field_action') }}</th>
                                    @endcan
                                </tr>
                            </thead>
                            <tbody id="template-list"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- 歷史紀錄 Tab --}}
        <div class="tab-pane fade" id="tab-broadcast-history">
            {{-- 桌面版：表格 --}}
            <div class="main-card mb-3 card d-none d-md-block">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        {{-- 2026-10-10：總數／成功／失敗三欄併成「送達」一欄，
                             thead 從 table-light 換成全站統一的 thead-gold --}}
                        <table class="table table-hover align-middle data-table">
                            <thead class="thead-gold">
                                <tr>
                                    <th>{{ trans('broadcast.field_status') }}</th>
                                    <th>{{ trans('broadcast.field_time') }}</th>
                                    <th>{{ trans('broadcast.field_sender') }}</th>
                                    <th>{{ trans('broadcast.field_target') }}</th>
                                    <th class="col-tight">{{ trans('broadcast.template_code') }}</th>
                                    <th>{{ trans('broadcast.field_content') }}</th>
                                    <th>{{ trans('broadcast.field_result') }}</th>
                                    <th class="col-actions">{{ trans('broadcast.field_action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($history as $record)
                                    <tr>
                                        <td>@include('admin.telegram-broadcast.partials.status-badge', ['record' => $record])</td>
                                        {{-- 預約中的還沒送出，顯示預約時間才知道何時會發 --}}
                                        <td>
                                            @if($record->status === \App\Models\TelegramBroadcast::STATUS_PENDING && $record->scheduled_at)
                                                <i class="far fa-clock me-1 text-muted"></i>{{ $record->scheduled_at->format('m/d H:i') }}
                                            @else
                                                {{ $record->sent_at ? $record->sent_at->format('m/d H:i') : '-' }}
                                            @endif
                                        </td>
                                        <td>{{ $record->sender ? $record->sender->nickname : '-' }}</td>
                                        <td>@include('admin.telegram-broadcast.partials.target-badge', ['record' => $record, 'groups' => $groups])</td>
                                        {{-- 自己打的公告沒有編號 --}}
                                        <td class="col-tight">{{ filled($record->template_code) ? $record->template_code : '-' }}</td>
                                        <td>{{ Str::limit($record->content, 50) }}</td>
                                        {{-- 成功／失敗／總數合成一格，跟手機版卡片同一種寫法 --}}
                                        <td class="text-nowrap">
                                            <span class="badge bg-success">{{ $record->success_count }}</span>
                                            @if($record->fail_count > 0)
                                                <span class="badge bg-danger">{{ $record->fail_count }}</span>
                                            @endif
                                            <span class="text-muted">/ {{ $record->total_count }}</span>
                                        </td>
                                        {{-- 取消預約自己一段、圖示用 text-danger ——
                                             取消之後那則公告就不會發了 --}}
                                        <td class="col-actions">
                                            <div class="row-actions">
                                                <div class="btn-group btn-group-sm" role="group" aria-label="{{ trans('common.row_actions.view') }}">
                                                    <button class="btn btn-outline-secondary btn-icon btn-transition js-copy-content"
                                                            data-content="{{ $record->content }}">
                                                        <i class="fas fa-copy btn-icon-wrapper"></i>{{ trans('broadcast.btn_copy') }}
                                                    </button>
                                                </div>
                                                @if($record->status === \App\Models\TelegramBroadcast::STATUS_PENDING)
                                                    <div class="btn-group btn-group-sm" role="group" aria-label="{{ trans('common.row_actions.danger') }}">
                                                        <button class="btn btn-outline-secondary btn-icon btn-transition js-cancel-schedule" data-id="{{ $record->id }}">
                                                            <i class="fas fa-ban btn-icon-wrapper text-danger"></i>{{ trans('broadcast.btn_cancel_schedule') }}
                                                        </button>
                                                    </div>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        {{-- 8 欄：狀態／時間／發送者／對象／編號／內容／送達／操作 --}}
                                        <td colspan="8" class="table-empty">
                                            <i class="fas fa-bullhorn"></i>{{ trans('broadcast.no_history') }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                @if($history->hasPages())
                    <div class="card-footer">{{ $history->links() }}</div>
                @endif
            </div>

            {{-- 手機版：卡片 --}}
            <div class="d-md-none">
                @forelse($history as $record)
                    <div class="card mb-2 shadow-sm">
                        <div class="card-body py-3">
                            <div class="data-card__head mb-2">
                                <div>
                                    <span class="cell-stack__main">{{ $record->sender ? $record->sender->nickname : '-' }}</span>
                                    @include('admin.telegram-broadcast.partials.status-badge', ['record' => $record])
                                    <div class="cell-stack__sub">
                                        @if($record->status === \App\Models\TelegramBroadcast::STATUS_PENDING && $record->scheduled_at)
                                            <i class="far fa-clock me-1"></i>{{ $record->scheduled_at->format('m/d H:i') }}
                                        @else
                                            {{ $record->sent_at ? $record->sent_at->format('m/d H:i') : '-' }}
                                        @endif
                                        {{-- 用了哪則建檔公告。自己打的沒有編號就整段不出現 --}}
                                        @if(filled($record->template_code))
                                            <span class="ms-1">· {{ $record->template_code }}</span>
                                        @endif
                                    </div>
                                </div>
                                @include('admin.telegram-broadcast.partials.target-badge', ['record' => $record, 'groups' => $groups])
                            </div>
                            <div class="mb-2 data-card__text">{{ $record->content }}</div>
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="cell-stack__sub">
                                    <span class="badge bg-success">{{ $record->success_count }}</span>
                                    @if($record->fail_count > 0)
                                        <span class="badge bg-danger">{{ $record->fail_count }}</span>
                                    @endif
                                    <span class="text-muted">/ {{ $record->total_count }}</span>
                                </div>
                                <div class="row-actions">
                                    <button class="btn btn-sm btn-outline-secondary btn-icon btn-transition js-copy-content" data-content="{{ $record->content }}">
                                        <i class="fas fa-copy btn-icon-wrapper"></i>{{ trans('broadcast.btn_copy') }}
                                    </button>
                                    @if($record->status === \App\Models\TelegramBroadcast::STATUS_PENDING)
                                        <button class="btn btn-sm btn-outline-secondary btn-icon btn-transition js-cancel-schedule" data-id="{{ $record->id }}">
                                            <i class="fas fa-ban btn-icon-wrapper text-danger"></i>{{ trans('broadcast.btn_cancel_schedule') }}
                                        </button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="text-center text-muted py-4">{{ trans('broadcast.no_history') }}</div>
                @endforelse
                @if($history->hasPages())
                    <div class="mt-2">{{ $history->links() }}</div>
                @endif
            </div>
        </div>
    </div>

    {{-- 訊息 Modal --}}
    <div class="modal fade" id="modal-broadcast-msg" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-body text-center py-4">
                    <p id="modal-broadcast-msg-text" class="mb-3"></p>
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
                </div>
            </div>
        </div>
    </div>

    {{-- 取消預約確認 Modal（專案規範不使用原生 confirm） --}}
    <div class="modal fade" id="modal-broadcast-confirm" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-body text-center py-4">
                    <p id="modal-broadcast-confirm-text" class="mb-3"></p>
                    <div class="d-flex gap-2 justify-content-center">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ trans('broadcast.btn_cancel') }}</button>
                        <button type="button" class="btn btn-primary" id="btn-broadcast-confirm-ok">{{ trans('broadcast.btn_confirm') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- 發送明細 Modal --}}
    <div class="modal fade" id="modal-send-detail" tabindex="-1">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-list-alt me-2"></i>{{ trans('broadcast.send_detail') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="send-detail-empty" class="text-center text-muted py-4" style="display:none">
                        <i class="fas fa-inbox fa-2x mb-2 d-block"></i>{{ trans('broadcast.no_send_detail') }}
                    </div>
                    <div id="send-detail-table-wrap" style="display:none">
                        <div class="d-flex gap-3 mb-3" id="send-detail-summary"></div>
                        <div id="send-detail-note" class="alert alert-warning py-2 d-none" style="font-size:0.8125rem">
                            <i class="fas fa-info-circle me-1"></i>{{ trans('broadcast.pending_all_note') }}
                        </div>
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>{{ trans('broadcast.field_station') }}</th>
                                    <th style="width:80px;text-align:center">{{ trans('broadcast.field_status') }}</th>
                                </tr>
                            </thead>
                            <tbody id="send-detail-body"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{--
        圖片放大。

        ⚠ 疊在「編輯公告」那個 modal 上面，所以 z-index 要比它高 ——
        Bootstrap 給所有 modal 同一個 z-index，後開的會被先開的蓋住一半
        （backdrop 疊在中間）。custom.css 已經處理過 backdrop 重複的問題。
    --}}
    <div class="modal fade" id="modal-template-image" tabindex="-1" style="z-index:1075">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-truncate" id="tpl-image-title" style="font-size:0.9375rem"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <img id="tpl-image-full" class="img-fluid" alt="">
                </div>
            </div>
        </div>
    </div>

    {{-- 公告建檔／編輯 --}}
    <div class="modal fade" id="modal-template" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-template-title">{{ trans('broadcast.template_add') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="form-template">
                        <input type="hidden" id="tpl-id">

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label" for="tpl-code">{{ trans('broadcast.template_code') }} <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="tpl-code" maxlength="32"
                                       placeholder="{{ trans('broadcast.template_code_ph') }}" required>
                                <small class="form-text text-muted">{{ trans('broadcast.template_code_hint') }}</small>
                            </div>
                            <div class="col-md-8 mb-3">
                                <label class="form-label" for="tpl-title">{{ trans('broadcast.template_title') }} <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="tpl-title" maxlength="100"
                                       placeholder="{{ trans('broadcast.template_title_ph') }}" required>
                                {{-- 一定要講：不然會有人把「【重要】」寫進標題以為客人看得到 --}}
                                <small class="form-text text-muted">{{ trans('broadcast.template_title_hint') }}</small>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="tpl-content">{{ trans('broadcast.field_content') }} <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="tpl-content" rows="8" maxlength="4096" required></textarea>
                            {{-- ⚠ 不要用 `text-end`：custom.css 把 modal 裡的 `.text-end` 改成
                                 `flex + space-between`（給底部按鈕排版用），字數會被拆成一左一右 --}}
                            <div class="mt-1" style="font-size:0.8125rem;text-align:right"><span id="tpl-content-count">0</span> / 4096</div>
                        </div>

                        {{-- 編輯時原有的附件：按叉掉就是不留，送出時以畫面上剩下的為準 --}}
                        <div class="mb-3 d-none" id="tpl-existing-wrap">
                            <label class="form-label">{{ trans('broadcast.template_attachments') }}</label>
                            <div id="tpl-existing-list" class="d-flex flex-wrap gap-2"></div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="tpl-files">{{ trans('broadcast.field_attachment') }}</label>

                            {{--
                                拖曳區。整塊都可以放，也可以點它開檔案選擇視窗。

                                ⚠ `<input>` 藏在裡面而不是拿掉：鍵盤使用者要 tab 得到，
                                而且表單的檔案選擇行為全靠它。
                            --}}
                            <div id="tpl-dropzone" class="border border-2 border-dashed rounded text-center py-4 px-3"
                                 style="cursor:pointer;transition:background-color .15s,border-color .15s">
                                <i class="fas fa-cloud-upload-alt fa-2x text-muted mb-2 d-block"></i>
                                <div class="text-muted" style="font-size:0.875rem">{{ trans('broadcast.dropzone_hint') }}</div>
                                {{-- 不設 accept：圖片與一般檔案都從這裡選，送出前再依 MIME 拆成兩個欄位 --}}
                                <input type="file" id="tpl-files" multiple class="d-none">
                            </div>

                            {{-- 這次新選的檔案（還沒上傳），圖片給縮圖 --}}
                            <div id="tpl-new-list" class="d-flex flex-wrap gap-2 mt-2"></div>
                        </div>

                        <div class="text-end">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ trans('broadcast.action_cancel') }}</button>
                            <button type="submit" class="btn btn-primary" id="btn-template-save">{{ trans('broadcast.action_save') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

@endsection

@section('scripts')
<script>
$(function () {
    var csrfToken = $('meta[name="csrf-token"]').attr('content');
    var pendingReload = false;

    // 一次帶進整包語系，免得散落數十個 blade 插值
    var BC_I18N = @json(trans('broadcast'));

    function showMessage(msg) {
        $('#modal-broadcast-msg-text').text(msg);
        showBsModal('modal-broadcast-msg');
    }

    /**
     * 確認視窗（專案規範不使用原生 confirm）
     *
     * @param {string}   text
     * @param {Function} onConfirm
     */
    function showConfirm(text, onConfirm) {
        $('#modal-broadcast-confirm-text').text(text);

        // 用 .off() 先解綁，否則連開兩次會把上一次的 callback 一起觸發
        $('#btn-broadcast-confirm-ok').off('click').on('click', function () {
            hideBsModal('modal-broadcast-confirm');
            onConfirm();
        });

        showBsModal('modal-broadcast-confirm');
    }

    document.getElementById('modal-broadcast-msg').addEventListener('hidden.bs.modal', function () {
        if (pendingReload) { location.reload(); }
    });

    // 切換全部/指定
    $('input[name="target_type"]').on('change', function () {
        var isSelected = $(this).val() === '2';
        $('#bc-all-hint').toggle(!isSelected);
        $('#bc-group-checkboxes').toggle(isSelected);
    });

    // 系統篩選
    $('#bc-system-filter').on('change', function () {
        var sysId = $(this).val();
        $('.bc-group-item').each(function () {
            var match = !sysId || $(this).data('system-id') == sysId;
            $(this).toggle(match);
        });
    });

    // 搜尋
    $('#bc-search').on('input', function () {
        var kw = $(this).val().toLowerCase();
        $('.bc-group-item').each(function () {
            var name = $(this).data('name') || '';
            $(this).toggle(name.indexOf(kw) !== -1);
        });
    });

    // 全選
    $('#bc-select-all').on('change', function () {
        var checked = $(this).prop('checked');
        $('.bc-group-item:visible .bc-group-cb').prop('checked', checked);
        updateSelectedCount();
    });

    // 計數
    function updateSelectedCount() {
        var visibleCbs = $('.bc-group-item:visible .bc-group-cb');
        var checkedCount = visibleCbs.filter(':checked').length;
        $('#bc-selected-count').text(BC_I18N.selected_count.replace(':count', checkedCount));
        $('#bc-select-all').prop('checked', visibleCbs.length > 0 && checkedCount === visibleCbs.length);
    }
    $(document).on('change', '.bc-group-cb', updateSelectedCount);

    // 多張圖片累加 + 預覽
    var bcImageFiles = [];

    $('#bc-images').on('change', function () {
        var newFiles = this.files;
        for (var i = 0; i < newFiles.length; i++) {
            bcImageFiles.push(newFiles[i]);
        }
        renderImagePreviews();
        // 清空 input 讓下次還能選同樣的檔案
        $(this).val('');
    });

    /**
     * @param {File} file
     * @returns {boolean}
     */
    function isImageFile(file) {
        return (file.type || '').indexOf('image/') === 0;
    }

    /**
     * 檔案大小顯示成 KB / MB
     *
     * @param {number} bytes
     * @returns {string}
     */
    function formatSize(bytes) {
        if (bytes < 1024 * 1024) { return Math.max(1, Math.round(bytes / 1024)) + ' KB'; }

        return (bytes / 1024 / 1024).toFixed(1) + ' MB';
    }

    /**
     * 移除鈕。用閉包記住 index，不放 data-remove ——
     * 縮圖是非同步載入的，靠屬性取 index 會對到錯的那一個
     *
     * @param {number} idx
     * @returns {jQuery}
     */
    function removeAttachButton(idx) {
        return $('<button type="button" class="btn btn-sm btn-danger position-absolute">')
            .css({ top: '-5px', right: '-5px', padding: '0 4px', fontSize: '0.625rem', lineHeight: '1.2', borderRadius: '50%' })
            .html('&times;')
            .on('click', function () {
                bcImageFiles.splice(idx, 1);
                renderImagePreviews();
            });
    }

    function renderImagePreviews() {
        var $preview = $('#bc-image-preview');
        $preview.empty();
        if (bcImageFiles.length === 0) { $preview.hide(); return; }

        // 同步依序建立節點，縮圖等 FileReader 回來再填 src，順序才與陣列一致
        bcImageFiles.forEach(function (file, idx) {
            var $wrap = $('<div class="position-relative">').css('display', 'inline-block');

            if (isImageFile(file)) {
                var $img = $('<img alt="preview">')
                    .css({ width: '80px', height: '80px', objectFit: 'cover', borderRadius: '0.375rem' });
                var reader = new FileReader();
                reader.onload = function (e) { $img.attr('src', e.target.result); };
                reader.readAsDataURL(file);
                $wrap.append($img);
            } else {
                $wrap.append(
                    $('<div class="d-flex flex-column justify-content-center px-2">')
                        .css({ width: '120px', height: '80px', border: '1px solid #dee2e6', borderRadius: '0.375rem', background: 'rgba(0,0,0,0.03)', fontSize: '0.75rem' })
                        .append($('<i class="fas fa-file-alt text-muted mb-1">'))
                        .append($('<div class="text-truncate">').text(file.name).attr('title', file.name))
                        .append($('<div class="text-muted">').text(formatSize(file.size)))
                );
            }

            $wrap.append(removeAttachButton(idx));
            $preview.append($wrap);
        });

        $preview.show();
    }

    // 預約傳送：勾選才展開時間欄位，並把送出鈕文字換成「預約傳送」
    $('#bc-schedule-enable').on('change', function () {
        var on = $(this).is(':checked');
        $('#bc-schedule-wrap').toggleClass('d-none', !on);
        $('#btn-send').html(on
            ? '<i class="far fa-clock me-1"></i>' + BC_I18N.btn_schedule
            : '<i class="fas fa-paper-plane me-1"></i>' + BC_I18N.btn_send);

        // 預設帶「五分鐘後」，省得每次都要從頭選
        if (on && !$('#bc-scheduled-at').val()) {
            $('#bc-scheduled-at').val(localDatetimeValue(5));
        }
    });

    /**
     * 產生 datetime-local 需要的本地時間字串（YYYY-MM-DDTHH:mm）
     *
     * toISOString() 會轉成 UTC，直接拿來填會差時區，必須自己補零組字串。
     *
     * @param {number} minutesLater 從現在往後幾分鐘
     * @returns {string}
     */
    function localDatetimeValue(minutesLater) {
        var d = new Date(Date.now() + minutesLater * 60 * 1000);
        var pad = function (n) { return n < 10 ? '0' + n : String(n); };

        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
            + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    /**
     * 還原送出鈕的文字（依目前是否勾選預約）
     */
    function resetSendButton() {
        var on = $('#bc-schedule-enable').is(':checked');
        $('#btn-send').prop('disabled', false).html(on
            ? '<i class="far fa-clock me-1"></i>' + BC_I18N.btn_schedule
            : '<i class="fas fa-paper-plane me-1"></i>' + BC_I18N.btn_send);
    }

    // 發送
    $('#form-broadcast').on('submit', function (e) {
        e.preventDefault();
        var content = $('#bc-content').val().trim();
        if (!content) { showMessage(BC_I18N.msg.content_required); return; }

        var targetType = parseInt($('input[name="target_type"]:checked').val(), 10);

        if (targetType === 2) {
            var ids = [];
            $('.bc-group-cb:checked').each(function () { ids.push(parseInt($(this).val(), 10)); });
            if (ids.length === 0) { showMessage(BC_I18N.msg.no_group_selected); return; }
        }

        var scheduledAt = '';
        if ($('#bc-schedule-enable').is(':checked')) {
            scheduledAt = $('#bc-scheduled-at').val();
            if (!scheduledAt) { showMessage(BC_I18N.msg.schedule_required); return; }

            // datetime-local 給的是 YYYY-MM-DDTHH:mm，後端要 YYYY-MM-DD HH:mm
            scheduledAt = scheduledAt.replace('T', ' ');
            if (new Date(scheduledAt.replace(' ', 'T')) <= new Date()) {
                showMessage(BC_I18N.msg.schedule_past);
                return;
            }
        }

        var formData = new FormData();
        formData.append('content', content);
        formData.append('target_type', targetType);
        if (scheduledAt) { formData.append('scheduled_at', scheduledAt); }

        if (targetType === 2) {
            ids.forEach(function (id) { formData.append('group_ids[]', id); });
        }

        // 圖片走 sendPhoto（客戶端看得到縮圖），其他走 sendDocument，後端驗證規則也不同
        bcImageFiles.forEach(function (file) {
            formData.append(isImageFile(file) ? 'images[]' : 'files[]', file);
        });

        /*
         * 選了建檔公告時，把「要帶走哪幾個附件」告訴後端。
         *
         * ⚠ 送的是 **id 與索引**，不是路徑 —— 路徑由後端拿 id 去資料庫查。
         * 讓前端送路徑等於開放「指定伺服器上任何檔案發給所有客人」。
         *
         * ⚠ **沒送索引＝一個都不帶**。FormData 沒辦法表達「空陣列」
         * （`append(name, '')` 會變成空字串，過不了 array 驗證），
         * 所以語意定成這樣，由這裡負責在選範本時就把全部索引放進
         * `keptTemplateImages` —— 使用者按叉掉才會變少。
         */
        if (selectedTemplate) {
            formData.append('template_id', selectedTemplate.id);
            keptTemplateImages.forEach(function (idx) { formData.append('keep_image_indexes[]', idx); });
            keptTemplateFiles.forEach(function (idx) { formData.append('keep_file_indexes[]', idx); });
        }

        $('#btn-send').prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i>' + BC_I18N.sending);

        $.ajax({
            url: '/admin/telegram-broadcast/ajax-send',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            data: formData,
            processData: false,
            contentType: false,
            success: function (body) {
                resetSendButton();
                $('#bc-content').val('');
                bcImageFiles = [];
                renderImagePreviews();
                showMessage(body.message || BC_I18N.msg.send_success);
                pendingReload = true;
            },
            error: function (xhr) {
                resetSendButton();
                showMessage((xhr.responseJSON && xhr.responseJSON.message) || BC_I18N.msg.send_failed);
            }
        });
    });

    // 取消預約
    $(document).on('click', '.js-cancel-schedule', function () {
        var id = $(this).data('id');
        showConfirm(BC_I18N.msg.confirm_cancel, function () {
            $.ajax({
                url: '/admin/telegram-broadcast/ajax-cancel-schedule/' + id,
                method: 'PUT',
                headers: { 'X-CSRF-TOKEN': csrfToken },
                success: function (body) {
                    showMessage(body.message || BC_I18N.msg.cancel_success);
                    location.reload();
                },
                error: function (xhr) {
                    showMessage((xhr.responseJSON && xhr.responseJSON.message) || BC_I18N.msg.cancel_not_pending);
                }
            });
        });
    });

    /**
     * 待發送：只有預定對象，還沒有成敗可言
     *
     * @param {string[]} targets 站台名稱
     */
    function renderPendingTargets(targets) {
        var html = '';
        targets.forEach(function (name) {
            html += '<tr><td>' + $('<span>').text(name).html() + '</td>'
                + '<td class="text-center"><span class="badge bg-warning text-dark">' + BC_I18N.status_pending + '</span></td></tr>';
        });

        $('#send-detail-summary').html(
            '<span class="text-muted" style="font-size:0.875rem">' + BC_I18N.detail_total.replace(':count', targets.length) + '</span>'
        );
        $('#send-detail-body').html(html);
    }

    /**
     * 已發送：顯示每站台成敗
     *
     * @param {Object[]} results
     */
    function renderSendResults(results) {
        var successCount = 0;
        var failCount = 0;
        var html = '';

        results.forEach(function (r) {
            if (r.success) { successCount++; } else { failCount++; }
            html += '<tr>';
            html += '<td>' + $('<span>').text(r.name).html() + '</td>';
            html += '<td class="text-center">';
            if (r.success) {
                html += '<span class="badge bg-success">' + BC_I18N.field_success + '</span>';
            } else {
                html += '<span class="badge bg-danger">' + BC_I18N.field_fail + '</span>';
            }
            html += '</td></tr>';
        });

        $('#send-detail-summary').html(
            '<span class="text-muted" style="font-size:0.875rem">' + BC_I18N.detail_total.replace(':count', results.length) + '</span>' +
            '<span style="font-size:0.875rem"><span class="badge bg-success">' + successCount + '</span> ' + BC_I18N.field_success + '</span>' +
            (failCount > 0 ? '<span style="font-size:0.875rem"><span class="badge bg-danger">' + failCount + '</span> ' + BC_I18N.field_fail + '</span>' : '')
        );
        $('#send-detail-body').html(html);
    }

    // 發送明細
    $(document).on('click', '.js-show-send-detail', function () {
        var $badge = $(this);
        var isPending = String($badge.data('pending')) === '1';
        // 待發送的還沒送出，send_results 必然是空的，改看預定對象
        var rows = isPending ? ($badge.data('targets') || []) : ($badge.data('results') || []);

        $('#send-detail-empty, #send-detail-table-wrap').hide();
        $('#send-detail-note').addClass('d-none');

        if (!rows.length) {
            $('#send-detail-empty').show();
        } else {
            if (isPending) {
                renderPendingTargets(rows);
                // 「全部群組」的預約在送出當下才決定對象，現在看到的只是目前的清單
                if (String($badge.data('target-type')) === '1') {
                    $('#send-detail-note').removeClass('d-none');
                }
            } else {
                renderSendResults(rows);
            }
            $('#send-detail-table-wrap').show();
        }

        showBsModal('modal-send-detail');
    });

    // 字數即時計算
    $('#bc-content').on('input', function () {
        var len = $(this).val().length;
        var $counter = $('#bc-content-count');
        $counter.text(len);
        $counter.css('color', len >= 4000 ? '#dc3545' : '');
    });

    // 複製公告內容
    $('.js-copy-content').on('click', function () {
        var content = $(this).data('content');
        var $btn = $(this);
        if (navigator.clipboard) {
            navigator.clipboard.writeText(content).then(function () {
                $btn.html('<i class="fas fa-check me-1"></i>' + BC_I18N.copied);
                setTimeout(function () { $btn.html('<i class="fas fa-copy me-1"></i>' + BC_I18N.btn_copy); }, 1500);
            });
        } else {
            // fallback
            var ta = document.createElement('textarea');
            ta.value = content;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            $btn.html('<i class="fas fa-check me-1"></i>' + BC_I18N.copied);
            setTimeout(function () { $btn.html('<i class="fas fa-copy me-1"></i>' + BC_I18N.btn_copy); }, 1500);
        }
    });

    // ===============================================================
    //  公告管理（建檔的範本）
    // ===============================================================

    /*
     * 範本清單隨頁面一起到，直接畫 —— 切到「公告管理」分頁時資料已經在手上，
     * 不會先空白一下才跳出來。異動之後才走 ajax 重載。
     */
    var templates = @json($templates->resolve());
    var canManageTemplate = @json(Auth::user()->hasPermission('telegram_chat.broadcast_template'));

    /**
     * 載入範本清單
     *
     * 發送分頁的下拉與公告管理分頁的表格共用同一份資料 ——
     * 範本數量是人工建檔的量級，整包拿回來比開兩支 API 省事。
     *
     * @param {Function} [after] 載完要做的事（例如存完重畫）
     */
    function loadTemplates(after) {
        // 重載時先擺一列「載入中」—— 空白的表格看起來像壞掉或沒資料
        $('#template-list').html('<tr><td colspan="' + (canManageTemplate ? 7 : 6) + '" class="table-empty">'
            + '<i class="fas fa-spinner fa-spin me-1"></i>' + @json(trans('common.loading')) + '</td></tr>');

        $.ajax({
            url: '/admin/telegram-broadcast/ajax-templates',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            success: function (body) {
                templates = body.data || [];
                renderTemplateList();
                renderTemplateOptions();
                if (after) { after(); }
            },
            error: function () {
                $('#template-list').html('<tr><td colspan="' + (canManageTemplate ? 7 : 6) + '" class="table-empty text-danger">'
                    + BC_I18N.msg.template_failed + '</td></tr>');
            }
        });
    }

    /**
     * 公告管理分頁的搜尋關鍵字
     *
     * 純前端過濾，不打 API —— 清單整包已經在手上（人工建檔的量級），
     * 每打一個字就往後端跑一趟只會換來延遲。
     *
     * @type {string}
     */
    var templateKeyword = '';

    /**
     * 關鍵字過濾
     *
     * 需求是搜公告編號，標題一起認 —— 成本相同，而「打了標題卻搜不到」
     * 會被當成壞掉。大小寫不計（編號常是 `AD-01` 這種）。
     *
     * @returns {Array}
     */
    function filteredTemplates() {
        if (blankKeyword()) {
            return templates;
        }

        var kw = templateKeyword;

        return templates.filter(function (t) {
            var code = (t.code || '').toLowerCase();
            var title = (t.title || '').toLowerCase();

            return code.indexOf(kw) !== -1 || title.indexOf(kw) !== -1;
        });
    }

    /** @returns {boolean} */
    function blankKeyword() {
        return templateKeyword.length === 0;
    }

    /** 公告管理分頁的表格 */
    function renderTemplateList() {
        var $tbody = $('#template-list');
        var cols = canManageTemplate ? 7 : 6;
        var rows = filteredTemplates();

        if (!rows.length) {
            // 「一筆都沒建」與「搜不到」是兩件事，訊息要分開 ——
            // 搜不到卻寫「尚無公告」會讓人以為資料不見了
            var emptyText = blankKeyword() ? BC_I18N.template_empty : BC_I18N.template_search_empty;
            var emptyIcon = blankKeyword() ? 'fa-inbox' : 'fa-search';

            $tbody.html('<tr><td colspan="' + cols + '" class="table-empty"><i class="fas ' + emptyIcon + '"></i>' + emptyText + '</td></tr>');
            return;
        }

        var html = '';

        rows.forEach(function (t) {
            var attachCount = (t.image_urls || []).length + (t.file_urls || []).length;

            html += '<tr>';
            // 既有資料可能還沒補編號
            html += '<td class="col-tight">' + (t.code ? escapeHtml(t.code) : '-') + '</td>';
            html += '<td><span class="cell-stack__main">' + escapeHtml(t.title) + '</span></td>';
            // 內容只給摘要 —— 整段貼進表格會把其他欄位擠掉
            html += '<td><span class="text-muted">' + escapeHtml(summarize(t.content)) + '</span></td>';
            html += '<td class="col-num">' + (attachCount > 0 ? attachCount : '-') + '</td>';
            html += '<td>' + escapeHtml(t.creator || '-') + '</td>';
            html += '<td>' + escapeHtml(t.updated_at || '-') + '</td>';

            if (canManageTemplate) {
                html += '<td><div class="row-actions"><div class="btn-group btn-group-sm" role="group">';
                html += '<button type="button" class="btn btn-outline-secondary btn-icon btn-transition js-template-edit" data-id="' + t.id + '">';
                html += '<i class="fas fa-edit btn-icon-wrapper"></i>' + BC_I18N.template_edit + '</button>';
                html += '<button type="button" class="btn btn-outline-secondary btn-icon btn-transition js-template-delete" data-id="' + t.id + '">';
                html += '<i class="fas fa-trash-alt btn-icon-wrapper text-danger"></i>' + BC_I18N.template_delete + '</button>';
                html += '</div></div></td>';
            }

            html += '</tr>';
        });

        $tbody.html(html);
    }

    /**
     * 把長內容切成一行摘要
     *
     * @param {string} text
     * @returns {string}
     */
    function summarize(text) {
        var oneLine = (text || '').replace(/\s+/g, ' ').trim();

        return oneLine.length > 40 ? oneLine.substring(0, 40) + '…' : oneLine;
    }

    function escapeHtml(value) {
        return $('<span>').text(value === null || value === undefined ? '' : value).html();
    }

    /** 發送分頁的下拉 */
    function renderTemplateOptions() {
        var html = '<option value="">' + BC_I18N.source_pick + '</option>';

        templates.forEach(function (t) {
            // 有編號就擺前面 —— 挑公告時那串比標題更好認
            var label = t.code ? t.code + '　' + t.title : t.title;

            html += '<option value="' + t.id + '">' + escapeHtml(label) + '</option>';
        });

        $('#bc-template-id').html(html);
    }

    // ---------------------------------------------------------------
    //  發送分頁：選用建檔的公告
    // ---------------------------------------------------------------

    var selectedTemplate = null;
    var keptTemplateImages = [];
    var keptTemplateFiles = [];

    $('input[name="bc_source"]').on('change', function () {
        var useTemplate = $(this).val() === 'template';

        $('#bc-template-wrap').toggleClass('d-none', !useTemplate);

        // 切回「自己輸入」要把帶進來的東西清掉，否則會連同範本附件一起送出
        if (!useTemplate) {
            clearTemplateSelection();
        }
    });

    $('#bc-template-id').on('change', function () {
        var id = parseInt($(this).val(), 10);
        var found = templates.filter(function (t) { return t.id === id; })[0];

        if (!found) {
            clearTemplateSelection();
            return;
        }

        selectedTemplate = found;

        /*
         * 一選就把全部索引放進來 —— 後端的語意是「沒送索引＝一個都不帶」，
         * 所以這裡必須先裝滿，使用者按叉掉才會變少。
         */
        keptTemplateImages = (found.image_urls || []).map(function (_, i) { return i; });
        keptTemplateFiles = (found.file_urls || []).map(function (_, i) { return i; });

        $('#bc-content').val(found.content).trigger('input');
        renderTemplateAttachments();
    });

    function clearTemplateSelection() {
        selectedTemplate = null;
        keptTemplateImages = [];
        keptTemplateFiles = [];
        $('#bc-template-id').val('');
        $('#bc-template-attachments').addClass('d-none');
        $('#bc-template-attachment-list').empty();
    }

    /** 範本帶來的附件（可逐個移除，只影響這一次發送） */
    function renderTemplateAttachments() {
        var $wrap = $('#bc-template-attachments');
        var $list = $('#bc-template-attachment-list').empty();

        if (!selectedTemplate) { $wrap.addClass('d-none'); return; }

        var total = keptTemplateImages.length + keptTemplateFiles.length;

        if (total === 0) { $wrap.addClass('d-none'); return; }

        keptTemplateImages.forEach(function (idx) {
            $list.append(templateChip(selectedTemplate.image_urls[idx], 'image', idx));
        });

        keptTemplateFiles.forEach(function (idx) {
            $list.append(templateChip(selectedTemplate.file_urls[idx], 'file', idx));
        });

        $wrap.removeClass('d-none');
    }

    /**
     * 一個附件的小標籤（含移除鈕）
     *
     * @param {Object} item {path, name}
     * @param {string} kind image / file
     * @param {number} idx  在範本陣列裡的索引
     */
    function templateChip(item, kind, idx) {
        var $chip = $('<span class="badge bg-light text-dark border d-inline-flex align-items-center gap-1">');

        $chip.append($('<i>').addClass(kind === 'image' ? 'fas fa-image' : 'fas fa-file-alt'));
        $chip.append($('<span>').text(item && item.name ? item.name : '-'));
        $chip.append(
            $('<button type="button" class="btn-close btn-close-sm ms-1" style="font-size:0.5rem">')
                .on('click', function () {
                    var list = kind === 'image' ? keptTemplateImages : keptTemplateFiles;
                    var at = list.indexOf(idx);

                    if (at !== -1) { list.splice(at, 1); }

                    renderTemplateAttachments();
                })
        );

        return $chip;
    }

    // ---------------------------------------------------------------
    //  公告管理分頁：新增／編輯／刪除
    // ---------------------------------------------------------------

    var editingKeepImages = [];
    var editingKeepFiles = [];

    /*
     * 這次新選的檔案。
     *
     * ⚠ 自己存成陣列而不是靠 `<input>` 的 `files` —— FileList 是唯讀的，
     * 拖進來的檔案沒辦法塞回去，而且「選了一次再選一次」會整組被覆蓋。
     * 送出時從這個陣列組 FormData（發送分頁的 `bcImageFiles` 也是同一招）。
     */
    var tplNewFiles = [];

    // ---------------------------------------------------------------
    //  拖曳上傳
    // ---------------------------------------------------------------

    var $dropzone = $('#tpl-dropzone');

    /*
     * ⚠ **`<input>` 的 click 一定要擋住冒泡**，否則是無限遞迴：
     *
     *   點 dropzone → trigger input.click() → 那個 click 冒泡回 dropzone
     *   → 又 trigger → … → Maximum call stack size exceeded
     *
     * 因為 `#tpl-files` 就藏在 `#tpl-dropzone` 裡面（刻意的，鍵盤使用者要
     * tab 得到）。點上方的 `<label for="tpl-files">` 也會走同一條路。
     *
     * 擋在 input 這一側而不是在 dropzone 裡判斷 `e.target` ——
     * 這樣不論 click 是誰觸發的（使用者、label、程式）都只會往下開檔案視窗。
     */
    $('#tpl-files').on('click', function (e) {
        e.stopPropagation();
    });

    $dropzone.on('click', function () {
        $('#tpl-files').trigger('click');
    });

    /*
     * ⚠ dragover 一定要 preventDefault，否則瀏覽器會沿用預設行為
     * （直接開啟那個檔案），drop 根本不會觸發。
     */
    $dropzone.on('dragover dragenter', function (e) {
        e.preventDefault();
        e.stopPropagation();
        $dropzone.addClass('border-primary bg-light');
    });

    $dropzone.on('dragleave dragend', function (e) {
        e.preventDefault();
        e.stopPropagation();
        $dropzone.removeClass('border-primary bg-light');
    });

    $dropzone.on('drop', function (e) {
        e.preventDefault();
        e.stopPropagation();
        $dropzone.removeClass('border-primary bg-light');

        addTemplateFiles(e.originalEvent.dataTransfer.files);
    });

    $('#tpl-files').on('change', function () {
        addTemplateFiles(this.files);
        // 清空 input，否則同一個檔案選第二次不會觸發 change
        $(this).val('');
    });

    /**
     * 把檔案加進待上傳清單
     *
     * @param {FileList} files
     */
    function addTemplateFiles(files) {
        Array.prototype.forEach.call(files || [], function (file) {
            tplNewFiles.push(file);
        });

        renderNewFiles();
    }

    /** 這次新選的檔案：圖片給縮圖，其他給檔名 */
    function renderNewFiles() {
        var $list = $('#tpl-new-list').empty();

        tplNewFiles.forEach(function (file, idx) {
            $list.append(newFileChip(file, idx));
        });
    }

    /**
     * @param {File}   file
     * @param {number} idx 在 tplNewFiles 裡的位置
     */
    function newFileChip(file, idx) {
        var $remove = $('<button type="button" class="btn-close" style="font-size:0.5rem">')
            .on('click', function (e) {
                e.stopPropagation();
                tplNewFiles.splice(idx, 1);
                renderNewFiles();
            });

        if (isImageFile(file)) {
            var $thumb = $('<div class="position-relative border rounded overflow-hidden" style="width:88px;height:88px">');
            var $img = $('<img class="w-100 h-100" style="object-fit:cover;cursor:zoom-in">')
                .attr('alt', file.name)
                .attr('title', file.name);

            // 還沒上傳，沒有網址可用 —— 讀成 data URL 當縮圖與放大圖
            var reader = new FileReader();
            reader.onload = function (e) {
                $img.attr('src', e.target.result);
                $img.on('click', function () {
                    openImagePreview({ name: file.name, url: e.target.result });
                });
            };
            reader.readAsDataURL(file);

            $thumb.append($img);
            $thumb.append(
                $('<span class="position-absolute top-0 end-0 bg-white rounded-circle d-flex align-items-center justify-content-center" style="width:20px;height:20px;margin:2px">')
                    .append($remove)
            );

            return $thumb;
        }

        var $chip = $('<span class="badge bg-light text-dark border d-inline-flex align-items-center gap-1">');

        $chip.append($('<i class="fas fa-file-alt">'));
        $chip.append($('<span>').text(file.name));
        $chip.append($('<small class="text-muted">').text(formatSize(file.size)));
        $chip.append($remove.addClass('ms-1'));

        return $chip;
    }

    $('#tpl-content').on('input', function () {
        $('#tpl-content-count').text($(this).val().length);
    });

    $('#btn-template-add').on('click', function () {
        openTemplateModal(null);
    });

    /*
     * 搜尋：輸入即篩。沒有「查詢」按鈕也不用 debounce ——
     * 過濾只是走一次記憶體裡的陣列，沒有往後端的往返要省。
     */
    $('#template-search').on('input', function () {
        templateKeyword = $(this).val().trim().toLowerCase();
        $('#btn-template-search-clear').toggleClass('d-none', blankKeyword());
        renderTemplateList();
    });

    $('#btn-template-search-clear').on('click', function () {
        $('#template-search').val('').trigger('input').focus();
    });

    $(document).on('click', '.js-template-edit', function () {
        var id = parseInt($(this).data('id'), 10);

        openTemplateModal(templates.filter(function (t) { return t.id === id; })[0]);
    });

    /**
     * @param {Object|null} tpl null＝新增
     */
    function openTemplateModal(tpl) {
        $('#modal-template-title').text(tpl ? BC_I18N.template_edit : BC_I18N.template_add);
        $('#tpl-id').val(tpl ? tpl.id : '');
        $('#tpl-code').val(tpl ? (tpl.code || '') : '');
        $('#tpl-title').val(tpl ? tpl.title : '');
        $('#tpl-content').val(tpl ? tpl.content : '').trigger('input');

        // 上一次開視窗選到一半的檔案不能留到這一次
        $('#tpl-files').val('');
        tplNewFiles = [];
        renderNewFiles();

        /*
         * 連同索引一起記下來 —— 送出時要給後端的是索引（它才有權威的路徑），
         * 而畫面上要顯示的是檔名。叉掉某一個之後，剩下的索引不會重新編號。
         */
        editingKeepImages = withIndex(tpl ? tpl.image_urls : []);
        editingKeepFiles = withIndex(tpl ? tpl.file_urls : []);
        renderExistingAttachments();

        showBsModal('modal-template');
    }

    /**
     * 把附件陣列配上它在範本裡的原始索引
     *
     * 索引是送回後端的憑據（「留哪幾個」），`url` 只給畫面預覽用。
     *
     * @param {Array} list
     * @returns {Array} [{index, name, url}]
     */
    function withIndex(list) {
        return (list || []).map(function (item, i) {
            return {
                index: i,
                name: item && item.name ? item.name : '-',
                url: item ? item.url : null
            };
        });
    }

    /** 編輯時原有的附件（按叉掉就是不留） */
    function renderExistingAttachments() {
        var $wrap = $('#tpl-existing-wrap');
        var $list = $('#tpl-existing-list').empty();
        var total = editingKeepImages.length + editingKeepFiles.length;

        if (total === 0) { $wrap.addClass('d-none'); return; }

        editingKeepImages.forEach(function (item) {
            $list.append(existingChip(item, 'image'));
        });

        editingKeepFiles.forEach(function (item) {
            $list.append(existingChip(item, 'file'));
        });

        $wrap.removeClass('d-none');
    }

    /**
     * 編輯視窗裡的一個附件
     *
     * 圖片給縮圖（點了放大），檔案維持檔名標籤 —— 檔案沒有「看一眼就認得」
     * 這回事，縮圖對它沒有意義。
     *
     * @param {Object} item {index, name, url}
     * @param {string} kind image / file
     */
    function existingChip(item, kind) {
        var $remove = $('<button type="button" class="btn-close" style="font-size:0.5rem">')
            .attr('title', BC_I18N.action_cancel)
            .on('click', function (e) {
                e.stopPropagation();

                var list = kind === 'image' ? editingKeepImages : editingKeepFiles;
                // 比對 index 而不是位置 —— 刪掉前面那個之後，位置會位移
                var at = list.map(function (x) { return x.index; }).indexOf(item.index);

                if (at !== -1) { list.splice(at, 1); }

                renderExistingAttachments();
            });

        if (kind === 'image' && item.url) {
            var $thumb = $('<div class="position-relative border rounded overflow-hidden" style="width:88px;height:88px">');

            $thumb.append(
                $('<img class="w-100 h-100" style="object-fit:cover;cursor:zoom-in">')
                    .attr('src', item.url)
                    .attr('alt', item.name)
                    .attr('title', item.name)
                    .on('click', function () { openImagePreview(item); })
            );

            // 叉掉鈕浮在縮圖右上角，白底才不會跟深色照片糊在一起
            $thumb.append(
                $('<span class="position-absolute top-0 end-0 bg-white rounded-circle d-flex align-items-center justify-content-center" style="width:20px;height:20px;margin:2px">')
                    .append($remove)
            );

            return $thumb;
        }

        var $chip = $('<span class="badge bg-light text-dark border d-inline-flex align-items-center gap-1">');

        $chip.append($('<i>').addClass(kind === 'image' ? 'fas fa-image' : 'fas fa-file-alt'));
        $chip.append($('<span>').text(item.name));
        $chip.append($remove.addClass('ms-1'));

        return $chip;
    }

    /**
     * 放大看一張圖
     *
     * ⚠ 疊在編輯視窗上面，**不關掉它** —— 關掉的話看完圖回不去，
     * 剛打到一半的內容也沒了。
     *
     * @param {Object} item {name, url}
     */
    function openImagePreview(item) {
        $('#tpl-image-title').text(item.name);
        $('#tpl-image-full').attr('src', item.url).attr('alt', item.name);

        showBsModal('modal-template-image');
    }

    $('#form-template').on('submit', function (e) {
        e.preventDefault();

        var id = $('#tpl-id').val();
        var formData = new FormData();

        formData.append('code', $('#tpl-code').val().trim());
        formData.append('title', $('#tpl-title').val().trim());
        formData.append('content', $('#tpl-content').val().trim());

        // 同發送那邊：圖片與一般檔案拆成兩個欄位，後端驗證規則不同
        tplNewFiles.forEach(function (file) {
            formData.append(isImageFile(file) ? 'images[]' : 'files[]', file);
        });

        /*
         * 編輯時送「要留下哪幾個」的**索引**，跟發送那條路一致。
         *
         * ⚠ 不送路徑：收路徑等於讓人把伺服器上任意檔案寫進範本，
         * 下次發送就送給所有客人了。路徑一律由後端從資料庫取。
         *
         * ⚠ 沒送＝一個都不留（FormData 表達不出空陣列）。
         */
        if (id) {
            editingKeepImages.forEach(function (item) { formData.append('keep_image_indexes[]', item.index); });
            editingKeepFiles.forEach(function (item) { formData.append('keep_file_indexes[]', item.index); });
        }

        $('#btn-template-save').prop('disabled', true);

        $.ajax({
            url: id ? '/admin/telegram-broadcast/ajax-template/' + id : '/admin/telegram-broadcast/ajax-template',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            data: formData,
            processData: false,
            contentType: false,
            success: function (body) {
                $('#btn-template-save').prop('disabled', false);
                hideBsModal('modal-template');
                showMessage(body.message);

                /*
                 * 新增完清掉搜尋：篩選中新增一筆不符關鍵字的公告，
                 * 存檔成功卻看不到那一列 —— 看起來像沒存進去。
                 * 編輯不清，那是在既有的檢視裡改東西，關鍵字要留著。
                 */
                if (!id) {
                    $('#template-search').val('').trigger('input');
                }

                loadTemplates();
            },
            error: function (xhr) {
                $('#btn-template-save').prop('disabled', false);
                showMessage((xhr.responseJSON && xhr.responseJSON.message) || BC_I18N.msg.template_failed);
            }
        });
    });

    $(document).on('click', '.js-template-delete', function () {
        var id = parseInt($(this).data('id'), 10);
        var tpl = templates.filter(function (t) { return t.id === id; })[0];

        if (!tpl) { return; }

        showConfirm(BC_I18N.template_delete_confirm.replace(':title', tpl.title), function () {
            $.ajax({
                url: '/admin/telegram-broadcast/ajax-template/' + id,
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': csrfToken },
                success: function (body) {
                    showMessage(body.message);
                    // 剛被刪掉的那則如果正被選用，要一起清掉
                    if (selectedTemplate && selectedTemplate.id === id) { clearTemplateSelection(); }
                    loadTemplates();
                },
                error: function (xhr) {
                    showMessage((xhr.responseJSON && xhr.responseJSON.message) || BC_I18N.msg.template_failed);
                }
            });
        });
    });

    // 資料已經隨頁面到了，直接畫；不打 ajax
    renderTemplateList();
    renderTemplateOptions();
});
</script>
@endsection
