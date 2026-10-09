{{--
    站台列表的列操作

    桌機表格與手機卡片都用這一份（原本兩邊各抄一次共十顆按鈕）。

    ⚠ 五個動作**全部留在畫面上、每顆都帶文字**，用 Architect theme 自己的語彙排：
    同一類用 `btn-group` 黏成一段（共用邊框），段與段之間留空隙；
    按鈕是 `btn-icon btn-transition`（淡灰外框，hover 才上色）。

      · 檢視  詳細
      · 管理  編輯／調整狀態
      · 通知  同步／補點通知

    「同步」歸在通知那一段是故意的 —— 補點通知送出前會先同步一次餘點，
    兩顆是同一件事的前後腳。

    ⚠ 2026-10-10 做過兩版都被需求方退回，不要再往那兩個方向改：
      · 「只留兩顆、其餘收進 ⋯ 選單」→「按鈕不應該全部收合，有點醜」
      · 「純圖示鈕 + title」→「每個按鈕旁邊還是要有說明，不能隱藏」

    ⚠ 「補點通知」的 disable 條件與停用原因在
    `admin.station.partials.topup-notice-button`，這裡只負責擺位置。
--}}
<div class="row-actions">
    <div class="btn-group btn-group-sm" role="group" aria-label="{{ trans('common.row_actions.view') }}">
        <button class="btn btn-outline-secondary btn-icon btn-transition js-station-detail"
                data-id="{{ $station->id }}">
            <i class="fas fa-info-circle btn-icon-wrapper"></i>{{ trans('station.action_detail') }}
        </button>
    </div>

    @if(Auth::user()->hasPermission('station.update'))
        <div class="btn-group btn-group-sm" role="group" aria-label="{{ trans('common.row_actions.manage') }}">
            <button class="btn btn-outline-secondary btn-icon btn-transition js-edit-station"
                    data-id="{{ $station->id }}"
                    data-name="{{ $station->name }}"
                    data-domain="{{ $station->domain }}"
                    data-system-id="{{ $station->system_id }}"
                    data-api-url="{{ $station->api_url }}"
                    data-key-masked="{{ filled($station->api_key) ? Str::substr($station->api_key, 0, 4) . str_repeat('*', max(0, Str::length($station->api_key) - 8)) . Str::substr($station->api_key, -4) : '' }}"
                    data-tg-masked="{{ $station->telegramGroup && filled($station->telegramGroup->chat_id) ? Str::substr((string) $station->telegramGroup->chat_id, 0, 4) . str_repeat('*', max(0, Str::length((string) $station->telegramGroup->chat_id) - 8)) . Str::substr((string) $station->telegramGroup->chat_id, -4) : '' }}"
                    data-credit-alert-threshold="{{ $station->credit_alert_threshold }}"
                    data-note="{{ $station->note }}">
                <i class="fas fa-edit btn-icon-wrapper"></i>{{ trans('station.action_edit') }}
            </button>
            <button class="btn btn-outline-secondary btn-icon btn-transition js-change-station-status"
                    data-id="{{ $station->id }}"
                    data-status="{{ $station->status }}">
                <i class="fas fa-exchange-alt btn-icon-wrapper"></i>{{ trans('station.action_change_status') }}
            </button>
        </div>

        <div class="btn-group btn-group-sm" role="group" aria-label="{{ trans('common.row_actions.notify') }}">
            <button class="btn btn-outline-secondary btn-icon btn-transition js-sync-credits"
                    data-id="{{ $station->id }}">
                <i class="fas fa-sync-alt btn-icon-wrapper"></i>{{ trans('station.action_sync') }}
            </button>
            @include('admin.station.partials.topup-notice-button', ['station' => $station])
        </div>
    @endif
</div>
