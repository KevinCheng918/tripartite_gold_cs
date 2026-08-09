$(function () {
    $('.build-google-qr').click(function () {
        $('#google-qr').html('');
        $('#google-secret').val('');

        $.ajax({
            type: 'GET',
            url: `/ajax-google/${$(this).data("user-id")}/build-2fa`,
            cache: 'false',
            success: function (response) {
                console.log(response.google_qr);
                let trString =
                    response.google_qr +
                    "<br/>" +
                    "<b>Key</b>" +
                    "<br/>" +
                    response.google_secret;

                $('#google-qr').html(trString);
                $('#google-secret').val(response.google_secret);
            },
            error: function (exception) {
                $('#google-qr').html("<span class='text-danger'>Error Build Google QR Code!!</span>");
            }
        });
    });
});