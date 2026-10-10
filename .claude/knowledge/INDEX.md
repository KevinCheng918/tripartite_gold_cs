# 知識庫索引

> 這是 `tripartite_gold_cs` 專案的知識庫主索引。執行任務時先讀這份索引，再依需求載入對應子文件——不要一次載入全部。
>
> **維護規則**：每次任務完成後，必須更新本索引 + 對應的子文件（新增/異動的功能寫進 `features/`，修的 bug 寫進 `bugfix/`）。細節見 `PROMPTS.md` 的「執行步驟」。

## 背景知識（domain/）

| 文件 | 內容 |
|------|------|
| [domain/payment-flow.md](domain/payment-flow.md) | 三方金流主系統的代收/代付四層架構、群組單規則 — 客服對話與工單判斷會用到 |
| [domain/dev-environment.md](domain/dev-environment.md) | 本機 laradock 環境的坑：nginx vhost 要手動 reload、.env host 要用 service name、webpack 版本 pin、config 快取會讓測試環境覆寫失效 |

## 功能規劃（features/）

> 目前皆為**規劃中、尚未實作**的功能。每個文件記錄需求現況與待釐清問題，實作後要更新為實際架構說明。

| 文件 | 功能 | 狀態 |
|------|------|------|
| [features/attendance-report-notice.md](features/attendance-report-notice.md) | 打卡週報表（週一 11:00 報上週）／月報表（1 號 11:00 報上月）：勾選的人收全部人的、其餘同仁收自己那份；含遲到早退曠工次數與時間、補打卡、請假、加班。附帶班表通知的請假預告（前兩天到請假結束）**以及修掉「請整天假被記成曠工」的 bug**（含一次性清理指令） | **已完成，正式機要跑一次 `attendance:fix-absent-on-leave --force`** |
| [features/task-archive-purge-pause.md](features/task-archive-purge-pause.md) | 封存卡的 30 天清理：專案停用期間不計入（重新啟用後整個專案重新給 30 天，`project.reactivated_at` 一個欄位搞定、`task` 不動）；順帶補上缺席的 `ProjectService`（狀態切換連動不能放 Controller） | **已實作，待跑 migration** |
| [features/admin-table-layout.md](features/admin-table-layout.md) | 後台表格與操作欄重排（操作按分類黏成幾段 `btn-group`、用 Architect 的 `btn-transition` 淡框，但**每顆都留在畫面上且都帶文字** —— 收合選單與純圖示兩版都被退回；同一件事的欄位合成一格、`.data-table` 全站統一樣式）；順手修掉 Bootstrap 列底色其實是 box-shadow（淺色模式 hover 一直是淡藍）、金色 inline style 在深色模式看不到、disabled 按鈕的 title 永遠不顯示 | 已完成 |
| [features/rbac.md](features/rbac.md) | 分帳號、權限管理（含最小登入/登出） | 已完成 |
| [features/scheduling.md](features/scheduling.md) | 排班（報班/換班/三班制） | 已完成 |
| [features/support-group-topics.md](features/support-group-topics.md) | 內部支援群組分話題發送（話題清單 + 逐話題勾要收哪些通知、`/topicid` 查 id、話題 id 填錯會整則拒收）；附帶把設定頁重整成「通訊管理 → 通知設定」三分頁 | 已完成 |
| [features/telegram-bind-code.md](features/telegram-bind-code.md) | 驗證碼綁定 Telegram（取代「填帳號就自動綁」的風險做法）、後台解綁、擋多重綁定、陌生人只回一次且回覆由 AI 寫 | 已完成 |
| [features/task-daily-notice.md](features/task-daily-notice.md) | 每日任務卡通知（每人收自己的已過期／今日到期／進行中，勾選的人收全部人的總覽；三個數字各算各的會重複、一張卡可多人指派） | 已完成 |
| [features/remind-escalation.md](features/remind-escalation.md) | 求助單持續提醒（1-2 次 tag 當班、3 次以後加 tag 主管老闆，到次數上限才停）＋ 08:30 每日統計（勾選的人收全部、被催到的人收自己那份）＋ 07:00 待接手清單發內部群組 tag 當天早班；同時移除對話視窗的超時紅橫幅，留下的告警缺口也記在裡面 | 已完成 |
| [features/shift-daily-notice.md](features/shift-daily-notice.md) | 班表通知（每天 8:00 私訊今日班表：勾選的人收完整班表含時間與缺人班次、有班的人收自己那份；bot 要先被私訊過才能發） | 已完成 |
| [features/telegram-chat.md](features/telegram-chat.md) | 客服對話窗（Telegram 整合、快速回覆、傳送圖片／檔案含進度條、表情符號選單） | 已完成 |
| [features/telegram-upload-limit.md](features/telegram-upload-limit.md) | 為什麼機器人只能傳 50MB 而自己的帳號可以傳 2GB（限制在官方的 Bot API 橋接伺服器，自架可到 2000MB）；附帶發現 purge 不刪實體檔 | 調查紀錄 |
| [features/attendance.md](features/attendance.md) | 打卡出勤 | 已完成（持續迭代） |
| [features/changelog.md](features/changelog.md) | 版本紀錄（左下角變更日誌） | 規劃中 |
| [features/auto-reply.md](features/auto-reply.md) | 自動回覆（Claude 從題庫挑答案、低信心反問、答不出來轉內部支援群組並回填題庫、全域設定頁） | **已實作，待上線前置作業** |
| [features/auto-reply-burst.md](features/auto-reply-burst.md) | 客人連發多則時只回一次的設計稿；**附帶查出正式機 `QUEUE_CONNECTION=sync`，佇列從來沒生效** —— 連帶 Telegram 重送與 php-fpm worker 被佔住兩個高風險問題 | 設計稿，等確認 |
| [features/ignore-member.md](features/ignore-member.md) | 忽略特定成員（每個對話各自設定誰不自動回覆，訊息照常收、只是系統不代答） | **已實作，待跑 migration 與勾權限** |
| [features/ignore-staff.md](features/ignore-staff.md) | 後台帳號預設不自動回覆（全域、動態跟著 `user` 表走、Telegram ID 首次命中自動回填；可在單一對話「特別打開」某位同事） | 已完成 |
| [features/auto-reply-progress.md](features/auto-reply-progress.md) | 自動回覆進行中標示（對話視窗提示 + 列表機器人圖示，避免客服重複回覆） | 已完成 |
| [features/auto-reply-natural.md](features/auto-reply-natural.md) | 自動回覆改版：intent 判斷（提問／需求／寒暄）、AI 承接句、移除反問選項 | **已完成，上線後要清話術** |
| [features/knowledge-import.md](features/knowledge-import.md) | 主系統知識題庫 seeder（41 題：錯誤碼、加簽、回調、必填欄位、測試餘額沖正；已建過的不覆蓋） | **已完成，待跑 migration 與 seeder** |
| [features/auto-reply-context.md](features/auto-reply-context.md) | 自動回覆帶對話脈絡（追問「這是什麼錯誤呢」時看得到前一則，求助單附前情） | 已完成 |
| [features/auto-reply-ask-info.md](features/auto-reply-ask-info.md) | 資訊不足時先跟客人要資料（求助單「先問客人」按鈕、AI 判斷 needs_info、追問冷卻） | 已完成 |
| [features/auto-reply-learning.md](features/auto-reply-learning.md) | 假陰性回收（求助單候選按鈕一鍵用題庫原文回覆、自動累積客人的實際問法） | 已完成 |
| [features/login-log.md](features/login-log.md) | 登入紀錄（每帳號登入時間/IP/裝置/成敗） | 已完成 |
| [features/staff-manage.md](features/staff-manage.md) | 內勤管理（身份 `level` 定義、名單兩層排序規則、年資「沒填」的處理） | 已完成（持續迭代，文件待補完） |
| [features/consumable.md](features/consumable.md) | 消耗品管理（內勤領用／使用流水、餘額計算、多品項可維護） | **已實作，待勾權限與建品項** |
| [features/task-board.md](features/task-board.md) | 任務看板（Kanban 五欄、封存系統、活動紀錄、多人指派、留言編輯、描述勾選清單、附件上傳） | 已完成（持續迭代） |
| [features/broadcast.md](features/broadcast.md) | 群發公告（多站台 Telegram 群發、附圖、預約傳送） | 已完成（持續迭代） |
| [features/vm.md](features/vm.md) | 虛擬機管理（主機、開關機、月費帳單、繳款通知） | 已完成（持續迭代）；每天 09:30 自動發繳款通知 |
| [features/quick-reply.md](features/quick-reply.md) | 快速回覆題庫（類別／問答維護、拖曳排序、編號與關鍵字搜尋） | 已完成（持續迭代，文件待補完） |
| [features/shared-file.md](features/shared-file.md) | 文件區（共用／個人資料夾、子資料夾、Telegram 選檔） | 已完成（持續迭代） |
| [features/station.md](features/station.md) | 站台管理（狀態定義、列表預設只看正常、預設值該放哪一層） | 已完成（持續迭代，文件待補完） |
| [features/station-topup.md](features/station-topup.md) | 站台補點／扣點（USDT 換算或直接輸入點數、審核、均匯率統計排除規則、日期快捷鈕與共用的 `window.DateRange`） | 已完成（持續迭代） |
| [features/finance.md](features/finance.md) | 財務管理（收入模型：補點與虛擬機分開計算、外幣支出換算、手動覆蓋機制） | 已完成（持續迭代） |
| [features/daily-rate.md](features/daily-rate.md) | 每日匯率報價（9 點報到內部群組、引用回覆決定、沒回每 30 分 tag 主管、客人問匯率的動態答案、歷史與手動修改） | 已完成（問匯率會直接發補點訊息含繳款圖） |
| [features/station-credit-alert.md](features/station-credit-alert.md) | 站台餘點告警（每日同步餘點、低於門檻自動發 Telegram、**告警與補點訊息分兩則發且圖只附在補點那則**、公版與門檻在繳款設定頁維護、不收費的站台不告警、沒設群組退到內部群組、`--dry-run` 完全唯讀） | 已完成 |

## Bug 修復紀錄（bugfix/）

> 目前無記錄。修完 bug 後在此新增 `bugfix/{yyyy-mm-dd}-{簡述}.md`，並在此表加一行。

| 文件 | 問題 |
|------|------|
| [bugfix/2026-08-07-overnight-shift-clock-out.md](bugfix/2026-08-07-overnight-shift-clock-out.md) | 跨日班下班打卡顯示遲到又早退 |
| [bugfix/2026-08-10-hardcoded-chinese.md](bugfix/2026-08-10-hardcoded-chinese.md) | 待辦：Blade 寫死中文改用 trans() 語系檔 |
| [bugfix/2026-08-26-password-regex-pipe.md](bugfix/2026-08-26-password-regex-pipe.md) | 修改密碼一律「更新失敗」：regex 含 `|` 被 pipe 規則字串拆壞 + 全形符號未提示 |
| [bugfix/2026-09-08-task-comment-emoji-duplicate.md](bugfix/2026-09-08-task-comment-emoji-duplicate.md) | 任務留言按一次表情符號插入好幾個：事件委派在 `document` 卻寫在會重跑的 `loadPanel()` 內而累積（含委派綁定的判斷準則） |
| [bugfix/2026-09-30-on-duty-reply-time.md](bugfix/2026-09-30-on-duty-reply-time.md) | 提醒 tag 到所有在班的人而非負責回訊的人：關聯 select 漏了 `reply_start_time`，`??` 悄悄 fallback 到上下班時間 |
| [bugfix/2026-09-30-amend-overnight-clock-out.md](bugfix/2026-09-30-amend-overnight-clock-out.md) | 晚班補下班卡核准後沒生效：出勤紀錄掛在前一天，只查申請日期找不到；跨日的早退計算也錯算成 1440 分 |
| [bugfix/2026-09-30-qr-badge-dark-mode.md](bugfix/2026-09-30-qr-badge-dark-mode.md) | 題庫編號在深色模式白底白字；句數標記誤用 Bootstrap 5.3 才有的 `*-subtle`（本專案是 5.1） |
| [bugfix/2026-10-01-badge-bg-light-dark-mode.md](bugfix/2026-10-01-badge-bg-light-dark-mode.md) | 「不自動回覆」標籤在深色模式消失：同一個 `.bg-light` 缺配對的洞第三次被踩，這次補在 `custom.css` 一次解決四處 |
| [bugfix/2026-10-02-rate-failure-blocks-credit-alert.md](bugfix/2026-10-02-rate-failure-blocks-credit-alert.md) | 匯率查不到時整輪餘點告警跟著不發：取補點訊息與 `run()` 迴圈都沒 try/catch，而 docblock 卻聲稱有（附三個「告警不發」的正常原因） |
| [bugfix/2026-10-02-remove-credit-alert-cooldown.md](bugfix/2026-10-02-remove-credit-alert-cooldown.md) | 手動按過補點通知，隔天 10 點就不告警：手動發送也寫 `credit_alerted_at`，被冷卻期吃掉 —— 冷卻期整個移除 |
| [bugfix/2026-10-06-task-assignee-cache-miss.md](bugfix/2026-10-06-task-assignee-cache-miss.md) | 改完卡片後指派人變「未指派」：`TaskResource` 的 static 快取「沒命中就跳過」，而四個回傳點只有三個呼叫 `preloadUsers()` —— 修在 Resource 自己身上，日後新的回傳點不必記得 |
| [bugfix/2026-10-02-fontawesome-6-icon.md](bugfix/2026-10-02-fontawesome-6-icon.md) | 消耗品分頁的兩個版面問題：FA6 的 icon 名稱在 FA5 不存在；tab-pane 插在 `.tab-content` 外導致人員統計跑進來（附數巢狀深度的驗法） |
| [bugfix/2026-10-02-btn-link-white-block.md](bugfix/2026-10-02-btn-link-white-block.md) | 圖示鈕在深色模式變白方塊：病根是 `app.css` 把 `background:#fff` 塞進 Bootstrap 尺寸 class `.btn-sm`，淺色模式也錯只是看不出來（附「先問淺色模式那裡是什麼顏色」的排查順序） |
| [bugfix/2026-09-30-btn-outline-info-light-mode.md](bugfix/2026-09-30-btn-outline-info-light-mode.md) | 登入紀錄按鈕在淺色模式滑上去沒反應：`btn-outline-info` 只補了深色模式那一半 |
| [bugfix/2026-09-29-session-expired-silent.md](bugfix/2026-09-29-session-expired-silent.md) | session 過期只跳「CSRF token mismatch.」：8 支 apiFetch 都沒處理 401/419，加 AuthGuard 統一攔截 + 15 分鐘心跳 + 打卡不再假成功 |
| [bugfix/2026-09-29-broadcast-js-syntax-error.md](bugfix/2026-09-29-broadcast-js-syntax-error.md) | broadcast.js 有兩個孤兒 `});`，語法錯誤讓群發頁的 JS 整份不執行（從 `9474c2d` 起一直壞著） |
| [bugfix/2026-09-14-multi-bot-media-download.md](bugfix/2026-09-14-multi-bot-media-download.md) | 固定某一個 Bot 的群組讀不到圖片：媒體下載排在 `switchBotToken()` 之前，`file_id` 用錯 Bot 的 token 呼叫 `getFile` |
| [bugfix/2026-09-30-utc-timestamp-display.md](bugfix/2026-09-30-utc-timestamp-display.md) | 上傳時間顯示少 8 小時：Model 轉 JSON 會序列化成 UTC，前端 `substring` 切字串不換算時區；新增共用的 `window.formatDateTime()`（附帶發現 `station.js` 是死檔） |
