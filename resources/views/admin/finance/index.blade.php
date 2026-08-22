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
                        <div class="fw-bold text-success" style="font-size:1.375rem" id="summary-revenue">0 USDT</div>
                        <div class="d-flex justify-content-between mt-1" style="font-size:0.8125rem">
                            <span class="text-muted"><span id="summary-revenue-count">0</span> 筆收入</span>
                            <span class="text-muted">匯率 <span id="summary-avg-rate">0</span></span>
                            <span class="fw-bold" style="color:#a67c00"><span id="summary-total-credit">0</span> 點</span>
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
                        <div class="text-muted" style="font-size:0.8125rem">收入 − 支出</div>
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
                        <span class="fw-bold" style="font-size:0.875rem">本月收入 <span id="income-total">0 USDT</span></span>
                    </div>
                    <div class="card-body py-2">
                        <div class="d-flex justify-content-between align-items-center py-2 border-bottom" style="font-size:0.9375rem">
                            <span>補點收入</span>
                            <div class="d-flex align-items-center gap-2">
                                <span class="fw-bold" id="income-topup">0 USDT</span>
                                @if(Auth::user()->hasPermission('finance.edit'))
                                <button class="btn btn-outline-secondary py-0 px-1" id="btn-edit-topup" style="font-size:0.7rem" title="調整"><i class="fas fa-edit"></i></button>
                                <button class="btn btn-outline-secondary py-0 px-1" id="btn-reset-topup" style="font-size:0.7rem;display:none" title="重置"><i class="fas fa-undo"></i></button>
                                @endif
                                <span class="badge" id="topup-mode-badge" style="font-size:0.65rem">自動</span>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center py-1 border-bottom" style="font-size:0.8125rem">
                            <span class="text-muted ps-3">平均匯率</span>
                            <span id="income-topup-rate">0</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center py-1 border-bottom" style="font-size:0.8125rem">
                            <span class="text-muted ps-3">換算點數</span>
                            <span id="income-topup-credit">0</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center py-2 border-bottom" style="font-size:0.9375rem">
                            <span>虛擬機服務收入</span>
                            <div class="d-flex align-items-center gap-2">
                                <span class="fw-bold" id="income-vm">0 USDT</span>
                                @if(Auth::user()->hasPermission('finance.edit'))
                                <button class="btn btn-outline-secondary py-0 px-1" id="btn-edit-vm" style="font-size:0.7rem" title="調整"><i class="fas fa-edit"></i></button>
                                <button class="btn btn-outline-secondary py-0 px-1" id="btn-reset-vm" style="font-size:0.7rem;display:none" title="重置"><i class="fas fa-undo"></i></button>
                                @endif
                                <span class="badge" id="vm-mode-badge" style="font-size:0.65rem">自動</span>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center py-1 border-bottom" style="font-size:0.8125rem">
                            <span class="text-muted ps-3">4H 均價匯率</span>
                            <span id="income-vm-rate">0</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center py-1" style="font-size:0.8125rem">
                            <span class="text-muted ps-3">換算 TWD</span>
                            <span id="income-vm-twd">0</span>
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
                        <span class="fw-bold" style="font-size:0.875rem">本月支出 <span id="expense-total">0</span></span>
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
                        <div class="mb-3">
                            <label class="form-label">日期 <span class="text-danger">*</span></label>
                            <input type="date" id="expense-date" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="expense-reimbursed">
                                <label class="form-check-label" for="expense-reimbursed">已請款</label>
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

    function renderAll(data) {
        var t = data.topup, v = data.vm;
        var allExpenses = (data.misc_expenses || []).concat(data.server_expenses || []);
        var totalRevenue = parseFloat(t.usdt || 0) + parseFloat(v.usdt || 0);
        var revenueCount = parseInt(v.count || 0);

        // 支出合計（全部算 TWD，非 TWD 的獨立顯示）
        var totalExpenseTwd = 0;
        allExpenses.forEach(function (e) {
            if ((e.currency || 'TWD') === 'TWD') totalExpenseTwd += parseFloat(e.amount);
        });

        // 損益（簡化：用補點換算的點數當 TWD 收入 - TWD 支出）
        var profitTwd = parseFloat(t.credit || 0) - totalExpenseTwd;

        // 三欄摘要
        $('#summary-revenue').text(trimFmt(totalRevenue) + ' USDT');  // 摘要卡用前端計算
        $('#summary-revenue-count').text(revenueCount);
        var topupUsdt = parseFloat(t.usdt || 0);
        var vmUsdt = parseFloat(v.usdt || 0);
        var topupRate = parseFloat(t.avg_rate || 0);
        var vmRate = parseFloat(v.avg_rate || 0);
        var avgRate = 0;
        if (topupUsdt + vmUsdt > 0) {
            avgRate = (topupUsdt * topupRate + vmUsdt * vmRate) / (topupUsdt + vmUsdt);
        }
        var totalCredit = totalRevenue * avgRate;
        $('#summary-avg-rate').text(trimFmt(avgRate));
        $('#summary-total-credit').text(trimFmt(totalCredit, 2));
        $('#summary-expense').text(trimFmt(totalExpenseTwd, 2) + ' TWD');
        $('#summary-expense-count').text(allExpenses.length);
        $('#summary-profit').text((profitTwd >= 0 ? '+' : '') + trimFmt(profitTwd, 2) + ' TWD');
        $('#summary-profit').css('color', profitTwd >= 0 ? '#198754' : '#dc3545');

        // 收入明細
        $('#income-topup').text(t.usdt_fmt + ' USDT');
        $('#income-topup-rate').text(t.avg_rate_fmt);
        $('#income-topup-credit').text(t.credit_fmt);
        $('#income-vm').text(v.usdt_fmt + ' USDT');
        $('#income-vm-rate').text(v.avg_rate_fmt);
        $('#income-vm-twd').text(v.twd_fmt + ' TWD');
        $('#income-total').text(trimFmt(totalRevenue) + ' USDT');

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
                html += '<td class="text-end">' + trimFmt(e.amount, 2) + (cur !== 'TWD' ? ' <span class="text-muted">' + cur + '</span>' : '') + '</td>';
                html += '<td class="text-center">';
                if (e.type === 'misc') {
                    html += parseInt(e.reimbursed, 10) === 1
                        ? '<span class="badge bg-success">已請款</span>'
                        : '<span class="badge bg-secondary">未請款</span>';
                } else { html += '-'; }
                html += '</td>';
                html += '<td><div class="d-flex gap-1">';
                html += '<button class="btn btn-sm btn-outline-secondary js-edit-expense" data-id="' + e.id + '" data-type="' + e.type + '" data-category="' + (e.category || '') + '" data-name="' + $('<span>').text(e.name).html() + '" data-amount="' + e.amount + '" data-currency="' + (e.currency || 'TWD') + '" data-date="' + (e.expense_date ? e.expense_date.substring(0, 10) : '') + '" data-reimbursed="' + (e.reimbursed || 0) + '" data-note="' + $('<span>').text(e.note || '').html() + '"><i class="fas fa-edit me-1"></i>編輯</button>';
                html += '<button class="btn btn-sm btn-outline-secondary js-del-expense" data-id="' + e.id + '"><i class="fas fa-trash-alt text-danger me-1"></i>刪除</button>';
                html += '</div></td>';
                html += '</tr>';
            });
            $body.html(html);
        }
        $('#expense-total').text(trimFmt(totalExpenseTwd, 2));
    }

    // 新增支出
    // 新增支出
    $('#btn-add-expense').on('click', function () {
        $('#modal-expense-title').text('新增支出');
        $('#form-expense')[0].reset();
        $('#expense-edit-id').val('');
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
        $('#expense-date').val($btn.data('date'));
        $('#expense-reimbursed').prop('checked', parseInt($btn.data('reimbursed'), 10) === 1);
        $('#expense-note').val($btn.data('note'));
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
            expense_date: $('#expense-date').val() || null,
            reimbursed: $('#expense-reimbursed').is(':checked') ? 1 : 0,
            note: $('#expense-note').val().trim() || null
        };
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
            '<div class="mb-2"><label class="form-label">總 USDT</label><input type="number" class="form-control" id="stat-topup-usdt" step="0.0001" value="' + (t.usdt || 0) + '"></div>' +
            '<div class="mb-2"><label class="form-label">平均匯率</label><input type="number" class="form-control" id="stat-topup-rate" step="0.0001" value="' + (t.avg_rate || 0) + '"></div>' +
            '<div class="mb-2"><label class="form-label">換算點數</label><input type="number" class="form-control" id="stat-topup-credit" step="0.01" value="' + (t.credit || 0) + '"></div>'
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
