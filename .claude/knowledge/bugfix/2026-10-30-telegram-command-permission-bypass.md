# 小幫手所有指令的權限檢查被繞過

> 在做「系統規章」時要驗下指令的人有沒有權限，照抄既有指令的寫法，
> 順手確認 `findByTelegramUserId()` 回的 Model 有哪些欄位 —— 才發現這件事。

## 現象

**任何綁定過 Telegram 的帳號，都能叫動每一支小幫手指令**，
包括會發訊息給客戶的那兩支（群發公告、餘點告警）。

實測（修之前）：

```
取到的帳號：localCS01（level=4，客服）
level 這個屬性：NULL
isAdmin() 回：true
hasPermission('telegram_chat.__not_exist__') 回：true   ← 連不存在的 keyword 都過
```

## 根因：`null == 0` 是 true，而管理者的 level 就是 0

三件事疊在一起：

1. `config('constants.USER.LEVEL.ADMIN')` 是 **0**
2. `User::isAdmin()` 寫的是 `$this->level == 0`（**鬆散比較**）
3. `UserRepository::findByTelegramUserId()` **沒有 select `level`**

→ `$user->level` 是 `null` → `null == 0` 成立 → `isAdmin()` 回 true
→ `hasPermission()` 第一行就 `return true` → **整個權限檢查被跳過**

所有小幫手指令都走同一支 `findByTelegramUserId()`，所以**八支全中**。

## ⚠ 為什麼一直沒被發現

因為它的失效方向是「放行」。被擋下來會有人回報；被放行不會 ——
功能看起來一切正常，只是誰都能用。

而且 `hasPermission()` 回 true 這件事，從外面看跟「這個人真的有權限」
一模一樣。

## 修兩層

### 1. `findByTelegramUserId()` 補 select `level`

直接原因。這一欄本來就該撈 —— 呼叫端第一件事就是驗權限。

### 2. `isAdmin()` 在 `level` 沒值時一律回 false

防線。欄位沒撈出來不該等於「他是管理者」。

```php
if (blank($this->level)) {
    return false;
}

return (int) $this->level === (int) config('constants.USER.LEVEL.ADMIN');
```

⚠ **不能只改成 `(int) $this->level === 0`** —— `(int) null` 也是 0，
一樣擋不住。要先確認那一欄真的有值。

⚠ `blank(0)` 是 **false**（Laravel 對數字一律不算空），所以管理者的
level 0 過得了這一關、null 過不了 —— 正是要的行為。

⚠ **寧可錯殺不可錯放**：真的管理者被擋下來會立刻有人回報，
非管理者被放行則是安靜出事。

## ⚠ 一般化：鬆散比較 ＋ 「0 是有意義的值」＝ 遲早出事

`level` 的管理者是 0、`status` 的停用是 0、`action_type` 沒有 0 ——
這種「0 有意義」的欄位碰上 `==`，只要值沒撈出來就會悄悄命中。

同一類的坑這個專案已經踩過第四次了（見 [[2026-10-12-filled-on-boolean]]：
`filled(false)` 是 true）。判斷「這一欄到底有沒有值」要用 `blank()`／`filled()`，
不要靠比較運算子順便判。

## 影響範圍

| 指令 | 權限 | 會不會動到客戶 |
|---|---|---|
| 群發公告 | `telegram_chat.broadcast` | **會，發到客戶群** |
| 餘點告警 | `telegram_chat.credit_alert` | **會，發到客戶群** |
| 查餘點 | `telegram_chat.station_credit` | 不會（但會打主系統 API）|
| 查虛擬機／卡片／匯率 | 各自的 keyword | 不會 |
| Gmail 憑證／spider_setting | 各自的 keyword | 不會（但會把金鑰印在群組）|

⚠ 另外注意：公告指令的【確認預約】按鈕**本來就沒驗權限**
（見 [[scheduled-broadcast-command]]），那是另一個還沒處理的問題。

## 新增/修改的檔案

| 檔案 | 說明 |
|---|---|
| `app/Models/User.php` | `isAdmin()` 在 `level` 沒值時回 false |
| `app/Repositories/UserRepository.php` | `findByTelegramUserId()` 補 select `level` |

## 驗證

實跑三種情況（本機 DB，唯讀）：

| 情況 | `isAdmin()` | `hasPermission('不存在的')` |
|---|---|---|
| 沒 select level 的非管理者 | false | **false**（修前是 true）|
| 真管理者（level=0）| true | true |
| 非管理者（level=4）| false | false |
