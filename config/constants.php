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

                /*
                 * 內部員工在這個對話的例外（帶 user_id，不是 member_id）。
                 *
                 * ALLOW = 特別打開（這個對話會自動回覆他）
                 * BLOCK = 收回放行，回到預設的不自動回覆
                 */
                'STAFF_ALLOW' => 'staff_allow',
                'STAFF_BLOCK' => 'staff_block',
            ],
        ],

        /*
         * 後台帳號一律不自動回覆（見 StaffIgnoreService）
         *
         * 跟上面的 IGNORE 分開：那個是每個對話一份名單（key 帶 group_id），
         * 這個是全域共用的一份（固定 key）。兩者並存，任一命中就不自動回。
         */
        'STAFF_IGNORE' => [
            'CACHE_KEY'     => 'tg_staff_ignore_keys',
            'CACHE_SECONDS' => 600,

            // 「這個對話特別打開了哪些同事」—— 逐對話一個 key，所以是 prefix
            'ALLOW_CACHE_PREFIX' => 'tg_staff_allow_',
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

        // 餘點告警。門檻與公版都可以在「繳款設定」頁改，
        // 這裡的值只是「還沒設定過」時的預設
        'CREDIT_ALERT' => [
            'THRESHOLD'     => 30000,

            // 發送者暱稱。告警是系統主動發的，不掛客服個人名字
            'SENDER_NAME' => '系統',

            /*
             * 繳款設定的「測試發送」用的文案與假資料。
             *
             * 一律發到內部群組，所以前綴要講清楚這是測試、不是真的告警 ——
             * 免得客服以為有站台真的快沒點了。
             */
            /*
             * 客戶那邊是分兩則發的（告警／補點訊息），測試也照發兩則，
             * 前綴只掛在第一則。
             *
             * 不在前綴寫「共兩則」—— 匯率未定時只會有一則，
             * 那種情況由 TEST_NO_RATE_NOTE 自己說明。
             */
            'TEST_PREFIX' => "🔧 <b>補點訊息測試</b>（不是真的告警，不用處理）\n"
                . "以下是客戶收到時的樣子：\n\n",
            'TEST_NO_RATE_NOTE' => "\n\n⚠️ 今天的匯率還沒決定，所以沒有發補點訊息那一則。"
                . '決定之後，這則後面會再跟一則補點訊息。',
            // 固定假名，不拿真實站台 —— 測試訊息不該長得像某個客人真的在告警
            'TEST_STATION_NAME' => '測試',
            'TEST_CREDITS'      => 16390.94,

            /*
             * 補點訊息裡 {usdt} 的基準點數。
             *
             * 告訴客戶「補這麼多點要付多少 USDT」——
             * {usdt} = 無條件進位(這個值 ÷ 今日匯率)。
             */
            'TOPUP_USDT_BASE' => 50000,

            // 預設公版。理由同 TOPUP_NOTIFY_FOOTER——這是客戶會看到的內容，不放語系檔。
            // {station} 而不是寫死站台名：同一份公版要給所有站台用
            'TEMPLATE' => "⚠️<系統餘點告警>\n"
                . "index: {station}系統餘點告警\n"
                . "credit: 當前系統餘點：{credit}\n"
                . 'note: 建議補充系統點數，點數不足將導致系統自動停用後台',

            /*
             * 站台沒設自己的 Telegram 群組時，告警退到內部支援群組。
             *
             * 前面這段要讓客服一眼看出兩件事：這則**沒有**發給客戶、以及為什麼沒發。
             * 少了原因那一行，看到的人只會覺得系統壞了。
             *
             * 用 <b> 而不是 Markdown 星號：TelegramBotService 是 parse_mode=HTML，
             * escapeHtml() 會還原 b/i/u/s/code/pre/a，星號只會原樣顯示出來。
             */
            'INTERNAL_PREFIX' => "🔔 <b>{station}</b> 的餘點告警<b>沒有</b>發給客戶\n"
                . "原因：這個站台沒有設定 Telegram 群組，麻煩協助手動通知客戶補點\n\n"
                . "以下是原本要發給客戶的內容：\n",

            /*
             * 餘點低於門檻、但這個站台還有補點單沒審核時走這條。
             *
             * 客戶已經申請補點了，再發「請補充點數」等於在催一件他已經做完的事 ——
             * 真正卡住的是我們這邊還沒審核，所以改成提醒自己人。
             */
            'INTERNAL_PENDING_PREFIX' => "⏳ <b>{station}</b> 的系統餘點已低於門檻，"
                . "但還有 <b>{count}</b> 筆補點單沒有審核\n"
                . "這則<b>沒有</b>發給客戶 —— 他已經申請補點了，再催一次只會造成困擾\n"
                . "麻煩盡快到後台審核，點數要審核通過才會進去\n\n"
                . "客戶目前的狀況：\n",
        ],
    ],

    /*
     * 每日匯率報價。
     *
     * ASK_TEMPLATE 是「還沒設定公版時」的預設值 —— 正式公版由需求方提供後
     * 填到後台（app_setting 的 daily_rate.ask_template），這裡的只是備援。
     *
     * 對客的兩則（CUSTOMER_*）維持固定話術、不放語系檔，
     * 理由同 STATION.TOPUP_NOTIFY_FOOTER：語系會跟著客服後台的語言跑，
     * 客戶收到的內容不該因此變動。
     */
    'DAILY_RATE' => [
        /*
         * 題庫裡「匯率是多少」那一題的 import_key。
         *
         * 匯率每天不同，題庫存不了固定答案 —— AI 比對照常命中這一題，
         * 但送出前會把內容換成當日報價（AutoReplyService::resolveAnswer()）。
         * 題庫裡的 answer 只是佔位用，實際不會送出去。
         */
        'QUICK_REPLY_KEY' => 'system.daily_rate',

        /*
         * 報價時一併附上 MAX 的走勢圖截圖。
         *
         * 需要 x86_64 Linux + google-chrome-stable。截不到就只發文字 ——
         * 報價不能因為截圖失敗就整則發不出去。
         *
         * WAIT_MS 是給 JS 畫圖表的時間（--virtual-time-budget）。圖截到一半
         * 空白的話就是這個值不夠，往上加。
         */
        'SCREENSHOT' => [
            'ENABLED' => true,
            'URL'     => 'https://max.maicoin.com/trading/usdttwd',

            /*
             * Chrome 的 user data 目錄基底。
             *
             * ⚠ **一定要指定，而且要是跑 PHP 的那個帳號寫得進去的地方。**
             *
             * 不指定時 chrome-php 會用 `sys_get_temp_dir()` 自己建一個暫存
             * 目錄。在 apache / php-fpm 帳號下跑時那裡**可能不可寫**
             * （家目錄 `/usr/share/httpd` 不可寫、`TMPDIR` 沒設或被
             * `open_basedir` 擋住），Chrome 會直接以
             * `Failed to create headless user data directory` 起不來。
             *
             * 放在 `storage/` 底下是因為那裡本來就得讓 PHP 寫（log、cache），
             * 權限對了這裡就一定對。`storage/app/.gitignore` 已經忽略一切，
             * 不會進版控。
             *
             * 每次截圖在這底下開一個唯一子目錄、用完刪掉 ——
             * 共用同一個 profile 的話並行截圖會被 Chrome 的 profile lock 卡住。
             */
            'USER_DATA_BASE' => storage_path('app/chrome-profile'),

            // 視窗尺寸。改這個就要重新校正下面的 CROP —— 版面是跟著寬度跑的
            'WIDTH'   => 1920,
            'HEIGHT'  => 1080,
            'WAIT_MS' => 10000,

            /*
             * 只要 K 線圖那一塊：從 USDT/TWD 標題列到成交量圖底部，
             * 不要右邊的成交明細、掛單簿與下單面板。
             *
             * 優先用 SELECTOR 直接截那個元素 —— 位置與大小由 MAX 自己決定，
             * 改版也不容易壞。下面幾個是常見的圖表容器，**實際值要用
             * 「測試截圖」確認**（F12 看圖表外層的 id/class 最快）。
             *
             * 選不到元素時會退回「截整頁 + 用 CROP 裁切」。CROP 是備案不是主力：
             * 座標寫死，改版就裁錯而且不會報錯。
             */
            'SELECTOR' => '#tv_chart_container',

            'CROP' => [
                'X'      => 0,
                'Y'      => 60,
                'WIDTH'  => 1035,
                'HEIGHT' => 660,
            ],

            /*
             * headless Chrome 預設的 User-Agent 帶有「HeadlessChrome」字樣，
             * 有 bot 防護的網站會直接擋 —— MAX 對一般 HTTP 請求就已經回 403，
             * 不設這個很可能連頁面都載不到。
             */
            'USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        ],

        'ASK_TEMPLATE' => "💱 <b>{date} 匯率報價</b>\n\n"
            . "4H 均價：{reference}\n"
            . "建議報價：<b>{suggested}</b>\n"
            . "上次報價（{yesterday_date}）：{yesterday}\n\n"
            . "請<b>引用這則訊息</b>回覆：\n"
            . "・回數字 → 當日就用這個數字\n"
            . "・回「好」 → 採用建議報價 {suggested}",

        // 後台「測試截圖」按鈕送出的那則，標明是測試免得同仁以為要回覆
        'SCREENSHOT_TEST_CAPTION' => "🔧 <b>截圖測試</b>（{time}）\n"
            . "這則是後台按測試鈕送出的，不用回覆。\n"
            . '來源：{url}',

        'CONFIRM' => "✅ 今日匯率已定為 <b>{rate}</b>",
        'CONFIRM_CHANGED' => "✅ 今日匯率已從 {previous} 改為 <b>{rate}</b>",
        'REPLY_UNPARSED' => "看不懂這個回覆 🤔\n請直接回數字（例如 32.95），或回「好」採用建議報價。",

        'REMIND' => "⏰ 今日匯率還沒決定（第 {count} 次提醒）\n"
            . "建議報價：<b>{suggested}</b>\n"
            . "引用上面那則訊息回覆數字或「好」就可以了\n{mentions}",

        // 對客
        'CUSTOMER_ANSWER'  => '您好，今日匯率為 {rate} 😊 有需要都歡迎再告訴我們！',
        'CUSTOMER_PENDING' => '您好，今日匯率正在確認中 🙏 確認後會第一時間回覆您，再請您稍候，謝謝！',
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

        /*
         * 每天 09:30 自動發繳款通知（見 SendVmPaymentNoticeCommand）。
         *
         * 對客的文案在繳款設定裡（客服自己維護），這裡只放**對內**的那幾段 ——
         * 理由同 STATION.CREDIT_ALERT：客戶看到的內容不該跟著客服的語系跑，
         * 而對內訊息是給自己人看的，不必進語系檔。
         */
        'NOTICE' => [
            // 應收日前幾天開始發。沒有下界 —— 逾期會一直發到客戶繳費為止
            'DAYS_AHEAD' => 2,

            // 發送者暱稱。系統主動發的，不掛客服個人名字
            'SENDER_NAME' => '系統',

            /*
             * 站台沒設 Telegram 群組時，通知退到內部支援群組。
             *
             * 要讓客服一眼看出兩件事：這則**沒有**發給客戶、以及為什麼沒發。
             * 用 <b> 而不是 Markdown 星號（TelegramBotService 是 parse_mode=HTML）。
             */
            'INTERNAL_PREFIX' => "🔔 <b>{station}</b> 的 {month} 虛擬機繳款通知<b>沒有</b>發給客戶\n"
                . "原因：這個站台沒有設定 Telegram 群組，麻煩協助手動通知客戶繳款\n\n"
                . "以下是原本要發給客戶的內容：\n",

            /*
             * 主機沒綁站台時走這條 —— 沒有站台就查不到繳款設定，
             * 連文案都組不出來，只能把情況報給客服。
             */
            'NO_STATION_TEXT' => "⚠️ 虛擬機 <b>{hostname}</b> 的 {month} 帳單無法發出繳款通知\n"
                . "原因：這台主機沒有綁定站台，查不到該用哪一筆繳款設定\n"
                . "金額：{amount} USDT ｜ 應收日：{due_date}\n"
                . '麻煩到虛擬機管理綁定站台，或手動通知客戶',

            /*
             * 這個系統沒有啟用中的繳款設定 —— 同樣組不出文案。
             */
            'NO_CONFIG_TEXT' => "⚠️ <b>{station}</b> 的 {month} 虛擬機繳款通知發不出去\n"
                . "原因：這個系統還沒有啟用中的繳款設定\n"
                . "金額：{amount} USDT ｜ 應收日：{due_date}\n"
                . '麻煩到繳款設定補上',

            /*
             * 待審核的催審核。這一則**永遠只發內部群組**，語氣與對客的
             * 繳款通知完全不同：客戶已經付了，要催的是我們自己。
             */
            'PENDING_TEXT' => "⏳ <b>{station}</b> 的 {month} 虛擬機繳款證明還沒有人審核\n"
                . "金額：{amount} USDT ｜ 上傳時間：{uploaded_at}\n"
                . '客戶已經付款並上傳證明了，麻煩盡快到後台審核',
        ],
    ],

    /*
     * 內勤消耗品（卡片、SIM 卡…）
     *
     * 流水只有進出兩種，沒有「歸還」—— 消耗品用掉就沒了，
     * 需求方確認過不需要第三種。
     *
     * 品項沒有清單表，登記時直接打名稱 —— 所以這裡也沒有品項相關的常數。
     */
    'CONSUMABLE' => [
        'TYPE' => [
            'ISSUE' => 1, // 領用（進）
            'USE'   => 2, // 使用（出）
        ],
        // 備註的長度上限，與 migration 的欄位長度一致
        'NOTE_MAX' => 255,
        // 單筆數量上限。沒有人一次領十萬張，填錯一個 0 比漏填更難發現
        'QUANTITY_MAX' => 9999,
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

        // 問法樣本是怎麼來的
        'PHRASING_SOURCE' => [
            'SUPPORT' => 1, // 同仁在求助單按了「用這題回覆」
            'MANUAL'  => 2, // 後台手動新增
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
            'ANSWER'   => 'answer', // 承接句 + 題庫答案原文
            'REPLY'    => 'reply',  // 只有承接句，不帶題庫內容
            'WAIT'     => 'wait',   // 承接句（或 fallback 模板）+ 開求助單
            'SILENT'   => 'silent', // 完全不回。客人只說了「好」「收到」
            // 資訊不足，先跟客人要資料。內容一樣是題庫原文（「需要補充資訊」那一類），
            // 但**不標記已回覆** —— 這件事還沒處理完，未回覆告警要繼續響
            'ASK_INFO' => 'ask_info',
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

        /*
         * 「要先跟客人要資料才回答得了」的題目歸這一類。
         *
         * 客人只丟一句「訂單沒收到款」，同仁得先問代理帳號與訂單號才查得下去。
         * 同仁在求助單按「先問客人」時，那句追問就以這個類別存進題庫，
         * 之後 AI 比對到同樣的問法就會自己追問，不必再轉一次人工。
         *
         * 類別名稱同時是給模型看的線索 —— prompt 裡會標出每題的類別，
         * 它靠這個分辨「這題是答案」還是「這題是要資料」。
         */
        'ASK_INFO_CATEGORY' => '需要補充資訊',

        /*
         * GREETING_GAP_MINUTES 2026-09-30 移除。
         *
         * 那是「完整版／精簡版話術」的切換門檻：距離上次回覆超過 30 分鐘就帶
         * 問候語。現在開頭一律由模型的承接句負責，沒有兩版模板可切了。
         */

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
            'PICK'     => 'arp', // arp:{ticket_id}:{item_id} —— 直接用某一題的題庫原文回覆
        ],

        /*
         * 求助訊息上最多列幾個候選題目按鈕。
         *
         * 超過三個同仁要一個一個讀標題，反而比自己去後台找還慢。
         */
        'CANDIDATE_LIMIT' => 3,

        // 支援群組按鈕的動作
        'ACTION' => [
            'REPLY'          => 'reply', // 只回覆客人
            'REPLY_AND_SAVE' => 'save',  // 回覆客人並加入題庫
            'SAVE_ONLY'      => 'only',  // 只加入題庫
            'ASK_INFO'       => 'ask',   // 先問客人，並記住這種問法要問什麼
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
