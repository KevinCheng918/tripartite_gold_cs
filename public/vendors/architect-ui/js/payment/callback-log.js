$(function () {
    // 資料詳情動態請求
    $('.callback-log-btn').on('click', function () {
        var systemSn = $(this).data('sn');
        showModalAndLoadData('callback-log', systemSn);
    });

    // 回調動態請求
    $('.callback-btn').on('click', function () {
        var systemSn = $(this).data('sn');
        showModalAndLoadData('agent-callback-log', systemSn);
    });

    // 設置剪貼簿事件
    setupClipboard('.modal-callback-log');
    setupClipboard('.modal-agent-callback-log');


    /////////////
    /////////////


    // 函數：顯示模態框並載入資料
    function showModalAndLoadData(modalType, systemSn) {
        $('.loading-chat').show();
        $('#fa-sync-alt-loading').addClass('fa-spin');
        $(`#${modalType}-text-${systemSn}`).html(''); // 清空內容

        $.get(`/ajax-${modalType}?system_sn=${systemSn}`, function (data) {
            $(`#${modalType}-text-${systemSn}`).html(data); // 寫入資料
            $('.loading-chat').hide();
            $('#fa-sync-alt-loading').removeClass('fa-spin');
        }).fail(function () {
            console.log('error');
            $('.loading-chat').hide();
            $('#fa-sync-alt-loading').removeClass('fa-spin');
        });

        // 顯示模態框
        $(`#modal-${modalType}-${systemSn}`).modal('show');
    }

    // 監聽剪貼簿事件
    function setupClipboard(modalClass) {
        $(modalClass).each(function (i, obj) {
            var callbackCopy = new ClipboardJS('.callback-copy', {
                container: document.getElementById(obj.id)
            });

            callbackCopy.on('success', function (e) {
                $(e.trigger).removeClass('btn-outline-dark btn-outline-danger')
                    .addClass('btn-outline-success')
                    .tooltip('show');

                setTimeout(function () {
                    $(e.trigger).removeClass('btn-outline-success')
                        .addClass('btn-outline-dark')
                        .tooltip('hide');
                }, 1000);
            });

            callbackCopy.on('error', function (e) {
                $(e.trigger).removeClass('btn-outline-dark btn-outline-success')
                    .addClass('btn-outline-danger')
                    .tooltip('show');

                setTimeout(function () {
                    $(e.trigger).removeClass('btn-outline-danger')
                        .addClass('btn-outline-dark')
                        .tooltip('hide');
                }, 1000);
            });
        });
    }
});
