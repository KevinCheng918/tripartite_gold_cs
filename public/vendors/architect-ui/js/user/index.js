$(function () {
    // 清除 Google 秘鑰
    $(document).on('click', '.clear-google-secret', function () {
        const userId = $(this).data('userid');
        $(`#google_secret-${userId}`).val('');
        $(`#google_qr-${userId}`).empty();
    });

    // 依據 radio 值切換「凍結/解凍」欄位
    function syncFreezeCredit(userId) {
        const val = $(`.freeze-credit-type-radio[data-userid="${userId}"]:checked`).val();
        const $freezeDiv    = $(`#freeze_credit_div_${userId}`);
        const $freezeInput  = $(`#freeze_credit_input_${userId}`);
        const $unfreezeDiv  = $(`#unfreeze_credit_div_${userId}`);
        const $unfreezeInput= $(`#unfreeze_credit_input_${userId}`);

        if (val === 'freeze') {
            $freezeDiv.removeClass('d-none').prop('hidden', false);
            $unfreezeDiv.addClass('d-none').prop('hidden', true);
            $freezeInput.prop('disabled', false);
            $unfreezeInput.prop('disabled', true);
        } else {
            $freezeDiv.addClass('d-none').prop('hidden', true);
            $unfreezeDiv.removeClass('d-none').prop('hidden', false);
            $freezeInput.prop('disabled', true);
            $unfreezeInput.prop('disabled', false);
        }
    }

    // 初始同步（頁面上每一個表單各跑一次）
    $('.freeze_credit_form').each(function () {
        const $firstRadio = $(this).find('.freeze-credit-type-radio').first();
        if ($firstRadio.length) {
            syncFreezeCredit($firstRadio.data('userid'));
        }
    });

    // 切換 radio 即時同步
    $(document).on('change', '.freeze-credit-type-radio', function () {
        syncFreezeCredit($(this).data('userid'));
    });

    // BS5：modal 展開時再同步一次（避免第一次開啟時沒跑到）
    document.querySelectorAll('.modal').forEach(function (m) {
        m.addEventListener('shown.bs.modal', function () {
            const r = m.querySelector('.freeze-credit-type-radio');
            if (r) syncFreezeCredit(r.getAttribute('data-userid'));
        });
    });

    $('[id^="modal-issued-credit-"]').on('shown.bs.modal', function(e) {
        console.log('Modal 完全打開了');

        var button = $(e.relatedTarget);
        var userId = button.data('user-id');

        console.log('User ID:', userId);

        // 初次打開時載入
        loadTotalCredit(userId);

        // 修正：使用正確的 ID (issued-date 不是 issued-datetime)
        var $dateInput = $('#issued-date-' + userId);
        console.log('時間輸入框:', $dateInput.length);

        $dateInput.off('change input').on('change input', function() {
            console.log('日期改變了！新日期:', $(this).val());
            loadTotalCredit(userId);
        });
    });

    function loadTotalCredit(userId) {
        console.log('呼叫 loadTotalCredit, userId:', userId);

        var $creditSpan = $('#total-credit-' + userId);

        // 取得時間類型
        var timeType = $('input[name="time_type"][data-user-id="' + userId + '"]:checked').val();

        // 取得開始和結束時間
        var startDate = $('#start-date-' + userId).val();
        var endDate = $('#end-date-' + userId).val();

        console.log('時間類型:', timeType);
        console.log('開始時間:', startDate);
        console.log('結束時間:', endDate);

        $creditSpan.html('<i class="fas fa-spinner fa-spin"></i> 載入中...');

        $.ajax({
            url: '/user/' + userId + '/get-total-credit',
            method: 'GET',
            data: {
                time_type: timeType,
                start_date: startDate,
                end_date: endDate
            },
            success: function(response) {
                console.log('API 回應:', response);
                $creditSpan.html(response.totalCredit);
            },
            error: function(xhr, status, error) {
                console.error('AJAX 錯誤:', error);
                console.error('回應:', xhr.responseText);
                $creditSpan.html('<span class="text-danger">載入失敗</span>');
            }
        });
    }

    // 當切換時間類型時，更新日期範圍並重新載入
    $(document).on('change', '.time-type-radio', function() {
        var userId = $(this).data('user-id');
        var timeType = $(this).val();
        var $startDate = $('#start-date-' + userId);
        var $endDate = $('#end-date-' + userId);

        var today = new Date();

        if (timeType === 'created') {
            // 建單時間：兩週前到今天
            var twoWeeksAgo = new Date(today);
            twoWeeksAgo.setDate(today.getDate() - 14);

            $startDate.val(formatDate(twoWeeksAgo));
            $endDate.val(formatDate(today));
        } else if (timeType === 'expected') {
            // 預計下放時間：今天到一週後
            var oneWeekLater = new Date(today);
            oneWeekLater.setDate(today.getDate() + 7);

            $startDate.val(formatDate(today));
            $endDate.val(formatDate(oneWeekLater));
        }

        // 重新載入總額
        loadTotalCredit(userId);
    });

    // 當修改開始或結束時間時，重新載入
    $(document).on('change', '[id^="start-date-"], [id^="end-date-"]', function() {
        // 從 ID 中提取 userId
        var userId = $(this).attr('id').match(/\d+/)[0];
        loadTotalCredit(userId);
    });

    // 格式化日期為 YYYY-MM-DD
    function formatDate(date) {
        var year = date.getFullYear();
        var month = String(date.getMonth() + 1).padStart(2, '0');
        var day = String(date.getDate()).padStart(2, '0');
        return year + '-' + month + '-' + day;
    }

    // 點擊查看餘額歷程
    $(document).on('click', '.view-credit-record-link', function(e) {
        e.preventDefault();

        var userId = $(this).data('user-id');
        var baseRoute = $(this).data('route');

        // 取得目前選擇的時間類型
        var timeType = $('input[name="time_type"][data-user-id="' + userId + '"]:checked').val();

        // 根據實際的 rules 調整 date_type
        var dateType = timeType === 'created' ? 'created_at' : 'calculate_at';

        // 取得開始和結束時間
        var startDate = $('#start-date-' + userId).val();
        var endDate = $('#end-date-' + userId).val();

        // 組合 URL 參數
        var params = new URLSearchParams({
            'start_at': startDate + ' 00:00:00',
            'end_at': endDate + ' 23:59:59',
            'date_type': dateType,
            'date_radio': 'this_today',
            'status': '0'
        });

        // 跳轉到餘額歷程頁面
        window.open(baseRoute + '?' + params.toString(), '_blank');
    });
});
