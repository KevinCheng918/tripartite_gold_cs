<?php

/**
 * 版本紀錄 — 由新到舊排列
 * 每筆格式：version / date / title / content
 */
return [
    [
        'version' => 'v1.34',
        'date'    => '2026-08-21',
        'title'   => '專案管理獨立頁面 + 任務看板專案按鈕移除',
        'content' => implode("\n", [
            '• 新增專案管理獨立頁面（sidebar 內勤管理下方）',
            '• 每個專案以 Tab 呈現，顯示名稱/狀態/說明/建立者/時間',
            '• 支援新增、編輯、啟停用專案',
            '• 新增 project.view / project.edit 權限',
            '• 移除任務看板「新增專案」按鈕及 task_board.manage_project 權限',
        ]),
    ],
    [
        'version' => 'v1.33',
        'date'    => '2026-08-21',
        'title'   => '換班/代班手機版 RWD 修正',
        'content' => implode("\n", [
            '• 換班請求、代班管理手機版與桌機版不再同時顯示',
            '• 桌機表格加上 table-hover、thead-gold 統一樣式',
            '• 手機版卡片加上間距（margin-bottom）',
        ]),
    ],
    [
        'version' => 'v1.32',
        'date'    => '2026-08-21',
        'title'   => '密碼規則調整 + 報班驗證修正',
        'content' => implode("\n", [
            '• 密碼規則改為允許英文、數字、符號，最少 8 碼',
            '• 報班員工必填驗證改為僅管理者檢查，一般員工自動帶入自己',
        ]),
    ],
    [
        'version' => 'v1.31',
        'date'    => '2026-08-21',
        'title'   => '補點紀錄備註欄位合併至操作欄',
        'content' => implode("\n", [
            '• 補點紀錄表格移除獨立備註欄，改為操作欄第一個按鈕',
            '• 有備註時顯示備註按鈕，與通過/拒絕/重試並排',
        ]),
    ],
    [
        'version' => 'v1.30',
        'date'    => '2026-08-21',
        'title'   => '全站 Tab 記憶 + Bootstrap Tab 統一 + Icon',
        'content' => implode("\n", [
            '• 全站 Tab 切換後自動記憶，換頁或重新整理後自動還原',
            '• 出勤頁面、排班管理改為標準 Bootstrap Tab，統一全站記憶機制',
            '• 出勤 Tab 加 icon：打卡/我的出勤/月報表/補打卡審核',
            '• 排班 Tab 加 icon：排班課表/班別設定/換班/代班/請假管理',
        ]),
    ],
    [
        'version' => 'v1.29',
        'date'    => '2026-08-21',
        'title'   => '全站 Tab 狀態記憶',
        'content' => implode("\n", [
            '• 全站 Tab 切換後自動記憶，換頁或重新整理後自動還原上次的 Tab',
            '• 以頁面路徑為 key 存入 localStorage，各頁面互不干擾',
            '• 支援 Bootstrap tab 和 pill 兩種 nav 元件',
        ]),
    ],
    [
        'version' => 'v1.28',
        'date'    => '2026-08-21',
        'title'   => '群發公告字數上限即時計算',
        'content' => implode("\n", [
            '• 公告內容 textarea 加上 maxlength=4096 限制',
            '• 下方即時顯示字數計數（N / 4096），接近上限時紅字警示',
        ]),
    ],
    [
        'version' => 'v1.27',
        'date'    => '2026-08-21',
        'title'   => '群發公告發送明細 + 結果提示優化',
        'content' => implode("\n", [
            '• 群發公告歷史紀錄：點擊全部/指定 badge 可查看每站台發送結果（成功/失敗）',
            '• 新增 send_results JSON 欄位記錄逐站發送狀態',
            '• 發送完成提示 Modal 改為手動關閉，點 OK 後才刷新頁面',
        ]),
    ],
    [
        'version' => 'v1.26',
        'date'    => '2026-08-21',
        'title'   => 'Dark Mode 補強 + 報班驗證',
        'content' => implode("\n", [
            '• 封存清單 Modal Dark Mode 完整適配（表格/篩選/按鈕）',
            '• 封存清單按鈕統一 btn-outline-secondary，還原/重置按鈕加上文字',
            '• 全站 input[type=file] 檔案選擇按鈕 Dark Mode 適配',
            '• 全站 input-group-text Dark Mode 適配（年資篩選等）',
            '• 報班新增員工必填驗證，未勾選員工阻擋送出',
        ]),
    ],
    [
        'version' => 'v1.25',
        'date'    => '2026-08-21',
        'title'   => '任務看板側邊面板屬性排版優化',
        'content' => implode("\n", [
            '• 屬性網格排版調整：專案/站台縮窄（col-3），指派人員/預期完成日加寬（col-6）',
            '• 屬性值字體放大（0.9375rem），標籤字體統一 0.75rem',
            '• inline 編輯改為 input + 下方按鈕排列，解決 col-3 內 select 跑版問題',
            '• 日期 input 加 form-control-sm + min-width:130px，確保日期完整顯示',
        ]),
    ],
    [
        'version' => 'v1.24',
        'date'    => '2026-08-21',
        'title'   => 'USDT 匯率圖表折線記憶',
        'content' => implode("\n", [
            '• USDT/TWD 匯率折線圖：點擊 legend 隱藏/顯示折線後，狀態記憶至 localStorage',
            '• 重新整理或下次進入頁面自動還原上次的折線顯示設定',
        ]),
    ],
    [
        'version' => 'v1.23',
        'date'    => '2026-08-21',
        'title'   => '封存清單強化 + 站台金黃色主題',
        'content' => implode("\n", [
            '• 封存清單新增欄位：專案、原始狀態、指派人員',
            '• 封存清單支援前端篩選（專案/人員/時間範圍）',
            '• 篩選選項從封存資料動態產生，確保匹配',
            '• 修正任務看板人員搜尋：assignee_ids 同時比對整數與字串型別',
            '• 修正封存時間顯示 1970/1/1 問題',
            '• 站台管理頁面套用金黃色主題',
        ]),
    ],
    [
        'version' => 'v1.22',
        'date'    => '2026-08-21',
        'title'   => '版本紀錄功能 + Sidebar 佈局優化',
        'content' => implode("\n", [
            '• 新增版本紀錄功能：sidebar 底部固定按鈕 + 彈窗顯示完整更新歷程',
            '• Sidebar 改為三區佈局：頂部固定（個人資訊+首頁）、中間可滾動、底部固定（版本紀錄）',
            '• 內勤管理頁面金黃色主題調整 + 手機版跑版修復',
        ]),
    ],
    [
        'version' => 'v1.21',
        'date'    => '2026-08-21',
        'title'   => '任務封存功能 + 帳號管理 UI 優化',
        'content' => implode("\n", [
            '• 任務看板「刪除」改為「封存」，封存 30 天後自動清除',
            '• 新增封存清單彈窗，可查看並還原封存任務',
            '• 帳號管理統計卡、表格 thead 改為金色主題風格',
            '• 帳號管理頁面金黃色主題調整：斑馬紋淺金、登入紀錄按鈕 dark-mode 樣式',
            '• Dark mode 新增 btn-outline-info 支援',
        ]),
    ],
    [
        'version' => 'v1.20',
        'date'    => '2026-08-21',
        'title'   => '登入紀錄 + 任務面板重構 + 出勤優化',
        'content' => implode("\n", [
            '• 新增登入紀錄功能：記錄登入時間/IP/裝置/成敗',
            '• 管理者可從帳號管理查看各帳號登入紀錄',
            '• 任務看板側邊面板重構 + 異動紀錄 + 屬性 Modal 編輯',
            '• 出勤明細新增早上班/晚下班欄位，欄位按上下班分組',
            '• 個人出勤及管理者出勤明細新增班別欄位',
            '• 出勤明細頁顯示使用者名稱，日期排序改為月底到月初',
        ]),
    ],
    [
        'version' => 'v1.19',
        'date'    => '2026-08-20',
        'title'   => '補點 API 加簽 + Dashboard 排班優化',
        'content' => implode("\n", [
            '• 補點 API 加簽改為 sha256(api_key + APP_ENCRYPT_KEY + timestamp)',
            '• isLeaderUp 判斷從 Blade 移至 User Model',
            '• Dashboard 主管以上顯示今日排班全員版和本週概況排名表',
            '• 修正 Dashboard 排班顯示相關問題',
        ]),
    ],
    [
        'version' => 'v1.18',
        'date'    => '2026-08-19',
        'title'   => '內勤管理 + 補點紀錄優化',
        'content' => implode("\n", [
            '• 新增內勤管理功能 + 帳號身份擴充 + 人員/設備管理',
            '• 內勤管理搜尋區新增排序功能',
            '• 補點紀錄搜尋區重構與統計總覽',
            '• 補點紀錄表格欄位優化',
        ]),
    ],
    [
        'version' => 'v1.17',
        'date'    => '2026-08-19',
        'title'   => '請假功能 + 出勤請假標注',
        'content' => implode("\n", [
            '• 新增請假功能（獨立於排班權限）',
            '• 我的出勤標注請假時間',
        ]),
    ],
    [
        'version' => 'v1.16',
        'date'    => '2026-08-19',
        'title'   => 'Dashboard 權限控管 + 任務看板權限',
        'content' => implode("\n", [
            '• Dashboard 排班區塊改由 shift.view 權限獨立控制',
            '• Telegram 客服綁定權限，無權限不顯示且無法進入',
            '• 任務看板非管理者依參與專案篩選，管理者看全部',
            '• 帳號新增參與專案設定',
        ]),
    ],
    [
        'version' => 'v1.15',
        'date'    => '2026-08-19',
        'title'   => '站台/虛擬機搜尋優化',
        'content' => implode("\n", [
            '• 站台列表搜尋區欄位順序調整為系統→站台名稱→域名',
            '• 虛擬機列表搜尋新增站台下拉選單（可輸入搜尋）',
        ]),
    ],
    [
        'version' => 'v1.14',
        'date'    => '2026-08-18',
        'title'   => '任務看板留言增強 + 排序',
        'content' => implode("\n", [
            '• 留言新增刪除功能 + emoji 表情按鈕',
            '• 任務指派人員改為多選',
            '• 新增排序下拉選單',
            '• 手機版拖曳改為長按觸發，避免滑動誤觸',
        ]),
    ],
    [
        'version' => 'v1.13',
        'date'    => '2026-08-17',
        'title'   => '補打卡審核 + Telegram 對話優化',
        'content' => implode("\n", [
            '• 補打卡審核顯示原始打卡時間',
            '• Telegram 對話顯示「正在輸入中」',
            '• 對話點擊自動標為已讀',
            '• 帳號管理與排班管理頁面補上副標題',
        ]),
    ],
    [
        'version' => 'v1.12',
        'date'    => '2026-08-17',
        'title'   => '任務看板到期提醒 + 搜尋篩選',
        'content' => implode("\n", [
            '• 明日到期卡片顯示黃色驚嘆號警告',
            '• 進行中卡片未設定到期日顯示紅字警告',
            '• 搜尋區新增優先順序篩選',
            '• 橫向滾動限制在看板區域內',
        ]),
    ],
    [
        'version' => 'v1.11',
        'date'    => '2026-08-17',
        'title'   => '任務看板富文本 + 多圖上傳 + 站台欄位',
        'content' => implode("\n", [
            '• 任務描述改用 TinyMCE 富文本編輯器',
            '• 任務與留言支援多圖上傳',
            '• 新增「測試中」欄位 + 站台欄位',
            '• 任務看板側邊面板、inline 編輯、留言系統',
        ]),
    ],
    [
        'version' => 'v1.10',
        'date'    => '2026-08-17',
        'title'   => '任務看板功能',
        'content' => implode("\n", [
            '• 新增任務看板功能，支援拖曳排序與狀態移動',
            '• 任務新增站台欄位 + Controller 改用 FormRequest',
        ]),
    ],
    [
        'version' => 'v1.9',
        'date'    => '2026-08-17',
        'title'   => '站台補點/扣點 + 補打卡功能',
        'content' => implode("\n", [
            '• 站台補點/扣點功能',
            '• 補點申請支援多圖上傳與查看',
            '• 補打卡功能：客服申請 + 管理者審核',
            '• 月報表補打卡次數 + 出勤明細補打卡標記',
        ]),
    ],
    [
        'version' => 'v1.8',
        'date'    => '2026-08-14',
        'title'   => '排班報班優化 + Flatpickr 金色主題',
        'content' => implode("\n", [
            '• 報班支援多天選擇（flatpickr multiple mode）',
            '• 報班加二次確認 modal，顯示日期/班別/員工',
            '• 報班日期限制只能選今天之後',
            '• 已結束的班別不能申請代班',
            '• Flatpickr 金色主題',
        ]),
    ],
    [
        'version' => 'v1.7',
        'date'    => '2026-08-14',
        'title'   => 'Telegram 聊天功能增強',
        'content' => implode("\n", [
            '• 群發公告支援多張圖片（sendMediaGroup）',
            '• 聊天 emoji reaction + picker',
            '• 群發公告支援圖片發送',
            '• Telegram 發送訊息 HTML 轉義',
            '• 群發公告發送後存入對話紀錄',
        ]),
    ],
    [
        'version' => 'v1.6',
        'date'    => '2026-08-14',
        'title'   => 'Dark mode 全面修正 + RWD 優化',
        'content' => implode("\n", [
            '• 全站 badge 改為淡底色 pill 樣式 + dark mode 適配',
            '• 補打卡審核手機版 RWD：桌面表格 + 手機卡片佈局',
            '• 手機版 header 展開選單 dark mode 修正',
            '• 桌面版 sidebar 收合功能修正',
            '• 防止 dark mode 頁面閃白（FOUC）',
        ]),
    ],
    [
        'version' => 'v1.5',
        'date'    => '2026-08-14',
        'title'   => '新增班別功能 + USDT 匯率 + 分頁統一',
        'content' => implode("\n", [
            '• 新增班別功能 + flatpickr 時間選擇器',
            '• USDT 匯率卡片 + 4H/1D K 線曲線圖',
            '• 匯率改權限控制',
            '• 分頁統一 10 筆 + Bootstrap 分頁樣式',
        ]),
    ],
    [
        'version' => 'v1.4',
        'date'    => '2026-08-12',
        'title'   => 'VM 管理功能 + 帳務紀錄',
        'content' => implode("\n", [
            '• 新增虛擬機管理（VM + 帳務紀錄 + 繳款證明）',
            '• 繳款設定功能 + 帳務紀錄複製文案/發送通知',
            '• VM 帳單日改為 1-31，短月自動取最後一天',
            '• 帳務紀錄加系統欄位 + 系統篩選',
            '• VM 搜尋表單 + 統計摘要',
            '• 站台管理系統狀態總覽 + 狀態切換功能',
            '• 帳號管理顯示客服狀態統計',
        ]),
    ],
    [
        'version' => 'v1.3',
        'date'    => '2026-08-11',
        'title'   => 'Dark mode 全面修正 + 排班色塊優化',
        'content' => implode("\n", [
            '• Dark mode 全面修正：modal/table/按鈕/排班/出勤/聊天',
            '• 排班色塊改為一人一色，欄位按班別時間排序',
            '• 排班/代班/換班 UI 修正 + 換班驗證強化',
            '• 群發公告 Tab 化',
        ]),
    ],
    [
        'version' => 'v1.2',
        'date'    => '2026-08-10',
        'title'   => '全站 Architect UI 框架重構',
        'content' => implode("\n", [
            '• 全站改用 Architect UI 框架：Layout/Sidebar/Header/Dashboard/Login 重構',
            '• 帳號管理/權限頁/站台列表/群發公告/出勤明細改 SSR（Blade 渲染）',
            '• 表格改 Architect Data Tables 風格：無外框、扁平、粗體 header',
            '• Telegram 聊天改 Architect 風格 + 拆分 JS 檔案',
            '• 登入頁全面重設計',
            '• 全站去藍色：Light/Dark mode 統一金色主題',
        ]),
    ],
    [
        'version' => 'v1.1',
        'date'    => '2026-08-09',
        'title'   => 'PWA + Web Push + 全站視覺升級',
        'content' => implode("\n", [
            '• PWA + Web Push 推播通知：Telegram 新訊息即時通知客服',
            '• 全站視覺升級：陰影系統取代硬邊框、專業質感提升',
            '• Telegram 引用回覆功能',
            '• 聊天修正：Enter 送出 / Shift+Enter 換行 / IME 選字不誤觸',
        ]),
    ],
    [
        'version' => 'v1.0',
        'date'    => '2026-08-06',
        'title'   => '系統初版上線',
        'content' => implode("\n", [
            '• 帳號管理（RBAC 角色權限）',
            '• 排班功能 + 報班/代班/換班',
            '• 打卡出勤 + 曠工排程',
            '• Telegram 客服對話（文字/圖片/貼圖/emoji）',
            '• 群發公告功能',
            '• 站台列表管理',
            '• 修改密碼 + 首頁固定班別',
            '• 登入頁記住帳號密碼',
        ]),
    ],
];
