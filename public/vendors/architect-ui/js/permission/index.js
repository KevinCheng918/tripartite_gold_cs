$(function () {
    $('#permission-table').dataTable({
        "columnDefs": [
            //指定列不排序
            {"orderable": false, "targets": 1}
        ],
        "paging": false,
        "info": false
    });
});