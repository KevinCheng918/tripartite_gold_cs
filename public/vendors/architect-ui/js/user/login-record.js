$(function () {
    // 日期選擇（沿用 ArchitectUI 範例設定）
    const drpOpts = {
        singleDatePicker: true,
        autoUpdateInput: true,
        showDropdowns: true,
        timePicker: true,
        timePicker24Hour: true,
        timePickerSeconds: true,
        locale: {
            format: 'YYYY-MM-DD HH:mm:ss',
            applyLabel: '套用',
            cancelLabel: '清除',
            daysOfWeek: ['日','一','二','三','四','五','六'],
            monthNames: ['一月','二月','三月','四月','五月','六月','七月','八月','九月','十月','十一月','十二月']
        }
    };
    $('#begin_at').daterangepicker(drpOpts).on('cancel.daterangepicker', function(){ $(this).val(''); });
    $('#end_at').daterangepicker(drpOpts).on('cancel.daterangepicker', function(){ $(this).val(''); });
});
