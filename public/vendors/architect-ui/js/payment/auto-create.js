$(function () {
    $('#auto-create').on('click', function () {
        $.get('/ajax-auto-create', function (data) {
            $('#order-sn').val(data);
        }).fail(function () {
            // BS5：用 d-none 控制顯示
            $('#auto-create-alert').removeClass('d-none');
        });
    });
});
