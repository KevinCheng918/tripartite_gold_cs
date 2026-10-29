@extends('layouts.app')

@section('title', '專案管理')
@section('icon', 'folder-open')
@section('subtitle', '管理系統專案')

@section('content')

    {{-- 操作列 --}}
    <div class="main-card mb-3 card">
        <div class="card-header d-flex align-items-center justify-content-between">
            @if(Auth::user()->hasPermission('project.edit'))
            <button class="btn btn-primary btn-sm" id="btn-add-project"><i class="fas fa-plus me-1"></i>新增專案</button>
            @else
            <div></div>
            @endif
            <span class="text-muted" style="font-size:0.875rem">共 <strong id="project-total">{{ $projects->count() }}</strong> 個專案</span>
        </div>
    </div>

    {{-- 專案 Tabs --}}
    @if($projects->isEmpty())
        <div class="text-center text-muted py-5"><i class="fas fa-inbox fa-3x mb-3 d-block"></i>目前沒有專案</div>
    @else
        <ul class="nav nav-tabs mb-3" role="tablist">
            @foreach($projects as $idx => $project)
            <li class="nav-item" role="presentation">
                <button class="nav-link{{ $idx === 0 ? ' active' : '' }}" data-bs-toggle="tab" data-bs-target="#project-tab-{{ $project->id }}" type="button" role="tab">
                    <i class="fas fa-folder me-1"></i>{{ $project->name }}
                    @if($project->status !== 1)
                        <span class="badge bg-secondary ms-1" style="font-size:0.65rem">停用</span>
                    @endif
                </button>
            </li>
            @endforeach
        </ul>
        <div class="tab-content">
            @foreach($projects as $idx => $project)
            <div class="tab-pane fade{{ $idx === 0 ? ' show active' : '' }}" id="project-tab-{{ $project->id }}" role="tabpanel">
                <div class="main-card mb-3 card">
                    <div class="card-body">
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <div class="d-flex justify-content-between mb-2" style="font-size:0.875rem">
                                    <span class="text-muted">專案名稱</span>
                                    <strong>{{ $project->name }}</strong>
                                </div>
                                <div class="d-flex justify-content-between mb-2" style="font-size:0.875rem">
                                    <span class="text-muted">狀態</span>
                                    @if($project->status === 1)
                                        <span class="badge bg-success">啟用</span>
                                    @else
                                        <span class="badge bg-secondary">停用</span>
                                    @endif
                                </div>
                                <div class="d-flex justify-content-between mb-2" style="font-size:0.875rem">
                                    <span class="text-muted">建立者</span>
                                    <span>{{ $project->creator ? $project->creator->nickname : '-' }}</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2" style="font-size:0.875rem">
                                    <span class="text-muted">建立時間</span>
                                    <span>{{ $project->created_at ? $project->created_at->format('Y-m-d H:i') : '-' }}</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2" style="font-size:0.875rem">
                                    <span class="text-muted">最後更新</span>
                                    <span>{{ $project->updated_at ? $project->updated_at->format('Y-m-d H:i') : '-' }}</span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="text-muted" style="font-size:0.875rem">說明</label>
                                <div style="font-size:0.875rem;white-space:pre-wrap">{{ $project->description ?: '無說明' }}</div>
                            </div>
                        </div>
                        @if(Auth::user()->hasPermission('project.edit'))
                        <div class="text-end">
                            <button class="btn btn-sm btn-outline-secondary js-edit-project"
                                data-id="{{ $project->id }}"
                                data-name="{{ $project->name }}"
                                data-desc="{{ $project->description }}"
                                data-status="{{ $project->status }}">
                                <i class="fas fa-edit me-1"></i>編輯
                            </button>
                        </div>
                        @endif
                    </div>
                </div>

                {{-- ============ 系統規章（需求方 2026-10-30）============ --}}
                {{-- ⚠ 一個專案可以有多份。內容不在這裡顯示（可能上萬字），
                     點「看內容」才去撈 --}}
                <div class="main-card mb-3 card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-book me-1"></i>{{ trans('project.regulation_section') }}
                            </h5>
                            @if(Auth::user()->hasPermission('project.edit'))
                            <button class="btn btn-sm btn-primary js-regulation-add" data-project="{{ $project->id }}">
                                <i class="fas fa-plus me-1"></i>{{ trans('project.regulation_add') }}
                            </button>
                            @endif
                        </div>

                        @php($items = $regulations[$project->id] ?? collect())

                        @if($items->isEmpty())
                            <p class="text-muted mb-0" style="font-size:0.875rem">{{ trans('project.regulation_empty') }}</p>
                        @else
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>{{ trans('project.regulation_title') }}</th>
                                            <th style="width:6rem">{{ trans('project.regulation_status') }}</th>
                                            <th style="width:12rem">{{ trans('project.regulation_updated_by') }}</th>
                                            {{-- 三顆帶文字的按鈕，12rem 放不下（`.row-actions` 是 nowrap，
                                                 放不下時會把其他欄擠窄）。寬度照實寫，讓標題欄去分剩下的。 --}}
                                            <th style="width:20rem" class="text-end">{{ trans('station.field_action') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($items as $item)
                                        <tr>
                                            <td>{{ $item->title }}</td>
                                            <td>
                                                @if($item->isActive())
                                                    <span class="badge bg-success">{{ trans('project.regulation_active') }}</span>
                                                @else
                                                    {{-- ⚠ 停用的要標出來：小幫手取不到，但後台還看得到 --}}
                                                    <span class="badge bg-secondary">{{ trans('project.regulation_disabled') }}</span>
                                                @endif
                                            </td>
                                            <td>
                                                <small class="text-muted">
                                                    {{ $item->updater ? $item->updater->nickname : '-' }}
                                                    @if($item->updated_at)
                                                        <div>{{ $item->updated_at->format('Y-m-d H:i') }}</div>
                                                    @endif
                                                </small>
                                            </td>
                                            {{--
                                                列操作照全站的 `.row-actions` 慣例寫
                                                （見 features/admin-table-layout.md）。
                                                第一版沒照慣例，踩到三件已經有定論的事：

                                                ⚠ **沒有 `.row-actions` 外框 → 深色模式整個沒套用**。
                                                custom.css 的淺／深兩套按鈕樣式都掛在
                                                `.row-actions` 底下，不包就只拿得到 Bootstrap 預設。

                                                ⚠ **刪除不可以用 `btn-outline-danger`**：custom.css
                                                只替 `outline-secondary` 補齊了淺／深兩種模式的 hover，
                                                換色系的 outline 變體在淺色模式滑過去不會變色、
                                                深色模式則完全沒有規則。危險動作改成
                                                **把圖示染成 `text-danger`**（帳號管理的「解除綁定」同一個寫法）。

                                                ⚠ **不可以只有圖示**：2026-10-10 需求方退過
                                                「純圖示鈕 + title」那一版 ——「每個按鈕旁邊還是要有說明，
                                                不能隱藏」。所以每顆都帶文字。

                                                ⚠ `role="group"` 與 `aria-label` 每段都要：
                                                視覺上的分段對讀螢幕的人不存在。
                                            --}}
                                            <td class="text-end">
                                                <div class="row-actions">
                                                    <div class="btn-group btn-group-sm" role="group" aria-label="{{ trans('common.row_actions.view') }}">
                                                        <button class="btn btn-outline-secondary btn-icon btn-transition js-regulation-view" data-id="{{ $item->id }}">
                                                            <i class="fas fa-eye btn-icon-wrapper"></i>{{ trans('project.regulation_view') }}
                                                        </button>
                                                    </div>
                                                    @if(Auth::user()->hasPermission('project.edit'))
                                                    <div class="btn-group btn-group-sm" role="group" aria-label="{{ trans('common.row_actions.manage') }}">
                                                        <button class="btn btn-outline-secondary btn-icon btn-transition js-regulation-edit" data-id="{{ $item->id }}">
                                                            <i class="fas fa-edit btn-icon-wrapper"></i>{{ trans('project.regulation_edit') }}
                                                        </button>
                                                    </div>
                                                    <div class="btn-group btn-group-sm" role="group" aria-label="{{ trans('common.row_actions.danger') }}">
                                                        <button class="btn btn-outline-secondary btn-icon btn-transition js-regulation-delete" data-id="{{ $item->id }}">
                                                            <i class="fas fa-trash btn-icon-wrapper text-danger"></i>{{ trans('project.regulation_delete') }}
                                                        </button>
                                                    </div>
                                                    @endif
                                                </div>
                                            </td>
                                        </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
            @endforeach
        </div>
    @endif

    {{-- 新增/編輯規章 Modal --}}
    <div class="modal fade" id="modal-regulation" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-regulation-title">{{ trans('project.regulation_add') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="form-regulation">
                        <input type="hidden" id="regulation-id">
                        <input type="hidden" id="regulation-project-id">
                        <div class="mb-3">
                            <label class="form-label">{{ trans('project.regulation_title') }} <span class="text-danger">*</span></label>
                            <input type="text" id="regulation-title" class="form-control" required
                                maxlength="{{ config('rules.REGULATION_TITLE_MAX') }}"
                                placeholder="{{ trans('project.regulation_title_placeholder') }}">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('project.regulation_content') }} <span class="text-danger">*</span></label>
                            <textarea id="regulation-content" class="form-control" rows="14" required
                                maxlength="{{ config('rules.REGULATION_CONTENT_MAX') }}"></textarea>
                            <small class="form-text text-muted">{{ trans('project.regulation_content_placeholder') }}</small>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">{{ trans('project.regulation_status') }}</label>
                                <select id="regulation-status" class="form-select">
                                    <option value="1">{{ trans('project.regulation_active') }}</option>
                                    <option value="0">{{ trans('project.regulation_disabled') }}</option>
                                </select>
                                <small class="form-text text-muted">{{ trans('project.regulation_status_hint') }}</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">{{ trans('project.regulation_sort') }}</label>
                                <input type="number" id="regulation-sort" class="form-control" min="0" max="9999" value="0">
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary" id="btn-regulation-save">儲存</button>
                </div>
            </div>
        </div>
    </div>

    {{-- 看規章內容 Modal（唯讀） --}}
    <div class="modal fade" id="modal-regulation-view" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-regulation-view-title"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    {{-- ⚠ 用 pre-wrap 保留換行 —— 規章是條列式的，壓成一段就看不懂了 --}}
                    <div id="modal-regulation-view-body" style="white-space:pre-wrap;font-size:0.9rem"></div>
                </div>
            </div>
        </div>
    </div>

    {{-- 新增/編輯專案 Modal --}}
    <div class="modal fade" id="modal-project" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-project-title">新增專案</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="form-project">
                        <input type="hidden" id="project-edit-id">
                        <div class="mb-3">
                            <label class="form-label">專案名稱 <span class="text-danger">*</span></label>
                            <input type="text" id="project-name" class="form-control" required maxlength="100">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">說明</label>
                            <textarea id="project-desc" class="form-control" rows="3" maxlength="500"></textarea>
                        </div>
                        <div class="mb-3" id="project-status-group" style="display:none">
                            <label class="form-label">狀態</label>
                            <select id="project-status" class="form-select">
                                <option value="1">啟用</option>
                                <option value="0">停用</option>
                            </select>
                        </div>
                        <div class="text-end">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                            <button type="submit" class="btn btn-primary">確認</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- 訊息 Modal --}}
    <div class="modal fade" id="modal-project-msg" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-body text-center py-4">
                    <p id="modal-project-msg-text" class="mb-3"></p>
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
                </div>
            </div>
        </div>
    </div>

@endsection

@section('scripts')
<script>
$(function () {
    var csrfToken = $('meta[name="csrf-token"]').attr('content');

    function showMsg(msg) {
        $('#modal-project-msg-text').text(msg);
        showBsModal('modal-project-msg');
    }

    // 新增
    $('#btn-add-project').on('click', function () {
        $('#modal-project-title').text('新增專案');
        $('#form-project')[0].reset();
        $('#project-edit-id').val('');
        $('#project-status-group').hide();
        showBsModal('modal-project');
    });

    // 編輯
    $(document).on('click', '.js-edit-project', function () {
        var $btn = $(this);
        $('#modal-project-title').text('編輯專案');
        $('#project-edit-id').val($btn.data('id'));
        $('#project-name').val($btn.data('name'));
        $('#project-desc').val($btn.data('desc'));
        $('#project-status').val($btn.data('status'));
        $('#project-status-group').show();
        showBsModal('modal-project');
    });

    // 送出
    $('#form-project').on('submit', function (e) {
        e.preventDefault();
        var id = $('#project-edit-id').val();
        var data = { name: $('#project-name').val().trim(), description: $('#project-desc').val().trim() };
        if (!data.name) return;
        if (id) { data.status = parseInt($('#project-status').val(), 10); }

        $.ajax({
            url: id ? '/admin/project/ajax-update/' + id : '/admin/project/ajax-store',
            method: id ? 'PUT' : 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            contentType: 'application/json',
            data: JSON.stringify(data),
            success: function (body) {
                hideBsModal(document.getElementById('modal-project'));
                setTimeout(function () { showMsg(body.message || '已儲存'); }, 400);
                document.getElementById('modal-project-msg').addEventListener('hidden.bs.modal', function handler() {
                    location.reload();
                    this.removeEventListener('hidden.bs.modal', handler);
                });
            },
            error: function (xhr) {
                showMsg((xhr.responseJSON && xhr.responseJSON.message) || '操作失敗');
            }
        });
    });

    // ===== 系統規章（需求方 2026-10-30）=====

    /** 存完要重新整理 —— 列表是 Blade 直接輸出的，沒有前端狀態可以更新 */
    function reloadAfterMsg(message) {
        showMsg(message);
        document.getElementById('modal-project-msg').addEventListener('hidden.bs.modal', function handler() {
            location.reload();
            this.removeEventListener('hidden.bs.modal', handler);
        });
    }

    $('.js-regulation-add').on('click', function () {
        $('#modal-regulation-title').text('{{ trans("project.regulation_add") }}');
        $('#regulation-id').val('');
        $('#regulation-project-id').val($(this).data('project'));
        $('#regulation-title').val('');
        $('#regulation-content').val('');
        $('#regulation-status').val('1');
        $('#regulation-sort').val('0');
        showBsModal('modal-regulation');
    });

    /*
     * ⚠ 編輯與檢視都要**先去撈內容**。列表那一份刻意沒帶 `content`
     * （規章可能上萬字，一次輸出十幾份會把頁面撐爆）。
     */
    function fetchRegulation(id, onDone) {
        $.ajax({
            url: '/admin/project/ajax-regulation/' + id,
            method: 'GET',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            success: function (body) { onDone(body.regulation); },
            error: function (xhr) {
                showMsg((xhr.responseJSON && xhr.responseJSON.message) || '操作失敗');
            }
        });
    }

    $('.js-regulation-view').on('click', function () {
        fetchRegulation($(this).data('id'), function (r) {
            $('#modal-regulation-view-title').text(r.title);
            // ⚠ 用 text() 不是 html()：規章是使用者輸入的，塞進 HTML 會變成 XSS
            $('#modal-regulation-view-body').text(r.content || '');
            showBsModal('modal-regulation-view');
        });
    });

    $('.js-regulation-edit').on('click', function () {
        fetchRegulation($(this).data('id'), function (r) {
            $('#modal-regulation-title').text('{{ trans("project.regulation_edit") }}');
            $('#regulation-id').val(r.id);
            $('#regulation-project-id').val(r.project_id);
            $('#regulation-title').val(r.title);
            $('#regulation-content').val(r.content || '');
            $('#regulation-status').val(String(r.status));
            $('#regulation-sort').val(r.sort);
            showBsModal('modal-regulation');
        });
    });

    $('#btn-regulation-save').on('click', function () {
        var id = $('#regulation-id').val();
        var data = {
            title: $('#regulation-title').val().trim(),
            content: $('#regulation-content').val(),
            status: parseInt($('#regulation-status').val(), 10),
            sort: parseInt($('#regulation-sort').val(), 10) || 0
        };

        if (!data.title || !data.content.trim()) {
            showMsg('{{ trans("project.regulation_title_required") }}');
            return;
        }

        // 新增才帶 project_id —— 更新不給改所屬專案（理由見 Service 的註解）
        if (!id) { data.project_id = parseInt($('#regulation-project-id').val(), 10); }

        var $btn = $(this);
        $btn.prop('disabled', true);

        $.ajax({
            url: id ? '/admin/project/ajax-regulation-update/' + id : '/admin/project/ajax-regulation-store',
            method: id ? 'PUT' : 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            contentType: 'application/json',
            data: JSON.stringify(data),
            success: function (body) {
                hideBsModal(document.getElementById('modal-regulation'));
                setTimeout(function () { reloadAfterMsg(body.message || '已儲存'); }, 400);
                $btn.prop('disabled', false);
            },
            error: function (xhr) {
                showMsg((xhr.responseJSON && xhr.responseJSON.message) || '操作失敗');
                $btn.prop('disabled', false);
            }
        });
    });

    /*
     * ⚠ 刪除用瀏覽器的 confirm。這個頁面沒有既有的確認視窗元件，
     * 而規章刪掉救不回來 —— 寧可用土法也不要少這一道。
     */
    $('.js-regulation-delete').on('click', function () {
        if (!window.confirm('{{ trans("project.regulation_delete_confirm") }}')) { return; }

        $.ajax({
            url: '/admin/project/ajax-regulation-destroy/' + $(this).data('id'),
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            success: function (body) {
                reloadAfterMsg(body.message || '已刪除');
            },
            error: function (xhr) {
                showMsg((xhr.responseJSON && xhr.responseJSON.message) || '操作失敗');
            }
        });
    });
});
</script>
@endsection
