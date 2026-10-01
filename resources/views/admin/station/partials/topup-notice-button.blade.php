{{--
    站台列表的「補點通知」按鈕

    表格檢視與卡片檢視都要用，所以抽成 partial —— 兩邊各寫一份的話，
    disable 條件改了很容易只改一邊，看起來像某一種版面壞掉。

    缺 API 網址／API 金鑰／Telegram 群組時直接 disable：手動發送會先同步
    點數（要 API）再發到站台自己的群組（要群組），三個缺任何一個都走不完，
    不該讓人按下去才收到錯誤。判斷在 Station::$topup_notice_blockers。

    ⚠ disabled 的 button 自己不觸發 hover，title 要掛在外層 span 上才看得到。

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
    <button class="btn btn-sm btn-outline-secondary js-send-topup-notice"
            data-id="{{ $station->id }}"
            data-name="{{ $station->name }}">
        <i class="fas fa-paper-plane me-1"></i>{{ trans('station.action_topup_notice') }}
    </button>
@else
    <span class="d-inline-block" tabindex="0" title="{{ $reason }}">
        <button class="btn btn-sm btn-outline-secondary" disabled>
            <i class="fas fa-paper-plane me-1"></i>{{ trans('station.action_topup_notice') }}
        </button>
    </span>
@endif
