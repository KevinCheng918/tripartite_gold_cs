{{-- 公告狀態標籤，桌面版表格與手機版卡片共用 --}}
@php
    $statusMap = [
        \App\Models\TelegramBroadcast::STATUS_SENT     => ['bg-success', 'broadcast.status_sent'],
        \App\Models\TelegramBroadcast::STATUS_PENDING  => ['bg-warning text-dark', 'broadcast.status_pending'],
        \App\Models\TelegramBroadcast::STATUS_CANCELED => ['bg-secondary', 'broadcast.status_canceled'],
    ];
    $status = $statusMap[$record->status] ?? $statusMap[\App\Models\TelegramBroadcast::STATUS_SENT];
@endphp
<span class="badge {{ $status[0] }}">{{ trans($status[1]) }}</span>
