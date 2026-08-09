$(function () {
    // 複製一組銀行資訊
    $("#copy-bank").on("click", function () {
        if (!confirm("您確定新增一組資訊嗎？")) return;

        $(".origin-tag").html(`<h4 class="text-danger">No. 1</h4>`);

        const newGroups = $(".new-bank");
        const originalGroupCount = $('[id^="bank-code-"]').length;
        let newTag = originalGroupCount + 1;

        const fieldNames = ['bank_code', 'bank_account', 'bank_name', 'user_name', 'custom'];
        const newFormGroups = [];

        fieldNames.forEach(fieldName => {
            const id = fieldName.replace(/_/g, '-');
            const $newGroup = $(`.${id}-group`).clone();
            $newGroup.attr('class', 'form-group');    // 保留簡單 class，不綁版型
            $newGroup.find("input")
                .attr("id", `${id}-${newTag}`)
                .attr("name", `${fieldName}_${newTag}`)
                .val("");
            newFormGroups.push($newGroup);
        });

        newTag += 1;
        newGroups.append(`<h4 class="text-danger">No. ${newTag}</h4>`);
        newGroups.append(newFormGroups);
        newGroups.append('<hr/>');
    });

    // 依入金類型切換 UI
    const $bankGroup = $('.bank-group');
    const $bankCodeInput = $('#bank-code');
    const $bankAccountInput = $('#bank-account');
    const $paymentSelect = $('#payment-select');
    const $depositPaymentTypeSelect = $('#deposit-payment-type-select');

    let depositPaymentType = localStorage.getItem('depositPaymentType');
    if (depositPaymentType === null) {
        depositPaymentType = $depositPaymentTypeSelect.val();
    }
    $depositPaymentTypeSelect.val(depositPaymentType);
    checkUi(depositPaymentType);
    localStorage.setItem('depositPaymentType', depositPaymentType);

    $depositPaymentTypeSelect.on('change', function () {
        const v = $(this).val();
        checkUi(v);
        localStorage.setItem('depositPaymentType', v);
    });

    function checkUi(val) {
        // 一律先重置 - 改用 css
        $('.user-name-group input').prop('disabled', false);
        $('.user-name-input').css('display', 'none');

        // BS5：cvs-input 裡面是 select，不一定是 input
        $('.cvs-input :input').prop('disabled', false);
        $('.cvs-input').css('display', 'none');

        $('.pay-content-input').css('display', 'none');
        $('.user-phone-input').css('display', 'none');
        $('.email-input').css('display', 'none');
        $('.user-id-number-input').css('display', 'none');
        $('.user-uid-input').css('display', 'none');
        $('.atm-bank-account-input').css('display', 'none');
        $('.atm-bank-code-input').css('display', 'none');

        if (val === '1') { // 超商
            $bankGroup.hide().find(':input').prop('disabled', true);
            $paymentSelect.find('option').show()
                .filter(':not(:contains("(CVS)"))').hide();
            $paymentSelect.find('option:contains("(CVS)"):first').prop('selected', true);

            $bankCodeInput.removeAttr('required');
            $bankAccountInput.removeAttr('required');

            // 改用 .css('display', 'block')
            $('.pay-content-input').css('display', 'block');
            $('.user-phone-input').css('display', 'block');
            $('.email-input').css('display', 'block');
            $('.user-id-number-input').css('display', 'block');
            $('.user-name-input').css('display', 'block');
            $('.cvs-input').css('display', 'block');

        } else if (val === '2') { // 虛擬帳號
            $bankGroup.hide().find(':input').prop('disabled', true);
            $paymentSelect.find('option').show()
                .filter(':not(:contains("(ATM)"))').hide();
            $paymentSelect.find('option:contains("(ATM)"):first').prop('selected', true);

            $bankCodeInput.removeAttr('required');
            $bankAccountInput.removeAttr('required');

            $('.user-name-input').css('display', 'block');
            $('.pay-content-input').css('display', 'block');
            $('.user-phone-input').css('display', 'block');
            $('.email-input').css('display', 'block');
            $('.user-id-number-input').css('display', 'block');
            $('.user-uid-input').css('display', 'block');
            $('.atm-bank-account-input').css('display', 'block');
            $('.atm-bank-code-input').css('display', 'block');

        } else if (val === '3') { // 信用卡
            $bankGroup.hide().find(':input').prop('disabled', true);
            $paymentSelect.find('option').show()
                .filter(':not(:contains("(CC)"))').hide();
            $paymentSelect.find('option:contains("(CC)"):first').prop('selected', true);

            $bankCodeInput.removeAttr('required');
            $bankAccountInput.removeAttr('required');

            $('.user-name-input').css('display', 'block');
            $('.user-phone-input').css('display', 'block');
            $('.pay-content-input').css('display', 'block');
            $('.email-input').css('display', 'block');
            $('.user-id-number-input').css('display', 'block');

        } else if (val === '5') { // QR Code
            $bankGroup.hide().find(':input').prop('disabled', true);
            $paymentSelect.find('option').show()
                .filter(':not(:contains("(QR)"))').hide();
            $paymentSelect.find('option:contains("(QR)"):first').prop('selected', true);

            $bankCodeInput.removeAttr('required');
            $bankAccountInput.removeAttr('required');

            $('.user-name-input').css('display', 'block');

        } else { // 銀行轉帳（預設）
            $bankGroup.show().find(':input').prop('disabled', false);

            // 顯示非 CVS/ATM/CC/USDT/QR 的支付
            $paymentSelect.find('option').show();
            $paymentSelect.find('option:contains("(CVS)")').hide();
            $paymentSelect.find('option:contains("(ATM)")').hide();
            $paymentSelect.find('option:contains("(CC)")').hide();
            $paymentSelect.find('option:contains("(USDT)")').hide();
            $paymentSelect.find('option:contains("(QR)")').hide();

            // 選第一個可見項
            $paymentSelect.find('option:visible:first').prop('selected', true);

            $bankCodeInput.attr('required', true);
            $bankAccountInput.attr('required', true);

            $('.user-id-number-input').css('display', 'block');
        }
    }

    // 避免重複送出
    $('button[type="submit"]').on('click', function () {
        const $btns = $('button[type="submit"]');
        $btns.prop('disabled', true);
        $(this).closest('form')[0].submit();
        setTimeout(function () {
            $btns.prop('disabled', false);
        }, 1500);
    });
});
