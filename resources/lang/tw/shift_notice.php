<?php

return [
    'nav_label'  => '班表通知',
    'page_title' => '班表通知',
    'subtitle'   => '每天早上自動私訊今日班表',

    'section_label' => '通訊管理',

    'manager_title' => '完整班表收件人',
    'manager_desc'  => '每天 :time 私訊今日完整班表（每個班次是誰、哪個班沒人）。可以勾多位，沒勾任何人時不會發送。',
    'manager_field'  => '收件人（可多選）',
    'manager_all'    => '全選',
    'manager_hint'   => '標示「未綁定」的同仁收不到訊息，請他先私訊機器人一次。全部取消勾選就不發送。',
    'manager_unbound' => '未綁定',

    'personal_title' => '個人班表',
    'personal_desc'  => '今天有班的同仁，每天 :time 會各自收到自己那一份（只有自己的班別與時間，不會看到其他人）。這部分自動發送，不需要設定。',

    'bind_title' => '為什麼有人收不到',
    'bind_step1' => 'Telegram 不允許機器人主動私訊「從來沒跟它對話過」的人。',
    'bind_step2' => '請同仁在 Telegram 找到客服機器人，私訊任何一句話（例如「hi」）。',
    'bind_step3' => '送出後系統會自動記錄，隔天早上就收得到班表了。',
    'bind_note'  => '只需要做一次。在群組裡發言不算，一定要是私訊。',

    'action_save'    => '儲存',
    'action_saving'  => '儲存中…',
    'action_test'    => '測試發送',
    'action_testing' => '發送中…',
    'test_hint'      => '會立刻把今天的完整班表私訊給上面勾選的收件人。',

    'msg' => [
        'saved'             => '已儲存',
        'save_failed'       => '儲存失敗，請稍後再試',
        'manager_invalid'   => '收件人格式不正確',
        'manager_not_found' => '找不到這個帳號',
        'test_sent'         => '測試訊息已送出 :sent 則，請確認 Telegram 有收到',
        'test_partial'      => '已送出 :sent 則，但這幾位沒收到：:failed（請他們先私訊機器人一次）',
        'test_no_manager'   => '請先勾選收件人並儲存',
        'test_not_bound'    => '勾選的同仁都還沒私訊過機器人，所以收不到訊息',
        'test_failed'       => '發送失敗，請確認同仁沒有封鎖機器人',
    ],
];
