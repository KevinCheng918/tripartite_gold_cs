# 驗證碼綁定 Telegram（取代「填帳號就自動綁」）

> **狀態：已實作（2026-10-09）。**

## 為什麼要改

現在的綁定只看一個條件：**私訊 bot 的人，他的 Telegram username 等於後台
`user.telegram_username` 欄位的值**。對上就綁，沒有任何其他驗證。

```php
// 改之前
$user = $this->userRepository->findByTelegramUsername($username);
// 對到就直接 markDmReady()
```

三種不需要攻擊、只要「剛好」就成立的出事情境：

1. 後台**打錯一個字**，而那個 handle 剛好有人在用
2. 同仁**改過 Telegram 帳號**，舊 handle 被別人註冊走（Telegram 會釋出）
3. 有人**離職**沒清資料，而他的帳號還在

出事的後果是陌生人開始收到班表（誰上哪一班）、任務卡（內部專案與進度）、
超時統計。

## ⚠ 順帶修掉的「多重綁定」

`telegram_user_id` 只有 index、**沒有 unique**，`markDmReady()` 也不檢查這個
Telegram 身份是不是已經綁在別的帳號上。所以：

> A 帳號填了某 handle 並綁好 → 把同一個 handle 改填到 B 帳號、A 的沒清掉
> → B 私訊後也綁上同一個 Telegram 身份
> → **那個人每天收到兩份班表、兩份任務卡**

反過來（一個後台帳號綁多個 Telegram 身份）不會發生 —— 欄位只有一個，
後綁的覆蓋前綁的。

新流程要在綁定時擋掉：**這個 Telegram 身份已經綁在別的帳號上就拒絕**，
並明講是哪個帳號，而不是靜默搬走別人的綁定。

## 已確認的決策（2026-10-09）

| 題目 | 決定 |
|---|---|
| 驗證碼在哪產生 | **兩邊都有** —— 「我的帳號」自助，帳號管理也能代為產生 |
| 舊的 username 自動綁定 | **移除**，一律走驗證碼 |
| 誰能解綁 | **只有管理者**（帳號管理頁），同仁不能解自己的 |
| 陌生人私訊 | **只回第一次，之後靜默** |

## 設計

### 綁定流程

```
後台按「產生綁定碼」
  └─ 產生 6 碼英數，存 cache（10 分鐘）
       code  → user_id
       user_id → code   （兩個方向都要，重新整理時要看得到同一組碼）

同仁私訊 bot 輸入那串碼
  └─ 查得到 → 檢查這個 Telegram 身份有沒有綁在別人身上
       ├─ 有 → 拒絕並說明是哪個帳號
       └─ 沒有 → 寫入 telegram_user_id + telegram_dm_ready
                 **順便回填 telegram_username**（從 webhook 的 from.username）
  └─ 查不到 → 當成一般陌生訊息處理
```

⚠ **驗證碼存 cache 不存 DB**：10 分鐘就過期的東西不該長住資料表，
也不必為它跑一支 migration。代價是清快取時碼會失效 —— 重新產生即可。

⚠ **綁定時順便回填 `telegram_username`**，所以**管理者不必再手動輸入那個欄位**。
它仍然有用（求助單提醒要在群組 `@` 他），只是不再是綁定的依據。

### 陌生人只回一次

```
陌生訊息進來
  └─ cache 有「這個 telegram_user_id 回過了」→ 靜默
  └─ 沒有 → 回一句，並記下 24 小時
```

⚠ 記的是 **Telegram 的 user id** 而不是 chat id —— 兩者在私訊裡相同，
但用 user id 語意才對（「這個人」回過了）。

⚠ 回覆內容要含蓄：原本那句告訴陌生人「這裡有後台、有『我的帳號』頁面」，
對想摸清楚系統的人是免費情報。

### 解綁

帳號管理的「TG 綁定」欄位旁邊加按鈕（只有已綁定時出現），
權限沿用 `account.update`。解綁 = 清掉 `telegram_user_id` 與 `telegram_dm_ready`。

⚠ **不清 `telegram_username`**：那是 `@` 他用的，跟能不能私訊是兩件事。

⚠ 同仁**不能解自己的**（需求方指定）—— 自己解掉之後收不到通知卻不自知，
而那些通知（班表、任務卡）是工作要用的。

## 會動到的檔案

| 檔案 | 內容 |
|---|---|
| `app/Services/TelegramBindService.php`（新增） | 產碼、驗碼、解綁、陌生人冷卻 |
| `app/Services/TelegramChatService.php` | `bindPrivateChat()` 改走驗證碼 |
| `app/Repositories/UserRepository.php` | `findByTelegramUserId()`、`clearDmBinding()`；移除 username 綁定的用法 |
| `app/Http/Controllers/Admin/AccountController.php` | 產碼、解綁兩支 ajax |
| `routes/web.php` | 兩條 ajax |
| `resources/views/admin/accounts/index.blade.php` | 綁定欄位加按鈕 |
| `resources/views/layouts/app.blade.php` | 「我的帳號」側邊欄加產碼按鈕 |
| `config/constants.php` | 碼長度、效期、陌生人冷卻、話術 |
| `resources/lang/{tw,cn,en}/account.php` | 語系 |

## ⚠ 實作上的幾個判斷

**碼的字元集排除 `0/O/1/I/l`** —— 這串要用看的念給別人或自己打進 Telegram，
那幾個字分不出來。

**同一個人重複按只拿到同一組**（效期內）。每按一次就換的話，他把碼念到一半
又重新整理，手上那組就失效了。

**長度對但查不到 → 回「碼過期了」而不是陌生人回覆。** 剛好打了六個字的人極少，
他顯然是在試綁定，回「請重新產生」比回「這裡沒有服務」有用得多。

**產碼的 route 不掛 `can:`** —— 同仁要能產自己的碼。「幫別人產」的權限檢查
在 Controller 裡，因為同一支要服務兩種情況（自助與代為產生）。
少了那道檢查，任何登入的人都能產出別人的碼、拿去綁走對方的通知。

**`findByTelegramUsername()` 保留但不再用於綁定** —— 它剩下的用途是「認出
群組裡發言的是哪位同仁」（匯率決定者、不自動回覆名單）。那些只是辨識，
認錯的後果是記錯名字，不像綁定會把內部通知送給外人。

**`markDmReady()` 移除**（改用 `bindTelegram()`，後者會順便回填 username）。

## ⚠ 還沒做：`telegram_user_id` 的 unique 約束

程式層已經擋住多重綁定（綁定前先查），但資料庫層仍然只有 index。
要徹底保證得加 unique index —— 那需要一支 migration，而且**跑之前要先確認
正式機沒有既有的重複資料**，否則 migration 會失敗。

## 相關

- [[shift-daily-notice]] — `telegram_dm_ready` 的用途與由來
- [[telegram-chat]] — webhook 收訊的入口
- [[rbac]] — `account.update` 權限
