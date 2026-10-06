# 每天 7:00 私訊今日班表（**已實作** 2026-10-06）

每天早上 7:00，用 bot **私訊**今日班表：

| 收件人 | 內容 |
|---|---|
| 設定頁勾選的人（**可多位**，含全選） | 今天**每個班次**各是誰、哪個班沒人、每班的上班與核心回訊時間 |
| 今天有班的每個人 | **只有自己那一筆**（不列同班的其他人，需求方指定） |

**平假日都發**（需求方指定）。假日沒排班時主管那則寫「今天沒有任何排班」——
群組裡完全沒動靜時，分不出是「今天本來就沒班」還是「排程又壞了」。

## ⚠ 最大的限制：bot 不能主動私訊陌生人

Telegram 的規定：**bot 只能發訊給曾經主動跟它對話過的使用者**。
對沒互動過的人呼叫 `sendMessage` 會得到：

```
403 Forbidden: bot can't initiate conversation with a user
```

所以**每個收件人都得先私訊 bot 一次**（送任何一句話都可以）。
那一刻由 `TelegramChatService::bindPrivateChat()` 記下 `user.telegram_dm_ready`。

> ⚠ **不能只看 `telegram_user_id`**：那個欄位從**群組訊息**也會被
> `StaffIgnoreService` 回填，「群組裡講過話」不等於「私訊過 bot」。
> 拿它當判斷會對著一整批沒加過 bot 的人狂發 403。
> 所以 `canDm()` 要求**兩個條件都成立**。

### private 訊息以前會被直接丟掉

```php
// 改之前：TelegramChatService，收 webhook 訊息時
if ($chatType === 'private') {
    return;     // ← 連 user id 都沒記
}
```

丟掉是為了不讓同事的私訊被建成客服對話。現在改成「綁定但仍然不建對話」。

## 實作

### 一、綁定（誰可以被私訊）

```
private 訊息進來（TelegramChatService）
  ├─ 用 from.username 對到後台帳號
  │   ├─ 對得到 → markDmReady()：回填 telegram_user_id + telegram_dm_ready，回一句確認
  │   └─ 對不到 → 回一句「請先在後台設定您的 Telegram 帳號」
  └─ 仍然不建立客服對話
```

話術在 `config/constants.php` 的 `TELEGRAM.DM_BIND`（DONE / UNKNOWN）。

### 二、每天 8:00 的排程

`shift:notify-daily`（`Console\Kernel` 的 `dailyAt('07:00')`）：

```
ShiftNoticeService::run($date, $dryRun)
  ├─ 今天的 ShiftAssignment（getByDateRange(today, today)）
  ├─ 所有啟用中的班別（ShiftRepository::allActive()）
  ├─ buildManagerText() → sendToManagers()（逐位勾選的收件人）
  └─ sendToEveryone()：逐一私訊今天有班的人
```

⚠ **主管那份走的是「所有啟用中的班別」而不是「今天有排班的班別」** ——
需求方要的是「哪個班沒人」，那種班在 `$assignments` 裡根本不存在，
只看排班資料永遠列不出來。

指令選項：`--date=Y-m-d`（補發）、`--dry-run`（只印內容不發送）。

### 三、發不出去怎麼辦

| 情況 | 做法 |
|---|---|
| 沒 `telegram_user_id` 或沒 `telegram_dm_ready` | 跳過，收進 `failed` |
| 送出失敗（被封鎖等） | 記 log，收進 `failed` |
| 排班的班別已被停用 | 記 `warning` 並略過那筆 |

整輪結束後把 `failed` **彙總成一則**發到內部支援群組（`FAILED_SUMMARY`）——
不逐人發，十個人沒綁定就會洗十則版。

⚠ **一個人失敗不能讓整輪停掉** —— 逐人 try/catch，比照
`StationCreditAlertService::run()`。

### 四、設定頁

sidebar 的**通訊管理**分組，把原本散在上面的「Telegram 客服」一起收進來：

```
通訊管理
├─ Telegram 客服   （原本在最上面那組，移過來）
└─ 通知設定        （三個分頁，班表通知是其中一個）
```

⚠ **2026-10-06 後續調整**：原本獨立的「班表通知」頁已併入「通知設定」的分頁，
權限 keyword 從 `shift_notice.*` 改成 `notification.*`。詳見 [[support-group-topics]]。

頁面上只有一個欄位（完整班表的收件人）+ 一顆測試發送。收件人是**可勾多位的清單
加一個「全選」**，沒綁定的人標成灰的「（未綁定）」但不隱藏 —— 勾了也收不到，
要在勾之前就看得出來；整個拿掉會變成「名單裡沒這個人」，更難判斷。

⚠ 收件人存的是**逗號串接的 id**（`3,7,12`），用 `AppSettingService::getIntList()` 讀、
`idListValue()` 寫。儲存鍵沿用單數的 `shift_notice.manager_user_id` ——
換掉字串會讓既有設定值歸零。

⚠ **「全選」的勾選狀態要跟著個別項目走**：少了那段同步，手動把人逐一勾完之後
「全選」還是沒勾，看起來像壞掉。

⚠ **測試按鈕是真的發出去**（不是 dry-run）：要回答的是「訊息到得了他手機嗎」，
只組字串的話，沒綁定、被封鎖這些真正會出事的狀況全都測不到。
只發完整班表那一則給勾選的人，不會打擾今天有班的同仁，開頭掛 `TEST_PREFIX`
以免被當成真的班表。

⚠ **有人收到、有人沒收到也要回報**：勾了五個人只有三個收到時，
只回「已送出」會讓另外兩個無聲消失（`test_partial` 那句語系就是為此）。

## 檔案

| 檔案 | 內容 |
|---|---|
| `database/migrations/2026_10_06_000001_add_telegram_dm_ready_to_user_table.php` | 新欄位 `telegram_dm_ready` |
| `app/Services/TelegramChatService.php` | `bindPrivateChat()`：private 訊息改成綁定而不是丟掉 |
| `app/Services/ShiftNoticeService.php`（新增） | 組訊息、逐人發送、彙總失敗、`forPage()` / `updateSetting()` / `test()` |
| `app/Services/StaffDmService.php`（新增） | 私訊同仁的共用入口（綁定檢查、逐人送、失敗處理），班表與提醒統計共用 |
| `app/Services/AppSettingService.php` | `KEY_SHIFT_NOTICE_MANAGER`、`getIntList()` / `idListValue()` |
| `app/Console/Commands/NotifyDailyShiftCommand.php`（新增） | `shift:notify-daily` |
| `app/Console/Kernel.php` | `dailyAt('07:00')` |
| `app/Http/Controllers/Admin/ShiftNoticeController.php`（新增） | 設定頁 + ajax |
| `app/Http/Requests/ShiftNotice/UpdateShiftNoticeRequest.php`（新增） | `manager_user_ids` 陣列驗證（`exists:user,id`，表名沒有 s） |
| `app/Repositories/UserRepository.php` | `markDmReady()` / `findForDm()` / `getForDmByIds()` / `getDmCandidates()` |
| `config/constants.php` | `TELEGRAM.DM_BIND`、`SHIFT_NOTICE.*` |
| `config/permissionMap.php` | `shift_notice.view` / `shift_notice.manage` |
| `routes/web.php` | `admin/shift-notice/*` |
| `resources/views/admin/shift-notice/index.blade.php`（新增） | 設定頁 |
| `resources/views/layouts/app.blade.php` | sidebar 新增「通訊管理」分組 |
| `public/js/shift-notice.js`（新增） | 設定頁前端 |
| `resources/lang/{tw,cn,en}/shift_notice.php`（新增） | 語系 |
| `resources/lang/{tw,cn,en}/permission.php` | 權限說明 |

## 怎麼看誰還沒綁定

**帳號管理的表格有「TG 綁定」一欄**（2026-10-06 加），三態：

| 顯示 | 意思 | 要做什麼 |
|---|---|---|
| 🟢 已綁定 | 私訊過機器人，`telegram_dm_ready` = true | 不用做事 |
| 🟡 未綁定 | 填了 `telegram_username` 但還沒私訊過 | 請**他本人**私訊機器人一次 |
| ⚪ 未填帳號 | `telegram_username` 是空的 | **後台先補**，不然他私訊也綁不上 |

⚠ **三態而不是兩態**是刻意的：後兩者要做的事完全不同（一個是後台補資料、
一個是請本人動作），併成「未綁定」會讓人不知道該找誰。

通知設定頁的收件人清單也會標「（未綁定）」，但那只涵蓋候選人；
要看全員狀態看帳號管理。

## ⚠ `telegram_username` 還是得手動填

`bindPrivateChat()` 是用 **`from.username` 去對後台帳號**
（`findByTelegramUsername()`）—— 沒填就對不到，bot 只能回
「請先到後台設定您的 Telegram 帳號」。

而且 **Telegram 的 username 是選填的**：沒設 username 的同事，
這條路永遠綁不上。

> 要真正免掉手動輸入，得改成**驗證碼綁定**：後台「我的帳號」顯示一組一次性
> 代碼，同事私訊那個代碼 → 系統用代碼找到帳號、記下 `telegram_user_id`。
> 那樣完全不需要事先知道對方的 username，也不要求他有 username。
> **尚未實作。**

## 上線要做的事

1. `php artisan migrate`（新欄位 `telegram_dm_ready`）
2. `php artisan optimize`（動過 `config/`）
3. 到「通訊管理 → 通知設定 → 班表通知」勾選收件人（可多位／全選）
4. **請收件人先私訊機器人一次**，否則清單會顯示「（未綁定）」、測試發送會失敗
5. 按「測試發送」確認真的收到

⚠ 第 4 步漏掉是最常見的狀況 —— 設定頁的右欄常駐說明就是為了這件事。

## ⚠ `SEND_AT` 是兩個地方

`constants.SHIFT_NOTICE.SEND_AT`（設定頁顯示的字）和 `Console\Kernel` 的
`dailyAt('07:00')`（真正的排程）**各自獨立**。改時間要兩邊一起改，
不然設定頁會寫著錯的時間。

## 相關

- [[scheduling]] — 排班功能本體（`ShiftAssignment` 的資料結構）
- [[ignore-staff]] — `telegram_user_id` 的另一個回填來源（群組訊息）
- [[daily-rate]] — 每日排程 + 測試按鈕的既有範例
- [[telegram-chat]] — webhook 收訊的入口
- [[remind-escalation]] — 另一個用 `StaffDmService` 的功能（每日提醒統計）
