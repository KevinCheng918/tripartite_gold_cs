$(function () {
    // ===== Clipboard =====
    var clipboard = new ClipboardJS('.copy');

    // 建立/取得 tooltip 實例（BS5 / jQuery 皆可）
    function getTooltipInstance(el) {
        // BS5：原生 Tooltip
        if (window.bootstrap && bootstrap.Tooltip) {
            let inst = bootstrap.Tooltip.getInstance(el);
            if (!inst) {
                inst = new bootstrap.Tooltip(el, {trigger: 'manual'});
            }
            return {
                show: () => inst.show(),
                hide: () => inst.hide()
            };
        }
        // 退回 jQuery Tooltip（BS4）
        const $el = $(el);
        if (!$el.data('bs.tooltip') && !$el.data('tooltip')) {
            $el.tooltip({trigger: 'manual'});
        }
        return {
            show: () => $el.tooltip('show'),
            hide: () => $el.tooltip('hide')
        };
    }

    function markCopied(triggerEl) {
        $(triggerEl).removeClass('btn-outline-dark btn-outline-danger')
            .addClass('btn-outline-success');

        const tip = getTooltipInstance(triggerEl);
        tip.show();

        // 高亮對應欄位
        const id = $(triggerEl).data('toggle-id');
        const $target = $(`span#${id}`);
        const text = $target.text();
        if (text && !text.startsWith('> ')) {
            $target.text('> ' + text);
        }
        $target.addClass('text-success');

        setTimeout(function () {
            $(triggerEl).removeClass('btn-outline-success').addClass('btn-outline-dark');
            tip.hide();
        }, 1000);
    }

    function markCopyError(triggerEl) {
        $(triggerEl).removeClass('btn-outline-dark btn-outline-success')
            .addClass('btn-outline-danger');

        const tip = getTooltipInstance(triggerEl);
        tip.show();

        setTimeout(function () {
            $(triggerEl).removeClass('btn-outline-danger').addClass('btn-outline-dark');
            tip.hide();
        }, 1000);
    }

    clipboard.on('success', function (e) { markCopied(e.trigger); });
    clipboard.on('error',   function (e) { markCopyError(e.trigger); });

    // ===== 全選 / 反選 =====
    $("#check-all").on('click', function (e) {
        e.preventDefault();
        const $boxes = $(".row-checkbox:not([disabled])");
        const allChecked = $boxes.length === $boxes.filter(":checked").length;
        $boxes.prop("checked", !allChecked);
    });

    // ===== 批次轉單（重送） =====
    $('#all-in-one-resend-btn').on('click', function () {
        $('#resend-data').empty();
        $("#hidden-check-boxes").empty();

        const $checked = $("input.withdraw-checkbox[type=checkbox]:checked");
        if ($checked.length < 1) {
            $("#submit-btn, #payment-note").hide();
        } else {
            $("#submit-btn, #payment-note").show();
        }

        $checked.each(function () {
            const value  = $(this).val();
            const amount = parseFloat($(this).data("amount"));
            if (isNaN(amount)) return;

            $('#resend-data').append($('<li>').text(`${value} => ${amount}`));
            $("#hidden-check-boxes").append(
                $("<input>", {type: "hidden", name: "system_sn[]", value})
            );
        });
    });

    // ===== 批次更新狀態 =====
    $('#all-in-one-update-status-btn').on('click', function () {
        $('#update-status-data').empty();
        $("#hidden-update-status-check-boxes").empty();

        // 只統計清單勾選的那組
        const $checked = $("input.withdraw-checkbox[type=checkbox]:checked");

        if ($checked.length === 0) {
            $("#all-update-status-submit-btn, #all-update-status-payment-note").hide();
        } else {
            $("#all-update-status-submit-btn, #all-update-status-payment-note").show();
        }

        $checked.each(function () {
            const value  = $(this).val();
            const amount = parseFloat($(this).data("amount"));
            if (isNaN(amount)) return;

            $('#update-status-data').append($('<li>').text(`${value} => ${amount}`));
            $("#hidden-update-status-check-boxes").append(
                $("<input>", {type: "hidden", name: "system_sn[]", value})
            );
        });
    });

    // ===== 送出時顯示 loading 並禁用按鈕 =====
    $("#resendSelectionForm").on('submit', function () {
        $("#submit-btn").prop('disabled', true).hide()
            .after('<i class="fas fa-3x fa-sync-alt fa-spin"></i>Loading...');
    });
});
