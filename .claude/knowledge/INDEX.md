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
| [features/rbac.md](features/rbac.md) | 分帳號、權限管理（含最小登入/登出） | 已完成 |
| [features/scheduling.md](features/scheduling.md) | 排班（報班/換班/三班制） | 已完成 |
| [features/telegram-chat.md](features/telegram-chat.md) | 客服對話窗（Telegram 整合、快速回覆、傳送圖片／檔案含進度條、表情符號選單） | 已完成 |
| [features/attendance.md](features/attendance.md) | 打卡出勤 | 已完成（持續迭代） |
| [features/changelog.md](features/changelog.md) | 版本紀錄（左下角變更日誌） | 規劃中 |
| [features/auto-reply.md](features/auto-reply.md) | 自動回覆（Claude 從題庫挑答案、低信心反問、答不出來轉內部支援群組並回填題庫、全域設定頁） | **已實作，待上線前置作業** |
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
| [features/task-board.md](features/task-board.md) | 任務看板（Kanban 五欄、封存系統、活動紀錄、多人指派、留言編輯、描述勾選清單、附件上傳） | 已完成（持續迭代） |
| [features/broadcast.md](features/broadcast.md) | 群發公告（多站台 Telegram 群發、附圖、預約傳送） | 已完成（持續迭代） |
| [features/vm.md](features/vm.md) | 虛擬機管理（主機、開關機、月費帳單、繳款通知） | 已完成（持續迭代，文件待補完） |
| [features/quick-reply.md](features/quick-reply.md) | 快速回覆題庫（類別／問答維護、拖曳排序、編號與關鍵字搜尋） | 已完成（持續迭代，文件待補完） |
| [features/shared-file.md](features/shared-file.md) | 文件區（共用／個人資料夾、子資料夾、Telegram 選檔） | 已完成（持續迭代） |
| [features/station.md](features/station.md) | 站台管理（狀態定義、列表預設只看正常、預設值該放哪一層） | 已完成（持續迭代，文件待補完） |
| [features/station-topup.md](features/station-topup.md) | 站台補點／扣點（USDT 換算或直接輸入點數、審核、均匯率統計排除規則、日期快捷鈕與共用的 `window.DateRange`） | 已完成（持續迭代） |
| [features/finance.md](features/finance.md) | 財務管理（收入模型：補點與虛擬機分開計算、外幣支出換算、手動覆蓋機制） | 已完成（持續迭代） |
| [features/daily-rate.md](features/daily-rate.md) | 每日匯率報價（9 點報到內部群組、引用回覆決定、沒回每 30 分 tag 主管、客人問匯率的動態答案、歷史與手動修改） | **已完成，待跑 migration 與 seeder** |
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
| [bugfix/2026-09-30-btn-outline-info-light-mode.md](bugfix/2026-09-30-btn-outline-info-light-mode.md) | 登入紀錄按鈕在淺色模式滑上去沒反應：`btn-outline-info` 只補了深色模式那一半 |
| [bugfix/2026-09-29-session-expired-silent.md](bugfix/2026-09-29-session-expired-silent.md) | session 過期只跳「CSRF token mismatch.」：8 支 apiFetch 都沒處理 401/419，加 AuthGuard 統一攔截 + 15 分鐘心跳 + 打卡不再假成功 |
| [bugfix/2026-09-29-broadcast-js-syntax-error.md](bugfix/2026-09-29-broadcast-js-syntax-error.md) | broadcast.js 有兩個孤兒 `});`，語法錯誤讓群發頁的 JS 整份不執行（從 `9474c2d` 起一直壞著） |
| [bugfix/2026-09-14-multi-bot-media-download.md](bugfix/2026-09-14-multi-bot-media-download.md) | 固定某一個 Bot 的群組讀不到圖片：媒體下載排在 `switchBotToken()` 之前，`file_id` 用錯 Bot 的 token 呼叫 `getFile` |
| [bugfix/2026-09-30-utc-timestamp-display.md](bugfix/2026-09-30-utc-timestamp-display.md) | 上傳時間顯示少 8 小時：Model 轉 JSON 會序列化成 UTC，前端 `substring` 切字串不換算時區；新增共用的 `window.formatDateTime()`（附帶發現 `station.js` 是死檔） |
