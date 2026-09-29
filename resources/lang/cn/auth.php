<?php

return [
    'failed'   => '账号或密码错误',
    'password' => '密码不正确',
    'throttle' => '登录尝试次数过多，请在 :seconds 秒后再试',
    'logout'   => '登出',

    // 登录过期提示（由 layout 传给 public/js/common.js 的 AuthGuard）
    'expired_title'  => '登录已过期',
    'expired_hint'   => '请重新登录后再操作，刚才那笔动作没有存档。',
    'expired_action' => '重新登录',
];
