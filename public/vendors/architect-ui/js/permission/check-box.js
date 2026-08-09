$(function () {

    clickDefaultBtn();//頁面載入勾選按鈕, for - id=permission-all, id^=permission-group-*

    clickAllBtn();//click id=permission-all             PS:全選 勾選按鈕
    clickGroupBtn();//click id^=permission-group-*      PS:全選分組 勾選按鈕
    clickMemberBtn();//click class^=permission-*        PS:細項 勾選按鈕

    var clickId;//click this id
    var idArray;//this id 拆成陣列
    var groupName;//id array last data == 權限群組代號（agent, member, sub...等）

    function clickDefaultBtn() {
        //for - id=permission-group-*
        $('[id^=permission-]').map(function () {
            clickId = this.id;
            idArray = clickId.split('-');
            groupName = idArray[idArray.length - 1];

            let permissionCount = $(`input:checkbox[class=permission-${groupName}]`).length;//該分組細項 按鈕總數量
            let checkPermissionCount = $(`input:checkbox[class=permission-${groupName}]:checked`).length;//該分組細項 已勾選按鈕總數量
            if (permissionCount === checkPermissionCount) {
                $(`#permission-group-${groupName}`).prop("checked", true);//全選 - 分組 按鈕勾選
            } else {
                $(`#permission-group-${groupName}`).prop("checked", false);//全選 - 分組 按鈕取消勾選
            }
        });
        //for - id=permission-all
        let groupCount = $('input:checkbox[id^=permission-group-]').length;//該分組 按鈕總數量
        let checkGroupCount = $('input:checkbox[id^=permission-group-]:checked').length;//該分組 已勾選按鈕總數量
        if (groupCount === checkGroupCount && groupCount !== 0) {
            $('#permission-all').prop("checked", true);//全選 按鈕勾選
        } else {
            $('#permission-all').prop("checked", false);//全選 按鈕取消勾選
        }
    }

    function clickAllBtn() {
        //for - id=permission-all click
        $('#permission-all').click(function () {
            if (this.checked) {
                $('[id^=permission-group-]').prop("checked", true);
                $('[class^=permission-]').prop("checked", true);
            } else {
                $('[id^=permission-group-]').prop("checked", false);
                $('[class^=permission-]').prop("checked", false);
            }

            clickDefaultBtn();
        });
    }

    function clickGroupBtn() {
        //for - id^=permission-group-* click
        $('[id^=permission-group-]').click(function () {
            clickId = this.id;
            idArray = clickId.split('-');
            groupName = idArray[idArray.length - 1];

            if (this.checked) {
                $(`.permission-${groupName}`).prop("checked", true);
            } else {
                $(`.permission-${groupName}`).prop("checked", false)
            }

            clickDefaultBtn();
        });
    }

    function clickMemberBtn() {
        //for - class^=permission-* click
        $('[class^=permission-]').click(function () {
            clickId = this.id;
            idArray = clickId.split('-');
            groupName = idArray[idArray.length - 1];

            if (this.checked) {
                $(`#permission-group-${groupName}`).prop("checked", true);
            } else {
                $(`#permission-group-${groupName}`).prop("checked", false)
            }

            clickDefaultBtn();
        });
    }
});
