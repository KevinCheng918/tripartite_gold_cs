# `filled()` 用在布林上：`filled(false)` 是 true

## 一句話

```php
filled(false);   // true  ← 不是 false
filled(true);    // true
blank(false);    // false
```

`blank()` 的實作對 `is_bool()` 與 `is_numeric()` **一律回 false**：

```php
if (is_numeric($value) || is_bool($value)) {
    return false;
}
```

它問的是「**這個變數有沒有值**」，不是「**這個值是不是真**」。
`false` 是一個明確的值，所以它「有值」。

## 踩到的三個地方

### 一、「我方承諾」從上線那天起一筆都沒開出來過（真的壞了）

`WaitDetector::source()`：

```php
// 修正前
return filled(Arr::get($context, 'is_auto'))
    ? null
    : (int) Arr::get($sources, 'STAFF');
```

`is_auto` 是真布林。本意是「自動回覆不算、客服手打的算我方承諾」，
實際是**兩種都回 null** —— 客服在對話視窗說「稍等，我確認一下」，
系統什麼都沒記。

⚠ **而且完全沒有跡象**：它只是安靜地不開單，不會報錯、不會留 log。
2026-10-12 是從一張 Telegram 截圖反推出來的（我方連說兩次「稍等」，
列表上卻一筆「我方承諾」都沒有）。

### 二、Claude CLI 成功的回應被當成失敗（極可能壞了）

`ClaudeCodeMatcher`：

```php
// 修正前
if (!$process->isSuccessful() || (is_array($envelope) && filled(Arr::get($envelope, 'is_error')))) {
```

CLI 成功時輸出 `"is_error": false` —— 被判成失敗，整則回應丟掉。

log 裡的形狀對得上：`Claude 生成文字回傳失敗 {"reason":""}`，
**錯誤訊息是空字串**（它本來就沒有錯）。10/10 有 16 筆、10/11 有 3 筆。

> ⚠ 不能 100% 斷言那幾筆全是誤判 —— CLI 真的非 0 結束而沒有訊息時，
> log 長得一樣。但這行判斷本身確實是錯的，修了之後語意才跟註解一致。

### 三、待追蹤事項的開關（還沒壞，但靠運氣）

`NotificationSettingService::updateFollowup()`：

```php
// 修正前
KEY_FOLLOWUP_ENABLED => filled(Arr::get($params, 'enabled')) ? '1' : '0',
```

目前前端送的是 `1` 或 **`null`**（`public/js/notification-setting.js`:549
寫成 `checked ? 1 : null`），`filled()` 剛好會對。
哪天有人改成送 `false` 或 `0`，這個開關就再也關不掉 —— 而且不會報錯。

## 修法

三處都換成 `(bool)` 轉型。

## 教訓

> ⚠️ **`filled()` / `blank()` 只能用在「可能沒填」的東西上**：字串、陣列、
> 關聯、nullable 欄位。**布林與數字一律不要用** —— 對它們來說
> `filled()` 永遠是 true（除非是 null），那個判斷等於沒寫。
>
> 判斷真假就用 `(bool)` 或直接 `if ($flag)`。
>
> ⚠️ 這個錯誤的可怕之處是**它不會壞得很明顯**：三處裡有兩處是「安靜地
> 走錯分支」，一處是「現在剛好對」。找的方法是 grep `filled(` 後面接
> `is_` / `has_` / `can_` / `enabled` / `_flag` 這類名字 ——
> 名字就是布林的欄位，不該出現在 `filled()` 裡。

## 異動檔案

- `app/Services/AutoReply/WaitDetector.php` — `source()` 的 `is_auto`
- `app/Services/AutoReply/ClaudeCodeMatcher.php` — envelope 的 `is_error`
- `app/Services/NotificationSettingService.php` — `updateFollowup()` 的 `enabled`

## 相關

- [[customer-followup]] — 待追蹤事項（我方承諾那一半）
- [[2026-10-12-followup-keyword-mismatch]] — 同一張截圖查出來的另一件事
- [[auto-reply]] — Claude CLI 的呼叫
