@extends('layouts.app')

@section('title', trans('setting.page_title'))
@section('icon', 'cog')
@section('subtitle', trans('setting.subtitle'))

@section('content')

    {{-- 初始資料隨頁面送出，避免載入時先空一拍。憑證只有遮罩，明文不在這裡 --}}
    {{-- ⚠️ data 屬性用單引號包，JSON 內的單引號（英文的 person's 之類）會提早結束屬性、
         讓整份 JSON 在前端解析失敗，所以一律用 JSON_HEX_APOS 轉義 --}}
    <div id="setting-app"
         data-i18n='@json(trans("setting"), JSON_HEX_APOS | JSON_HEX_QUOT)'
         data-initial='@json($initialSettings, JSON_HEX_APOS | JSON_HEX_QUOT)'
         data-can-manage="{{ Auth::user()->hasPermission('setting.manage') ? '1' : '0' }}">

        <div class="row">
            {{-- Claude 主要設定 --}}
            <div class="col-lg-6">
                <div class="main-card mb-3 card">
                    <div class="card-body">
                        <h5 class="card-title">{{ trans('setting.claude_title') }}</h5>
                        <form id="form-claude">
                            <div class="mb-3">
                                <label class="form-label" for="claude-token">{{ trans('setting.claude_token') }}</label>
                                <input type="password" class="form-control" id="claude-token" autocomplete="new-password" placeholder="">
                                <small class="form-text text-muted">{{ trans('setting.claude_token_hint') }}</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="claude-model">{{ trans('setting.claude_model') }}</label>
                                <select class="form-select" id="claude-model"></select>
                            </div>
                            <div class="mb-3 text-muted" style="font-size:0.875rem">
                                {{ trans('setting.claude_verified_at') }}：<span id="claude-verified-at">-</span>
                            </div>
                            <button type="submit" class="btn btn-primary js-manage-only">{{ trans('setting.action_save') }}</button>
                        </form>

                        {{-- 取得流程。指令本身不進語系檔（不需要翻譯，也不該被翻壞） --}}
                        <div class="mt-3">
                            <a class="d-inline-block" data-bs-toggle="collapse" href="#howto-claude" role="button"
                               style="cursor:pointer;font-size:0.875rem">
                                <i class="fas fa-question-circle me-1"></i>{{ trans('setting.claude_howto_title') }}
                            </a>
                            <div class="collapse mt-2" id="howto-claude">
                                <div class="p-3" style="background:rgba(0,0,0,0.03);border-radius:6px;font-size:0.875rem">
                                    <p class="mb-1">{{ trans('setting.claude_howto_install') }}</p>
                                    <pre class="mb-2 p-2" style="background:#fff;border-radius:4px;overflow-x:auto"><code>curl -fsSL https://claude.ai/install.sh | bash

brew install --cask claude-code

npm install -g @anthropic-ai/claude-code</code></pre>

                                    <p class="mb-2">{{ trans('setting.claude_howto_login') }}</p>

                                    <p class="mb-1">{{ trans('setting.claude_howto_token') }}</p>
                                    <pre class="mb-2 p-2" style="background:#fff;border-radius:4px;overflow-x:auto"><code>claude setup-token</code></pre>

                                    <p class="mb-2">{{ trans('setting.claude_howto_paste') }}</p>
                                    <p class="mb-0 text-danger">
                                        <i class="fas fa-exclamation-triangle me-1"></i>{{ trans('setting.claude_howto_note') }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- 備援 API --}}
            <div class="col-lg-6">
                <div class="main-card mb-3 card">
                    <div class="card-body">
                        <h5 class="card-title">{{ trans('setting.fallback_title') }}</h5>
                        <p class="text-muted" style="font-size:0.875rem">{{ trans('setting.fallback_desc') }}</p>
                        <form id="form-fallback">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="fallback-enabled">
                                <label class="form-check-label" for="fallback-enabled">{{ trans('setting.fallback_enabled') }}</label>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="fallback-api-key">{{ trans('setting.fallback_api_key') }}</label>
                                <input type="password" class="form-control" id="fallback-api-key" autocomplete="new-password">
                                <small class="form-text text-muted">{{ trans('setting.fallback_api_key_hint') }}</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="fallback-model">{{ trans('setting.fallback_model') }}</label>
                                <select class="form-select" id="fallback-model"></select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="fallback-daily-limit">{{ trans('setting.fallback_daily_limit') }}</label>
                                <input type="number" class="form-control" id="fallback-daily-limit" min="0" step="1">
                                <small class="form-text text-muted">{{ trans('setting.fallback_daily_limit_hint') }}</small>
                                <small class="form-text text-muted d-block">
                                    {{ trans('setting.fallback_used_today') }}：<span id="fallback-used-today">0</span>
                                </small>
                            </div>
                            <button type="submit" class="btn btn-primary js-manage-only">{{ trans('setting.action_save') }}</button>
                        </form>

                        {{-- 取得流程 --}}
                        <div class="mt-3">
                            <a class="d-inline-block" data-bs-toggle="collapse" href="#howto-fallback" role="button"
                               style="cursor:pointer;font-size:0.875rem">
                                <i class="fas fa-question-circle me-1"></i>{{ trans('setting.fallback_howto_title') }}
                            </a>
                            <div class="collapse mt-2" id="howto-fallback">
                                <div class="p-3" style="background:rgba(0,0,0,0.03);border-radius:6px;font-size:0.875rem">
                                    <p class="mb-1">
                                        {{ trans('setting.fallback_howto_open') }}
                                        <a href="https://platform.claude.com" target="_blank" rel="noopener noreferrer">platform.claude.com</a>
                                    </p>
                                    <p class="mb-2">{{ trans('setting.fallback_howto_create') }}</p>
                                    <p class="mb-2">{{ trans('setting.fallback_howto_paste') }}</p>
                                    <p class="mb-0 text-danger">
                                        <i class="fas fa-exclamation-triangle me-1"></i>{{ trans('setting.fallback_howto_note') }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- 內部支援群組 --}}
        <div class="main-card mb-3 card">
            <div class="card-body">
                <h5 class="card-title">{{ trans('setting.support_title') }}</h5>
                <p class="text-muted" style="font-size:0.875rem">{{ trans('setting.support_desc') }}</p>
                <form id="form-support">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="support-chat-id">{{ trans('setting.support_chat_id') }}</label>
                            <input type="text" class="form-control" id="support-chat-id" placeholder="-1001234567890">
                            <small class="form-text text-muted">{{ trans('setting.support_chat_id_hint') }}</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="support-system">{{ trans('setting.support_system') }}</label>
                            <select class="form-select" id="support-system"></select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="support-remind-first">{{ trans('setting.support_remind_first') }}</label>
                            <input type="number" class="form-control" id="support-remind-first" min="1" max="1440" step="1">
                            <small class="form-text text-muted">{{ trans('setting.support_remind_first_hint') }}</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="support-remind-second">{{ trans('setting.support_remind_second') }}</label>
                            <input type="number" class="form-control" id="support-remind-second" min="1" max="1440" step="1">
                            <small class="form-text text-muted">{{ trans('setting.support_remind_second_hint') }}</small>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary js-manage-only">{{ trans('setting.action_save') }}</button>
                    <button type="button" class="btn btn-outline-secondary js-manage-only" id="btn-test-support">{{ trans('setting.support_test') }}</button>
                </form>
            </div>
        </div>

        {{-- 用量 --}}
        <div class="main-card mb-3 card">
            <div class="card-body">
                <h5 class="card-title">{{ trans('setting.usage_title') }}</h5>
                <p class="text-muted" style="font-size:0.875rem">{{ trans('setting.usage_desc') }}</p>
                <div id="usage-summary"></div>
                <div class="table-responsive mt-3">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>{{ trans('setting.usage_today') }}</th>
                                <th>{{ trans('setting.usage_subscription') }}</th>
                                <th>{{ trans('setting.usage_fallback') }}</th>
                                <th>{{ trans('setting.usage_rate_limited') }}</th>
                            </tr>
                        </thead>
                        <tbody id="usage-daily"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- 訊息 Modal（結構比照 quick-reply，不用 alert） --}}
    <div class="modal fade" id="modal-setting-msg" tabindex="-1">
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
    <script src="{{ asset('js/setting-admin.js') }}?v={{ filemtime(public_path('js/setting-admin.js')) }}"></script>
@endsection
