@extends('layouts.app')

@section('title', trans('attendance.page_title'))
@section('icon', 'clock')
@section('subtitle', trans('attendance.subtitle'))

@section('content')

    <div id="attendance-app"
         {{-- JSON_HEX_APOS：屬性用單引號包，JSON 內的單引號（英文縮寫如 don't）會提早結束屬性 --}}
         data-i18n='@json(trans("attendance"), JSON_HEX_APOS | JSON_HEX_QUOT)'
         data-user-id="{{ Auth::id() }}"
         data-is-admin="{{ Auth::user()->isAdmin() ? '1' : '0' }}"
         data-permissions='@json(Auth::user()->isAdmin() ? ["all"] : Auth::user()->permissions()->pluck("permission_keyword")->all(), JSON_HEX_APOS | JSON_HEX_QUOT)'>
        <p>Loading…</p>
    </div>

    {{-- 打卡二次確認 Modal --}}
    @component('components.modal', ['id' => 'modal-attendance-confirm', 'title' => ''])
        <div id="modal-attendance-confirm-body"></div>
        <div class="text-end mt-3">
            <button type="button" data-bs-dismiss="modal" class="btn btn-secondary">取消</button>
            <button type="button" id="btn-confirm-clock" class="btn btn-primary">確認</button>
        </div>
    @endcomponent

    {{-- 補打卡申請 Modal --}}
    @if(Auth::user()->hasPermission('attendance.amend'))
    <div class="modal fade" id="modal-amend" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ trans('attendance.amend_title') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="form-amend">
                        <div class="mb-3">
                            <label class="form-label">{{ trans('attendance.amend_field_date') }}</label>
                            {{-- 原生 date/time：手機上會跳出系統的滾輪選擇器，好按得多，
                                 而且桌機可以直接打字。值的格式固定是 YYYY-MM-DD / HH:mm，
                                 正好是後端 date_format:H:i 要的，不必再轉。 --}}
                            <input id="amend-date" type="date" class="form-control" required autocomplete="off">
                            {{-- 晚班下班是隔天凌晨，選哪一天不直覺。後端會自己往前找到
                                 對應的班次（見 ClockAmendmentService::applyAmendment()），
                                 但先講清楚可以少一次來回。
                                 直接舉晚班的例子 —— 那是唯一會搞混的情況 --}}
                            <div class="form-text">
                                {{ trans('attendance.amend_date_hint') }}
                                <div class="mt-1 ps-2 border-start">
                                    {{ trans('attendance.amend_date_example_title') }}<br>
                                    {{ trans('attendance.amend_date_example_in') }}<br>
                                    {{ trans('attendance.amend_date_example_out') }}
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('attendance.amend_field_type') }}</label>
                            <select id="amend-type" class="form-select" required>
                                <option value="1">{{ trans('attendance.amend_type_in') }}</option>
                                <option value="2">{{ trans('attendance.amend_type_out') }}</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('attendance.amend_field_time') }}</label>
                            {{-- js-time-select：由 public/js/common.js 轉成「時」「分」兩個下拉。
                                 原生 <input type="time"> 顯示 12 還是 24 小時制是看瀏覽器／系統的
                                 locale，網頁端控制不了（掛 lang="en-GB" 也沒用，Chromium 不吃），
                                 客服會看到「下午 01:05」而跟系統其他地方的 13:05 對不起來。
                                 這個 input 會變成 hidden，id 與 value（HH:mm）都維持原樣。 --}}
                            <input id="amend-time" type="text" class="js-time-select" required autocomplete="off">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ trans('attendance.amend_field_reason') }}</label>
                            {{-- 必填：補打卡等於事後修改出勤紀錄，主管要據此核准 --}}
                            <textarea id="amend-reason" class="form-control" rows="2" required
                                      maxlength="500"
                                      placeholder="{{ trans('attendance.amend_reason_placeholder') }}"></textarea>
                        </div>

                        {{-- 本月已補幾次。資料來自「我的補打卡申請」那支 ajax（已經載好了），
                             不必為了這個數字再打一次後端。都是 0 就不顯示 --}}
                        <div id="amend-month-hint" class="alert alert-warning py-2 px-3 mb-3"
                             style="font-size:0.8125rem;display:none"></div>
                        <div class="text-end">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                            <button type="submit" class="btn btn-primary">送出申請</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- 訊息提示 Modal --}}
    @component('components.modal', ['id' => 'modal-attendance-message', 'title' => ''])
        <p id="modal-attendance-message-text"></p>
        <div class="text-end mt-3">
            <button type="button" data-bs-dismiss="modal" class="btn-primary">OK</button>
        </div>
    @endcomponent

@endsection

@section('scripts')
    <script src="{{ asset('js/attendance.js') }}?v={{ filemtime(public_path('js/attendance.js')) }}"></script>
@endsection
