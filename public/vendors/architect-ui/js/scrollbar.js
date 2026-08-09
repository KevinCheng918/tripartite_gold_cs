// Perfect Scrollbar

$(document).ready(() => {
    setTimeout(function () {
        // 初始化 content scrollbar（若有）
        $(".scrollbar-container").each(function () {
            new PerfectScrollbar($(this)[0], {
                wheelSpeed: 2,
                wheelPropagation: false,
                minScrollbarLength: 20,
            });
        });

        // 初始化 sidebar scrollbar（一定要有這段）
        if ($(".scrollbar-sidebar")[0]) {
            new PerfectScrollbar(".scrollbar-sidebar", {
                wheelSpeed: 2,
                wheelPropagation: true,
                minScrollbarLength: 20,
            });
        }
    }, 1000);
});
