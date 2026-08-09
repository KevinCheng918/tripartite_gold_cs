$(function () {
    $('#show-summary-btn').on('click', function () {
        // 从表单中提取关键信息
        var agent = $('#account-name').val();
        var payment = $('#payment-select option:selected').text();
        var orderSn = $('#order-sn').val();
        var amount = $('#amount').val();
        var bankCode = $('#bank-code').val();
        var bankAccount = $('#bank-account').val();
        var bankName = $('#bank-name').val();
        var userName = $('#user-name').val();
        var note = $('#note').val();

        // 创建一个 HTML 字符串来显示在模态框中
        var modalContent = '<ul><li>代理帳號: ' + agent + '</li>' +
            '<li>支付管道: ' + payment + '</li>' +
            '<li>商戶訂單號: ' + orderSn + '</li><hr/>' +
            '<li><h3>金額: ' + amount + '</h3></p>' +
            '<li>銀行代碼: ' + bankCode + '</li >' +
            '<li>銀行帳號: ' + bankAccount + '</li>' +
            '<li>銀行名稱: ' + bankName + '</li>' +
            '<li>收款人: ' + userName + '</li>' +
            '<li>備註: ' + note + '</li></ul>';

        // 将信息插入模态框中
        $('#modal-summary').html(modalContent);

        // 显示模态框
        $('#submit-create-modal').modal('show');

        $('button[id="confirm-submit"]').on('click', function() {
            // 禁用所有的 submit 按鈕
            $('button[id="confirm-submit"]').prop('disabled', true);

            $('#withdraw-form').submit();

            // 設定一段時間後重新啟用按鈕（例如 2 秒）
            setTimeout(function() {
                $('button[id="confirm-submit"]').prop('disabled', false);
            }, 1500);
        });
    });

    $('button[type="submit"]').on('click', function() {
        // 禁用所有的 submit 按鈕
        $('button[type="submit"]').prop('disabled', true);

        $(this).closest('form').submit();

        // 設定一段時間後重新啟用按鈕（例如 2 秒）
        setTimeout(function() {
            $('button[type="submit"]').prop('disabled', false);
        }, 1500);
    });
});
