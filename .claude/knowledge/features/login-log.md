# 登入紀錄（Login Log）

## 狀態：已完成

## 功能說明

記錄每個帳號的登入行為（成功與失敗），供管理者查閱。

### 記錄欄位

- `user_id` — 登入帳號 ID（失敗且帳號不存在時為 null）
- `account` — 輸入的帳號名稱
- `ip` — 登入 IP
- `is_success` — 登入成功或失敗
- `device` — User-Agent（登入裝置資訊）
- `fail_reason` — 失敗原因（帳號或密碼錯誤 / 帳號已停用）
- `created_at` — 登入時間

### 記錄時機

在 `LoginController::login()` 中，依三種情境記錄：
1. 帳號或密碼錯誤 → `is_success=false`, `fail_reason=帳號或密碼錯誤`
2. 帳號已停用 → `is_success=false`, `fail_reason=帳號已停用`
3. 登入成功 → `is_success=true`

### 查詢介面

1. **獨立頁面**：`GET /admin/login-log`（頁面）、`GET /admin/login-log/ajax-list`（Ajax 列表）
   - 權限：`login_log.view`
   - 篩選條件：帳號、IP、登入結果、起始日期、結束日期
   - 分頁排序：依 `created_at DESC`

2. **帳號管理內嵌**：帳號管理頁面每個帳號的操作欄有「登入紀錄」按鈕
   - 路由：`GET /admin/accounts/ajax-login-log/{user}`
   - 權限：`account.view`
   - 以 Modal 顯示該帳號的登入紀錄，含分頁

## 架構

| 層 | 檔案 |
|---|---|
| Migration | `database/migrations/2026_08_21_000001_create_login_log_table.php` |
| Model | `app/Models/LoginLog.php` |
| Repository | `app/Repositories/LoginLogRepository.php` |
| Service | `app/Services/LoginLogService.php` |
| Controller | `app/Http/Controllers/Admin/LoginLogController.php` |
| Resource | `app/Http/Resources/LoginLogResource.php` |
| 語系 | `resources/lang/{tw,cn,en}/login_log.php` |

## 異動檔案

- `app/Http/Controllers/Auth/LoginController.php` — 注入 LoginLogService，登入成功/失敗時記錄
- `app/Http/Controllers/Admin/AccountController.php` — 新增 ajaxLoginLog 方法，帳號管理頁面查看登入紀錄
- `resources/views/admin/accounts/index.blade.php` — 新增登入紀錄按鈕與 Modal
- `routes/web.php` — 新增 login-log 路由群組 + accounts 下 ajax-login-log 路由
- `config/permissionMap.php` — 新增 `login_log` 權限群組
- `resources/lang/{tw,cn,en}/permission.php` — 新增 login_log 權限翻譯
