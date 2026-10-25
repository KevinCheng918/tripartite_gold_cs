<?php

return [
    'failed'   => '帳號或密碼錯誤',
    'password' => '密碼不正確',
    'throttle' => '登入嘗試次數過多，請在 :seconds 秒後再試',
    'logout'   => '登出',

    /*
     * 登入頁停留太久才送出（419）時，導回登入頁要講的話。
     *
     * ⚠ 不要寫成「連線逾時」或「系統忙碌」—— 那會讓人以為是網路或系統有問題
     * 而一直重試。他什麼都沒做錯，只是頁面開太久了，重打一次就好。
     */
    'page_expired' => '登入頁開啟太久，請重新輸入帳號密碼再登入一次',

    // 登入過期提示（由 layout 傳給 public/js/common.js 的 AuthGuard）
    'expired_title'  => '登入已過期',
    'expired_hint'   => '請重新登入後再操作，剛才那筆動作沒有存檔。',
    'expired_action' => '重新登入',
];
