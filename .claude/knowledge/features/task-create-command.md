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

## 專案用按鈕選，不是叫他重打（2026-11-01 追加）

需求方：「如果沒有帶入專案的話，讓他用選擇的，不要用再次輸入的方式。」

第一版是回一段文字請他帶上專案名再說一次。⚠ 叫人重打整句話的代價不只是
麻煩 —— **重打一次模型會重新解析一次**，標題可能被拆成不一樣的字，
於是他得再看一次確認卡。按鈕則是沿用同一份草稿，只補上專案。

流程變成：

```
沒講專案 / 講的對不到 / 對到兩個以上
  → 存草稿（project_id 先留空）＋ 出專案按鈕
  → 按下某個專案 → 補進草稿 → 原地 editMessage 成確認卡
  → 按確認 → 建立
```

⚠ **草稿裡要同時存 id 與顯示名稱**（`project_names`、`assignee_name`）。
只存 id 的話，選完專案要重畫確認卡時得再查一次 —— 而那時候手上只有
callback，原本那句話早就不在了。

⚠ **選專案那一步用 `Cache::get` 不是 `pull`**：後面還要等他按確認，
草稿不能在這一步被拿走。

⚠⚠ **一定要驗選到的 id 在不在 `allowed_project_ids` 裡**。`callback_data`
是從外面送進來的，不能直接拿來當 `project_id` 寫入 —— 否則任何人只要改一個
數字就能把卡片開到他看不到的專案裡。允許清單存在草稿（伺服器端快取）裡，
所以不必再查一次，也動不了手腳。

⚠ **專案太多就不出按鈕**（上限 `MAX_PROJECT_BUTTONS`，預設 12），退回原本
的「請帶上專案名再說一次」—— inline keyboard 塞幾十顆會長到要捲動，
比重打還難用。

⚠ 一行一顆按鈕。專案名長度不一，兩顆並排時寬度對不齊而且字會被截掉。

⚠ **多一道「還沒選專案就按確認」的防呆**（舊訊息上的按鈕）：沒有的話
`project_id` 是 null，會一路撞到資料庫的 not null，而使用者只看到
「建立失敗了」。

### 實測九項

出按鈕（不是重打）、按下專案原地變確認卡、接著確認後寫入的 `project_id`
是選的那個、專案有歧義也出按鈕、專案講得清楚則直接確認卡不出選單、
**竄改 callback 選清單外的專案被擋**、專案 13 個超過上限退回文字、
還沒選專案就按確認被擋且沒有寫入、選專案時草稿已過期。

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

## ⚠ 上線前抓到：參與專案打了勾卻說「你沒有參與任何專案」

需求方回報。原因**不在帳號設定**，而是
`UserRepository::findByTelegramUserId()` 的 select **沒有撈 `project_ids`**。

```
User::projectIdList()  讀 $this->project_ids → 欄位沒撈 → null
  → blank() → ProjectRepository::getVisibleTo() 回空集合
  → 「你沒有參與任何專案」
```

⚠ **這是這個專案重複踩過的同一種坑。** 同一支方法的 docblock 上就寫著
2026-10-30 為了 `level` 補過一次（沒撈 `level` 會讓 `isAdmin()` 誤判成
管理者而**每一支指令都放行**）。欄位沒撈不會報錯、不會留 log ——
只會變成一句看起來像資料設定問題的訊息，而回報的人會先去翻帳號設定，
然後發現那邊是對的。

⚠ 實測對照（同一個人、同一支 Repository，只差 select）：

| select | `projectIdList()` | `getVisibleTo()` |
|---|---|---|
| 有 `project_ids` | `[3]` | 1 個專案 |
| 沒有 | `[]` | **0 個** ← 修之前 |

> 日後在這支 Repository 加任何「依使用者判斷可見範圍」的功能之前，
> 先確認那個判斷依據的欄位有沒有在 select 裡。

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
