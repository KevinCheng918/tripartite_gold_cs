<?php

return [
    'nav_label'   => '全域設定',
    'page_title'  => '全域設定',
    'subtitle'    => 'Claude 憑證、備援 API 與用量流量',

    // Claude 主要設定
    'claude_title'        => 'Claude（自動回覆）',
    'claude_token'        => '訂閱 Token',
    'claude_token_hint'   => '在自己電腦執行 claude setup-token 產生，貼上後會先驗證再儲存。留空代表沿用現有的。',
    'claude_model'        => '模型',
    'claude_verified_at'  => '最後驗證成功',
    'claude_not_set'      => '尚未設定',

    // 如何取得訂閱 Token
    'claude_howto_title'   => '如何取得訂閱 Token？',
    'claude_howto_install' => '1. 在自己的電腦安裝 Claude Code（三種方式擇一）：',
    'claude_howto_login'   => '2. 執行 claude 並依瀏覽器提示登入。需要 Pro、Max、Team 或 Enterprise 訂閱，免費的 claude.ai 帳號不支援。',
    'claude_howto_token'   => '3. 登入後執行下列指令產生長效 Token：',
    'claude_howto_paste'   => '4. 依畫面指示完成授權，把終端機輸出的 Token 複製貼到上方欄位後儲存。系統會先驗證再存，驗不過不會動到原本的設定。',
    'claude_howto_note'    => '這把 Token 綁定執行者的訂閱帳號 —— 那個人的訂閱到期或取消，Token 就會失效。',

    // 備援
    'fallback_title'       => 'Claude（備援 API）',
    'fallback_desc'        => '訂閱額度用盡時自動接手。會實際產生 API 費用，請設定每日上限。',
    'fallback_enabled'     => '啟用備援',
    'fallback_api_key'     => 'API Key',
    'fallback_api_key_hint' => '從 Claude Console 取得。留空代表沿用現有的。',
    'fallback_model'       => '備援模型',
    'fallback_daily_limit' => '每日呼叫上限',
    'fallback_daily_limit_hint' => '0 代表不限制。超過上限後一律改走人工。',
    'fallback_used_today'  => '今日已用',

    // 如何取得 API Key
    'fallback_howto_title'  => '如何取得 API Key？',
    'fallback_howto_open'   => '1. 前往 Claude Console 並登入：',
    'fallback_howto_create' => '2. 左側選單進入 Settings → API Keys，點「Create Key」。',
    'fallback_howto_paste'  => '3. 複製產生的金鑰貼到上方欄位後儲存。金鑰只會完整顯示一次，關掉視窗就看不到了。',
    'fallback_howto_note'   => 'API 與訂閱是分開計費的兩件事 —— 帳戶要先有餘額，備援才用得起來。',

    // 用量
    'usage_title'        => '用量流量',
    'usage_desc'         => '只要有呼叫到 Claude 就計一次，成功、失敗、撞限額都算。',
    'usage_today'        => '今日',
    'usage_month'        => '本月',
    'usage_subscription' => '訂閱',
    'usage_fallback'     => '備援 API',
    'usage_calls'        => '呼叫次數',
    'usage_errors'       => '失敗',
    'usage_rate_limited' => '撞限額',
    'usage_avg_duration' => '平均耗時',
    'usage_cost'         => '預估費用',
    'usage_cost_hint'    => '依設定的單價估算，實際金額以 Anthropic Console 為準。',

    'action_save'   => '儲存',
    'action_saving' => '儲存中...',

    'msg' => [
        'saved'         => '已儲存',
        'save_failed'   => '儲存失敗',
        'load_failed'   => '設定載入失敗',
        'token_max'     => '憑證長度過長',
        'token_invalid' => '這把 Token 無法通過驗證，原本的設定沒有變動',
        'api_key_invalid' => '這把 API Key 無法通過驗證，原本的設定沒有變動',
        'model_required' => '請選擇模型',
        'model_invalid' => '不支援的模型',
        'daily_limit_required' => '請填寫每日呼叫上限',
        'daily_limit_invalid'  => '每日呼叫上限須為 0 以上的整數',
    ],
];
