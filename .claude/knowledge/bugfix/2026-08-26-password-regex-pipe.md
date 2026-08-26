# Bug 修復：修改密碼一律「更新失敗」（regex 規則被 pipe 拆壞）

## 問題描述

在個人資訊 modal 或帳號管理修改密碼時，輸入含 `@`、`!` 等符號的密碼一律回報「更新失敗」。
實測所有密碼都會失敗，並非只有特殊符號。

## 根因

`config/rules.php` 的 `USER_PASSWORD_REGEX` 內含 `|` 字元（字元類中的 `{}|;:`）：

```
'USER_PASSWORD_REGEX' => 'regex:/^[A-Za-z0-9!@#$%^&*()_+\-=\[\]{}|;:,.<>?\/?]{8,}$/',
```

驗證規則以 pipe 字串串接（`'sometimes|nullable|' . config('rules.USER_PASSWORD_REGEX')`）時，
Laravel 的 `ValidationRuleParser::explodeExplicitRule()` 會對整串 `explode('|')`，把 regex 從中間切成兩段：

| 拆出的規則 | 結果 |
|-----------|------|
| `regex:/^[A-Za-z0-9!@#$%^&*()_+\-=\[\]{}` | 字元類未閉合，pattern 不合法 |
| `;:,.<>?\/?]{8,}$/` | 被當成未知規則名 |

因此驗證必定失敗，前端 `error` callback 又一律顯示「更新失敗」，掩蓋了真正的 422 訊息。

> Laravel 官方文件已註明：regex / not_regex 規則若 pattern 含 `|`，**必須用陣列形式**指定規則。

容器內實測（`php artisan tinker`）確認：

```
pipe 形式  => BadMethodCallException: Method Validator::validate; does not exist
             （並伴隨 preg_match(): No ending delimiter '/' found 警告）
陣列形式   => Abc@1234 PASS / Abc!1234 PASS / Abc＠1234 FAIL / short1 FAIL
```

### 次要問題：全形字元

`USER_PASSWORD_REGEX` 只涵蓋半形 ASCII，中文輸入法打出的全形 `＠`（U+FF20）、`！`（U+FF01）、
全形空白（U+3000）都會被擋。原本錯誤訊息只寫「密碼允許英文、數字、符號」，沒說明必須半形，
使用者無從判斷。

## 修復內容

### 異動檔案

| 檔案 | 變更 |
|------|------|
| `app/Http/Requests/Account/UpdateAccountRequest.php` | `password` 規則改陣列形式 `['sometimes', 'nullable', config('rules.USER_PASSWORD_REGEX')]` |
| `app/Http/Requests/Account/StoreAccountRequest.php` | `account`、`password` 規則改陣列形式 |
| `app/Http/Requests/Account/UpdateProfileRequest.php` | **新增**。原本 `ajaxUpdateProfile()` 直接在 Controller 內 `$request->validate()`，違反 PROMPTS.md「有 Request 就補驗證檔」，抽成 FormRequest |
| `app/Http/Controllers/Admin/AccountController.php` | `ajaxUpdateProfile()` 改注入 `UpdateProfileRequest`，以 `validated()` 取值（對齊其他方法慣例）|
| `public/js/login.js` | **新增**。登入頁全形偵測腳本，依 PROMPTS.md「CSS/JS 分離」放 `public/js` |
| `resources/views/layouts/app.blade.php` | 個人資訊密碼欄 `minlength` 4 → 8（與後端一致）；新增 `hasFullWidth()` 提交前擋全形；ajax `error` callback 改讀 `xhr.responseJSON.errors` 顯示實際驗證訊息，而非固定「更新失敗」 |
| `resources/views/admin/accounts/index.blade.php` | 新增/編輯帳號表單提交前用 `hasFullWidth()` 檢查帳號與密碼，含全形直接提示 |
| `resources/views/auth/login.blade.php` | 新增全形提示條與 `.login-warning` 樣式，掛載 `public/js/login.js`（登入頁未載入 jQuery，腳本為原生 JS）|
| `resources/lang/{tw,cn,en}/account.php` | `regex_account`／`regex_password` 補「半形」字樣；新增 `full_width_account`、`full_width_password` |
| `resources/lang/{tw,cn,en}/profile.php` | 新增 `full_width_password` |
| `resources/lang/{tw,cn,en}/login.php` | 新增 `full_width_hint` |

### 登入頁的設計取捨

登入頁採 **input／compositionend 事件即時提示，但不阻擋送出**，與帳號設定頁的「提交前擋下」不同。
原因：若有既存帳號的密碼含全形（regex 修好前的種子或歷史資料），前端硬擋會讓該帳號完全無法登入。
提示只負責讓使用者看見輸入法問題，是否送出仍交給使用者。

偵測範圍 `/[　-〿＀-￯‘’“”一-鿿]/`
（CJK 標點、全形形式、彎引號、漢字），比帳號設定頁的 `/[^\x21-\x7E]/` 保守，避免誤報。

## 注意事項

日後在 `config/rules.php` 新增含 `|` 的 regex 時，**所有引用處都要用陣列形式**，不可用 `'a|b|' . config(...)` 串接。
目前 `USER_ACCOUNT_REGEX` 不含 `|`，但也一併改成陣列形式以維持一致。
