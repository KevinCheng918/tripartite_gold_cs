# 後台清除與客服的 Telegram 私訊紀錄

> 需求方 2026-11-09：「我想要能夠在後台刪除機器人跟客服帳號的私訊的對話紀錄，
> 然後也要刪除撥電話的 user bot 跟客服的對話記錄」

**狀態：Bot ↔ 客服那一半已完成（v3.85）；userbot ↔ 客服那一半未做。** 下面先把可行性與威脅模型講清楚 —— 其中一項的結論
可能會讓需求方改變決定，所以先確認再動工。

> ### ⚠ 需求釐清（2026-11-09）
>
> 需求方補充：「我要做的不是客人的，我要做的是我的機器人跟客服人員的私訊的」。
>
> 也就是**第一次評估建議略過的那一半（Bot ↔ 客服）才是主目標**。
> 已向需求方說明「48 小時以前的刪不掉」這個改不掉的限制，需求方確認照做
> ⇒ **做到最大可行範圍，並在介面上誠實顯示刪不掉的部分**。
>
> ⚠ 下面第二節（威脅模型）的結論**不再是「所以不要做」**，而是降級為
> 「要知道這個功能防的是什麼」：它防的是**同仁的手機／帳號被看到**，
> 不是防 bot token 洩漏（拿 token 讀不到歷史）。這個區別會影響文案怎麼寫
> —— 不要讓人以為清完就「安全了」。

---

## ⚠⚠ 一、兩半的可行性完全不同

| | Bot ↔ 客服（Bot API） | **Userbot ↔ 客服（MTProto）** |
|---|---|---|
| 有「清空對話」的 API 嗎 | ❌ **沒有**。只有逐則 `deleteMessage` | ✅ `messages.deleteHistory` 整段清 |
| 時間限制 | ⚠ **只有 48 小時內的刪得掉** | ✅ 私訊無時間限制 |
| 需要事先記下 message_id 嗎 | ⚠ **要，而我們一筆都沒記** | ❌ 不用，整段清 |
| 刪得掉對方那一側嗎 | ✅ 私訊中 bot 可刪雙方訊息 | ✅ `revoke=True` |
| **結論** | **「清掉過去的私訊」做不到** | **做得到，而且乾淨** |

### Bot 側為什麼做不到「清掉過去的」

兩個獨立的原因，任一個都足以擋死：

1. **我們沒有任何 message_id**。`StaffDmService::send()` 拿到 API 回傳之後
   只看 `result` 有沒有值就 `return true`，**message_id 直接丟掉**
   （`app/Services/StaffDmService.php:195`）。而唯一存 `telegram_message_id`
   的 `telegram_message` 表，它的 `telegram_group_id` 是**必填外鍵** ⇒
   那張表只存群組訊息，私訊一筆都沒有。
2. **Bot API 沒有「清空聊天室」的方法**。只有 `deleteMessage`／`deleteMessages`
   逐則刪，而且**送出超過 48 小時就刪不掉**。

⇒ Bot 側能做的最多是：**從現在開始記 message_id，然後清 48 小時內的那些**。
歷史訊息永久留在同仁的 Telegram 裡，後台做什麼都刪不掉。

---

## ⚠⚠ 二、威脅模型：這一半的風險比想像中小，另一半比想像中大

這是整份文件最重要的一段，**它可能讓 Bot 那一半不值得做**。

### 拿到 bot token 的人，讀不到任何歷史私訊

Bot API **沒有「讀取過去訊息」的能力** —— bot 只能透過 webhook／`getUpdates`
收**新進來**的 update，無法回頭查任何一則舊訊息。

所以「機器人跟客服的私訊」這段歷史，**攻擊者拿到 token 是看不到的**。
唯一看得到的人是：

- 同仁自己（他手機裡那段對話）
- 拿到**同仁手機或他 Telegram 帳號**的人

⇒ 要防的其實是「同仁的手機被看到」，而那件事**後台清不乾淨**
（48 小時以前的都還在）。

### userbot 的 session 檔才是真的大洞

session 檔**等同那個帳號的密碼**。拿到它的人不是只看到我們這幾段對話，
而是：整個帳號的**所有**歷史訊息、所有加入的群組（含內部支援群組）、
聯絡人、以及用那個身分繼續發訊息的能力。

⇒ 需求方的直覺（清 userbot 那邊）**正好指向風險最高、也最清得乾淨的那一半**。

> ⚠ 但要認清比例：清掉 userbot↔客服那幾則「未接來電」，對一個已經拿到
> session 檔的攻擊者來說幾乎沒差 —— 他要的東西在別處。真正的對策是
> **session 檔的保管與輪替**，清對話是補強，不是主防線。

### userbot↔客服那段對話裡其實有什麼

只有**通話的服務訊息**（未接來電／已取消通話）—— 我們從來沒用 userbot 發過
一則文字。所以洩漏的是 metadata：**我們撥給了哪些人、什麼時候撥**。
不是客戶資料，但會曝光客服名單與這個帳號的用途。

---

## 三、⚠ 功能衝突：清掉私訊 = 清掉逾時升級的通知本身

那則私訊就是「告訴同仁哪一張單逾時了」的唯一載體（電話只會響、沒有內容）。

⇒ **不能做成自動定期清**。同仁還沒讀就被清掉的話，那張單的提醒等於消失，
而且不會有任何錯誤訊息 —— 跟之前「升級門檻 ≥ 提醒上限」同一種靜默壞法。

若將來真要自動清，必須**排除最近 N 分鐘的訊息**，而且預設關。

---

## 四、⚠ 「刪痕跡」的按鈕本身就是風險

一個能清掉自己通知紀錄的按鈕，正是內部人員要掩蓋「我沒處理」時會用的東西。

所以這個功能必須：

- **獨立權限 keyword**（不要掛在 `notification.manage` 上 —— 那個權限給得比較寬）
- **寫稽核紀錄**：誰、什麼時候、清了誰、清掉幾則
- ⚠ **那份稽核紀錄不能被這個按鈕清掉**（否則整個稽核沒有意義）
- 操作前**二次確認 modal**（這個專案不用 alert，見既有慣例）

---

## 五、建議的範圍（待確認）

### 方案 A：只做 userbot 那一半（推薦）

做得到、乾淨、而且對應到真正的風險。

- 新增 sidecar `tools/tg_wipe.py`：`delete_dialog(user, revoke=True)`
- 後台選一位或全部同仁 → 整段清掉（含未接來電紀錄）
- 不碰 Bot 側，介面上**明講為什麼不做**（避免以為漏了）

### 方案 B：A ＋ Bot 側「從現在開始可清」

多出來的工：新表存 message_id、改 `StaffDmService::send()`、48 小時的過期處理。

⚠ 代價是**介面要誠實顯示「N 則已超過 48 小時，Telegram 不允許刪除」** ——
不顯示的話，使用者會以為清乾淨了，那比沒這個功能更危險。

### 方案 C：改成處理根本問題（可與 A 併行）

- **session 檔輪替**：`telegram:call-login` 重跑 ＋ 舊 session 失效（Telegram 可以踢掉其他 session）
- 私訊內容**減量**：現在私訊裡帶了群組名稱與題目前 80 字。若改成只帶單號與
  「請到後台處理」，那段對話就幾乎沒有東西可洩漏 —— **比事後清更有效**

---

## 六、預計異動檔案（方案 A，估算）

### 新增

- `tools/tg_wipe.py` — Telethon sidecar，一行 JSON 輸出（比照 `tg_call.py`）
- `database/migrations/*_create_telegram_wipe_log_table.php` — 稽核紀錄（比照 `login_log` 的結構）
- `app/Models/TelegramWipeLog.php`
- `app/Repositories/TelegramWipeLogRepository.php`
- `app/Services/TelegramWipeService.php` — 包 sidecar ＋ 寫稽核
- `app/Http/Requests/Telegram/WipeDmRequest.php`
- 語系 key（tw → cn → en）

### 修改

- `config/permissionMap.php` — 新 keyword（例如 `telegram_chat.dm_wipe`）
- `routes/web.php` — `ajax-wipe-dm`
- 後台頁面（放哪裡待確認，見下）＋ 對應 JS
- `config/changelog.php`

---

## 七、待釐清（需求方決定）

1. **Bot 那一半還要做嗎？** 知道了「拿 token 讀不到歷史」＋「只能清 48 小時內」
   之後，可能不值得。（方案 A vs B）
2. **範圍**：一次清一位，還是「全部同仁一鍵清」？後者威力大、也更危險。
3. **`revoke`（兩邊都刪）還是只刪 userbot 這一側？** 兩邊都刪比較徹底，
   但同仁那邊的未接來電也會消失。
4. **放在哪個後台頁面？** 候選：帳號管理（逐人操作最直覺）、
   通知設定（跟撥號設定在一起）、或獨立一頁。
5. **要不要連「通話紀錄」那個分頁一起清？** ⚠ 待技術確認（見下），
   而且那個 API 是**全帳號的，不能只刪某一個人**。

---

## 八、⚠ 待技術確認（本機容器沒裝 telethon，無法驗）

telethon 只裝在 UAT 的 `/home/lv_cs_uat/venv-tg/bin/python`，本機 laradock
沒有。下面兩點**我是憑 MTProto schema 的理解寫的，還沒實機驗過**：

- `messages.deleteHistory(peer, max_id=0, revoke=True)` 對私訊是否真的無時間限制
- `messages.deletePhoneCallHistory` 這個方法在裝的那個版本裡存不存在
  （它是**全帳號**的通話紀錄，沒有 peer 參數）

請在 UAT 跑這一行確認：

```bash
/home/lv_cs_uat/venv-tg/bin/python -c "
import telethon, inspect
print('telethon', telethon.__version__)
from telethon.tl.functions.messages import DeleteHistoryRequest as H
print('deleteHistory:', [p for p in inspect.signature(H.__init__).parameters][1:])
try:
    from telethon.tl.functions.messages import DeletePhoneCallHistoryRequest as C
    print('deletePhoneCallHistory:', [p for p in inspect.signature(C.__init__).parameters][1:])
except ImportError:
    print('deletePhoneCallHistory: 這個版本沒有')
"
```

⚠ **不要在 prod 的 userbot 上試刪**。要驗刪除行為的話，拿一個測試帳號
跟 userbot 互發幾則再刪，確認兩邊都消失。

---

## 相關

- [features/ticket-overdue-escalation.md](ticket-overdue-escalation.md) — 私訊與撥號的來源，含 session 檔保管的規則

---

## 九、Bot ↔ 客服：確定要做之後的設計

### ✅ 好消息：私訊只有一個出口

`StaffDmService::send()` 是**唯一**的同仁私訊出口 —— 15 個服務／指令全走它，
而且**沒有任何地方繞過它**直接拿 `telegram_user_id` 去 `sendMessage()`（已 grep 確認）。

⇒ **一個掛鉤就涵蓋全部私訊**，不必逐個服務去改。

這也讓範圍比直覺大。那個 bot 發給客服的私訊包含：

逾時升級、班表通知、打卡提醒、打卡報表、任務卡、任務變更、審核、
財務、錢包、待追蹤、超時提醒統計、登入通知

### 做得到的範圍

| | 做得到 | 做不到 |
|---|---|---|
| 從現在開始發出的私訊 | ✅ 48 小時內可刪（雙方都刪掉） | 超過 48 小時就永久留著 |
| 功能上線**之前**發的所有私訊 | ❌ **一則都刪不掉**（沒有 message_id） | — |

⚠⚠ **介面必須誠實顯示刪不掉的數量**。讓人以為清乾淨了，比沒有這個功能
更危險 —— 他會因此不去做真正有效的事（換手機、登出帳號、改私訊內容）。

### ⚠ 48 小時的窗口決定了「手動按鈕」不夠用

這點是設計上最關鍵的推論：

> 窗口只有 48 小時 ⇒ **人工按鈕必須每天記得按**，否則訊息就過期變成永久留存。
> 而「要記得每天按」的安全措施，實務上等於沒有。

⇒ 真正能達成「同仁的 Telegram 裡不留內部資訊」的是**自動清除**
（排程每 N 分鐘清掉超過 X 小時的私訊，X < 48）。手動按鈕留著當立即處置用
（例如某人離職、手機遺失）。

⚠ 但自動清除會刪掉**還沒讀的通知**（見第三節的衝突）。所以：

- X 要可設定、**預設給夠長**（例如 12 小時）
- **預設關**，由需求方確認後再開
- 逾時升級那種「叫人起來處理」的私訊，刪掉等於提醒消失 ——
  若要更保險，可以排除「關聯求助單還沒結案」的那幾則（較複雜，先不做）

### 資料表 `staff_dm_message`

只為了「能刪」而存在，不是訊息歸檔 —— **不存內容**。

| 欄位 | 用途 |
|---|---|
| `user_id` | 哪位同仁（FK `user`） |
| `telegram_chat_id` | 刪除時要帶（＝他的 `telegram_user_id`，但要存當下的值，之後改了也刪得掉） |
| `telegram_message_id` | 刪除的目標 |
| `notice_key` | 哪一種通知（給介面分類、也給未來的排除規則用） |
| `created_at` | 判斷 48 小時 |
| `deleted_at` 或 `wiped_at` | 已經刪掉的就不要再試 |

⚠ **不存訊息內容**：存了就等於把要防的東西搬進自己的 DB，反而擴大曝光面。

⚠ **記錄失敗不能影響發送**：`send()` 裡要 try/catch 包起來並記 log。
私訊本身是通知的載體，不能為了「記得住以便將來刪」而讓通知發不出去。

⚠ 批量發送（報表類一次發 20 人）會變成 20 筆 insert。先接受，
量大再改批次寫入。

### 稽核紀錄 `staff_dm_wipe_log`

誰、什麼時候、清了誰、成功幾則、失敗幾則、超過 48 小時幾則。

⚠ **這張表不能被清除功能自己清掉** —— 否則稽核沒有意義（見第四節）。

### 權限

新 keyword，**不要掛在 `notification.manage`**（那個權限給得比較寬）。
候選：`telegram_chat.dm_wipe`。

### Bot API 的用法

- 單則：`deleteMessage(chat_id, message_id)`
- 批次：`deleteMessages(chat_id, message_ids[])` —— 一次最多 100 則（Bot API 7.0+）
- ⚠ `TelegramBotService` 目前**沒有任何 delete 方法**，要新增

⚠ 已經被同仁自己刪掉的訊息，再刪會回錯誤 —— 要當成「已達成目的」處理，
不要算進失敗數（否則介面天天顯示失敗）。

---

## 十、實作（2026-11-09，v3.85）

需求方三個決定：**全部私訊**、**手動 ＋ 自動清除（自動預設關）**、
**逐位同仁**（不做「全部一鍵清」—— 炸裂範圍小很多）。

### ✅ 最大的好消息：私訊只有一個出口

`StaffDmService::send()` 是**唯一**出口，15 個服務／指令全走它，
grep 確認**沒有任何地方繞過它**直接 `sendMessage($user->telegram_user_id, ...)`。

⇒ 一個掛鉤（`recordForWipe()`）就涵蓋全部私訊。

### 關鍵取捨：`GONE` 這個狀態不需要存在

原本設計了四種結果（已刪／過期／對方自己刪了／失敗）。查清楚之後砍成兩種：

- Bot API 的 `deleteMessages`（複數）對刪不掉的訊息是**靜默跳過、整批仍回傳
  成功** ⇒ 拿不到逐則結果，分不出「我刪的」與「他早就自己刪的」。
  而那兩者的結果完全一樣（不在他的 Telegram 裡了），**分它沒有操作意義**。
- 48 小時那條線**本地就算得出來**（看 `created_at`），不需要 Telegram 告訴我們
  ⇒ 過期的根本不送出去，在 DB 端就標成 `EXPIRED`。

剩下 `CLEARED` 與 `EXPIRED` 兩種。**失敗刻意不標記**（維持 null）：
留著下一輪會再試，等它過了 48 小時自然被掃成 `EXPIRED` ——
標成失敗會讓一次暫時性的網路錯誤變成永久放棄。

### ⚠⚠ 實作時自己抓到的四個真問題

| 問題 | 為什麼嚴重 | 修法 |
|---|---|---|
| **稽核列用了全表的 expired 數字** | `markExpiredBefore()` 掃的是全表，把它的回傳值塞進某一位同仁的稽核列，會記出一個看起來很嚴重、卻跟那個人無關的數字 —— 稽核紀錄失去可信度 | 改成逐人從 `countByUser()` 算 |
| **`bindDmWipe()` 被包在 `if (canManage)` 裡** | 私訊清除是**獨立權限**。「有清除權限、沒有改通知設定權限」的人會看到分頁、按鈕也沒鎖，但**一個事件都沒綁**，按下去完全沒反應 | 移出該區塊（它自己用「表單在不在」判斷） |
| **`applyPermission()` 會把清除分頁一起鎖掉** | 同上一條的另一半：沒有 `notification.manage` 的人即使有清除權限也全部 disabled | 加 `el.closest('#tab-dm-wipe')` 跳過 |
| **`candidates()` 的快取寫成呼叫自己** | 全域替換時把 `candidates()` 內部那一行也換掉 ⇒ **無限遞迴** | 改回 `$this->userRepository->getDmWipeCandidates()` |

> ⚠ 另外在 `config/constants.php` **第二次**切壞別人的註解區塊（`NOTICE` 的
> docblock 開頭 `/*` 失去配對）、在 `UserRepository` 切掉 `getCallCandidates()`
> 的 docblock。**往既有檔案插入區塊前，先看清楚插入點上下的註解邊界** ——
> 這是這兩天第三次。

### 刻意的設計

- **不存訊息內容**（只存 message_id）：這個功能要防的就是「內部資訊留在同仁
  的 Telegram」—— 把內容抄進自己的 DB 等於把要防的東西搬到另一個地方
- **記錄不受開關控制**（一律記）：「開了開關才開始記」會讓人按下去發現一則
  都清不掉。代價是打開自動清除時有一批舊的可清 —— 設定頁明講了
- **記錄失敗不影響發送**（try/catch 包起來）：私訊本身是通知的載體，
  不能為了「將來刪得掉」而讓通知發不出去
- **`user_id` 用 `nullOnDelete` 不是 `cascadeOnDelete`**：同仁離職、帳號刪掉之後
  那些私訊**還在他手機裡**，正是最需要清的時候。留著 `telegram_chat_id`
  就還刪得掉，cascade 會把這個能力一起刪掉
- **手動清除不套用保留時數**：按這個按鈕的情境是「離職」「手機不見了」，
  那時候「保留最近 12 小時」完全是反效果
- **`MAX_KEEP_HOURS = 47`**：填 48 以上的話排程撈出來的訊息永遠都已經過期 ——
  **自動清除看起來開著、卻一則都清不掉而且不報錯**。Request 擋住
- **排程每 10 分鐘而非每分鐘**：保留時數以小時計，每分鐘跑只是重複掃同一批
- **稽核 Repository 刻意沒有任何 delete 方法**：留一個在那裡，遲早有人
  （或某個 TTL 排程）順手用掉，而可以被清掉的稽核等於沒有稽核

### 驗證（51 項全過，唯讀）

Repository／Bot／AppSetting 全部換成假的綁進容器，不碰 DB、不呼叫 Telegram。
重點涵蓋：手動不套保留時數、自動要保留最近 N 小時、開關關著時**完全不碰 DB**、
失敗不標記結果、稽核的 expired 是個人的不是全表的、結果訊息一定提到
「還有幾則永久刪不掉」、設定頁名單不外洩 telegram 欄位、
`MAX_KEEP_HOURS < DELETE_WINDOW_HOURS`、稽核 Repository 沒有刪除方法。

### ⚠ 上線要做的事

1. **跑 migration**（兩張表）：
   `php artisan migrate --path=database/migrations/2026_11_10_000001_create_staff_dm_message_table.php`
   以及 `..._000002_create_staff_dm_wipe_log_table.php`
2. **指派權限** `telegram_chat.dm_wipe` 給該有的人（預設沒人有）
3. `php artisan optimize`（改了 routes 與 config）
4. 自動清除要用的話，到設定頁打開並確認保留時數
   —— ⚠ **第一次執行就會清掉現有的那些**
5. ⚠ **功能上線之前發出的私訊一則都刪不掉**（沒有 message_id）。
   那些要清只能請同仁自己刪除與機器人的對話

### 檔案清單

**新增**

- `database/migrations/2026_11_10_000001_create_staff_dm_message_table.php`
- `database/migrations/2026_11_10_000002_create_staff_dm_wipe_log_table.php`
- `app/Models/StaffDmMessage.php`、`app/Models/StaffDmWipeLog.php`
- `app/Repositories/StaffDmMessageRepository.php`、`app/Repositories/StaffDmWipeLogRepository.php`
- `app/Services/StaffDmWipeService.php`
- `app/Console/Commands/WipeStaffDmCommand.php`
- `app/Http/Requests/Notification/WipeStaffDmRequest.php`
- `app/Http/Requests/Notification/UpdateStaffDmWipeRequest.php`

**修改**

- `app/Services/StaffDmService.php` — `recordForWipe()`（唯一的掛鉤點）
- `app/Services/TelegramBotService.php` — `deleteMessages()`、`errorDescription()`
- `app/Services/AppSettingService.php` — 兩個設定 key
- `app/Repositories/UserRepository.php` — `getDmWipeCandidates()`（不排除任何身份，理由見該處）
- `app/Http/Controllers/Admin/NotificationController.php` — `ajaxWipeDm()`、`ajaxUpdateDmWipe()`、`index()` 依權限給資料
- `app/Console/Kernel.php` — `staff-dm:wipe` 排程
- `routes/web.php`、`config/permissionMap.php`、`config/constants.php`、`config/changelog.php`
- `resources/lang/{tw,cn,en}/notification.php`、`.../permission.php`
- `resources/views/admin/notification/index.blade.php` — 第十一個分頁 ＋ 二次確認 modal
- `public/js/notification-setting.js` — `renderDmWipe()`／`bindDmWipe()`／`applyPermission()` 的例外

### 收尾稽核（2026-11-09，v3.85）

逐條對 PROMPTS.md 重掃 v3.85（1810 行新增），抓到兩處違反：

| 違反 | 問題 | 修法 |
|---|---|---|
| **第 5 節 DB Transaction**（「多表寫入必須在交易中」） | `wipe()` 寫**兩張表** —— 標記清除結果（`staff_dm_message`）與稽核紀錄（`staff_dm_wipe_log`），卻沒有交易。⚠⚠ **稽核寫失敗而訊息已標成已清除 ⇒ 清掉了但沒有紀錄**，對一個以「每次清除都留痕」為前提的安全功能，那正好打掉它的根據 | 兩者包進 `DB::transaction`。⚠ **Telegram 的呼叫刻意留在交易外**（跨網路，包進去會讓連線在整串 HTTP 往返期間持有鎖）。順帶好處：標記從「每 100 則一次 UPDATE」變成一次批量 |
| **第 1 節 Request**（「補 `config/rules.php`」） | 保留時數的上下限只寫在 `constants.php`。而最接近的類比 `UpdateAutoReplyResumeRequest` 正是從 `config/rules.php` 取邊界、並在兩邊互相交叉註解 | 加 `rules.STAFF_DM_WIPE_MIN/MAX_KEEP_HOURS`，Request 改讀它，兩個 config 互相註明「改這裡要一起改」 |

#### ⚠ 交易帶來的一個已知邊角（可自癒）

稽核寫入失敗時交易回滾，但**Telegram 那邊已經刪掉了**（既成事實，不可能回滾）。
結果是：使用者看到「清除私訊失敗」、訊息其實已經不見、而且沒有稽核。

**不是永久狀態**：那些列維持 `result = null` ⇒ 下一輪（排程或再按一次按鈕）
會重送，Telegram 對已刪除的靜默跳過，然後標記成功並**補上稽核**。
所以最終一致，只有那一瞬間的訊息會誤導。

順手確認過但**刻意不改**：

- **`Criteria` 封裝**：`app/Criteria/` 只給「複雜且可重用」的條件用
  （`UserRepository` 有 35 處手寫 where），固定條件照慣例手寫
- **Response 資源檔**：兩支 ajax 只回一句 `message`，同這一頁既有 20 支
- **`webpack.mix.js`**：`public/js/notification-setting.js` 是直接載入、不經編譯
- **`markExpiredBefore()` 不進交易**：單表、冪等的維護動作，
  跟「這次誰清了誰」不是同一件事

> **版本號**：**沒有加新版號**。兩處都是內部正確性修正，使用者看到的行為
> 一字未變 —— 而 v3.85 的版本紀錄已經寫了「每一次清除都會留下紀錄」，
> 交易正是讓那句話成立的東西，不是新功能。
