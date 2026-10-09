{{--
    站台列表的「補點通知」按鈕

    表格檢視與卡片檢視都要用，所以抽成 partial —— 兩邊各寫一份的話，
    disable 條件改了很容易只改一邊，看起來像某一種版面壞掉。
    （2026-10-10 操作欄重排之後，兩邊都是透過
      `admin.station.partials.row-actions` 間接引入這一份。）

    缺 API 網址／API 金鑰／Telegram 群組時直接 disable：手動發送會先同步
    點數（要 API）再發到站台自己的群組（要群組），三個缺任何一個都走不完，
    不該讓人按下去才收到錯誤。判斷在 Station::$topup_notice_blockers。

    ⚠ 停用時用 `.disabled` **class** 而不是 `disabled` **屬性**：
      disabled 的元素不觸發 hover，掛在它身上的 title 就永遠看不到，
      使用者只會覺得這顆按鈕莫名其妙沒反應。
      沒有 disabled 屬性也不必擔心被按到 —— 那一版不掛 js-send-topup-notice，
      沒有任何 handler 會接。
      （Bootstrap 的 .btn.disabled 會關掉 pointer-events，
        custom.css 的 `.row-actions .btn.disabled` 把它開回來。）

    ⚠ 它是 btn-group 的直接子元素，不要再包一層 span ——
      btn-group 靠 `> .btn` 收合相鄰按鈕的邊框，中間插一層就斷掉。

    ⚠ 這個檔案的 PHP 一律用 @php ... @endphp 區塊，**不要混用 @php(...) 單行**
    —— Blade 的 storePhpBlocks 會把單行 @php( 一路吃到後面的 @endphp，
    中間的指令全部不編譯。
--}}
@php
    $missing = $station->topup_notice_blockers;
    $reason = '';

    // 都設好了就不必算文案
    if (filled($missing)) {
        // PHP 7.4 沒有 arrow function，map 一律用 function
        $missingLabels = collect($missing)->map(function ($field) {
            return trans("station.topup_notice_missing.{$field}");
        })->implode('、');

        $reason = trans('station.topup_notice_disabled', ['missing' => $missingLabels]);
    }
@endphp

@if(blank($missing))
    <button type="button" class="btn btn-outline-secondary btn-icon btn-transition js-send-topup-notice"
            data-id="{{ $station->id }}"
            data-name="{{ $station->name }}">
        <i class="fas fa-paper-plane btn-icon-wrapper"></i>{{ trans('station.action_topup_notice') }}
    </button>
@else
    <button type="button" class="btn btn-outline-secondary btn-icon btn-transition disabled"
            aria-disabled="true"
            title="{{ $reason }}">
        <i class="fas fa-paper-plane btn-icon-wrapper"></i>{{ trans('station.action_topup_notice') }}
    </button>
@endif
