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
| [features/remind-period-report.md](features/remind-period-report.md) | 超時統計的週報表（週一）／月報表（1 號）：一支 Service 吃 `--type`，**三種都在 08:30 跟日報同一分鐘**（需求方要求時間一致），收件人沿用日報那份、只發完整版不發個人版、日報保留。文案裡寫死的「昨天」全部參數化成 `{period}` | 已完成 |
| [features/scheduled-broadcast-command.md](features/scheduled-broadcast-command.md) | 用 Telegram 指令預約發公告（「20 分鐘後發送 T01 公告給 LV_UAT」）：接上既有的範本 `code` 與預約發送排程。**後果是公告發到客戶群且不可逆**，所以每個環節都往「寧可不做」倒 —— 要 `telegram_chat.broadcast` 權限、代號與站台名完全相符不猜、**解析完先回預覽按確認才成立**。⚠ 對象存的是 **station id** 不是 telegram_group id，查找刻意走發送端同一個來源免得兩邊分岔。2026-10-14 追加 `message` 意圖：**預約傳送自己當場打的訊息**（`schedule()` 本來就吃 `content`、代號選填，所以是打開既有管線的另一個入口而非新功能）—— 預覽**完整貼出不截斷**（那段字是模型從句子裡抄的，會原封不動發給客戶），且**不要自己 `e()`**（`sendMessage()` 已經 `escapeHtml()`，再跳脫會變 `&amp;lt;`）。⚠ **【確認預約】按鈕沒驗權限** —— 有權限的人下指令、沒權限的人按確認就能發出去（[[station-credit-command]] 已補這一層，這支還沒） | **已完成，範本要先填 code** |
| [features/scheduled-reminder.md](features/scheduled-reminder.md) | 預約提醒：在內部群組 tag 機器人說「明天下午三點提醒 @abc123aa 對帳」，時間到原文發回**同一個話題**。AI 解析自然語言（一定複述＋取消按鈕，因為解析錯要到那個時間才會被發現）、內容原文送出讓 Telegram 自己 mention、送失敗不標記下一分鐘再試。**實測踩到模型把整包 JSON 塞進 content 欄位**，schema 描述＋prompt 去掉排版符號＋程式端 `looksLikeJson()` 三層防。話題分流多一項 `scheduled_reminder`，**語意跟其他類型相反**（勾的是「哪些話題可以排」而不是「發到哪」），一個都沒勾＝不限制。⚠ 2026-10-13 起「有沒有 tag 到我／話題准不准／哪一種指令」已搬到 `TelegramCommandRouter`，這支只負責提醒本身（但仍是「看不懂」時的歸宿），見 [[station-credit-command]] | **已完成，正式機待跑 migration ＋ 勾話題** |
| [features/rate-query-command.md](features/rate-query-command.md) | 用 Telegram 指令要今天的匯率補點訊息（「今天匯率多少」）：**有幾個系統就回幾則**（補點訊息跟著繳款設定走，收款方式不同，併成一則會貼錯給客戶），每則標 `【系統名】`、含繳款圖，拿到直接貼給客戶。⚠ 訊息走 `buildTopupMessage()`——跟餘點告警第二則、繳款設定測試發送**同一支**，不自己排版。三種情況分開講（有匯率有公版／匯率未定／有匯率但沒公版，第三種要把匯率講出來免得跑去查好好的匯率頁）。順手加 `SupportGroupService::sendPhotoToThread()`（圖文一起回在原話題；圖說超過 1024 字退回純文字，寧可少圖也不要整則失敗） | **已完成，多系統與真實發送未驗** |
| [features/task-query-command.md](features/task-query-command.md) | 用 Telegram 指令查某人的卡片進度（「確認一下 @aabb123 的卡片進度狀況」）：待處理／進行中／測試中／審核中（後三者含預計完成時間）／已解決（2 天內），**空的段落連大標題一起不印**。⚠ **`task` 表沒有 `resolved_at`**，「2 天內完成」只能從 `task_activity` 的 `moved` 紀錄回推 —— 用 `updated_at` 會把上個月解決、昨天改過標題的卡算成「剛完成」。⚠ `assignee_ids` 與 `changes` 都是 `longtext` 不是真 JSON 欄位，所以撈回來在 PHP 比對。排分派器第五棒，**四支上游全部實測讓路** | **已完成，@帳號查找無真資料驗過** |
| [features/credit-alert-command.md](features/credit-alert-command.md) | 用 Telegram 指令發餘點告警＋補點通知給客戶（「傳餘點告警給 LV_UAT」→ 確認 → 同步 → 發兩則）。⚠ **五支 tag 指令裡唯一會發給客戶、而且收不回來的**，所以獨立權限 `telegram_chat.credit_alert`、確認訊息要寫明**發到哪個群組**、按鈕 30 分鐘過期、按的人再驗一次權限。送出走 `StationCreditAlertService::sendManual()`（＝站台列表那顆按鈕），不重寫免得規則分岔。⚠ 缺設定就不往下走，判斷用 `Station::$topup_notice_blockers` ——跟後台按鈕 disable **同一個來源**，各寫一份的話日後多一個必要欄位只會改到一邊 | **已完成，未真的發送過** |
| [features/vm-query-command.md](features/vm-query-command.md) | 用 Telegram 指令查虛擬機：講了站台回完整卡片（費用／規格／電源／當月帳單），沒講站台則回跨站台的「誰還沒繳錢」或「誰開機誰關機」。⚠ **繳費是三態**：`paid=2` 是「客戶已上傳證明等我們審」，講成「未繳費」是把我方延誤說成客戶欠款、會催錯人。⚠ **不列主機名稱但要分台列**（需求方兩條補充）＝ 用**序號**區分；第一版誤做成加總，把兩台不同的規格與電源壓成一行就把資訊弄丟了。⚠ 關機不出帳，沒帳單時要先看電源才不會把「不該有帳單」說成「帳單還沒產」；**關機的未收款不列也不算**（繳款通知本來就不催關機的，清單列了反而淹掉要處理的）——**但待審核的不分電源**，濾掉等於客戶付了錢永遠沒人審。純讀本地 DB 所以**不做二次確認**（跟餘點相反）。另有 `afford` 試算「點數夠不夠扣這期虛擬機費用」：⚠ **單位不同必須換算**（費用是 USDT、餘點是「點」，點數 ＝ USDT × 匯率，實測差三十幾倍），匯率用今日報價、沒決定退 4H 均價、都沒有就照實說算不出來**絕不湊數字**；要兩個權限（答案裡有餘點）。⚠ 上線前抓到餘點解析器會把這題搶走 —— 修的是它**過度認領**（「查餘額」≠「拿餘額比對費用」），不是調順序。順手抽出 `StationNameMatcher` 讓 `lv-uat` 對得到 `LV_UAT`，四支指令共用 | **已完成，多台／關機／待審核無真資料驗過** |
| [features/station-credit-command.md](features/station-credit-command.md) | 用 Telegram 指令查站台餘點（「幫我查一下 LV_UAT 餘點」→ 確認 → 同步 → 回報）。**看起來唯讀其實會寫 DB 又打外部 API**，所以要二次確認、要 `telegram_chat.station_credit`、**按鈕要再驗一次權限**（按的人不一定是下指令的人）。⚠ 權限**刻意不沿用**後台同步按鈕的 `station.update`：底層是同一支 `syncInfo()`，但「後台編輯站台」與「用指令查一下餘點」是兩件事 —— 權限照**使用者在做什麼**切，不是照程式走到哪一支切。同步失敗**絕不回舊點數**；找站台走 `getForCreditSync()` 免得「指令找得到、按下去同步不了」。⚠ 多站台是用**實際站台清單**擋的 —— prompt 寫了「兩個以上回 unknown」但實測模型不聽。順手抽出 `TelegramCommandRouter` 收攏三種 tag 指令的分流 | **已完成，使用者要先綁 Telegram ID** |
| [features/customer-followup.md](features/customer-followup.md) | 待追蹤事項（有人說「稍等」就記一筆）：詞表先篩＋AI 確認、**客人承諾與我方承諾分開標示**（AI 自動回覆那句排除）、對話安靜 N 分鐘才到內部群組 tag 當班人員問「要不要追蹤」（按鈕）、**客人又說一次「稍等」不算結案**、不自動結案由人決定、沒人按比照求助單計次升級（升級規則抽成共用的 `EscalatingReminder`）、後台列表頁（狀態**預設看全部**、期間**預設今天開的**，清除條件會連期間一起清掉＝看全部）、10:30 獨立統計 | **已實作，待跑 migration、開開關、勾權限** |
| [features/attendance-report-notice.md](features/attendance-report-notice.md) | 打卡週報表（週一 11:00 報上週）／月報表（1 號 11:00 報上月）：勾選的人收全部人的、其餘同仁收自己那份；含遲到早退曠工次數與時間、補打卡、請假、加班。附帶班表通知的請假預告（前兩天到請假結束）**以及修掉「請整天假被記成曠工」的 bug**（含一次性清理指令） | **已完成，正式機要跑一次 `attendance:fix-absent-on-leave --force`** |
| [features/report-page.md](features/report-page.md) | 報表頁（內務管理 → 報表）：打卡報表與超時提醒統計兩個分頁，**自己選起訖日期＋今日/昨日/本週/上週/本月/上月快捷鈕**（比照補點紀錄，共用 `window.DateRange`；後端只收 start/end，不吃期間類型）、**數字跟 Telegram 那份走同一支 `collect()`**；打卡出勤頁原本的「月報表」分頁搬過來後拿掉；兩個分頁各自一個權限，頁面本身因為 `can:` 是 AND 而改在 Controller 自己擋。**兩種報表都不列主管以上（含主管本人）**；超時統計的「仍未處理」整列標紅＋摘要帶題數（pending 由後端判定，前端不寫死 status 數字），紅標那一列可按「標記為已處理」結案（另一個 keyword `report.remind_handle`，狀態條件寫在 update 的 where 裡避開與 Telegram 按鈕互相覆蓋，並回報支援群組） | 已完成 |
| [features/task-archive-purge-pause.md](features/task-archive-purge-pause.md) | 封存卡的 30 天清理：專案停用期間不計入（重新啟用後整個專案重新給 30 天，`project.reactivated_at` 一個欄位搞定、`task` 不動）；順帶補上缺席的 `ProjectService`（狀態切換連動不能放 Controller） | **已實作，待跑 migration** |
| [features/admin-table-layout.md](features/admin-table-layout.md) | 後台表格與操作欄重排（操作按分類黏成幾段 `btn-group`、用 Architect 的 `btn-transition` 淡框，但**每顆都留在畫面上且都帶文字** —— 收合選單與純圖示兩版都被退回；同一件事的欄位合成一格、`.data-table` 全站統一樣式）；順手修掉 Bootstrap 列底色其實是 box-shadow（淺色模式 hover 一直是淡藍）、金色 inline style 在深色模式看不到、disabled 按鈕的 title 永遠不顯示。**列狀態的左條走 `--row-accent` 變數，box-shadow 全專案只寫一次**（各自寫會互相整條覆蓋） | 已完成 |
| [features/rbac.md](features/rbac.md) | 分帳號、權限管理（含最小登入/登出） | 已完成 |
| [features/user-notice-toggle.md](features/user-notice-toggle.md) | 逐個帳號開關要收哪些私訊（班表／任務卡／超時統計／打卡報表）：新表 `user_notice_mute` 走**黑名單**（有列＝關掉，上線不必補資料、新增通知自動全收）；過濾擋在共同出口 `StaffDmService::send()` 的 `noticeKey` 參數，`sendToUserIds()`（完整版）不帶 key 所以天然不受管。**靜音時回 `true` 讓 `sent` 小幅失真，換掉「被誤算進 failed 害主管去追人」** | 已完成 |
| [features/scheduling.md](features/scheduling.md) | 排班（報班/換班/三班制） | 已完成 |
| [features/support-group-topics.md](features/support-group-topics.md) | 內部支援群組分話題發送（話題清單 + 逐話題勾要收哪些通知、`/topicid` 查 id、話題 id 填錯會整則拒收）；附帶把設定頁重整成「通訊管理 → 通知設定」三分頁。⚠ 2026-10-13 起清單裡混了一個**語意相反**的類型（`scheduled_reminder` 勾的是「哪些話題可以排提醒」），而且它管的是**全部三種 tag 指令**（提醒／公告／查餘點）不只提醒，見 [[scheduled-reminder]]、[[station-credit-command]] | 已完成 |
| [features/telegram-bind-code.md](features/telegram-bind-code.md) | 驗證碼綁定 Telegram（取代「填帳號就自動綁」的風險做法）、後台解綁、擋多重綁定、陌生人只回一次且回覆由 AI 寫 | 已完成 |
| [features/task-daily-notice.md](features/task-daily-notice.md) | 每日任務卡通知（每人收自己的已過期／今日到期／進行中，勾選的人收全部人的總覽；三個數字各算各的會重複、一張卡可多人指派） | 已完成 |
| [features/remind-escalation.md](features/remind-escalation.md) | 求助單持續提醒（1-2 次 tag 當班、3 次以後加 tag 主管老闆，到次數上限才停）＋ 08:30 每日統計（勾選的人收全部、被催到的人收自己那份）＋ 07:00 待接手清單發內部群組 tag 當天早班；同時移除對話視窗的超時紅橫幅，留下的告警缺口也記在裡面 | 已完成 |
| [features/shift-daily-notice.md](features/shift-daily-notice.md) | 班表通知（每天 8:00 私訊今日班表：勾選的人收完整班表含時間與缺人班次、有班的人收自己那份；bot 要先被私訊過才能發） | 已完成 |
| [features/telegram-chat.md](features/telegram-chat.md) | 客服對話窗（Telegram 整合、快速回覆、傳送圖片／檔案含進度條、表情符號選單） | 已完成 |
| [features/unread-badge.md](features/unread-badge.md) | 未讀數（對話列表各自的則數 ＋ sidebar 的「有未讀的對話數」）：已讀**每人各自**存在新表 `telegram_group_read`，`telegram_message.replied` 回歸「真的回了」原意；Pusher 連線全站共用一條（`window.AppPusher`）；順手修掉 `getConversationList()` 逐群組 COUNT 的 N+1。**`updateOrCreate` 不是原子操作，要用 `upsert`** | **已完成，正式機待跑 migration** |
| [features/telegram-upload-limit.md](features/telegram-upload-limit.md) | 為什麼機器人只能傳 50MB 而自己的帳號可以傳 2GB（限制在官方的 Bot API 橋接伺服器，自架可到 2000MB）；附帶發現 purge 不刪實體檔 | 調查紀錄 |
| [features/fixed-work-hours.md](features/fixed-work-hours.md) | 固定上下班時間（工程不排班也要算出勤）：帳號上設「輪班／固定時間」，輪班吃原本的排班、固定班吃帳號上的起訖與上班日；遲到／早退／加班／曠工四處都改走同一支 `resolveWorkTime()`；排班紀錄**合成顯示**固定班的客服而不寫實體 assignment（避免每人一個 shift、避免跑進換班選單、避免改設定要回頭補刪未來的列）。實作時發現同一條規則其實散在**五處**（補打卡那套完全獨立、Blade 裡還有一份）；`end_minutes` 統一在 `buildWorkTime()` 換算好跨日，呼叫端不再各自認 `00:00` | **已完成，正式機待跑 migration（沒跑會直接壞帳號頁）** |
| [features/attendance.md](features/attendance.md) | 打卡出勤。2026-10-14：個人每日出勤狀況改成**每一天都有一列**（`getMonthlyTimeline()`）—— 原本跑的是出勤紀錄清單，所以**請整天假那天整個消失**（`MarkAbsentCommand` 對請整天假的人刻意不建曠工紀錄）。⚠ `records`（統計用，只能算真有紀錄的）與 `timeline`（表格用）**兩個都要傳**，共用的話總天數會變成「這個月幾天」。沒紀錄又沒請假的標「休假」——那是安全推論：有排班沒打卡的會被補成曠工紀錄。順手修掉逐筆查請假的 N+1 | 已完成（持續迭代） |
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
| [features/broadcast-template.md](features/broadcast-template.md) | 公告建檔（群發公告 → 公告管理）：標題＋內容＋附件先建好，發送時選一則帶進表單、**帶進去還能改**（範本模式，改動不回寫）；清單綁 `broadcast`、增刪改綁新的 `broadcast_template`。**附件一律只收「索引」不收路徑** —— 路徑最後會進 `sendDocument()`（吃本地絕對路徑），收路徑等於開放把任意檔案發給所有客人 | **已完成，正式機待跑 migration 與勾權限** |
| [features/vm.md](features/vm.md) | 虛擬機管理（主機、開關機、月費帳單、繳款通知） | 已完成（持續迭代）；每天 09:30 自動發繳款通知 |
| [features/quick-reply.md](features/quick-reply.md) | 快速回覆題庫（類別／問答維護、拖曳排序、編號與關鍵字搜尋） | 已完成（持續迭代，文件待補完） |
| [features/shared-file.md](features/shared-file.md) | 文件區（共用／個人資料夾、子資料夾、Telegram 選檔） | 已完成（持續迭代） |
| [features/station.md](features/station.md) | 站台管理（狀態定義、列表預設只看正常、預設值該放哪一層） | 已完成（持續迭代，文件待補完） |
| [features/station-topup.md](features/station-topup.md) | 站台補點／扣點（USDT 換算或直接輸入點數、審核、均匯率統計排除規則、日期快捷鈕與共用的 `window.DateRange`）。2026-10-14 加點數類型**「其他」**：一樣能輸入金額、一樣要通過／拒絕，但**不呼叫主系統 API**（點數不會真的加扣）、**不發客戶通知**（沒東西可通知，硬發就是騙人）、**備註必填**（沒有 `api_response` 可對照，不寫就查不出這筆是什麼）。⚠ 分支要擋在「站台沒設 API 就判失敗」**之前**，否則沒設 API 的站台連記帳都會失敗 | 已完成（持續迭代） |
| [features/finance.md](features/finance.md) | 財務管理（收入模型：補點與虛擬機分開計算、外幣支出換算、手動覆蓋機制） | 已完成（持續迭代） |
| [features/daily-rate.md](features/daily-rate.md) | 每日匯率報價（9 點報到內部群組、引用回覆決定、沒回每 30 分 tag 主管、客人問匯率的動態答案、歷史與手動修改） | 已完成（問匯率會直接發補點訊息含繳款圖） |
| [features/station-credit-alert.md](features/station-credit-alert.md) | 站台餘點告警（每日同步餘點、低於門檻自動發 Telegram、**告警與補點訊息分兩則發且圖只附在補點那則**、公版與門檻在繳款設定頁維護、不收費的站台不告警、沒設群組退到內部群組、`--dry-run` 完全唯讀）。2026-10-18：發送時間從固定 10:00 改成**決定匯率後的下一個整點**（10:30 決定→11:00 發、23:30 決定→隔天 00:00 發、整天沒決定就不發）——原本 09:00 才問匯率，這一小時沒決定好的話告警就少了補點訊息，客戶收到「點數不足」卻不知道要匯多少。排程改 `hourly()->when()`，判斷**不寫在指令裡**（手動跑要能隨時執行），而且**要看昨天那筆**（跨午夜）。⚠ 改匯率會重寫 `replied_at`，不擋就會重送 —— 改成**問一次**（附按鈕）再發，權限與「用指令發餘點告警」共用；`CreditAlertResendService` 拆成獨立一支是因為塞回 `DailyRateService` 會 A→B→A 循環相依 | 已完成 |

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
| [bugfix/2026-10-11-modal-dialog-shadow.md](bugfix/2026-10-11-modal-dialog-shadow.md) | 置中彈窗後面多一塊「白板」：Architect 把陰影下在 `.modal-dialog`，而 `modal-dialog-centered` 的 `min-height` 讓它變成滿版的隱形矩形 —— 看到的亮長條其實是**外面被陰影壓暗**。排查時查背景色一無所獲，**背景透明不代表不會畫出東西**，要連 `boxShadow` 一起看 |
| [bugfix/2026-10-11-attendance-detail-sidebar-active.md](bugfix/2026-10-11-attendance-detail-sidebar-active.md) | 出勤明細已搬到報表底下，側邊選單卻還亮「打卡出勤」：頁面搬了但路由名沒搬，`routeIs('admin.attendance.*')` 照樣命中 |
| [bugfix/2026-10-11-remind-report-still-pending.md](bugfix/2026-10-11-remind-report-still-pending.md) | 「明明回了也加進題庫，超時提醒統計還是仍未處理」：報表無快取、紅的那列是另一張單；附求助單離開 `PENDING` 的全部路徑，與「同仁直接在客戶群回客人不會結單」這個缺口；已補上手動出口（報表那列可「標記為已處理」） |
| [bugfix/2026-10-13-dropzone-click-recursion.md](bugfix/2026-10-13-dropzone-click-recursion.md) | 公告管理的拖曳區點下去整頁 JS 當掉（Maximum call stack size exceeded）：`<input>` 藏在 dropzone 裡，`trigger('click')` 的事件冒泡回容器又觸發一次 —— 「點容器 → trigger 裡面的元素」是遞迴陷阱，要擋在 input 側的 `stopPropagation()` |
| [bugfix/2026-10-13-validation-message-swallowed.md](bugfix/2026-10-13-validation-message-swallowed.md) | 傳 .exe 只看到「輸入資料無效」：422 的自訂訊息全在 `errors` 裡，前端卻只讀通用的 `message`。**第三次**踩到（report.js、followup.js 都修過）—— 錯誤處理的順序永遠是 `errors` 第一句 → `message` → fallback |
| [bugfix/2026-10-12-double-wrapped-json-prompt.md](bugfix/2026-10-12-double-wrapped-json-prompt.md) | AI 寫的文案送出去變成 `{"text":"…"}` 連大括號：`--json-schema` 已經強制格式，prompt 又寫「只回 JSON」—— 模型把要輸出的 JSON 寫進 `text` 的內容裡。**格式只能由一個地方決定**；也是「修好擋住一切的 bug 後，後面沒走過的路一次曝光」的例子 |
| [bugfix/2026-10-12-filled-on-boolean.md](bugfix/2026-10-12-filled-on-boolean.md) | **`filled(false)` 回的是 true**（`blank()` 對布林與數字一律回 false）——「我方承諾」因此從上線起一筆都沒開出來過、Claude CLI 成功的回應被當成失敗丟掉。`filled()`/`blank()` 只能用在可能沒填的東西上，布林用 `(bool)` |
| [bugfix/2026-10-12-followup-keyword-mismatch.md](bugfix/2026-10-12-followup-keyword-mismatch.md) | 待追蹤事項顯示「原句：能回調了嗎／命中：稍等」：沒有誤判，是 `resetWait()` 被兩種語意共用 —— 客人說別的話只該推後計時，卻連原句一起換掉而留著舊的命中詞。「傳舊值進去以免被覆蓋」就是該拆的訊號 |
| [bugfix/2026-10-11-ignore-open-tickets-scope.md](bugfix/2026-10-11-ignore-open-tickets-scope.md) | 求助單被誤關與漏關：繳款通知／補點結果漏傳 `is_auto` 而清空整群的單，送檔案的兩支從來沒有關單規則 —— `is_auto` 問的是「算不算客服親自回覆」而不是「誰觸發的」，它幾乎總是跟 `mark_replied => false` 成對出現 |
| [bugfix/2026-10-14-ai-claims-reading-customer-data.md](bugfix/2026-10-14-ai-claims-reading-customer-data.md) | AI 回客人「已請專員**調閱 10/13 的設定紀錄**比對」—— 不是語氣問題，是說了一件我們不會做也不該做的事（連日期都是它自己補的）。病根是 prompt 要它「順著問題講，不要罐頭句」＝ 給了它編故事的空間。⚠ **要模型講得自然，就等於給它編故事的空間** —— 會被客人看到的生成文字都要明講「哪些事不能寫」。修 prompt（正反例）＋ 黑名單護欄（只擋「調閱／查閱」這類**動作**，不擋「紀錄／設定」本身，免得誤殺），兩支對客 writer 一起改 |
| [bugfix/2026-10-14-done-reply-treated-as-request.md](bugfix/2026-10-14-done-reply-treated-as-request.md) | 客人說「已完畢！」被回「已請專員接手跟進」—— 根本沒有東西要跟進。病根是 prompt 規則「或是**在回答我們先前問他的事情** → request」太寬：那句話確實在回答我們，但**沒帶任何要同仁處理的內容**。判準換成「這句話裡有沒有東西要同仁處理」並給對照例。⚠ **沒有上下文時測不出來**（無上下文判得對），而「客人回覆我們」正是最常見的情境。⚠ 整份 system prompt 存在 Cache（TTL 300 秒），**`artisan optimize` 清不掉** —— 重 dump 長度一樣不是沒改成功，是讀到快取 |
| [bugfix/2026-10-19-proof-uploaded-still-chased.md](bugfix/2026-10-19-proof-uploaded-still-chased.md) | 上傳了繳款證明還是被催「麻煩協助手動通知客戶繳款」：那段文字只有 `handleUnpaidBilling()` 會發，而它只吃 `paid = UNPAID` —— 所以排程當下 `paid` 就是 0，但現有程式碼**沒有任何路徑**能造成「有證明卻 paid=0」（上傳只有一條路且一定寫 2、匯率呼叫不會丟例外、沒有地方寫回 0）。根因未明，但重點是**程式只看了 `paid` 一個訊號**。改成兩張清單都看兩個訊號：有證明就歸「催審核」。⚠ **兩邊要一起改** —— 只排除不接的話那筆會從兩張清單同時消失，比原本更糟。一般化：**狀態欄位與事實分家時以事實為準**（`paid` 是我們維護的狀態，`proof_image` 是客戶真的做過的事） |
