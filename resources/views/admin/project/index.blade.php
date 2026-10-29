@extends('layouts.app')

@section('title', '專案管理')
@section('icon', 'folder-open')
@section('subtitle', '管理系統專案')

@section('content')

    @php
        /*
         * 「變更負責人」下拉的候選名單，壓成 `專案 id => [{id, name}]`。
         *
         * ⚠ 刻意**不直接 `@json($ownerCandidates)`**：那會把 User 整個序列化
         * 出去（含 `level`、`project_ids`），那些是後端判斷用的欄位，
         * 沒有理由出現在畫面原始碼裡。
         *
         * ⚠ PHP 7.4 沒有 arrow function，一律用 `function`。
         *
         * @var \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, array>>
         */
        $ownerOption = function ($candidate) {
            return [
                'id'   => (int) $candidate->id,
                'name' => "{$candidate->nickname}（{$candidate->account}）",
            ];
        };

        $ownerCandidateMap = $ownerCandidates->map(function ($candidates) use ($ownerOption) {
            return $candidates->map($ownerOption)->values();
        });

        /*
         * 「新增專案」那個下拉的名單 —— 全部啟用帳號，壓成同一個形狀。
         *
         * @var \Illuminate\Support\Collection<int, array>
         */
        $ownerAssignableList = $ownerAssignable->map($ownerOption)->values();
    @endphp

    {{-- 權限與語系由 data-* 帶給 public/js/project.js（照 shared-file 的做法）--}}
    <div id="project-app"
         {{-- JSON_HEX_APOS：屬性用單引號包，JSON 內的單引號會提早結束屬性 --}}
         data-i18n='@json(trans("project"), JSON_HEX_APOS | JSON_HEX_QUOT)'
         {{-- 「變更負責人」的候選名單，**逐專案一份**（只有參與該專案的人）。
              ⚠ 一次全部帶給前端，不要按一次按鈕打一次 API ——
              專案數量是個位數，而名單是開 modal 當下就要有的東西。
              ⚠ 這裡**只帶 id 與顯示名稱**：候選名單撈的 User 還有 `level`
              與 `project_ids`，那是後端判斷用的，沒必要吐到畫面原始碼裡。 --}}
         data-owner-candidates='@json($ownerCandidateMap, JSON_HEX_APOS | JSON_HEX_QUOT)'
         {{-- 「新增專案」的候選名單，**全部啟用帳號**（那一刻專案還不存在，
              沒有人勾得了參與它）。⚠ 跟上面那一份不可以共用，兩者的差別與
              後續補救見 .claude/knowledge/features/project-owner.md --}}
         data-owner-assignable='@json($ownerAssignableList, JSON_HEX_APOS | JSON_HEX_QUOT)'
         {{-- ⚠ 只帶 `project.assign_owner`：`project.edit` 的按鈕與 modal 是
              Blade 用 `@if` 直接決定要不要輸出，JS 不需要再知道一次 --}}
         data-can-assign-owner="{{ Auth::user()->hasPermission('project.assign_owner') ? '1' : '0' }}">

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
                                {{-- ============ 負責人（需求方 2026-10-30）============ --}}
                                {{-- ⚠ 刻意插在名稱之後、**建立者之前**：擺在建立者旁邊的話
                                     兩個都是人名，一眼分不出哪個才是負責人 ——
                                     而「建立者」恰好是現在最常被誤讀成負責人的那一欄 --}}
                                @php($ownerIssue = $project->owner_issue)
                                <div class="d-flex justify-content-between align-items-start mb-2" style="font-size:0.875rem">
                                    <span class="text-muted">{{ trans('project.owner_label') }}</span>
                                    <span class="text-end">
                                        @if(filled($project->owner))
                                            <strong><i class="fas fa-user-tie me-1 text-muted"></i>{{ $project->owner->nickname }}</strong>
                                            @if(filled($ownerIssue))
                                                {{-- ⚠ 三種原因要分開講：離職／停用要換人，沒參與是去帳號管理補勾 --}}
                                                <span class="text-danger d-block" style="font-size:0.8rem">
                                                    <i class="fas fa-triangle-exclamation me-1"></i>{{ trans("project.owner_issue_{$ownerIssue}") }}
                                                </span>
                                            @endif
                                        @else
                                            {{-- ⚠ 用橘色 badge 不要用「-」：「-」看起來像沒這個欄位 --}}
                                            <span class="badge bg-warning text-dark">{{ trans('project.owner_unassigned') }}</span>
                                        @endif
                                        @if(Auth::user()->hasPermission('project.assign_owner'))
                                            <button class="btn btn-link btn-sm p-0 ms-2 align-baseline js-owner-change"
                                                data-project="{{ $project->id }}"
                                                data-owner="{{ $project->owner_id }}"
                                                data-owner-name="{{ filled($project->owner) ? $project->owner->nickname : '' }}"
                                                data-issue="{{ filled($ownerIssue) ? trans("project.owner_issue_{$ownerIssue}") : '' }}"
                                                style="font-size:0.8rem">{{ trans('project.owner_change') }}</button>
                                        @endif
                                    </span>
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
                                    <span>{{ filled($project->creator) ? $project->creator->nickname : '-' }}</span>
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
                            {{-- ⚠ 負責人那三個 data-* 是給編輯視窗的下拉用的：
                                 要選回現任那位，而他萬一已離職／停用／沒參與，
                                 視窗上方還要標出來（不然按確認就清空了）--}}
                            <button class="btn btn-sm btn-outline-secondary js-edit-project"
                                data-id="{{ $project->id }}"
                                data-name="{{ $project->name }}"
                                data-desc="{{ $project->description }}"
                                data-status="{{ $project->status }}"
                                data-owner="{{ $project->owner_id }}"
                                data-owner-name="{{ filled($project->owner) ? $project->owner->nickname : '' }}"
                                data-issue="{{ filled($ownerIssue) ? trans("project.owner_issue_{$ownerIssue}") : '' }}">
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
                                                        {{-- ⚠ `data-title` 是給 modal **先開起來**用的：內容要去撈
                                                             （列表刻意沒帶 `content`），但標題這裡就有 ——
                                                             先把標題擺上去，使用者按下去就看得到視窗 --}}
                                                        <button class="btn btn-outline-secondary btn-icon btn-transition js-regulation-view" data-id="{{ $item->id }}" data-title="{{ $item->title }}">
                                                            <i class="fas fa-eye btn-icon-wrapper"></i>{{ trans('project.regulation_view') }}
                                                        </button>
                                                    </div>
                                                    @if(Auth::user()->hasPermission('project.edit'))
                                                    <div class="btn-group btn-group-sm" role="group" aria-label="{{ trans('common.row_actions.manage') }}">
                                                        {{-- ⚠ 除了 `content` 以外的欄位列表都有 —— 全部帶上來，
                                                             modal 就能立刻開並填好，只有內容那一格在等 --}}
                                                        <button class="btn btn-outline-secondary btn-icon btn-transition js-regulation-edit"
                                                            data-id="{{ $item->id }}"
                                                            data-title="{{ $item->title }}"
                                                            data-project="{{ $item->project_id }}"
                                                            data-status="{{ $item->status }}"
                                                            data-sort="{{ $item->sort }}">
                                                            <i class="fas fa-edit btn-icon-wrapper"></i>{{ trans('project.regulation_edit') }}
                                                        </button>
                                                    </div>
                                                    <div class="btn-group btn-group-sm" role="group" aria-label="{{ trans('common.row_actions.danger') }}">
                                                        <button class="btn btn-outline-secondary btn-icon btn-transition js-regulation-delete" data-id="{{ $item->id }}" data-title="{{ $item->title }}">
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
                        @if(Auth::user()->hasPermission('project.assign_owner'))
                        {{-- 負責人：**新增與編輯都在這裡改**。
                             ⚠ 兩種模式的候選名單不一樣（新增＝全部啟用帳號，
                             編輯＝只有參與這個專案的人），所以選項由 JS 填，
                             不在 Blade 寫死。理由見
                             .claude/knowledge/features/project-owner.md --}}
                        <div class="mb-3" id="project-owner-group">
                            <label class="form-label">{{ trans('project.owner_label') }}</label>
                            {{-- ⚠ 現任負責人已離職／停用／沒參與時，下拉會落在「未指派」——
                                 這條警示要講明現況，不然按下確認就把負責人清空了 --}}
                            <div class="alert alert-danger py-2 d-none mb-2" id="project-owner-issue" style="font-size:0.85rem"></div>
                            <select id="project-owner" class="form-select"></select>
                            <small class="text-muted d-block mt-1" id="project-owner-hint"></small>
                        </div>
                        @endif
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

    @if(Auth::user()->hasPermission('project.edit'))
    {{-- 刪除規章確認 Modal --}}
    {{-- ⚠ **不要用 `window.confirm`**：這個系統所有對使用者的回饋一律走 modal ——
         瀏覽器原生的 confirm 樣式不受控、套不到深色模式，而且在部分環境會被擋掉
         （被擋掉時 `confirm()` 直接回 false，刪除會安靜地不發生）。
         做法照 `task-board` 既有的確認視窗。 --}}
    <div class="modal fade" id="modal-regulation-delete" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-body text-center py-4">
                    <p class="mb-1">{{ trans('project.regulation_delete_confirm') }}</p>
                    {{-- ⚠ 把規章標題顯示出來：一個專案可以有好幾份，
                         只問「確定刪除嗎」的話刪錯了也不會知道 --}}
                    <p class="text-muted mb-3" style="font-size:0.8125rem;word-break:break-all" id="regulation-delete-title"></p>
                    <input type="hidden" id="regulation-delete-id">
                    <div class="d-flex justify-content-center gap-2">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                        <button type="button" class="btn btn-danger" id="btn-regulation-delete-ok">{{ trans('project.regulation_delete') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

    @if(Auth::user()->hasPermission('project.assign_owner'))
    {{-- 變更負責人 Modal（只有負責人一個欄位，需求方 2026-10-30）--}}
    <div class="modal fade" id="modal-owner" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ trans('project.owner_change_title') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="owner-project-id">
                    {{-- ⚠ 現任負責人不在候選名單裡（離職／停用／沒參與）時，下拉會落在
                         「未指派」—— 這一條警示是在講「您現在看到的不是沒改，是現任
                         負責人已經不能用了」，少了它直接按確認就把負責人清空了 --}}
                    <div class="alert alert-danger py-2 d-none" id="owner-issue-alert" style="font-size:0.85rem"></div>
                    <div class="mb-3">
                        <label class="form-label">{{ trans('project.owner_label') }}</label>
                        {{-- 選項由 JS 依專案填入（每個專案的候選名單不同）--}}
                        <select id="owner-select" class="form-select"></select>
                        <small class="text-muted d-block mt-1" id="owner-select-hint">{{ trans('project.owner_select_hint') }}</small>
                    </div>
                    <div class="text-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                        <button type="button" class="btn btn-primary" id="btn-owner-save">確認</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

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

    </div>{{-- /#project-app --}}

@endsection

@section('scripts')
    {{-- ⚠ `?v=filemtime`：少了版本號瀏覽器會一直用舊的快取檔 ——
         2026-10-30 點數報表就是漏了這一段，程式改好了但畫面沒變 --}}
    <script src="{{ asset('js/project.js') }}?v={{ filemtime(public_path('js/project.js')) }}"></script>
@endsection
