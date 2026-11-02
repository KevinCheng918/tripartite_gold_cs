# 小幫手開任務卡

**需求方 2026-11-01**：「我小幫手這邊，我要新增一個功能是，請他幫我開任務版的卡片。」

**狀態：已實作（v3.64，2026-11-01）。**
**⚠ 上線前置：到帳號管理把 `telegram_chat.task_create` 勾給該用的人。**

## 它會長什麼樣

```
你：@bot 幫我開一張卡，三方金流的登入頁一直 500，指派給 @mary，很急

機器人：要建立這張卡片嗎？
       標題：登入頁一直 500
       專案：三方金流
       指派：@mary
       優先序：🔴 高
       [ 確認建立 ]  [ 取消 ]

（按下確認）

機器人：✅ 已建立 #37 登入頁一直 500
       （標題是連結，點了直接開看板的那張卡）
```

## 接進現有架構的方式

這個專案已經有一套成熟的指令分派，**不需要新的進入點**：

| 元件 | 做什麼 | 照誰寫 |
|---|---|---|
| `AutoReply/TaskCreateCommandParser` | 讀懂整句，吐出 intent ＋ 欄位 | `TaskQueryCommandParser` |
| `TaskCreateCommandService` | 權限、二次確認、呼叫 `TaskBoardService::storeTask()` | `ScheduledBroadcastCommandService`（它是現有唯一「要確認的寫入型指令」）|
| `TelegramCommandRouter` | 多掛一支 handler | — |
| `config/constants.php` | 文案與 callback 前綴 | 各 `*_COMMAND` 區塊 |
| `config/permissionMap.php` | 一個新 keyword | — |

⚠ handler 的簽章固定是 `handle($body, $from, $threadId): bool`，
回 `false` 代表「不是我的事，換下一個」—— 加這一支**不必動任何既有的
service**（Router 的 docblock 就是這麼設計的）。

### ⚠ 擺在 `taskCommand`（查卡片）之前

兩支都在講「任務卡片」，誰先問就有機會先認領。照 Router 現有的慣例
——「**會寫入的擺在只讀的前面**」（發公告在查餘點之前、發告警在查餘點
之前）——開卡要排在查卡片之前。

理由在 Router 裡寫過：查詢被誤判成寫入，至少還有二次確認擋著；
反過來則是使用者想開卡卻只拿到一份查詢結果，會重打好幾次。

## ⚠ 最大的障礙：`project_id` 是必填

`StoreTaskRequest` 的 `project_id` 是 `required|exists:project,id`，而
`storeTask()` 也直接讀 `$params['project_id']`。**沒有專案就開不出卡。**

用講的要補上這一格，只有三條路：

| 做法 | 代價 |
|---|---|
| 從句子裡認專案名 | 講錯或沒講時要回問，多一輪對話 |
| 用「發話者參與的唯一專案」當預設 | 他參與兩個以上就不成立 |
| 固定一個預設專案 | 卡片會集中到錯的專案，之後要人工搬 |

**裁示：認得出來就用，認不出來就回問**「要開在哪個專案？」並列出發話者
看得到的專案 —— 不猜。

## 權限

新 keyword **`telegram_chat.task_create`**，不沿用後台的 `task_board.create`。

⚠ 這跟 `telegram_chat.task_query` 的理由一致（`permissionMap.php` 上就寫著）：
「後台開卡」與「用指令開卡」是兩件事 —— 用講的沒有表單擋著，
誰能用應該單獨決定。

## 發話者是誰

`$from` 裡有 Telegram 的 `id`，用 `UserRepository::findByTelegramUserId()`
換成系統帳號（`TaskQueryCommandService::permissionError()` 就是這樣做的）。

⚠ 被指派的人才是用 `findByTelegramUsername()` —— 句子裡的 `@帳號`。
兩支不要搞混：發話者認的是 Telegram **使用者 id**（改暱稱也認得出來），
指派對象只能靠他打出來的 username。

⚠ **換不到系統帳號就直接拒絕**，不要用「系統」當建立者：卡片上的建立者
是之後追責任的依據，掛成系統等於沒人知道是誰開的。

## 裁示結果（需求方 2026-11-01）

| 題目 | 裁示 |
|---|---|
| 專案怎麼決定 | **認不出來就回問**，並列出他看得到的專案。不猜 |
| 二次確認 | **要** —— 複述一次再按【確認建立】 |
| 能用講的設哪些欄位 | **標題／專案／指派／優先序／到期日**（描述、站台、參與人都進後台補）|
| 建立後通知任務卡專區 | **要通知**（⚠ 推翻同日稍早的「只有狀態更改與新留言」，見 [[task-change-notice]]）|
| 沒講指派人 | **留空**（未指派），不要預設成發話者 |
| 開在哪個狀態欄 | **固定「待處理」**，不開放用講的 |

⚠ 「要通知」那一項是**改在 `TaskChangeNoticeService`**，不是在這支指令裡
自己發一則 —— 所以**後台開的卡也會通知**。只讓指令開的卡發的話，
「有沒有通知」會取決於開卡的人用哪個介面，而看群組的人不知道有這種差別。

## 實作時踩到／刻意處理的幾件事

⚠ **指派對象查不到要停下來問**，不要默默開成未指派：他明明講了要給誰，
拿到一張沒人認領的卡會以為系統壞了。

⚠ **專案名比對「先求完全相同，再求包含」**：專案叫「金流」與「金流測試」時，
只做包含比對的話講「金流測試」會同時中兩個而被當成有歧義回問 ——
但他其實講得很清楚。完全相同的優先。

⚠ 比對用 `mb_strtolower`，`strtolower` 只處理 ASCII —— 專案名混英文大小寫
時會比不到。

⚠ **模型回的 `"null"` 字串要當成沒講**：不擋的話專案名會變成一個叫
「null」的專案去比對，然後永遠對不上。

⚠ **到期日只收 `YYYY-MM-DD`**，不要丟給 `Carbon::parse()` 自己猜 ——
模型偶爾會回「下週一」原文，`parse()` 會丟例外或給出一個沒人看得懂的日期。

⚠⚠ **指令這條路沒有經過 `StoreTaskRequest`** —— 那是後台表單的驗證，
`max:200` 擋不到這裡，而 `task.title` 是 `varchar(200)`。不截的話，使用者
貼一整段錯誤訊息進來會直接撞資料庫長度限制，而他只看到一句「建立失敗了」，
完全不知道是標題太長。所以在**解析層**就截到 200 字
（`TaskCreateCommandParser::TITLE_MAX`）。

⚠ 截在解析層而不是寫入前：這樣確認卡上看到的就是真正會存下來的標題，
覺得被截得奇怪可以當場按取消。

⚠ 一定要用 `mb_substr` —— `substr` 會把中文從中間切斷成亂碼
（2026-11-01 實測對照過：`substr` 截 250 個中文字會產生非法 UTF-8）。

⚠ **標題與專案名要 `htmlspecialchars()`**：那是人打的字，含 `<` 或 `&` 時
`parse_mode: HTML` 會判成壞掉的標籤而**整則發不出去**。

⚠ **寫入包 try/catch**：沒有這一層的話，webhook 吞掉例外之後按鈕只會停在
「確認建立」，使用者以為開好了。

⚠ **草稿不在了不能當成成功**（過期、或已經按過一次）—— 按了確認的人一定
要知道結果。同 [[scheduled-broadcast-command]]。

⚠ callback 前綴用 **`tc`**。既有的是 rm／sc／cr／ca／bc／ar／arc／arp／fu，
加之前先確認沒撞號。

## 實測十六項（假模型、假 Repository，不碰 DB 也不發 Telegram）

解析器六項：正常句、不是開卡、沒講標題、模型回 `"null"` 字串、
亂填優先序、到期日不是 Y-m-d —— 後四項都正確退回 null／unknown。

流程十項：不是開卡回 false（不講話）、沒權限、完整流程（確認卡 → 按確認 →
寫入參數正確 → 回卡片連結）、沒講專案回問並列清單、專案有歧義回問、
完全相同優先於包含、指派查不到停住且不給按鈕、草稿過期、取消、
標題含 `<` `&` 正確轉義。

## 相關檔案

**新增**

```
app/Services/AutoReply/TaskCreateCommandParser.php
app/Services/TaskCreateCommandService.php
.claude/knowledge/features/task-create-command.md（本檔）
```

**修改**

```
app/Services/TelegramCommandRouter.php     多一支 handler（擺在 taskCommand 之前）
config/constants.php                       TASK_CREATE_COMMAND 文案與 callback 前綴
config/permissionMap.php                   telegram_chat.task_create
resources/lang/{tw,cn,en}/permission.php   新 keyword 的顯示名
app/Http/Controllers/Webhook/TelegramWebhookController.php
                                           callback_query 分流（前綴 tc）
app/Services/TaskChangeNoticeService.php   建立卡片也通知（抬頭 🆕 新任務卡）
```

## 相關

- [[task-query-command]] — 查卡片進度，最接近的前例
- [[scheduled-broadcast-command]] — 現有唯一「要確認的寫入型指令」
- [[task-board]] — `storeTask()` 與欄位規則
- [[task-change-notice]] — 建立卡片的通知（2026-11-01 改成要發，抬頭是 🆕 新任務卡）
