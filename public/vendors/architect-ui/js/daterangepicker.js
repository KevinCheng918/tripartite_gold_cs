$(function () {
    // 統一格式
    const FMT = 'YYYY-MM-DD HH:mm:ss';

    // 若後端/頁面有注入最小日期，這裡自動套用（可省略）
    const minDateStr = window.minDate || null;
    const minMoment  = minDateStr ? moment(minDateStr, FMT, true) : null;

    // 共同選項（單一日期 + 時分秒）
    const baseOpts = {
        singleDatePicker: true,
        autoUpdateInput: true,
        showDropdowns: true,
        timePicker: true,
        timePicker24Hour: true,
        timePickerSeconds: true,
        locale: {
            format: FMT,
            applyLabel: '套用',
            cancelLabel: '清除',
            daysOfWeek: ['日','一','二','三','四','五','六'],
            monthNames: [
                '一月','二月','三月','四月','五月','六月',
                '七月','八月','九月','十月','十一月','十二月'
            ]
        }
    };

    // 依目前 input 值建出對應初始化選項（含 minDate）
    function buildOpts($input) {
        const val  = $.trim($input.val());
        const opts = $.extend(true, {}, baseOpts);
        if (val && moment(val, FMT, true).isValid()) {
            opts.startDate = moment(val, FMT);
        }
        if (minMoment) opts.minDate = minMoment;
        return opts;
    }

    // 初始化兩個單日選擇器
    $('#start_at')
        .daterangepicker(buildOpts($('#start_at')))
        .on('apply.daterangepicker', function (ev, picker) {
            $(this).val(picker.startDate.format(FMT));
        })
        .on('cancel.daterangepicker', function () { $(this).val(''); });

    $('#end_at')
        .daterangepicker(buildOpts($('#end_at')))
        .on('apply.daterangepicker', function (ev, picker) {
            $(this).val(picker.startDate.format(FMT));
        })
        .on('cancel.daterangepicker', function () { $(this).val(''); });

    // 手輸校驗（格式 + 可選 minDate），並做格式 normalize
    const RE = /^\d{4}-(0\d|1[0-2])-(0\d|[12]\d|3[01]) (0\d|1\d|2[0-3]):[0-5]\d:[0-5]\d$/;
    $('.selector').on('blur', function () {
        const $i = $(this);
        const v  = $.trim($i.val());
        if (!v) return;

        const valid = RE.test(v) && moment(v, FMT, true).isValid();
        const beforeMin = valid && minMoment && moment(v, FMT, true).isBefore(minMoment);

        if (!valid) {
            // 不合法：清空或改成 minDate（若有）
            $i.val(minMoment ? minMoment.format(FMT) : '');
        } else if (beforeMin) {
            $i.val(minMoment.format(FMT));
        } else {
            // 正常化格式
            $i.val(moment(v, FMT).format(FMT));
        }
    });

    // 把字串寫回 input 並同步 daterangepicker 實例
    function writeInput($input, dateStr) {
        $input.val(dateStr);
        const drp = $input.data('daterangepicker');
        if (drp) {
            const m = moment(dateStr, FMT, true);
            drp.setStartDate(m);
            drp.setEndDate(m);
        }
    }

    function setStartAt(arr) { writeInput($('#start_at'), arr[0]); }
    function setEndAt(arr)   { writeInput($('#end_at'),   arr[1]); }

    // 快速日期 - 產生 [startStr, endStr]
    function dayRange(offset) {
        const base = moment().subtract(offset, 'days');
        return [
            base.clone().startOf('day').format(FMT),
            base.clone().endOf('day').format(FMT)
        ];
    }
    function weekRange(offset) {
        const base = moment().subtract(offset, 'week');
        return [
            base.clone().startOf('week').format(FMT),
            base.clone().endOf('week').format(FMT)
        ];
    }
    function monthRange(offset) {
        const base = moment().subtract(offset, 'month'); // 注意：是 'month' 不是 'months'
        return [
            base.clone().startOf('month').format(FMT),
            base.clone().endOf('month').format(FMT)
        ];
    }

    // 快速日期 radio 點擊
    $("input[name='date_radio']").on('click', function () {
        let r = null;
        switch (this.value) {
            case 'this_today':     r = dayRange(0);   break;
            case 'this_yesterday': r = dayRange(1);   break;
            case 'this_week':      r = weekRange(0);  break;
            case 'last_week':      r = weekRange(1);  break;
            case 'this_month':     r = monthRange(0); break;
            case 'last_month':     r = monthRange(1); break;
        }
        if (r) { setStartAt(r); setEndAt(r); }
    });

    // 送出前：把手輸值同步回 drp 實例，避免被覆寫
    $('#submit').on('click', function () {
        $('#start_at, #end_at').each(function () {
            const $i = $(this);
            const v  = $.trim($i.val());
            const drp = $i.data('daterangepicker');
            if (drp && moment(v, FMT, true).isValid()) {
                const m = moment(v, FMT, true);
                drp.setStartDate(m);
                drp.setEndDate(m);
            }
        });
    });
});
