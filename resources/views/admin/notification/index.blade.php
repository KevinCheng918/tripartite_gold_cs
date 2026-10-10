@extends('layouts.app')

@section('title', trans('notification.page_title'))
@section('icon', 'bell')
@section('subtitle', trans('notification.subtitle'))

@section('content')

    {{-- 五個分頁的資料隨頁面一起送出，切分頁不必再等 ajax --}}
    {{-- ⚠️ data 屬性用單引號包，JSON 內的單引號會提早結束屬性、讓前端解析失敗，
         所以一律用 JSON_HEX_APOS 轉義 --}}
    <div id="notification-app"
         data-i18n='@json(trans("notification"), JSON_HEX_APOS | JSON_HEX_QUOT)'
         data-initial='@json($initialSettings, JSON_HEX_APOS | JSON_HEX_QUOT)'
         data-can-manage="{{ Auth::user()->hasPermission('notification.manage') ? '1' : '0' }}">

        <ul class="nav nav-tabs mb-3" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" data-bs-toggle="tab" href="#tab-group" role="tab">
                    <i class="fas fa-comment-dots me-1"></i>{{ trans('notification.tab_group') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#tab-remind" role="tab">
                    <i class="fas fa-bell me-1"></i>{{ trans('notification.tab_remind') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#tab-shift" role="tab">
                    <i class="fas fa-calendar-alt me-1"></i>{{ trans('notification.tab_shift') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#tab-report" role="tab">
                    <i class="fas fa-chart-bar me-1"></i>{{ trans('notification.tab_report') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#tab-task" role="tab">
                    <i class="fas fa-columns me-1"></i>{{ trans('notification.tab_task') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#tab-attendance" role="tab">
                    <i class="fas fa-user-clock me-1"></i>{{ trans('notification.tab_attendance') }}
                </a>
            </li>
        </ul>

        <div class="tab-content">

            {{-- ============ 分頁一：支援群組與話題分流 ============ --}}
            <div class="tab-pane fade show active" id="tab-group" role="tabpanel">
                <div class="main-card mb-3 card">
                    <div class="card-body">
                        <h5 class="card-title">{{ trans('notification.support_title') }}</h5>
                        <p class="text-muted" style="font-size:0.875rem">{{ trans('notification.support_desc') }}</p>
                        <form id="form-group">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="support-chat-id">{{ trans('notification.support_chat_id') }}</label>
                                    <input type="text" class="form-control" id="support-chat-id" placeholder="-1001234567890">
                                    <small class="form-text text-muted">{{ trans('notification.support_chat_id_hint') }}</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="support-system">{{ trans('notification.support_system') }}</label>
                                    <select class="form-select" id="support-system"></select>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary js-manage-only">{{ trans('notification.action_save') }}</button>
                            <button type="button" class="btn btn-outline-secondary js-manage-only" id="btn-test-support">{{ trans('notification.support_test') }}</button>
                        </form>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-8">
                        <div class="main-card mb-3 card">
                            <div class="card-body">
                                <h5 class="card-title">{{ trans('notification.topic_title') }}</h5>
                                <p class="text-muted" style="font-size:0.875rem">{{ trans('notification.topic_desc') }}</p>

                                <form id="form-topic">
                                    <div id="topic-list"></div>

                                    <button type="button" class="btn btn-outline-primary btn-sm js-manage-only mb-3" id="btn-add-topic">
                                        <i class="fas fa-plus me-1"></i>{{ trans('notification.topic_add') }}
                                    </button>

                                    <div>
                                        <button type="submit" class="btn btn-primary js-manage-only">{{ trans('notification.action_save') }}</button>
                                        <button type="button" class="btn btn-outline-secondary js-manage-only" id="btn-test-topic">{{ trans('notification.topic_test') }}</button>
                                    </div>
                                    <div class="form-text mt-2">{{ trans('notification.topic_test_hint') }}</div>
                                </form>
                            </div>
                        </div>
                    </div>

                    {{-- 怎麼取得話題 id。這是這一頁最常被問的事，所以常駐在旁邊 --}}
                    <div class="col-lg-4">
                        <div class="main-card mb-3 card">
                            <div class="card-body">
                                <h5 class="card-title">
                                    <i class="fas fa-info-circle me-1"></i>{{ trans('notification.topic_howto_title') }}
                                </h5>
                                <ol class="mb-2 ps-3" style="font-size:0.875rem;line-height:1.9">
                                    <li>{{ trans('notification.topic_howto_1') }}</li>
                                    <li>
                                        {{ trans('notification.topic_howto_2') }}
                                        <code>{{ config('constants.SUPPORT_TOPIC.ID_COMMAND') }}</code>
                                    </li>
                                    <li>{{ trans('notification.topic_howto_3') }}</li>
                                </ol>
                                <p class="mb-0 text-danger" style="font-size:0.875rem">
                                    <i class="fas fa-exclamation-triangle me-1"></i>{{ trans('notification.topic_howto_note') }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ============ 分頁二：求助單提醒 ============ --}}
            <div class="tab-pane fade" id="tab-remind" role="tabpanel">
                <div class="main-card mb-3 card">
                    <div class="card-body">
                        <h5 class="card-title">{{ trans('notification.remind_title') }}</h5>
                        <p class="text-muted" style="font-size:0.875rem">
                            {{ trans('notification.remind_desc', ['escalate' => config('constants.AUTO_REPLY.REMIND.ESCALATE_AT')]) }}
                        </p>
                        <form id="form-remind">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="support-remind-first">{{ trans('notification.remind_first') }}</label>
                                    <input type="number" class="form-control" id="support-remind-first" min="1" max="1440" step="1">
                                    <small class="form-text text-muted">{{ trans('notification.remind_first_hint') }}</small>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="support-remind-interval">{{ trans('notification.remind_interval') }}</label>
                                    <input type="number" class="form-control" id="support-remind-interval" min="1" max="1440" step="1">
                                    <small class="form-text text-muted">
                                        {{ trans('notification.remind_interval_hint', ['escalate' => config('constants.AUTO_REPLY.REMIND.ESCALATE_AT')]) }}
                                    </small>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="support-remind-max">{{ trans('notification.remind_max') }}</label>
                                    <input type="number" class="form-control" id="support-remind-max" min="1" max="200" step="1">
                                    <small class="form-text text-muted">{{ trans('notification.remind_max_hint') }}</small>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary js-manage-only">{{ trans('notification.action_save') }}</button>
                        </form>
                    </div>
                </div>
            </div>

            {{-- ============ 分頁三：班表通知 ============ --}}
            <div class="tab-pane fade" id="tab-shift" role="tabpanel">
                <div class="row">
                    <div class="col-lg-7">
                        <div class="main-card mb-3 card">
                            <div class="card-body">
                                <h5 class="card-title">{{ trans('notification.shift_title') }}</h5>
                                <p class="text-muted" style="font-size:0.875rem">
                                    {{ trans('notification.shift_desc', ['time' => config('constants.SHIFT_NOTICE.SEND_AT')]) }}
                                </p>
                                <form id="form-shift">
                                    <div class="mb-3">
                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                            <label class="form-label mb-0">{{ trans('notification.shift_user') }}</label>
                                            <div class="form-check mb-0">
                                                <input class="form-check-input" type="checkbox" id="shift-user-all">
                                                <label class="form-check-label" for="shift-user-all">{{ trans('notification.select_all') }}</label>
                                            </div>
                                        </div>
                                        <div id="shift-user-list" class="notice-check-list p-2">
                                        </div>
                                        <small class="form-text text-muted">{{ trans('notification.shift_user_hint') }}</small>
                                    </div>
                                    <button type="submit" class="btn btn-primary js-manage-only">{{ trans('notification.action_save') }}</button>
                                    <button type="button" class="btn btn-outline-secondary js-manage-only" id="btn-test-shift">{{ trans('notification.shift_test') }}</button>
                                    <div class="form-text mt-2">{{ trans('notification.shift_test_hint') }}</div>
                                </form>
                            </div>
                        </div>

                        {{-- 個人班表（純說明，沒有東西要設定） --}}
                        <div class="main-card mb-3 card">
                            <div class="card-body">
                                <h5 class="card-title">{{ trans('notification.shift_personal_title') }}</h5>
                                <p class="text-muted mb-0" style="font-size:0.875rem">
                                    {{ trans('notification.shift_personal_desc', ['time' => config('constants.SHIFT_NOTICE.SEND_AT')]) }}
                                </p>
                            </div>
                        </div>
                    </div>

                    {{-- 綁定說明。「為什麼他沒收到」是這一頁最常被問的事 --}}
                    <div class="col-lg-5">
                        <div class="main-card mb-3 card">
                            <div class="card-body">
                                <h5 class="card-title">
                                    <i class="fas fa-info-circle me-1"></i>{{ trans('notification.bind_title') }}
                                </h5>
                                <ol class="mb-2 ps-3" style="font-size:0.875rem;line-height:1.9">
                                    <li>{{ trans('notification.bind_step1') }}</li>
                                    <li>{{ trans('notification.bind_step2') }}</li>
                                    <li>{{ trans('notification.bind_step3') }}</li>
                                </ol>
                                <p class="mb-0 text-danger" style="font-size:0.875rem">
                                    <i class="fas fa-exclamation-triangle me-1"></i>{{ trans('notification.bind_note') }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>


            {{-- ============ 分頁四：超時提醒統計 ============ --}}
            <div class="tab-pane fade" id="tab-report" role="tabpanel">
                <div class="row">
                    <div class="col-lg-7">
                        <div class="main-card mb-3 card">
                            <div class="card-body">
                                <h5 class="card-title">{{ trans('notification.report_title') }}</h5>
                                <p class="text-muted" style="font-size:0.875rem">
                                    {{ trans('notification.report_desc', ['time' => config('constants.AUTO_REPLY.REMIND.REPORT_AT')]) }}
                                </p>
                                {{-- 週報與月報用同一份收件人、同一個時間，這裡不講的話
                                     勾選的人不會知道自己星期一還會再收到一則 --}}
                                <p class="text-muted" style="font-size:0.875rem">
                                    <i class="fas fa-info-circle me-1"></i>{{ trans('notification.report_period', ['time' => config('constants.AUTO_REPLY.REMIND.REPORT_AT')]) }}
                                </p>
                                <form id="form-report">
                                    <div class="mb-3">
                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                            <label class="form-label mb-0">{{ trans('notification.report_user') }}</label>
                                            <div class="form-check mb-0">
                                                <input class="form-check-input" type="checkbox" id="support-report-all">
                                                <label class="form-check-label" for="support-report-all">{{ trans('notification.select_all') }}</label>
                                            </div>
                                        </div>
                                        {{-- 人多的時候不要把整頁撐長，超過就在框內捲動 --}}
                                        <div id="support-report-list" class="notice-check-list p-2">
                                        </div>
                                        <small class="form-text text-muted">{{ trans('notification.report_user_hint') }}</small>
                                    </div>
                                    <button type="submit" class="btn btn-primary js-manage-only">{{ trans('notification.action_save') }}</button>
                                    <button type="button" class="btn btn-outline-secondary js-manage-only" id="btn-test-report">{{ trans('notification.report_test') }}</button>
                                    <div class="form-text mt-2">{{ trans('notification.report_test_hint') }}</div>
                                </form>
                            </div>
                        </div>
                    </div>

                    {{-- 個人版（純說明，沒有東西要設定） --}}
                    <div class="col-lg-5">
                        <div class="main-card mb-3 card">
                            <div class="card-body">
                                <h5 class="card-title">{{ trans('notification.report_personal_title') }}</h5>
                                <p class="text-muted mb-0" style="font-size:0.875rem">
                                    {{ trans('notification.report_personal_desc', ['time' => config('constants.AUTO_REPLY.REMIND.REPORT_AT')]) }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>


            {{-- ============ 分頁五：任務卡通知 ============ --}}
            <div class="tab-pane fade" id="tab-task" role="tabpanel">
                <div class="row">
                    <div class="col-lg-7">
                        <div class="main-card mb-3 card">
                            <div class="card-body">
                                <h5 class="card-title">{{ trans('notification.task_title') }}</h5>
                                <p class="text-muted" style="font-size:0.875rem">
                                    {{ trans('notification.task_desc', ['time' => config('constants.TASK_NOTICE.SEND_AT')]) }}
                                </p>
                                <form id="form-task">
                                    <div class="mb-3">
                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                            <label class="form-label mb-0">{{ trans('notification.task_user') }}</label>
                                            <div class="form-check mb-0">
                                                <input class="form-check-input" type="checkbox" id="task-user-all">
                                                <label class="form-check-label" for="task-user-all">{{ trans('notification.select_all') }}</label>
                                            </div>
                                        </div>
                                        <div id="task-user-list" class="notice-check-list p-2"></div>
                                        <small class="form-text text-muted">{{ trans('notification.task_user_hint') }}</small>
                                    </div>
                                    <button type="submit" class="btn btn-primary js-manage-only">{{ trans('notification.action_save') }}</button>
                                    <button type="button" class="btn btn-outline-secondary js-manage-only" id="btn-test-task">{{ trans('notification.task_test') }}</button>
                                    <div class="form-text mt-2">{{ trans('notification.task_test_hint') }}</div>
                                </form>
                            </div>
                        </div>
                    </div>

                    {{-- 個人版（純說明，沒有東西要設定） --}}
                    <div class="col-lg-5">
                        <div class="main-card mb-3 card">
                            <div class="card-body">
                                <h5 class="card-title">{{ trans('notification.task_personal_title') }}</h5>
                                <p class="text-muted" style="font-size:0.875rem">
                                    {{ trans('notification.task_personal_desc', ['time' => config('constants.TASK_NOTICE.SEND_AT')]) }}
                                </p>
                                <p class="mb-0 text-muted" style="font-size:0.875rem">
                                    <i class="fas fa-info-circle me-1"></i>{{ trans('notification.task_count_note') }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- 打卡報表：週報與月報共用同一份收件人 --}}
            <div class="tab-pane fade" id="tab-attendance" role="tabpanel">
                <div class="row">
                    <div class="col-lg-7">
                        <div class="main-card mb-3 card">
                            <div class="card-body">
                                <h5 class="card-title">{{ trans('notification.attendance_title') }}</h5>
                                <p class="text-muted" style="font-size:0.875rem">
                                    {{ trans('notification.attendance_desc', [
                                        'weekly'  => config('constants.ATTENDANCE_REPORT.WEEKLY_SEND_AT'),
                                        'monthly' => config('constants.ATTENDANCE_REPORT.MONTHLY_SEND_AT'),
                                    ]) }}
                                </p>
                                <form id="form-attendance">
                                    <div class="mb-3">
                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                            <label class="form-label mb-0">{{ trans('notification.attendance_user') }}</label>
                                            <div class="form-check mb-0">
                                                <input class="form-check-input" type="checkbox" id="attendance-user-all">
                                                <label class="form-check-label" for="attendance-user-all">{{ trans('notification.select_all') }}</label>
                                            </div>
                                        </div>
                                        <div id="attendance-user-list" class="notice-check-list p-2"></div>
                                        <small class="form-text text-muted">{{ trans('notification.attendance_user_hint') }}</small>
                                    </div>
                                    <button type="submit" class="btn btn-primary js-manage-only">{{ trans('notification.action_save') }}</button>
                                    <button type="button" class="btn btn-outline-secondary js-manage-only" id="btn-test-attendance">{{ trans('notification.attendance_test') }}</button>
                                    <div class="form-text mt-2">{{ trans('notification.attendance_test_hint') }}</div>
                                </form>
                            </div>
                        </div>
                    </div>

                    {{-- 個人版（純說明，沒有東西要設定） --}}
                    <div class="col-lg-5">
                        <div class="main-card mb-3 card">
                            <div class="card-body">
                                <h5 class="card-title">{{ trans('notification.attendance_personal_title') }}</h5>
                                <p class="text-muted" style="font-size:0.875rem">
                                    {{ trans('notification.attendance_personal_desc') }}
                                </p>
                                <p class="mb-0 text-muted" style="font-size:0.875rem">
                                    <i class="fas fa-info-circle me-1"></i>{{ trans('notification.attendance_leave_note') }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    {{-- 訊息 Modal（不用 alert） --}}
    <div class="modal fade" id="modal-notification-msg" tabindex="-1">
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
    <script src="{{ asset('js/notification-setting.js') }}?v={{ filemtime(public_path('js/notification-setting.js')) }}"></script>
@endsection
