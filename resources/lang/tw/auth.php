<?php

return [
    'failed'   => '帳號或密碼錯誤',
    'password' => '密碼不正確',
    'throttle' => '登入嘗試次數過多，請在 :seconds 秒後再試',
    'logout'   => '登出',

    // 登入過期提示（由 layout 傳給 public/js/common.js 的 AuthGuard）
    'expired_title'  => '登入已過期',
    'expired_hint'   => '請重新登入後再操作，剛才那筆動作沒有存檔。',
    'expired_action' => '重新登入',
];
