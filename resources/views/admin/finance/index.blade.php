@extends('layouts.app')

@section('title', '財務管理')
@section('icon', 'calculator')
@section('subtitle', '每月財務記錄總覽')

@section('content')

    {{-- 標題列 + 月份選擇 --}}
    <div class="main-card mb-3 card">
        <div class="card-header d-flex align-items-center gap-3 py-2">
            <label class="form-label mb-0 fw-bold" style="font-size:0.9375rem">月份</label>
            <input type="text" id="finance-month" class="form-control form-control-sm" style="width:130px;cursor:pointer" readonly>
        </div>
    </div>

    <div id="finance-loading" class="text-center py-5" style="display:none">
        <div class="spinner-border text-secondary" role="status"></div>
    </div>

    <div id="finance-content" style="display:none">

        {{-- 三欄摘要 --}}
        <div class="row g-3 mb-3">
            <div class="col-md-4 d-flex">
                <div class="main-card card w-100">
                    <div class="card-body py-3">
                        <div class="text-muted mb-1" style="font-size:0.8125rem">本月收入</div>
                        {{-- 補點與虛擬機是兩條獨立收入線，分開呈現，不做合計 --}}
                        <div class="d-flex justify-content-between align-items-baseline">
                            <span class="text-muted" style="font-size:0.8125rem">補點</span>
                            <span class="fw-bold text-success" style="font-size:1.375rem"><span id="summary-topup-credit">0</span> 點</span>
                        </div>
                        <div class="text-end text-muted" style="font-size:0.75rem">
                            實收 <span class="fw-bold" style="color:#a67c00" id="summary-topup-usdt">0</span> USDT
                            ＋ <span class="fw-bold" style="color:#a67c00" id="summary-topup-twd">0</span> TWD
                            <span class="ms-1">（<span id="summary-topup-count">0</span> 筆）</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-baseline mt-2 pt-2 border-top">
                            <span class="text-muted" style="font-size:0.8125rem">虛擬機</span>
                            <span class="fw-bold" style="font-size:1rem;color:#0d9488"><span id="summary-vm-credit">0</span> 點</span>
                        </div>
                        <div class="text-end text-muted" style="font-size:0.75rem">
                            實收 <span class="fw-bold" style="color:#a67c00" id="summary-vm-usdt">0</span> USDT
                            <span class="ms-1">（<span id="summary-vm-count">0</span> 筆）</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 d-flex">
                <div class="main-card card w-100">
                    <div class="card-body py-3">
                        <div class="text-muted mb-1" style="font-size:0.8125rem">本月支出</div>
                        <div class="fw-bold text-danger" style="font-size:1.375rem" id="summary-expense">0 TWD</div>
                        <div class="text-muted" style="font-size:0.8125rem"><span id="summary-expense-count">0</span> 筆支出</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 d-flex">
                <div class="main-card card w-100">
                    <div class="card-body py-3">
                        <div class="text-muted mb-1" style="font-size:0.8125rem">本月損益</div>
                        <div class="fw-bold" style="font-size:1.375rem" id="summary-profit">0 TWD</div>
                        <div class="text-muted" style="font-size:0.8125rem">補點收入 − 支出（不含虛擬機）</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            {{-- 收入明細（左） --}}
            <div class="col-md-5 d-flex">
                <div class="main-card card w-100">
                    <div class="card-header d-flex justify-content-between align-items-center py-2">
                        <span class="fw-bold" style="font-size:0.9375rem">收入明細</span>
                        <span class="text-muted" style="font-size:0.75rem">補點與虛擬機分開計算</span>
                    </div>
                    <div class="card-body py-2">
                        {{-- 補點收入：分 USDT 補點與台幣補點 --}}
                        <div class="d-flex justify-content-between align-items-center py-2 border-bottom" style="font-size:0.9375rem">
                            <span class="fw-bold">補點收入</span>
                            <div class="d-flex align-items-center gap-2">
                                <span class="fw-bold" style="color:#a67c00"><span id="income-topup-credit">0</span> 點</span>
                                @if(Auth::user()->hasPermission('finance.edit'))
                                <button class="btn btn-outline-secondary py-0 px-1" id="btn-edit-topup" style="font-size:0.7rem" title="調整"><i class="fas fa-edit"></i></button>
                                <button class="btn btn-outline-secondary py-0 px-1" id="btn-reset-topup" style="font-size:0.7rem;display:none" title="重置"><i class="fas fa-undo"></i></button>
                                @endif
                                <span class="badge" id="topup-mode-badge" style="font-size:0.65rem">自動</span>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center py-1" style="font-size:0.8125rem">
                            <span class="text-muted ps-3">USDT 補點 <span class="text-muted" id="income-topup-usdt-count"></span></span>
                            <span><span class="fw-bold" id="income-topup">0</span> USDT</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center py-1 border-bottom" style="font-size:0.75rem">
                            <span class="text-muted ps-4">均匯率 <span id="income-topup-rate">0</span> 換算</span>
                            <span class="text-muted"><span id="income-topup-credit-usdt">0</span> 點</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center py-1" style="font-size:0.8125rem">
                            <span class="text-muted ps-3">台幣補點 <span class="text-muted" id="income-topup-twd-count"></span></span>
                            <span><span class="fw-bold" id="income-topup-twd">0</span> TWD</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center py-1" style="font-size:0.75rem">
                            <span class="text-muted ps-4">1:1 換算</span>
                            <span class="text-muted"><span id="income-topup-credit-twd">0</span> 點</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center py-1 border-bottom" style="font-size:0.8125rem;background:rgba(166,124,0,0.06)">
                            <span class="ps-3">補點實收</span>
                            <span class="fw-bold" style="color:#a67c00">
                                <span id="income-sum-usdt">0</span> USDT ＋ <span id="income-sum-twd">0</span> TWD
                            </span>
                        </div>

                        {{-- 虛擬機服務收入：收 USDT，依當下匯率換算成可扣的系統點 --}}
                        <div class="d-flex justify-content-between align-items-center py-2 border-bottom mt-1" style="font-size:0.9375rem">
                            <span class="fw-bold">虛擬機服務收入</span>
                            <div class="d-flex align-items-center gap-2">
                                <span class="fw-bold" style="color:#a67c00"><span id="income-vm-credit">0</span> 點</span>
                                @if(Auth::user()->hasPermission('finance.edit'))
                                <button class="btn btn-outline-secondary py-0 px-1" id="btn-edit-vm" style="font-size:0.7rem" title="調整"><i class="fas fa-edit"></i></button>
                                <button class="btn btn-outline-secondary py-0 px-1" id="btn-reset-vm" style="font-size:0.7rem;display:none" title="重置"><i class="fas fa-undo"></i></button>
                                @endif
                                <span class="badge" id="vm-mode-badge" style="font-size:0.65rem">自動</span>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center py-1" style="font-size:0.8125rem">
                            <span class="text-muted ps-3">收款 <span class="text-muted" id="income-vm-count"></span></span>
                            <span><span class="fw-bold" id="income-vm">0</span> USDT</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center py-1" style="font-size:0.75rem">
                            <span class="text-muted ps-4">4H 均價匯率 <span id="income-vm-rate">0</span> 換算</span>
                            <span class="text-muted"><span id="income-vm-twd">0</span> 點</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center py-1" style="font-size:0.8125rem;background:rgba(13,148,136,0.06)">
                            <span class="ps-3">虛擬機實收</span>
                            <span class="fw-bold" style="color:#0d9488"><span id="income-vm-sum-usdt">0</span> USDT</span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- 支出紀錄（右） --}}
            <div class="col-md-7 d-flex">
                <div class="main-card card w-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center gap-2">
                            <span class="fw-bold" style="font-size:0.9375rem">支出紀錄</span>
                            @if(Auth::user()->hasPermission('finance.edit'))
                            <button class="btn btn-sm btn-primary" id="btn-add-expense"><i class="fas fa-plus me-1"></i>新增支出</button>
                            @endif
                        </div>
                        <div class="text-end" style="font-size:0.8125rem">
                            <span class="text-success">已請款 <span id="expense-reimbursed">0</span></span>
                            <span class="mx-1">|</span>
                            <span class="text-danger">未請款 <span id="expense-unreimbursed">0</span></span>
                            <span class="mx-1">|</span>
                            <span class="fw-bold">總計 <span id="expense-total">0</span></span>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0" style="white-space:nowrap">
                                <thead class="thead-gold">
                                    <tr>
                                        <th>日期</th>
                                        <th>分類</th>
                                        <th>項目</th>
                                        <th class="text-end">金額</th>
                                        <th class="text-center">請款</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody id="expense-table-body">
                                    <tr><td colspan="6" class="text-center text-muted py-3">尚無支出</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    {{-- 新增支出 Modal --}}
    <div class="modal fade" id="modal-expense" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-expense-title">新增支出</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="form-expense">
                        <input type="hidden" id="expense-edit-id">
                        <div class="mb-3">
                            <label class="form-label">分類 <span class="text-danger">*</span></label>
                            <select id="expense-category" class="form-select">
                                @foreach($categories as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">項目 <span class="text-danger">*</span></label>
                            <input type="text" id="expense-name" class="form-control" required maxlength="200">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">金額 <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" id="expense-amount" class="form-control" required min="0.01" step="0.01">
                                <select id="expense-currency" class="form-select" style="max-width:90px">
                                    @foreach($currencies as $c)
                                        <option value="{{ $c }}">{{ $c }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        {{-- 外幣支出要輸入匯率，否則無法併入台幣的支出合計 --}}
                        <div class="mb-3" id="expense-rate-block" style="display:none">
                            <label class="form-label">匯率 <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" id="expense-rate" class="form-control" step="0.0001" min="0.0001">
                                <button type="button" class="btn btn-outline-secondary" id="btn-expense-fetch-rate">
                                    <i class="fas fa-sync-alt me-1"></i>即時匯率
                                </button>
                            </div>
                            <div class="form-text">換算後 <span class="fw-bold" style="color:#a67c00" id="expense-rate-preview">0</span> TWD</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">日期 <span class="text-danger">*</span></label>
                            <input type="date" id="expense-date" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="expense-reimbursed-check">
                                <label class="form-check-label" for="expense-reimbursed-check">已請款</label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">備註</label>
                            <input type="text" id="expense-note" class="form-control" maxlength="500">
                        </div>
                        <div class="text-end">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                            <button type="submit" class="btn btn-primary">確認</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- 編輯統計 Modal --}}
    <div class="modal fade" id="modal-edit-stat" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-edit-stat-title">手動調整</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="form-edit-stat">
                        <input type="hidden" id="stat-field">
                        <div id="stat-fields"></div>
                        <div class="text-end mt-3">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                            <button type="submit" class="btn btn-primary">儲存</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- 訊息 Modal --}}
    <div class="modal fade" id="modal-finance-msg" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-body text-center py-4">
                    <p id="modal-finance-msg-text" class="mb-3"></p>
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
                </div>
            </div>
        </div>
    </div>

    {{-- 刪除確認 Modal --}}
    <div class="modal fade" id="modal-delete-expense" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-body text-center py-4">
                    <p class="mb-3">確定刪除此筆支出？</p>
                    <input type="hidden" id="delete-expense-id">
                    <div class="d-flex justify-content-center gap-2">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                        <button type="button" class="btn btn-danger" id="btn-delete-expense-ok">確定刪除</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

@endsection

@section('scripts')
<script>
$(function () {
    var csrfToken = $('meta[name="csrf-token"]').attr('content');
    var currentMonth = '';
    var currentData = null;
    var categoryMap = @json($categories);

    function showMsg(msg) {
        $('#modal-finance-msg-text').text(msg);
        showBsModal('modal-finance-msg');
    }

    flatpickr('#finance-month', {
        plugins: [new monthSelectPlugin({ shorthand: true, dateFormat: 'Y-m', altFormat: 'Y-m' })],
        disableMobile: true,
        defaultDate: new Date(),
        onChange: function (selectedDates) {
            if (selectedDates.length === 0) return;
            var d = selectedDates[0];
            currentMonth = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
            loadDetail();
        }
    });

    var now = new Date();
    currentMonth = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
    $('#finance-month').val(currentMonth);
    loadDetail();

    function loadDetail() {
        $('#finance-loading').show();
        $('#finance-content').hide();
        $.ajax({
            url: '/admin/finance/ajax-detail',
            method: 'GET',
            data: { month: currentMonth },
            headers: { 'X-CSRF-TOKEN': csrfToken },
            success: function (data) {
                currentData = data;
                renderAll(data);
                $('#finance-loading').hide();
                $('#finance-content').show();
            },
            error: function () { $('#finance-loading').hide(); showMsg('載入失敗'); }
        });
    }

    function fmt(n) { return parseFloat(n || 0).toLocaleString('zh-TW', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function fmt4(n) { return parseFloat(n || 0).toLocaleString('zh-TW', { minimumFractionDigits: 4, maximumFractionDigits: 4 }); }
    function trimFmt(n, maxDec) {
        maxDec = maxDec || 4;
        var s = parseFloat(n || 0).toFixed(maxDec);
        if (s.indexOf('.') !== -1) { s = s.replace(/0+$/, '').replace(/\.$/, ''); }
        return s.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    /** 筆數標註，0 筆時不顯示以免版面雜亂 */
    function countLabel(n) {
        n = parseInt(n || 0, 10);
        return n > 0 ? '（' + n + ' 筆）' : '';
    }

    function renderAll(data) {
        var t = data.topup, v = data.vm;
        var allExpenses = (data.misc_expenses || []).concat(data.server_expenses || []);

        // 支出合計：外幣支出已由後端依輸入的匯率換算成 twd_amount，一併計入
        var totalExpenseTwd = 0;
        allExpenses.forEach(function (e) {
            totalExpenseTwd += parseFloat(e.twd_amount || 0);
        });

        // 損益只算補點：虛擬機是另一條收入線，需求方要求分開看，不能併進來
        var profitTwd = parseFloat(t.credit || 0) - totalExpenseTwd;

        // 摘要卡：補點與虛擬機各自一組，點數為主、實收幣別為輔
        $('#summary-topup-credit').text(trimFmt(t.credit, 2));
        $('#summary-topup-usdt').text(trimFmt(t.usdt));
        $('#summary-topup-twd').text(trimFmt(t.twd, 2));
        $('#summary-topup-count').text(parseInt(t.count || 0, 10));
        $('#summary-vm-credit').text(trimFmt(v.credit, 2));
        $('#summary-vm-usdt').text(trimFmt(v.usdt));
        $('#summary-vm-count').text(parseInt(v.count || 0, 10));
        $('#summary-expense').text(trimFmt(totalExpenseTwd, 2) + ' TWD');
        $('#summary-expense-count').text(allExpenses.length);
        $('#summary-profit').text((profitTwd >= 0 ? '+' : '') + trimFmt(profitTwd, 2) + ' TWD');
        $('#summary-profit').css('color', profitTwd >= 0 ? '#198754' : '#dc3545');

        // 收入明細 — 補點拆成 USDT 補點與台幣補點
        $('#income-topup-credit').text(t.credit_fmt);
        $('#income-topup').text(t.usdt_fmt);
        $('#income-topup-usdt-count').text(countLabel(t.usdt_count));
        $('#income-topup-rate').text(t.avg_rate_fmt);
        $('#income-topup-credit-usdt').text(t.credit_from_usdt_fmt);
        $('#income-topup-twd').text(t.twd_fmt);
        $('#income-topup-twd-count').text(countLabel(t.twd_count));
        $('#income-topup-credit-twd').text(t.twd_fmt);

        // 虛擬機 — 收 USDT，依當下匯率換算成可扣的系統點
        $('#income-vm-credit').text(v.credit_fmt);
        $('#income-vm').text(v.usdt_fmt);
        $('#income-vm-count').text(countLabel(v.count));
        $('#income-vm-rate').text(v.avg_rate_fmt);
        $('#income-vm-twd').text(v.credit_fmt);

        // 兩條收入線各自的實收，不做跨線合計
        $('#income-sum-usdt').text(t.usdt_fmt);
        $('#income-sum-twd').text(t.twd_fmt);
        $('#income-vm-sum-usdt').text(v.usdt_fmt);

        if (t.is_manual) {
            $('#topup-mode-badge').text('手動').removeClass('bg-secondary').addClass('bg-warning text-dark');
            $('#btn-reset-topup').show();
        } else {
            $('#topup-mode-badge').text('自動').removeClass('bg-warning text-dark').addClass('bg-secondary');
            $('#btn-reset-topup').hide();
        }
        if (v.is_manual) {
            $('#vm-mode-badge').text('手動').removeClass('bg-secondary').addClass('bg-warning text-dark');
            $('#btn-reset-vm').show();
        } else {
            $('#vm-mode-badge').text('自動').removeClass('bg-warning text-dark').addClass('bg-secondary');
            $('#btn-reset-vm').hide();
        }

        // 支出表格（雜項 + 伺服器合併，按日期排序）
        allExpenses.sort(function (a, b) {
            var da = a.expense_date || '';
            var db = b.expense_date || '';
            return da < db ? -1 : (da > db ? 1 : 0);
        });

        var $body = $('#expense-table-body');
        if (allExpenses.length === 0) {
            $body.html('<tr><td colspan="6" class="text-center text-muted py-3">尚無支出</td></tr>');
        } else {
            var html = '';
            allExpenses.forEach(function (e) {
                var catLabel = e.type === 'server' ? '伺服器' : (categoryMap[e.category] || e.category || '-');
                var cur = e.currency || 'TWD';
                html += '<tr>';
                html += '<td>' + (e.expense_date ? e.expense_date.substring(5, 10) : '-') + '</td>';
                html += '<td><span class="badge bg-info text-dark">' + catLabel + '</span></td>';
                html += '<td>' + $('<span>').text(e.name).html() + '</td>';
                // 外幣支出同時列出原幣金額與換算後的台幣，方便對帳
                html += '<td class="text-end">';
                if (cur === 'TWD') {
                    html += trimFmt(e.amount, 2);
                } else {
                    html += trimFmt(e.amount, 2) + ' <span class="text-muted">' + cur + '</span>';
                    html += '<br><span class="text-muted" style="font-size:0.75rem">× ' + trimFmt(e.exchange_rate, 4)
                         + ' ＝ ' + trimFmt(e.twd_amount, 2) + ' TWD</span>';
                }
                html += '</td>';
                html += '<td class="text-center">';
                if (e.type === 'misc') {
                    html += parseInt(e.reimbursed, 10) === 1
                        ? '<span class="badge bg-success">已請款</span>'
                        : '<span class="badge bg-secondary">未請款</span>';
                } else { html += '-'; }
                html += '</td>';
                html += '<td><div class="d-flex gap-1">';
                html += '<button class="btn btn-sm btn-outline-secondary js-edit-expense" data-id="' + e.id + '" data-type="' + e.type + '" data-category="' + (e.category || '') + '" data-name="' + $('<span>').text(e.name).html() + '" data-amount="' + e.amount + '" data-currency="' + (e.currency || 'TWD') + '" data-rate="' + (e.exchange_rate || '') + '" data-date="' + (e.expense_date ? e.expense_date.substring(0, 10) : '') + '" data-reimbursed="' + (e.reimbursed || 0) + '" data-note="' + $('<span>').text(e.note || '').html() + '"><i class="fas fa-edit me-1"></i>編輯</button>';
                html += '<button class="btn btn-sm btn-outline-secondary js-del-expense" data-id="' + e.id + '"><i class="fas fa-trash-alt text-danger me-1"></i>刪除</button>';
                html += '</div></td>';
                html += '</tr>';
            });
            $body.html(html);
        }
        var reimbursedTotal = 0;
        var unreimbursedTotal = 0;
        // 請款金額同樣以換算後的台幣為準
        allExpenses.forEach(function (e) {
            if (parseInt(e.reimbursed, 10) === 1) {
                reimbursedTotal += parseFloat(e.twd_amount || 0);
            } else {
                unreimbursedTotal += parseFloat(e.twd_amount || 0);
            }
        });
        $('#expense-reimbursed').text(trimFmt(reimbursedTotal, 2));
        $('#expense-unreimbursed').text(trimFmt(unreimbursedTotal, 2));
        $('#expense-total').text(trimFmt(totalExpenseTwd, 2));
    }

    // 新增支出
    /**
     * 幣別切換：只有外幣需要匯率
     * required 要跟著隱藏一起拿掉，否則瀏覽器會擋在看不見的欄位上無法送出
     */
    function switchExpenseCurrency() {
        var isForeign = $('#expense-currency').val() !== 'TWD';
        $('#expense-rate-block').toggle(isForeign);
        $('#expense-rate').prop('required', isForeign);
        if (!isForeign) $('#expense-rate').val('');
        updateExpenseRatePreview();
    }

    /** 即時顯示換算後的台幣金額，避免輸入完才發現匯率填錯 */
    function updateExpenseRatePreview() {
        var amount = parseFloat($('#expense-amount').val()) || 0;
        var rate = parseFloat($('#expense-rate').val()) || 0;
        $('#expense-rate-preview').text(trimFmt(amount * rate, 2));
    }

    $('#expense-currency').on('change', function () { switchExpenseCurrency(); });
    $('#expense-amount, #expense-rate').on('input', function () { updateExpenseRatePreview(); });

    // 帶入 USDT 即時匯率（USD 與 USDT 對台幣的匯率實務上視為相同）
    $('#btn-expense-fetch-rate').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i>取得中...');
        $.ajax({
            url: '/admin/dashboard/ajax-usdt-rate',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            success: function (body) {
                if (body && body.avg_rate) {
                    $('#expense-rate').val(parseFloat(body.avg_rate).toFixed(4));
                    updateExpenseRatePreview();
                }
                $btn.prop('disabled', false).html('<i class="fas fa-sync-alt me-1"></i>即時匯率');
            },
            error: function () {
                $btn.prop('disabled', false).html('<i class="fas fa-sync-alt me-1"></i>即時匯率');
                showMsg('匯率取得失敗');
            }
        });
    });

    // 新增支出
    $('#btn-add-expense').on('click', function () {
        $('#modal-expense-title').text('新增支出');
        $('#form-expense')[0].reset();
        $('#expense-edit-id').val('');
        // reset() 不觸發 change，要手動同步匯率欄位的顯示狀態
        switchExpenseCurrency();
        showBsModal('modal-expense');
    });

    // 編輯支出
    $(document).on('click', '.js-edit-expense', function () {
        var $btn = $(this);
        $('#modal-expense-title').text('編輯支出');
        $('#expense-edit-id').val($btn.data('id'));
        $('#expense-category').val($btn.data('type') === 'server' ? 'server' : ($btn.data('category') || 'office'));
        $('#expense-name').val($btn.data('name'));
        $('#expense-amount').val($btn.data('amount'));
        $('#expense-currency').val($btn.data('currency') || 'TWD');
        $('#expense-rate').val($btn.data('rate') || '');
        $('#expense-date').val($btn.data('date'));
        $('#expense-reimbursed-check').prop('checked', parseInt($btn.data('reimbursed'), 10) === 1);
        $('#expense-note').val($btn.data('note'));
        switchExpenseCurrency();
        showBsModal('modal-expense');
    });

    // 送出支出（新增/編輯）
    $('#form-expense').on('submit', function (e) {
        e.preventDefault();
        var editId = $('#expense-edit-id').val();
        var cat = $('#expense-category').val();
        var isServer = (cat === 'server');
        var data = {
            year_month: currentMonth,
            type: isServer ? 'server' : 'misc',
            category: isServer ? null : cat,
            name: $('#expense-name').val().trim(),
            amount: parseFloat($('#expense-amount').val()),
            currency: $('#expense-currency').val(),
            exchange_rate: null,
            expense_date: $('#expense-date').val() || null,
            reimbursed: $('#expense-reimbursed-check').is(':checked') ? 1 : 0,
            note: $('#expense-note').val().trim() || null
        };
        if (data.currency !== 'TWD') {
            data.exchange_rate = parseFloat($('#expense-rate').val()) || 0;
            if (data.exchange_rate <= 0) {
                showMsg('外幣支出請填入匯率');
                return;
            }
        }
        if (!data.name || !data.amount) return;
        $.ajax({
            url: editId ? '/admin/finance/ajax-update-expense/' + editId : '/admin/finance/ajax-store-expense',
            method: editId ? 'PUT' : 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            contentType: 'application/json',
            data: JSON.stringify(data),
            success: function () { hideBsModal(document.getElementById('modal-expense')); setTimeout(function () { loadDetail(); }, 400); },
            error: function (xhr) { showMsg((xhr.responseJSON && xhr.responseJSON.message) || '操作失敗'); }
        });
    });

    // 刪除
    $(document).on('click', '.js-del-expense', function () {
        $('#delete-expense-id').val($(this).data('id'));
        showBsModal('modal-delete-expense');
    });

    $('#btn-delete-expense-ok').on('click', function () {
        var $btn = $(this).prop('disabled', true);
        $.ajax({
            url: '/admin/finance/ajax-delete-expense/' + $('#delete-expense-id').val(),
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            success: function () { hideBsModal(document.getElementById('modal-delete-expense')); setTimeout(function () { loadDetail(); }, 400); $btn.prop('disabled', false); },
            error: function (xhr) { hideBsModal(document.getElementById('modal-delete-expense')); setTimeout(function () { showMsg((xhr.responseJSON && xhr.responseJSON.message) || '刪除失敗'); }, 400); $btn.prop('disabled', false); }
        });
    });

    // 編輯統計
    $('#btn-edit-topup').on('click', function () {
        $('#modal-edit-stat-title').text('調整補點統計');
        $('#stat-field').val('topup');
        var t = currentData ? currentData.topup : {};
        $('#stat-fields').html(
            '<div class="mb-2"><label class="form-label">USDT 補點總額</label><input type="number" class="form-control" id="stat-topup-usdt" step="0.0001" value="' + (t.usdt || 0) + '"></div>' +
            '<div class="mb-2"><label class="form-label">平均匯率</label><input type="number" class="form-control" id="stat-topup-rate" step="0.0001" value="' + (t.avg_rate || 0) + '"></div>' +
            '<div class="mb-2"><label class="form-label">台幣補點總額</label><input type="number" class="form-control" id="stat-topup-twd" step="0.01" value="' + (t.twd || 0) + '"></div>' +
            '<div class="mb-2"><label class="form-label">點數合計</label><input type="number" class="form-control" id="stat-topup-credit" step="0.01" value="' + (t.credit || 0) + '"><div class="form-text">USDT 補點換算的點數 ＋ 台幣補點（1:1）</div></div>'
        );
        showBsModal('modal-edit-stat');
    });

    $('#btn-edit-vm').on('click', function () {
        $('#modal-edit-stat-title').text('調整 VM 收入');
        $('#stat-field').val('vm');
        var v = currentData ? currentData.vm : {};
        $('#stat-fields').html(
            '<div class="mb-2"><label class="form-label">總收入 (USDT)</label><input type="number" class="form-control" id="stat-vm-usdt" step="0.0001" value="' + (v.usdt || 0) + '"></div>' +
            '<div class="mb-2"><label class="form-label">筆數</label><input type="number" class="form-control" id="stat-vm-count" step="1" value="' + (v.count || 0) + '"></div>'
        );
        showBsModal('modal-edit-stat');
    });

    $('#form-edit-stat').on('submit', function (e) {
        e.preventDefault();
        var field = $('#stat-field').val();
        var data = {};
        if (field === 'topup') {
            data.topup_usdt = parseFloat($('#stat-topup-usdt').val()) || 0;
            data.topup_avg_rate = parseFloat($('#stat-topup-rate').val()) || 0;
            data.topup_credit = parseFloat($('#stat-topup-credit').val()) || 0;
            data.topup_twd = parseFloat($('#stat-topup-twd').val()) || 0;
        } else {
            data.vm_income_usdt = parseFloat($('#stat-vm-usdt').val()) || 0;
            data.vm_income_count = parseInt($('#stat-vm-count').val(), 10) || 0;
        }
        $.ajax({
            url: '/admin/finance/ajax-update-summary/' + currentData.record_id,
            method: 'PUT',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            contentType: 'application/json',
            data: JSON.stringify(data),
            success: function () { hideBsModal(document.getElementById('modal-edit-stat')); setTimeout(function () { loadDetail(); }, 400); },
            error: function (xhr) { showMsg((xhr.responseJSON && xhr.responseJSON.message) || '更新失敗'); }
        });
    });

    $('#btn-reset-topup').on('click', function () {
        $.ajax({ url: '/admin/finance/ajax-update-summary/' + currentData.record_id, method: 'PUT', headers: { 'X-CSRF-TOKEN': csrfToken }, contentType: 'application/json', data: JSON.stringify({ reset_field: 'topup' }), success: function () { loadDetail(); } });
    });
    $('#btn-reset-vm').on('click', function () {
        $.ajax({ url: '/admin/finance/ajax-update-summary/' + currentData.record_id, method: 'PUT', headers: { 'X-CSRF-TOKEN': csrfToken }, contentType: 'application/json', data: JSON.stringify({ reset_field: 'vm' }), success: function () { loadDetail(); } });
    });
});
</script>
@endsection
