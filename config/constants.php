<?php

/**
 * 系統常數
 *
 * 對齊主系統 tripartite_gold 的 constants.php 風格，
 * 透過 config('constants.XXX') 取值。
 */

return [

    'USER' => [
        'LEVEL' => [
            'ADMIN'    => 0,
            'BOSS'     => 1,
            'LEADER'   => 2,
            'ENGINEER' => 3,
            'CS'       => 4,
        ],
        'STATUS' => [
            'NORMAL'     => 1,  // 正常：完整功能
            'LOCK'       => 2,  // 鎖定：可登入，不可報班/換班，班別由管理者指派
            'DEACTIVATE' => 0,  // 停用：無法登入
        ],
    ],

    'TELEGRAM' => [
        'DIRECTION' => [
            'INBOUND'  => 1, // 從 Telegram 群組收到的訊息
            'OUTBOUND' => 2, // 從後台發送到 Telegram 的訊息
        ],
        'GROUP_STATUS' => [
            'ACTIVE'   => 1,
            'ARCHIVED' => 0,
        ],
        'ALERT' => [
            'WEEKDAY_MINUTES' => 5,  // 週一至週五未回覆告警閾值
            'WEEKEND_MINUTES' => 30, // 週六日未回覆告警閾值
        ],
        'PHOTO' => [
            'MAX_DIMENSION_SUM' => 10000, // 寬 + 高上限，超過 Telegram 回 PHOTO_INVALID_DIMENSIONS
            'MAX_RATIO'         => 20,    // 長短邊比例上限，同上
        ],
        'TTL_DAYS' => 7, // 訊息保留天數

        /*
         * 忽略名單（不自動回覆的成員）
         *
         * 名單極少異動卻每則訊息都要讀，所以整包快取；
         * 清單只取近期發言過的人，群組待久了會累積一堆早就離開的成員。
         */
        'IGNORE' => [
            'RECENT_DAYS'   => 30,  // 「發言過的人」回溯天數
            'RECENT_LIMIT'  => 50,  // 「發言過的人」筆數上限（前端搜尋就在這批裡過濾）
            'CACHE_SECONDS' => 600, // 忽略名單快取秒數
            'CACHE_PREFIX'  => 'tg_ignore_members_',

            // 面板動作
            'ACTION' => [
                'IGNORE'  => 'ignore',  // 把名冊上的人設為不自動回覆
                'ADD'     => 'add',     // 用 username 手動加入
                'RESTORE' => 'restore', // 恢復自動回覆
            ],
        ],

        /*
         * 客人只傳媒體沒打字時，寫進 content 的替代文字。
         *
         * 刻意不放語系檔：這會存進資料庫成為訊息紀錄的一部分，
         * 跟著客服當下的語系跑會讓同一筆訊息在不同人眼中長得不一樣。
         */
        'MEDIA_LABELS' => [
            'photo'      => '[圖片]',
            'sticker'    => '[貼圖]',
            'video'      => '[影片]',
            'animation'  => '[動圖]',
            'video_note' => '[影片訊息]',
            'voice'      => '[語音訊息]',
            'audio'      => '[音訊]',
            'document'   => '[檔案]',
            'default'    => '[訊息]',
        ],
    ],

    'STATION' => [
        'STATUS' => [
            'ACTIVE'   => 1, // 啟用
            'FROZEN'   => 2, // 凍結
            'DISABLED' => 0, // 停用
        ],
        // 補點結果通知的結尾提醒。這是發給客戶的固定話術，
        // 不放語系檔——語系會跟著客服後台的語言跑，客戶收到的內容不該因此變動
        'TOPUP_NOTIFY_FOOTER' => '老闆，點數再麻煩確認，謝謝',
    ],

    'VM' => [
        'POWER' => [
            'ON'  => 1,
            'OFF' => 0,
        ],
        'STATUS' => [
            'ACTIVE'   => 1,
            'DISABLED' => 0,
        ],
        'BILLING' => [
            'UNPAID'   => 0,
            'PAID'     => 1,
            'PENDING'  => 2, // 待審核（已上傳繳款證明）
            'GRACE_DAYS' => 3,
        ],
    ],

    'TASK' => [
        'STATUS' => [
            'PENDING'     => 1,
            'IN_PROGRESS' => 2,
            'TESTING'     => 3,
            'IN_REVIEW'   => 4,
            'RESOLVED'    => 5,
            'ARCHIVED'    => 6,
        ],
        'PRIORITY' => [
            'LOW'    => 1,
            'MEDIUM' => 2,
            'HIGH'   => 3,
            'URGENT' => 4,
        ],
    ],

    'PROJECT' => [
        'STATUS' => [
            'ACTIVE'   => 1,
            'DISABLED' => 0,
        ],
    ],

    'QUICK_REPLY' => [
        'STATUS' => [
            'ACTIVE'   => 1,
            'DISABLED' => 0,
        ],
    ],

    /*
     * 自動回覆
     *
     * 這裡只放「不開放後台修改」的狀態常數與調參。
     * Claude 憑證、支援群組、對客話術模板都存在 app_setting，由全域設定頁維護。
     */
    'AUTO_REPLY' => [
        // 每個對話各自的開關，預設關閉
        'STATUS' => [
            'ON'  => 1,
            'OFF' => 0,
        ],

        // 呼叫來源。撞到訂閱額度時會降級到備援，兩者的用量要分開看
        'SOURCE' => [
            'SUBSCRIPTION' => 1, // 訂閱（Claude Code CLI）
            'FALLBACK'     => 2, // 備援 API Key（會實際產生費用）
        ],

        // 求助單狀態
        'TICKET_STATUS' => [
            'IGNORED'  => 0, // 忽略（含客服已自行人工回覆）
            'PENDING'  => 1, // 待自己人回答
            'ANSWERED' => 2, // 已回答，等按鈕決定怎麼處理
            'REPLIED'  => 3, // 已回覆客人
            'SAVED'    => 4, // 已加入題庫
        ],

        // 求助單超時提醒的階段。第二階段之後不再提醒，避免變成定時轟炸
        'REMIND' => [
            'NONE'    => 0,
            'ON_DUTY' => 1, // 已 tag 當班人員
            'MANAGER' => 2, // 已 tag 主管與老闆
        ],

        // 模型回傳的信心度
        'CONFIDENCE' => [
            'HIGH' => 'high',
            'LOW'  => 'low',
        ],

        /*
         * 客人這則訊息是哪一種。
         *
         * 以前每則都被當成「在問問題」，於是需求與寒暄也會被拿去比對題庫，
         * 撈出不相干的答案再逼客人挑一個。先分類才不會答非所問。
         */
        'INTENT' => [
            'QUESTION' => 'question', // 在問一件事，可以查題庫
            'REQUEST'  => 'request',  // 提需求，題庫不該回答這種，一律轉人工
            'CHAT'     => 'chat',     // 寒暄、道謝、確認
        ],

        // 決策結果
        'DECISION' => [
            'ANSWER' => 'answer', // 承接句 + 題庫答案原文
            'REPLY'  => 'reply',  // 只有承接句，不帶題庫內容
            'WAIT'   => 'wait',   // 承接句（或 fallback 模板）+ 開求助單
            'SILENT' => 'silent', // 完全不回。客人只說了「好」「收到」
        ],

        /*
         * 承接句（模型唯一能自由生成的地方）的護欄。
         *
         * 答案本體永遠是題庫原文，承接句只負責「回應客人的那一兩句」。
         * 違反任何一條就退回固定話術模板 —— 最壞情況等於改版前，不會更糟。
         */
        'OPENING' => [
            'MAX_LENGTH' => 60,

            /*
             * 出現這些字就不採用。
             *
             * 前三類是「做了不該由 AI 做的承諾或裁決」，
             * 最後一類是「指示客人該怎麼回覆」—— 客人想怎麼講就怎麼講，
             * 系統不該規定格式。
             */
            'BLACKLIST' => [
                '保證', '一定', '絕對', '免費', '不會有', '沒問題',
                '可以幫您', '我們有提供', '已為您開通', '已完成',
                '請回覆', '請提供', '請告知', '麻煩您回覆', '請選擇', '請輸入',
            ],
        ],

        /*
         * 自動回覆的固定署名。
         *
         * 刻意不放語系檔：這會寫進 content 成為訊息紀錄的一部分，
         * 跟著客服當下的語系跑會讓同一筆訊息在不同人眼中不一樣，
         * 客戶收到的內容更不該因此變動。（同 TELEGRAM.MEDIA_LABELS 的理由）
         */
        'SIGNATURE' => '-A',

        /*
         * 訊息紀錄上的發送者名稱。同上，會寫進 DB 成為紀錄的一部分，不放語系檔。
         */
        'SENDER_NAME' => '自動回覆',

        /*
         * 支援群組回填題庫時，「不確定歸哪一類」的收納類別。
         * 由 seeder 建立，sort 排最後，方便事後盤點。
         */
        'PENDING_CATEGORY' => '待整理',

        // 距離上次自動回覆超過這個分鐘數，才用帶問候語的完整版模板
        'GREETING_GAP_MINUTES' => 30,

        /*
         * 這裡原本有 WAIT_COOLDOWN_MINUTES（「稍等」的冷卻），已移除。
         *
         * 它會讓客人在冷卻內問的第二個問題既沒有回應、也沒有進支援群組。
         * 而冷卻原本要解決的「稍等一再重複顯得敷衍」，已經由每次都不同的
         * 承接句解決掉了。
         */

        /*
         * Telegram inline keyboard 的 callback_data 前綴。
         *
         * callback_data 有 64 bytes 上限，所以只放前綴與 id，不放任何文字。
         */
        'CALLBACK' => [
            'ACTION'   => 'ar',  // ar:{action}:{ticket_id}
            'CATEGORY' => 'arc', // arc:{ticket_id}:{category_id}
        ],

        // 支援群組按鈕的動作
        'ACTION' => [
            'REPLY'          => 'reply', // 只回覆客人
            'REPLY_AND_SAVE' => 'save',  // 回覆客人並加入題庫
            'SAVE_ONLY'      => 'only',  // 只加入題庫
            'IGNORE'         => 'skip',  // 忽略
        ],
    ],

    'PAGINATION' => [
        'DEFAULT' => 10,
        'USER' => 10,
    ],

    'FINANCE' => [
        'EXPENSE_TYPE' => [
            'MISC'   => 'misc',
            'SERVER' => 'server',
        ],
        'CATEGORY' => [
            'office'       => '辦公用品',
            'transport'    => '交通',
            'meal'         => '餐費',
            'communication' => '通訊',
            'subscription' => '軟體訂閱',
            'other'        => '其他',
            'server'       => '伺服器',
        ],
        'CURRENCY' => ['TWD', 'USD', 'USDT'],
    ],

];
