<?php

return [
    'nav_label'  => '通知設定',
    'page_title' => '通知設定',
    'subtitle'   => '支援群組與話題、求助單提醒、班表通知、提醒統計',

    'section_label' => '通訊管理',

    'tab_group'  => '支援群組與話題',
    'tab_remind' => '求助單提醒',
    'tab_shift'  => '班表通知',
    'tab_report' => '超時提醒統計',

    'select_all' => '全選',
    'unbound'    => '未綁定',

    // ===== 內部支援群組 =====
    'support_title'          => '內部支援群組',
    'support_desc'           => '題庫裡找不到答案時，問題會轉到這個群組請自己人回答。',
    'support_chat_id'        => '群組 chat_id',
    'support_chat_id_hint'   => '群組的 chat_id 是負數。Bot 必須已加入群組，且 Group Privacy 要關閉。',
    'support_system'         => '使用的 Bot',
    'support_system_default' => '預設 Bot（.env）',
    'support_test'           => '發送測試訊息',

    // ===== 求助單提醒 =====
    'remind_title'         => '求助單超時提醒',
    'remind_desc'          => 'AI 答不出來轉到支援群組的問題，沒人回答就會一直提醒到有人處理。第 1、2 次 tag 當班人員，第 :escalate 次起同時 tag 主管與老闆（都跳過工程）。',
    'remind_first'         => '第一次提醒（分鐘）',
    'remind_first_hint'    => '開單後超過這個時間沒人回答，tag 當下排班的人員。',
    'remind_interval'      => '之後每隔（分鐘）',
    'remind_interval_hint' => '第一次之後每隔這個時間再提醒一次，一直催到問題被處理。第 :escalate 次起會同時 tag 主管與老闆（都跳過工程）。',
    'remind_max'           => '最多提醒幾次',
    'remind_max_hint'      => '到這個次數仍沒處理就停止提醒，並發最後一則通知主管與老闆。避免深夜沒人值班時被連續轟炸。',

    // ===== 每日統計 =====
    'report_title'     => '每日提醒統計',
    'report_desc'      => '每天 :time 把前一天的超時提醒統計私訊出去。勾選的人收「全部人的」（依題目、依人員），被提醒到的同仁另外會各自收到「自己那份」，不需要設定。',
    'report_user'      => '完整統計私訊給（可多選）',
    'report_user_hint' => '建議勾主管以上。標示「未綁定」的人收不到，請他先私訊機器人一次。',
    'report_test'      => '測試發送',
    'report_test_hint' => '會立刻把昨天的完整統計私訊給上面勾選的人。只發完整版，不會打擾昨天被提醒到的同仁。',
    'report_personal_title' => '個人統計',
    'report_personal_desc'  => '昨天被提醒到的同仁，每天 :time 會各自收到「自己那份」（我被催了哪幾題）。這部分自動發送，不需要設定；沒被提醒到的人不會收到。',

    // ===== 話題分流 =====
    'topic_title'         => '話題分流',
    'topic_desc'          => '群組開了 Telegram 的話題功能時，可以把不同通知分到不同話題。新增話題後勾選它要收哪些通知；沒有被任何話題勾到的通知會發到群組主區。',
    'topic_general'       => '群組主區',
    'topic_name'          => '話題名稱（備註用）',
    'topic_name_ph'       => '例如：排程通知',
    'topic_thread_id'     => '話題 id',
    'topic_types'         => '這個話題要收哪些通知',
    'topic_add'           => '新增話題',
    'topic_remove'        => '刪除',
    'topic_empty'         => '還沒有設定任何話題，所有通知都會發到群組主區。',
    'topic_single_badge'  => '只能一個話題',
    'topic_test'          => '測試發送',
    'topic_test_hint'     => '會逐話題各發一則測試訊息（含群組主區）。話題 id 填錯 Telegram 會整則拒收，所以一定要測過才算設定完成。',

    'topic_howto_title' => '話題 id 怎麼取得',
    'topic_howto_1'     => '在 Telegram 打開您要設定的那個話題。',
    'topic_howto_2'     => '在話題裡直接輸入：',
    'topic_howto_3'     => '機器人會回覆那個話題的 id，把數字填到左邊即可。',
    'topic_howto_note'  => '一定要在話題「裡面」輸入。在群組主區輸入會回覆「這裡沒有話題 id」。',

    // ===== 班表通知 =====
    'shift_title'          => '完整班表收件人',
    'shift_desc'           => '每天 :time 私訊今日完整班表（每個班次是誰、哪個班沒人）。可以勾多位，沒勾任何人時不會發送。',
    'shift_user'           => '收件人（可多選）',
    'shift_user_hint'      => '標示「未綁定」的同仁收不到訊息，請他先私訊機器人一次。全部取消勾選就不發送。',
    'shift_test'           => '測試發送',
    'shift_test_hint'      => '會立刻把今天的完整班表私訊給上面勾選的收件人。',
    'shift_personal_title' => '個人班表',
    'shift_personal_desc'  => '今天有班的同仁，每天 :time 會各自收到自己那一份（只有自己的班別與時間，不會看到其他人）。這部分自動發送，不需要設定。',

    'bind_title' => '為什麼有人收不到私訊',
    'bind_step1' => 'Telegram 不允許機器人主動私訊「從來沒跟它對話過」的人。',
    'bind_step2' => '請同仁在 Telegram 找到客服機器人，私訊任何一句話（例如「hi」）。',
    'bind_step3' => '送出後系統會自動記錄，隔天早上就收得到了。',
    'bind_note'  => '只需要做一次。在群組裡發言不算，一定要是私訊。',

    'action_save'    => '儲存',
    'action_saving'  => '儲存中…',
    'action_testing' => '發送中…',

    /*
     * 話題分流可以勾的通知類型。
     *
     * ⚠ 名稱要寫「**群組裡會收到什麼**」，不是「這是哪個功能」。
     * 班表通知本身是私訊、不會進群組，進群組的只有「誰沒收到」那則彙總 ——
     * 原本寫「班表通知異常」會讓人以為是另一個功能。
     */
    'notice_type' => [
        'auto_reply_ticket'      => 'AI 轉來的問題',
        'auto_reply_ticket_hint' => '題庫找不到答案時轉出來的求助單、超時提醒、處理結果。客服在這裡引用回覆作答',
        'daily_rate'             => '每日匯率報價',
        'daily_rate_hint'        => '每天 9:00 的報價、沒決定時每 30 分的提醒、決定後的結果。要引用回覆決定',
        'ticket_handover'        => '早班待接手清單',
        'ticket_handover_hint'   => '每天 7:30 把還沒人處理的問題交接給當天早班',
        'shift_notice'           => '班表沒收到的人',
        'shift_notice_hint'      => '今日班表私訊不出去時，把沒收到的同仁彙總成一則。班表本身是私訊，不會進群組',
        'vm_payment'             => '虛擬機繳款',
        'vm_payment_hint'        => '每天 9:30 的繳款通知：站台沒設群組時退回內部、有待審核時催內部審核',
        'station_credit'         => '站台餘點告警',
        'station_credit_hint'    => '每天 10:00 的餘點告警與補點訊息，站台沒設群組時退回內部',
    ],

    'msg' => [
        'saved'       => '已儲存',
        'save_failed' => '儲存失敗，請稍後再試',

        // 支援群組
        'chat_id_invalid'     => 'chat_id 格式不正確（群組的 id 是負數）',
        'chat_id_is_customer' => '這個 chat_id 已經出現在 Telegram 客服的對話列表裡，不能同時當內部支援群組（那個對話的訊息會全部被攔掉、不會送達也不會自動回覆）。請先到「Telegram 客服」把該筆對話刪除，再回來儲存。'
            . '｜常見情況：支援群組開啟「話題」功能被升級成 supergroup 之後，舊 chat_id 失效的那段時間，系統認不得它，就把它當成新客戶建了一筆對話。那一筆刪掉即可。',
        'system_not_found'    => '找不到該系統',
        'support_test_sent'   => '測試訊息已送出，請到群組確認',
        'support_test_failed' => '測試訊息送出失敗，請確認 chat_id、Bot 是否已加入群組、以及 Group Privacy 是否關閉',
        'chat_id_migrated'    => '這個群組已經升級成 supergroup（開啟「話題」功能時會發生），chat_id 換了。新的是 :id —— 請把上面的 chat_id 改成這組再儲存。',

        // 提醒
        'remind_required'      => '請填寫提醒時間',
        'remind_invalid'       => '提醒時間須介於 1 到 1440 分鐘',
        'remind_max_required'  => '請填寫最多提醒幾次',
        'remind_max_invalid'   => '提醒次數須介於 1 到 200 次',
        'report_user_not_found' => '找不到這個帳號',
        'report_test_sent'      => '測試統計已送出 :sent 則，請確認 Telegram 有收到',
        'report_test_partial'   => '已送出 :sent 則，但這幾位沒收到：:failed（請他們先私訊機器人一次）',
        'report_test_no_user'   => '請先勾選收件人並儲存',
        'report_test_not_bound' => '勾選的同仁都還沒私訊過機器人，所以收不到訊息',
        'report_test_failed'    => '發送失敗，請確認同仁沒有封鎖機器人',

        // 話題
        'topic_too_many'       => '話題數量超過上限',
        'topic_name_too_long'  => '話題名稱太長',
        'thread_id_required'   => '請填寫話題 id',
        'thread_id_invalid'    => '話題 id 必須是大於 0 的整數',
        'thread_id_duplicate'  => '話題 id :id 重複了，同一個話題不要設定兩次',
        'notice_type_invalid'  => '通知類型不正確',
        'type_single_only'     => '「:type」需要客服引用回覆才能運作，只能勾在一個話題',
        'topic_test_sent'      => '已送出 :sent 則測試訊息，請到群組各話題確認',
        'topic_test_partial'   => '已送出 :sent 則，但這幾個發不出去：:failed（話題 id 可能填錯或話題已刪除）',
        'topic_test_failed'    => '測試訊息送不出去，請先確認上一個分頁的群組 chat_id 設定正確',

        // 班表
        'manager_invalid'        => '收件人格式不正確',
        'manager_not_found'      => '找不到這個帳號',
        'shift_test_sent'        => '測試訊息已送出 :sent 則，請確認 Telegram 有收到',
        'shift_test_partial'     => '已送出 :sent 則，但這幾位沒收到：:failed（請他們先私訊機器人一次）',
        'shift_test_no_manager'  => '請先勾選收件人並儲存',
        'shift_test_not_bound'   => '勾選的同仁都還沒私訊過機器人，所以收不到訊息',
        'shift_test_failed'      => '發送失敗，請確認同仁沒有封鎖機器人',
    ],
];
