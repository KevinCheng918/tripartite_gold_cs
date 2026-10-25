# 有人申請／有人審核就私訊通知主管

> 需求（2026-10-26）：
> - 「我要發送一個通知給主管以上，當有人申請請假、申請補打卡、申請換班、
>   申請代班的話，要通知，用私人通知就可以了」
> - 「上述這些有人審核時，無論通過或拒絕，也都要發送私人通知給主管及主管以上」
> - 「換班如果不用主管審核，那就不用通知了」
> - 「不要用職級篩選好了，一樣用通知設定頁的名單」

## 通知的時機

| 流程 | 申請時 | 中間 | 審核後 |
|---|---|---|---|
| 請假 | ✅ | — | ✅ 通過／拒絕 |
| 補打卡 | ✅ | — | ✅ 通過／拒絕 |
| 代班 | ✅ | ✅ 代班人同意／拒絕 | ✅ 核准／駁回 |
| **換班** | ❌ | ❌ | ❌ |

### ⚠ 換班為什麼不通知

需求方一開始把換班列進來，看到「換班沒有主管審核那一關」之後自己剔除了：
被換的同仁答應，`executeSwap()` 就**直接互換 user_id 生效**，主管收到也不能
做什麼。

**判準是「主管要不要處理」，不是「有沒有人提出申請」。**
通知設定頁上也寫了這一句 —— 不寫的話會被當成漏做。

### ⚠ 代班為什麼有三則

代班是兩段式：`cover_user_status`（代班人回應）→ `admin_status`（主管審核），
而且 `respondByAdmin()` 會擋住「代班人還沒同意」的單。

所以**代班人同意那一則就是「輪到您審了」的信號** —— 少了它，主管收到申請通知
之後就是一片寂靜，不知道什麼時候該去按。

⚠ 代班人**拒絕也要發**：不然主管會一直等一筆已經死掉的申請。
同意與拒絕的下一步要分開寫（「輪到您審核」vs「這筆到此結束」），
只寫「已回應」等於要主管自己去查狀態。

## 收件人：通知設定頁的名單

需求方選的是名單制而不是職級篩（`level <= 2`）。

| | 名單制（採用） | 職級篩 |
|---|---|---|
| 新主管上任 | **要記得改名單** | 自動收到 |
| 想收報表但不審申請的人 | 可以只勾其中一邊 | 做不到 |
| 跟其他通知的一致性 | 一致（班表／任務／打卡報表都是名單） | 多一套規則 |

⚠ **新開一個 key**（`approval_notice.manager_user_ids`），不共用既有的報表名單：
那幾份是定時報表，這一份是「有人在等你審」—— 收件人往往不同，混在一起的話，
想收報表但不審申請的人就沒辦法只關掉其中一邊。

## ⚠ 通知失敗不能讓申請失敗

`ApprovalNoticeService::push()` 整段包 try/catch，而且呼叫端擺在
**`DB::transaction()` 外面**。

兩個理由：

1. 通知沒送到頂多主管晚點才看到；**申請被例外中斷的話，同仁會以為自己沒送出
   而重複送** —— 那個代價大得多
2. 擺在 transaction 裡面的話，通知失敗會把已經審好的結果 rollback
   （補打卡那一支還會連打卡紀錄一起退回）

## 訊息長怎樣

```
📝 請假申請
小明 申請請假
期間：2026-10-28 ~ 2026-10-29（整天）
原因：家裡有事
→ 請到「排班管理 → 請假申請」審核

📝 請假已通過
主管A 已通過 小明 的請假申請
期間：2026-10-28 14:00 ~ 18:00
備註：記得找人代班

🔁 代班人同意
小華 同意代 小明 的班
時間：2026-10-28 09:00 ~ 2026-10-28 18:00
→ 輪到您審核了（排班管理 → 代班申請）
```

⚠ **「通過／拒絕」的字眼各流程不同**：請假與補打卡是「已通過／已拒絕」、
代班人是「同意／拒絕」、主管審代班是「已核准／已駁回」——
照畫面上的用字，混用會讓人以為是不同的狀態。

⚠ 請假期間**整天與時段要分開講**：只寫日期的話，請半天的人會被當成整天不在。

⚠ 原因／備註沒填就**整行不印**（印「原因：（無）」只是佔位）。

## 新增/修改的檔案

| 檔案 | 說明 |
|---|---|
| `app/Services/ApprovalNoticeService.php` | **新增**。組內容、決定發給誰；送出走 `StaffDmService` |
| `config/constants.php` | 新增 `APPROVAL_NOTICE`（八種訊息的公版） |
| `app/Services/AppSettingService.php` | 新增 `KEY_APPROVAL_NOTICE_MANAGER` |
| `app/Services/LeaveRequestService.php` | `apply()`／`respond()` 各掛一則 |
| `app/Services/ClockAmendmentService.php` | `request()`／`respond()` 各掛一則 |
| `app/Services/ShiftCoverService.php` | `request()`／`respondByCoverUser()`／`respondByAdmin()` 各掛一則 |
| `app/Services/NotificationSettingService.php` | 設定頁讀寫 ＋ 測試發送 |
| `app/Http/Controllers/Admin/NotificationController.php` | `ajaxUpdateApproval()`／`ajaxTestApproval()` |
| `app/Http/Requests/Notification/UpdateApprovalNoticeRequest.php` | **新增**。⚠ `exists:user,id`（這個專案的表沒有複數 s） |
| `routes/web.php` | 兩支 ajax 路由 |
| `resources/views/admin/notification/index.blade.php` | 新分頁「申請審核」 |
| `public/js/notification-setting.js` | `renderApproval()`／`bindApproval()` |
| `resources/lang/{tw,cn,en}/notification.php` | 分頁與訊息三語系 |

## 驗證

八則訊息的內容逐一看過（請假整天／請假時段＋備註／補打卡沒填原因／補打卡拒絕／
代班申請／代班人同意／代班人拒絕／主管核准），用假的 Model 與 mock 的
`StaffDmService`，不碰 DB、不真的送出。

五支 Service 與設定服務都確認 DI 解析得開。

## 待釐清

- **沒有真的私訊出去過**：要有人實際申請一次才驗得到端到端。
- 收件人是名單制，所以**換主管要記得改名單** —— 目前沒有「新主管上任提醒」。
- 審核結果目前**只通知主管，不通知申請人**（需求方只提了主管）。
  申請人現在仍然要自己回後台看。
