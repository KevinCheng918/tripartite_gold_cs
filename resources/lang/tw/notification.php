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
    'tab_task'   => '任務卡通知',
    'tab_attendance' => '打卡報表',
    'tab_approval'   => '申請審核',
    'tab_finance'    => '財務申請',
    'tab_wallet'     => '錢包轉帳',
    'tab_followup'   => '待追蹤事項',

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
    'report_title'     => '超時提醒統計',
    'report_desc'      => '每天 :time 把前一天的超時提醒統計私訊出去。勾選的人收「全部人的」（依題目、依人員），被提醒到的同仁另外會各自收到「自己那份」，不需要設定。',
    'report_period'    => '同一份名單也會收到週報表（每週一 :time 報上週）與月報表（每月 1 號 :time 報上個月），時間跟日報一致。週報與月報只發完整版，不會逐人發個人版。',
    'report_user'      => '完整統計私訊給（可多選）',
    'report_user_hint' => '建議勾主管以上。標示「未綁定」的人收不到，請他先私訊機器人一次。',
    'report_test'      => '測試發送',
    'report_test_hint' => '會立刻把昨天的完整統計私訊給上面勾選的人。只發完整版，不會打擾昨天被提醒到的同仁。',
    'report_personal_title' => '個人統計',
    'report_personal_desc'  => '昨天被提醒到的同仁，每天 :time 會各自收到「自己那份」（我被催了哪幾題）。這部分自動發送，不需要設定；沒被提醒到的人不會收到。',

    // ===== 求助單逾時升級 =====
    /*
     * 求助單逾時升級（需求方 2026-11-08）。
     *
     * ⚠ `escalation_trigger_hint` 一定要寫出「必須小於提醒次數上限」：
     * 設得比上限高的話，提醒會在升級之前就停止，電話永遠不會響**而且不報錯**。
     */
    'escalation_title'   => '逾時升級（打電話）',
    'escalation_desc'    => '求助單催到指定次數還沒有人處理時，用 Telegram 撥電話給當班人員把他叫起來，同時私訊告訴他是哪一張單。撥滿 :calls 通仍然沒有人處理，就改私訊下面勾選的人。⚠ 電話只會響、沒有語音內容（接起來會斷線）—— 要處理什麼寫在私訊裡。⚠ 判斷「處理了沒」一律看那張單的狀態，不是看電話有沒有被接起。',
    'escalation_enabled' => '啟用逾時升級',
    'escalation_enabled_hint' => '⚠ 這一項**預設是關的**。它會撥電話也會私訊，請先把下面的收件人勾好、確認撥號設定完成再打開。',
    'escalation_trigger'      => '催到第幾次開始升級',
    'escalation_trigger_hint' => '以現行的提醒間隔，第 10 次大約是開單後 100 分鐘。⚠ **必須小於「最多提醒幾次」（目前是 :max）** —— 設得比它高的話，提醒會在升級之前就停止，電話永遠不會響。',
    'escalation_ring'      => '電話響幾秒',
    'escalation_ring_hint' => '超過約 90 秒 Telegram 會自己掛斷，填更大沒有用。越長越可能被接起來，而接起來會直接斷線。',
    'escalation_interval'      => '兩通之間隔幾分鐘',
    'escalation_interval_hint' => '最多撥 :calls 通（這個數字不開放調整）。⚠ 間隔太短會讓機器人帳號被 Telegram 判定為騷擾而停權，所以不接受小於 3 分鐘。',
    'escalation_manager'      => '撥不通時私訊給（可多選）',
    'escalation_manager_hint' => ':calls 通電話都撥完、那張單仍然沒有人處理時，立刻私訊這些人。標示「未綁定」的人收不到，請他先私訊機器人一次。',
    'escalation_test'      => '測試撥號',
    'escalation_test_user' => '撥給誰',
    'escalation_test_hint' => '會**真的撥一通**給選定的同仁、響 :seconds 秒。請先告知他，不然他會被嚇到。⚠ 只列出有填 Telegram 帳號的人 —— 沒填的撥不出去。',
    'escalation_call_missing' => '撥號功能尚未設定完成（需要 Telegram 應用程式憑證與登入檔）—— 在那之前只會私訊，不會撥電話。',

    // ===== 班別交接 =====
    /*
     * 班別交接（需求方 2026-11-08）。
     *
     * ⚠ `handover_interval_hint` 一定要寫明「沒有次數上限」：這個功能會
     * 一直催到有人交接，而設定頁不講的話，填 5 分鐘的人不會意識到那是
     * 「每 5 分鐘 tag 一群人直到有人動」。
     */
    'handover_title'   => '班別交接',
    'handover_desc'    => '每一班都要在交接話題打交接事項。兩班回訊時間重疊的那一小時就是交接窗口，系統會在下一班回訊開始前幾分鐘，在交接話題同時 tag 兩邊 —— 上一班「請打交接事項」、下一班「你要上線了，請先看交接」。那一班的人在交接話題發訊息就算完成。⚠ 上一班當天沒有人排班時**不會催**，改成跟下一班說一聲、並引用上一份交接（大夜班目前沒人就是這種情況，那時請看系統每天發的「早班待接手清單」）。',
    'handover_enabled' => '啟用交接提醒',
    'handover_enabled_hint' => '關閉時完全不發交接訊息，已經記下的交接紀錄會留著。⚠ 這一項**預設是開的**（交接是每班都要做的事）。',
    'handover_lead'      => '提前幾分鐘開始提醒',
    'handover_lead_hint' => '從「下一班回訊開始時間」往前算。例如午班回訊 13:00 開始、填 5，就是 12:55 發第一則。',
    'handover_interval'      => '沒交接時每隔幾分鐘再催',
    'handover_interval_hint' => '⚠ **沒有次數上限** —— 會一直催到那一班的人在交接話題發訊息為止（沒事情交接的話回一句「沒有」就結束）。所以這個數字就是「會被吵多兇」的唯一控制項，建議不要填太小。第 :escalate 次起會連主管一起 tag。',
    'handover_topic_missing' => '還沒在「支援群組與話題」分頁把「班別交接」勾到任何話題 —— 在那之前這個功能不會動作（避免在群組主區催一件無法被完成的事）。',

    // ===== 自動回覆自動恢復 =====
    /*
     * 自動回覆自動恢復（需求方 2026-11-07）。
     *
     * ⚠ `resume_minutes` 的 label 一定要寫「預設」：真正生效的分鐘數是客服
     * 在聊天視窗 modal 當場填的，這裡只是那個欄位的預填值。不寫「預設」的話，
     * 改了這個數字卻發現既有的待恢復沒變，會被當成壞掉。
     */
    'resume_title'   => '自動回覆自動恢復',
    'resume_desc'    => '客服取消勾選自動回覆改人工處理，處理完常常忘記勾回來 —— 那個對話從此一直是人工。開啟後，轉人工的對話靜置夠久就會自動把自動回覆勾回來，並到支援群組通知、tag 當初關掉的人。⚠ 只會恢復「關閉時選了暫時轉人工」的對話：從沒開過自動回覆的對話、以及選了永久關閉的對話都不會被碰到。',
    'resume_enabled' => '啟用自動恢復',
    'resume_enabled_hint' => '關閉時完全不會改動任何對話的開關，已經記下來的關閉紀錄會留著。',
    'resume_minutes'      => '靜置分鐘數的預設值',
    'resume_minutes_hint' => '⚠ 這**只是聊天視窗那個 modal 的預填值**，不是全域生效的時間 —— 真正用哪個數字由關掉的客服當場決定（他才知道這一通要處理多久）。可填 :min ～ :max 分鐘。',

    // ===== 待追蹤事項 =====
    'followup_title'   => '待追蹤事項',
    'followup_desc'    => '對話裡有人說「稍等、晚點給你」就記成一筆待追蹤事項。對話安靜一段時間後，系統會到支援群組 tag 當班人員問「要不要追蹤」：選要追蹤就發訊息問客人進度（我方自己說的則改成 tag 當班人員接手），選不追了就結案。沒有人按按鈕時會每隔一段時間再問一次，第 :escalate 次起同時 tag 主管與老闆。',
    'followup_enabled' => '啟用待追蹤事項',
    'followup_enabled_hint' => '關閉時完全不偵測、不發訊息，已經記下來的事項會留著。',
    'followup_keywords'      => '等待詞（一行一個）',
    'followup_keywords_hint' => '命中其中一個詞才會進一步請 AI 確認「是不是真的在說稍後提供」，所以寧可寬一點。留白就用系統預設的這一組：',
    'followup_quiet'         => '安靜多久後詢問（分鐘）',
    'followup_quiet_hint'    => '對方說完「稍等」之後，對話安靜超過這個時間才去問當班人員。客人後來又說話會重新計時 —— 他還在線上聊的時候系統不會插進來。',
    'followup_interval'      => '沒人決定時每隔（分鐘）',
    'followup_interval_hint' => '沒有人按按鈕時，每隔這個時間再問一次。第 :escalate 次起會同時 tag 主管與老闆。',
    'followup_max'           => '最多詢問幾次',
    'followup_max_hint'      => '到這個次數仍沒有人按按鈕就停止詢問，並發最後一則通知主管與老闆（按鈕仍然有效）。追問客人的輪數不受這個限制。',
    'followup_stale'         => '幾天沒結果就能收掉',
    'followup_stale_hint'    => '填 0 表示不自動收（預設）。填天數之後，要清理時執行 followup:close-stale 才會真的收，排程不會自動跑。',
    'followup_report_user'      => '每日統計私訊給（可多選）',
    'followup_report_user_hint' => '每天 :time 把前一天的待追蹤事項統計私訊出去（新開幾筆、結案幾筆、還掛著幾筆、拖最多次的是誰）。標示「未綁定」的人收不到，請他先私訊機器人一次。',

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

    // ===== 任務卡通知 =====
    'task_title'          => '任務卡總覽收件人',
    'task_desc'           => '每天 :time 私訊一份全部人的任務卡總覽（每人各有幾項已過期、今日到期、進行中）。可以勾多位，沒勾任何人時不會發送。',
    'task_user'           => '收件人（可多選）',
    'task_user_hint'      => '標示「未綁定」的同仁收不到訊息，請他先私訊機器人一次。勾選的人不會再另外收到個人版。',
    'task_test'           => '測試發送',
    'task_test_hint'      => '會立刻把今天的總覽私訊給上面勾選的人。只發總覽，不會打擾其他同仁。',
    'task_personal_title' => '個人任務卡',
    'task_personal_desc'  => '每位在職同仁每天 :time 會各自收到自己的那一份：已過期幾項、今日到期幾項、進行中幾項，以及要留意的卡片清單。手上沒卡的人收到的是一句輕鬆的話。這部分自動發送，不需要設定。',
    'task_count_note'     => '一張卡可以指派給多個人，所以各人的數字加起來會大於卡片總張數 —— 那是對的，他們每個人都該知道。',

    // 打卡報表
    'attendance_title'          => '打卡報表收件人',
    'attendance_desc'           => '週報表 :weekly 統計上週一到上週日，月報表 :monthly 統計上個月。兩份都會私訊一份全部人的統計（誰全勤、誰遲到早退曠工幾次幾分鐘、誰加班多久）。可以勾多位，沒勾任何人時不會發送。',
    'attendance_user'           => '收件人（可多選）',
    'attendance_user_hint'      => '週報與月報共用這一份名單。標示「未綁定」的同仁收不到訊息，請他先私訊機器人一次。勾選的人不會再另外收到個人版。',
    'attendance_test'           => '測試發送',
    'attendance_test_hint'      => '會立刻把上週的完整報表私訊給上面勾選的人。只發完整版，不會打擾其他同仁。',

    'approval_title'          => '申請審核通知收件人',
    'approval_desc'           => '有人申請請假、補打卡、代班時立刻私訊通知；審核完（不管通過或拒絕）再通知一次。可以勾多位，沒勾任何人時不會發送（申請本身照樣成立）。',
    'approval_user'           => '收件人（可多選）',
    'approval_user_hint'      => '標示「未綁定」的同仁收不到訊息，請他先私訊機器人一次。',
    'approval_test'           => '測試發送',
    'approval_test_hint'      => '會立刻私訊一則測試訊息給上面勾選的人。這種通知是有人申請時才觸發的，沒辦法補發先前的。',
    'approval_events_title'   => '會通知哪些事',
    'approval_event_leave'    => '請假：申請時、審核通過或拒絕時',
    'approval_event_amendment' => '補打卡：申請時、審核通過或拒絕時',
    'approval_event_cover'    => '代班：申請時、代班人同意或拒絕時、主管核准或駁回時',
    // ⚠ 要講明為什麼沒有換班，不然會被當成漏做
    'approval_swap_hint'      => '換班不在通知範圍內：換班沒有主管審核那一關，被換的同仁答應就直接生效，通知了也沒有需要處理的事。',
    'finance_title'           => '財務申請通知收件人',
    'finance_desc'            => '有人上傳虛擬機繳款證明、申請補點或扣點時立刻私訊通知；審核完再通知一次。可以勾多位，沒勾任何人時不會發送（上傳與申請本身照樣成立）。',
    'finance_user'            => '收件人（可多選）',
    'finance_user_hint'       => '標示「未綁定」的同仁收不到訊息，請他先私訊機器人一次。',
    'finance_test'            => '測試發送',
    'finance_test_hint'       => '會立刻私訊一則測試訊息給上面勾選的人。這種通知是有人送件時才觸發的，沒辦法補發先前的。',
    'finance_events_title'    => '會通知哪些事',
    'finance_event_proof'     => '虛擬機繳款證明：上傳時、審核確認收款時',
    'finance_event_topup'     => '補點／扣點：申請時、審核通過或拒絕時（通過但主系統沒加成功會另外提醒）',
    // ⚠ 要講明為什麼跟上一個分頁是兩份名單，不然會被當成重複設定
    'finance_split_hint'      => '這份名單跟「申請審核」是分開的：那邊是人事（請假、補打卡、代班），這邊是錢（繳款、補點）。想收其中一種的人可以只勾一邊。',
    'wallet_title'            => '錢包轉帳通知',
    'wallet_desc'             => '監聽的 TRON 錢包只要有 USDT（或其他 TRC20 代幣）轉入或轉出，就立刻私訊通知，內容包含時間、金額、幣別、From、To 與交易 hash，並附上可以直接點開的 Tronscan 交易連結。只通知，不會做任何動作。',
    'wallet_enabled'          => '啟用錢包轉帳通知',
    'wallet_addresses'        => '要監聽的錢包（地址／備註名稱）',
    'wallet_address_add'      => '新增一個錢包',
    'wallet_address_hint'     => 'TRON 地址是 T 開頭、34 碼。備註名稱會出現在通知裡（例如「公司主錢包」），不填也可以。',
    'wallet_address_placeholder' => 'TRON 地址（T 開頭）',
    'wallet_label_placeholder'   => '備註名稱（選填）',
    'wallet_min_amount'       => '最低通知金額',
    'wallet_min_amount_hint'  => '低於這個金額的轉帳不會通知（但仍然會留紀錄）。填 0 表示全部都通知。',
    'wallet_user'             => '收件人（可多選）',
    'wallet_user_hint'        => '標示「未綁定」的同仁收不到訊息，請他先私訊機器人一次。',
    'wallet_test'             => '測試發送',
    'wallet_test_hint'        => '會立刻私訊一則測試訊息給上面勾選的人。這種通知是鏈上有轉帳才觸發的，沒辦法補發先前的。',
    'wallet_events_title'     => '會通知哪些事',
    'wallet_event_in'         => '有人轉 USDT 或其他 TRC20 代幣進來',
    'wallet_event_out'        => '從這個錢包轉出去',
    // ⚠ 這兩句一定要寫出來，不然會被當成功能壞掉
    'wallet_baseline_hint'    => '⚠️ 新增一個錢包後，第一次檢查只會把目前的紀錄抓回來當基準，不會發通知 —— 否則鏈上幾千筆歷史轉帳會一次全部送出。從第二次檢查（約一分鐘後）開始才會通知。',
    'wallet_dust_hint'        => '⚠️ TRON 上有「地址投毒」的手法：對方從長得很像的地址轉 0.000001 USDT 進來，誘使你下次複製到錯的地址。最低通知金額就是用來擋這種的，建議至少填 1。',
    'attendance_personal_title' => '個人打卡報表',
    'attendance_personal_desc'  => '每位在職同仁都會各自收到自己的那一份：遲到、早退、曠工的次數與時間，補打卡次數，請假幾號到幾號，加班總時數。沒有遲到、早退、曠工的人收到的是一句勉勵的話。這部分自動發送，不需要設定。',
    'attendance_leave_note'     => '完整版每人一行分三種：全勤（沒有遲到早退曠工，而且沒請假）、有請假（報請假多久 + 其餘無狀況）、有狀況（報次數與時間）。請假不算出狀況，但也不算全勤。',

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
        'conversation_followup'      => '待追蹤事項',
        'conversation_followup_hint' => '有人說「稍等」之後對話就安靜了，機器人來問當班人員要不要追蹤。靠訊息上的按鈕決定，不需要引用回覆',
        'ticket_handover'        => '早班待接手清單',
        'ticket_handover_hint'   => '每天 7:30 把還沒人處理的問題交接給當天早班',
        'shift_notice'           => '班表沒收到的人',
        'shift_notice_hint'      => '今日班表私訊不出去時，把沒收到的同仁彙總成一則。班表本身是私訊，不會進群組',
        'vm_payment'             => '虛擬機繳款',
        'vm_payment_hint'        => '每天 9:30 的繳款通知：站台沒設群組時退回內部、有待審核時催內部審核',
        'station_credit'         => '站台餘點告警',
        // 2026-10-18 起不是固定 10:00，改成「決定匯率之後的下一個整點」
        'station_credit_hint'    => '決定匯率後下一個整點發的餘點告警與補點訊息，站台沒設群組時退回內部',

        /*
         * ⚠ 這一項的語意跟其他不一樣：其他是「這類通知**發到**哪」，
         * 這一項是「哪些話題**可以用**」。hint 一定要寫清楚，
         * 不然看的人會以為回覆會統一發到勾選的話題。
         *
         * ⚠ **key 仍然是 `scheduled_reminder`**（2026-10-19 只改顯示文字）。
         * 它是 `TelegramCommandRouter::NOTICE_TYPE`，而且已經存進每個話題的
         * `types` 設定裡 —— 改 key 等於把所有人已經勾好的設定清空。
         *
         * 一開始只有預約提醒，所以叫「預約提醒」；現在它管的是**全部六支
         * tag 指令**（預約提醒／預約公告／查餘點／查虛擬機／查卡片／查匯率，
         * 以及發餘點告警），名字早就名不符實了。
         */
        /*
         * 任務卡異動（需求方 2026-11-01：新增了「任務卡專區」話題）。
         *
         * ⚠ hint 要寫出**兩件事**：①會合併不是即時（不然同仁會以為通知壞了）
         * ②哪些變更不通知（描述與打勾太頻繁，不寫的話會以為漏通知）。
         */
        'task_change'             => '任務卡異動',
        'task_change_hint'        => '任務卡有建立、狀態、指派人員、參與人、到期日、優先順序、專案站台、封存還原的變更時通知。'
            . '⚠ 同一張卡連續的異動會**合併成一則**（安靜幾分鐘後才發），不是改一次發一次；'
            . '描述、標題與勾選清單打勾**不通知**（太頻繁）',

        /*
         * 自動回覆自己勾回來了（需求方 2026-11-07）。
         *
         * ⚠ hint 要提醒**不要勾在「AI 轉來的問題」那個話題**：那裡是客服要
         * 引用回覆作答的作業區，混進純告知會讓那區的「有新訊息」失去意義。
         */
        'auto_reply_resume'      => '自動回覆已恢復',
        'auto_reply_resume_hint' => '對話轉人工後靜置夠久，系統自動把自動回覆勾回來時通知，並 tag 當初關掉的人。'
            . '⚠ 建議跟「早班待接手清單」放同一個話題；**不要**勾在「AI 轉來的問題」那一個（那裡要留給需要引用回覆的求助單）',

        /*
         * 班別交接（需求方 2026-11-08）。
         *
         * ⚠ 這一項**雙向**：除了「通知發到這裡」，同仁在這個話題發言還會被
         * 當成「完成交接」。hint 一定要寫出來，不然會有人在這裡閒聊而
         * 誤消掉提醒。
         */
        'shift_handover'      => '班別交接',
        'shift_handover_hint' => '交接時間到了的提醒（同時 tag 上下兩班）。'
            . '⚠ 這一項不只是「發到這裡」—— **那一班的人在這個話題發訊息就算完成交接**，'
            . '所以請勾一個專門做交接的話題，不要跟閒聊混在一起',

        'scheduled_reminder'      => '小幫手',
        'scheduled_reminder_hint' => '⚠ 這一項是「哪些話題可以 tag 小幫手下指令」，不是「發到哪裡」——'
            . '小幫手一律回在你說話的那個話題。一個都沒勾＝所有話題都能用',
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

        'followup_keywords_invalid' => '等待詞總長度不可超過 2000 字',
        'followup_minutes_required' => '請填寫詢問時間',
        'followup_minutes_invalid'  => '詢問時間須介於 1 到 1440 分鐘',
        'followup_max_required'     => '請填寫最多詢問幾次',
        'followup_max_invalid'      => '詢問次數須介於 1 到 200 次',
        'followup_stale_required'   => '請填寫幾天沒結果就能收掉（不自動收請填 0）',
        'followup_stale_invalid'    => '天數須介於 0 到 365 天',

        'call_test_sent'        => '已撥給 :name，請確認他的手機有響（沒有語音內容，接起來會斷線，那是正常的）',
        'call_test_not_ready'   => '撥號設定還沒完成 —— 請先在伺服器上執行 php artisan telegram:call-login',
        'call_test_no_user'     => '找不到這個人，或他沒有填 Telegram 帳號（沒填就撥不出去）',
        'call_test_privacy'     => '撥不到 :name —— 他的 Telegram「誰可以打電話給我」設成僅聯絡人。請用這個機器人帳號把他加進聯絡人，或請他改設定',
        'call_test_not_found'   => '找不到 :name 的 Telegram 帳號 —— 請確認他填的 username 正確，或先用機器人帳號把他加進聯絡人',
        'call_test_no_session'  => '機器人還沒登入 —— 請在伺服器上執行 php artisan telegram:call-login',
        'call_test_no_telethon' => '撥號用的 Python 找不到 telethon —— 多半是 .env 的 TG_CALL_PYTHON 沒指向那個 venv（要絕對路徑）',
        'call_test_error'       => '撥給 :name 失敗：:error',
        // 這一句不能帶 :name／:error —— 前端把它當「連不上後端」的退路，那時候沒有值可以填
        'call_test_failed'      => '測試撥號失敗',
        'escalation_trigger_required' => '請填寫催到第幾次開始升級',
        'escalation_trigger_invalid'  => '升級門檻須介於 2 到 200 次',
        'escalation_trigger_too_high' => '升級門檻必須小於「最多提醒幾次」（目前是 :max）—— 否則提醒會在升級之前就停止，電話永遠不會響',
        'escalation_ring_required'    => '請填寫電話響幾秒',
        'escalation_ring_invalid'     => '響鈴秒數須介於 5 到 90 秒',
        'escalation_interval_required' => '請填寫兩通之間隔幾分鐘',
        'escalation_interval_invalid'  => '撥號間隔須介於 3 到 60 分鐘（太短會讓機器人帳號被判定為騷擾）',
        'remind_max_below_escalation'  => '提醒次數上限必須大於逾時升級的門檻（目前是第 :trigger 次）—— 否則提醒會在升級之前就停止，電話永遠不會響',

        'handover_lead_required'     => '請填寫提前幾分鐘開始提醒',
        'handover_lead_invalid'      => '提前時間須介於 1 到 60 分鐘',
        'handover_interval_required' => '請填寫沒交接時每隔幾分鐘再催',
        'handover_interval_invalid'  => '再催間隔須介於 5 到 1440 分鐘（這個功能沒有次數上限，所以不接受小於 5 分鐘）',

        'resume_minutes_required' => '請填寫預設的靜置分鐘數',
        'resume_minutes_range'    => '靜置分鐘數須介於 :min 到 :max 分鐘',
        'report_user_not_found' => '找不到這個帳號',
        'report_test_sent'      => '測試統計已送出 :sent 則，請確認 Telegram 有收到',
        'report_test_partial'   => '已送出 :sent 則，但這幾位沒收到：:failed（請他們先私訊機器人一次）',
        'report_test_no_user'   => '請先勾選收件人並儲存',
        'report_test_not_bound' => '勾選的同仁都還沒私訊過機器人，所以收不到訊息',
        'report_test_failed'    => '發送失敗，請確認同仁沒有封鎖機器人',

        // 任務卡
        'task_user_not_found' => '找不到這個帳號',
        'task_test_sent'      => '測試總覽已送出 :sent 則，請確認 Telegram 有收到',
        'task_test_partial'   => '已送出 :sent 則，但這幾位沒收到：:failed（請他們先私訊機器人一次）',
        'task_test_no_user'   => '請先勾選收件人並儲存',
        'task_test_not_bound' => '勾選的同仁都還沒私訊過機器人，所以收不到訊息',
        'task_test_failed'    => '發送失敗，請確認同仁沒有封鎖機器人',

        'attendance_user_not_found' => '找不到這個帳號',
        'attendance_test_sent'      => '測試報表已送出 :sent 則，請確認 Telegram 有收到',
        'attendance_test_partial'   => '已送出 :sent 則，但這幾位沒收到：:failed（請他們先私訊機器人一次）',
        'attendance_test_no_user'   => '請先勾選收件人並儲存',
        'attendance_test_not_bound' => '勾選的同仁都還沒私訊過機器人，所以收不到訊息',
        'attendance_test_failed'    => '發送失敗，請確認同仁沒有封鎖機器人',

        'approval_user_not_found' => '找不到這個帳號',
        'approval_test_sent'      => '測試訊息已送出 :sent 則，請確認 Telegram 有收到',
        'approval_test_partial'   => '已送出 :sent 則，但這幾位沒收到：:failed（請他們先私訊機器人一次）',
        'approval_test_no_user'   => '請先勾選收件人並儲存',
        'approval_test_not_bound' => '勾選的同仁都還沒私訊過機器人，所以收不到訊息',
        'approval_test_failed'    => '發送失敗，請確認同仁沒有封鎖機器人',
        'finance_user_not_found'  => '找不到這個帳號',
        'finance_test_sent'       => '測試訊息已送出 :sent 則，請確認 Telegram 有收到',
        'finance_test_partial'    => '已送出 :sent 則，但這幾位沒收到：:failed（請他們先私訊機器人一次）',
        'finance_test_no_user'    => '請先勾選收件人並儲存',
        'finance_test_not_bound'  => '勾選的同仁都還沒私訊過機器人，所以收不到訊息',
        'finance_test_failed'     => '發送失敗，請確認同仁沒有封鎖機器人',
        'wallet_user_not_found'   => '找不到這個帳號',
        'wallet_address_required' => '請填寫錢包地址，或把整列刪掉',
        'wallet_address_invalid'  => '錢包地址格式不對（TRON 地址是 T 開頭、34 碼）',
        'wallet_address_too_many' => '監聽的錢包太多了，最多 :max 個',
        'wallet_label_too_long'   => '備註名稱最多 50 個字',
        'wallet_min_amount_invalid' => '最低通知金額請填 0 以上的數字',
        'wallet_test_sent'        => '測試訊息已送出 :sent 則，請確認 Telegram 有收到',
        'wallet_test_partial'     => '已送出 :sent 則，但這幾位沒收到：:failed（請他們先私訊機器人一次）',
        'wallet_test_no_user'     => '請先勾選收件人並儲存',
        'wallet_test_not_bound'   => '勾選的同仁都還沒私訊過機器人，所以收不到訊息',
        'wallet_test_failed'      => '發送失敗，請確認同仁沒有封鎖機器人',

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
