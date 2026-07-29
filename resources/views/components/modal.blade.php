{{-- 共用 Modal 元件 --}}
<div id="{{ $id }}" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <div class="modal-header">
            <h3>{{ $title ?? '' }}</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <div class="modal-body">
            {{ $slot }}
        </div>
    </div>
</div>
