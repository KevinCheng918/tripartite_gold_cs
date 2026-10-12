# 每個帳號自己開關要收哪些私訊

> **狀態：已實作，待跑 migration。**

## 需求（2026-10-12）

> 「帳號管理，新增開關選項會收到的私人通知，有打勾則送出，沒打勾則不送」

## 現況：目前有四種私訊會發給個人

全部走同一個出口 `StaffDmService`，而且每一支都是「完整版 ＋ 個人版」兩種收件人：

| 通知 | 排程 | 個人版發給誰 | 完整版發給誰 |
|---|---|---|---|
| 班表通知 | 每天 08:00 | 今天有班的人 | 通知設定頁勾選的人 |
| 任務卡通知 | 每天 08:00 | 每位在職同仁 | 同上（另一份名單） |
| 超時提醒統計（日報） | 每天 08:30 | 每位在職同仁 | 同上（另一份名單） |
| 打卡週報／月報 | 週一、1 號 11:00 | 每位在職同仁（扣掉收完整版的） | 同上（另一份名單） |

目前**沒有任何人可以關掉**其中任何一則 —— 只要在職又綁了 Telegram 就會收到。

## 設計

### 過濾放在 `StaffDmService`，不是各支 Service

四支 Service 的私訊都經過 `StaffDmService::send()` / `sendToUserIds()`，
所以在那裡擋一次就好：

```php
// 呼叫端只要多傳一個 key
$this->staffDm->send($user, $text, StaffDmService::NOTICE_SHIFT);
```

⚠ **不要在四支 Service 各自過濾** —— 那是同一條規則抄四份，
而且以後新增第五種通知時，很容易忘記加。擋在共同出口，新通知自動受管。

### 新表 `user_notice_mute`：有列＝關掉

```
user_id      FK user  cascadeOnDelete
notice_key   string(32)
unique(user_id, notice_key)
```

⚠ **用「存在與否」表達狀態**（比照 `telegram_group_staff_allow`），
而且是**黑名單**方向：

- 沒有列 ＝ 要收（維持現狀，不必為既有帳號補任何資料）
- 有列 ＝ 這個人關掉了這一種

反過來做成白名單的話，上線當天所有人都會變成「沒勾選」而收不到任何通知，
得先補一輪資料；而且以後加第五種通知時又要再補一次。

### 通知種類定義在 config

`constants.NOTICE.PERSONAL` 一個陣列，key 存進 DB、label 走語系檔。
新增一種通知只要加一行，畫面上的勾選框自動多一個。

### 畫面

帳號管理每一列的操作加一顆「通知設定」，開 modal 勾四項（預設全勾）。

## 需求方的三個決定（2026-10-12）

| 問題 | 回答 | 影響 |
|---|---|---|
| 關掉後完整版也不發嗎 | **只擋個人版** | 過濾掛在 `send()` 的新參數，`sendToUserIds()` 轉呼叫時不帶 key |
| 誰能改 | **只有管理者** | 不做「我的帳號」那一側 |
| 瀏覽器推播一起納管嗎 | **這次只做 Telegram** | 不碰 `WebPushService` |

⚠ 「只擋個人版」能成立，是因為 `sendToUserIds()`（完整版）**內部就是逐人呼叫
`send()`**。過濾條件掛在 `send()` 的第三個參數上，完整版那條路不傳 key，
於是天然不受管 —— 不必多一個旗標。

## ⚠ 一個刻意選擇的數字失真

`send()` 在「他關掉了這一種」時**回 `true`**，於是呼叫端的 `sent` 會多算一，
Command 印的「發送 5 則」其實是「處理了 5 個收件人」。

另一邊（回 `false`）的代價更大：那個人會被算進 `failed`，而那份名單的用途是
讓人去追「誰該收卻沒收到」—— 自己關掉的人出現在那裡，主管會跑去問他
是不是沒綁定機器人。**數字小小失真，換掉一個會造成錯誤行動的誤導。**

要查「為什麼他沒收到」直接看 `user_notice_mute` 表；`send()` 裡不寫 log，
不然每天每人一筆會把日誌洗掉。

## ⚠ 新增第五種通知時

1. `config/constants.php` 的 `NOTICE.PERSONAL` 加一行
2. `resources/lang/*/account.php` 的 `notice_labels` 加同一個 key
3. **發送端把 key 傳給 `StaffDmService::send()`**

第 3 步漏了不會報錯（那個洞是為了讓完整版走同一支而留的），畫面上勾選框
照樣會出現，但勾不勾都沒作用。

## 檔案（預估）

**新增**
```
database/migrations/..._create_user_notice_mute_table.php
app/Models/UserNoticeMute.php
app/Repositories/UserNoticeMuteRepository.php
app/Http/Requests/Account/UpdateNoticeRequest.php
```

**修改**
```
config/constants.php                      NOTICE.PERSONAL 四個 key
app/Services/StaffDmService.php           send()/sendToUserIds() 收 noticeKey 並過濾
app/Services/{Shift,Task}NoticeService.php
app/Services/{Remind,Attendance}ReportService.php   各自傳自己的 key
app/Http/Controllers/Admin/AccountController.php
routes/web.php
resources/views/admin/accounts/index.blade.php      操作欄加按鈕 + modal
resources/views/admin/accounts/partials/row-actions.blade.php
public/js/accounts.js
resources/lang/{tw,cn,en}/account.php               四種通知的標籤
```

## 相關

- [[shift-daily-notice]]、[[task-daily-notice]]、[[remind-escalation]]、
  [[attendance-report-notice]] —— 四種通知本身
- [[rbac]] —— 權限 keyword
