{{--
    帳號管理的列操作

    桌機表格與手機卡片都用這一份 —— 兩邊各寫一次的話，
    加一個動作或改一次權限條件很容易只改到一邊，
    看起來就像某一種版面壞掉（站台的「補點通知」按鈕也是同樣理由抽成 partial）。

    ⚠ 五個動作**全部留在畫面上、每顆都帶文字**，用 Architect theme 自己的語彙排：
    同一類用 `btn-group` 黏成一段（共用邊框），段與段之間留空隙；
    按鈕是 `btn-icon btn-transition`（淡灰外框，hover 才上色）。

      · 管理     編輯／調整狀態／設定權限
      · 紀錄     登入紀錄
      · Telegram 產生綁定碼 或 解除綁定

    ⚠ 2026-10-10 做過兩版都被需求方退回，不要再往那兩個方向改：
      · 「只留兩顆、其餘收進 ⋯ 選單」→「按鈕不應該全部收合，有點醜」
      · 「純圖示鈕 + title」→「每個按鈕旁邊還是要有說明，不能隱藏」

    ⚠ 「解除綁定」的圖示用 text-danger：解除之後對方會靜悄悄收不到班表與任務卡。
    （整顆不用 btn-outline-danger：custom.css 只替 outline-secondary 補齊了
      淺／深兩種模式的 hover，換色系的 outline 變體在淺色模式滑過去不會變色。）

    ⚠ 每段都要 `role="group"` 與 `aria-label`：對讀螢幕的人來說，
    視覺上的分段不存在，分類名稱是唯一的線索。
--}}
<div class="row-actions">
    <div class="btn-group btn-group-sm" role="group" aria-label="{{ trans('common.row_actions.manage') }}">
        <button class="btn btn-outline-secondary btn-icon btn-transition js-edit"
                data-id="{{ $account->id }}"
                data-nickname="{{ $account->nickname }}"
                data-telegram-nickname="{{ $account->telegram_nickname }}"
                data-telegram-username="{{ $account->telegram_username }}"
                data-level="{{ $account->level }}"
                data-project-ids="{{ json_encode(filled($account->project_ids) ? $account->project_ids : []) }}">
            <i class="fas fa-edit btn-icon-wrapper"></i>{{ trans('account.action_edit') }}
        </button>
        <button class="btn btn-outline-secondary btn-icon btn-transition js-change-status"
                data-id="{{ $account->id }}"
                data-status="{{ $account->status }}">
            <i class="fas fa-exchange-alt btn-icon-wrapper"></i>{{ trans('account.action_change_status') }}
        </button>
        <a href="{{ route('admin.accounts.permissions', $account->id) }}"
           class="btn btn-outline-secondary btn-icon btn-transition">
            <i class="fas fa-key btn-icon-wrapper"></i>{{ trans('account.action_assign_permissions') }}
        </a>
    </div>

    <div class="btn-group btn-group-sm" role="group" aria-label="{{ trans('common.row_actions.record') }}">
        <button class="btn btn-outline-secondary btn-icon btn-transition js-login-log"
                data-id="{{ $account->id }}"
                data-account="{{ $account->account }}">
            <i class="fas fa-sign-in-alt btn-icon-wrapper"></i>{{ trans('login_log.nav_label') }}
        </button>
    </div>

    @can('account.update')
        <div class="btn-group btn-group-sm" role="group" aria-label="{{ trans('account.col_telegram') }}">
            @if($account->telegram_dm_ready)
                <button class="btn btn-outline-secondary btn-icon btn-transition js-telegram-unbind"
                        title="{{ trans('account.telegram_unbind_hint') }}"
                        data-id="{{ $account->id }}"
                        data-name="{{ $account->nickname }}">
                    <i class="fas fa-unlink btn-icon-wrapper text-danger"></i>{{ trans('account.action_telegram_unbind') }}
                </button>
            @else
                <button class="btn btn-outline-secondary btn-icon btn-transition js-telegram-code"
                        title="{{ trans('account.telegram_code_hint') }}"
                        data-id="{{ $account->id }}"
                        data-name="{{ $account->nickname }}">
                    <i class="fas fa-qrcode btn-icon-wrapper"></i>{{ trans('account.action_telegram_code') }}
                </button>
            @endif
        </div>
    @endcan
</div>
