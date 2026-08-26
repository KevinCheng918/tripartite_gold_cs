/**
 * 登入頁：全形字元即時提示
 *
 * 帳號密碼皆只接受半形，中文輸入法打出的全形符號（＠！）、全形空白會登入失敗。
 * 這裡只做提示不阻擋送出 —— 若有既存帳號的密碼含全形，硬擋會讓該帳號完全無法登入。
 */
(function () {
    'use strict';

    // CJK 標點、全形形式、彎引號、漢字
    var FULL_WIDTH = /[\u3000-\u303F\uFF00-\uFFEF\u2018\u2019\u201C\u201D\u4E00-\u9FFF]/;

    var warning = document.getElementById('login-fw-warning');
    var fields = [document.getElementById('account'), document.getElementById('password')];

    if (!warning) {
        return;
    }

    function checkFullWidth() {
        var found = false;

        fields.forEach(function (field) {
            var hit = FULL_WIDTH.test(field.value);
            field.classList.toggle('has-full-width', hit);
            if (hit) {
                found = true;
            }
        });

        warning.style.display = found ? 'block' : 'none';
    }

    fields.forEach(function (field) {
        field.addEventListener('input', checkFullWidth);
        // 注音／拼音組字結束時也要檢查
        field.addEventListener('compositionend', checkFullWidth);
    });
})();
