{{-- 發送對象標籤，桌面表格與手機卡片共用

     已發送的點開看 send_results（每站台成敗）；
     待發送的還沒有 send_results，改帶預定要送的站台名稱，否則點開是空的。 --}}
@php
    $isPending = $record->status === \App\Models\TelegramBroadcast::STATUS_PENDING;
    $targetNames = [];

    if ($isPending) {
        $targetNames = $record->target_type === \App\Models\TelegramBroadcast::TARGET_ALL
            ? collect($groups)->pluck('name')->all()
            : collect($groups)->whereIn('id', $record->target_group_ids ?? [])->pluck('name')->all();
    }
@endphp
<a href="javascript:void(0)"
   class="badge {{ $record->target_type === \App\Models\TelegramBroadcast::TARGET_ALL ? 'bg-primary' : 'bg-secondary' }} js-show-send-detail"
   style="cursor:pointer;text-decoration:none"
   data-results="{{ json_encode($record->send_results ?? []) }}"
   data-targets="{{ json_encode($targetNames) }}"
   data-pending="{{ $isPending ? 1 : 0 }}"
   data-target-type="{{ $record->target_type }}">
    {{ $record->target_type === \App\Models\TelegramBroadcast::TARGET_ALL ? trans('broadcast.target_all') : trans('broadcast.target_selected') }}
</a>
