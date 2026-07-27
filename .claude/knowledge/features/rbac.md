# 帳號權限（RBAC）

## 現況

已實作（角色 + 權限 keyword 模式，含最小登入/登出）。

## 架構

- **Schema**：`roles`（含 `is_active`/`sort`/`softDeletes`）、`role_user`（多對多 pivot，`withTimestamps()`）、`role_permissions`（角色底下的 keyword 清單，非真正 pivot——沒有 `permissions` 表，keyword 目錄活在 `config/permissionMap.php`）。`users` 表新增 `status`（啟用/停用）+ `softDeletes`。
- **權限目錄**：`config/permissionMap.php`——依功能分組，值是 lang key（非字面字串），對應 `resources/lang/{tw,cn,en}/permission.php`。新功能開發時在此檔案 top up 新的分組。
- **驗證規則庫**：`config/rules.php`——複用的驗證片段（`account.*`、`role.*`），Requests 從這裡取用再疊加 contextual 規則。
- **權限檢查**：`Illuminate\Auth\Middleware\Authorize`（內建 `can` middleware）+ `Gate::define()`（在 `AuthServiceProvider::boot()` 依 `PermissionMapService::getAllKeywords()` 逐一註冊）。`admin` 角色透過 `Gate::before()` 直接放行，避免新增 keyword 後 admin 被鎖住（仍需重跑 `SetPermissionSeeder` 才會實際擁有該 keyword，但不會被擋）。
- **快取**：`PermissionService` 以「角色」為單位快取 permission keyword 清單（`role_permissions:{role_id}`），指派/異動權限時清快取，避免整表掃描且不會有 per-user 快取失效的扇出問題。
- **登入**：`app/Http/Controllers/Auth/LoginController.php`，`web` guard + session，`Auth::attempt` 帶入 `status=>1` 條件，停用帳號自動無法登入。失敗登入會記 log。

## 分層檔案

```
app/Models/{Role,RolePermission}.php, User.php（新增 roles()/hasRole()/hasPermission()）
app/Repositories/{UserRepository,RoleRepository}.php
app/Criteria/{User,Role}/*.php
app/Services/{AccountService,RoleService,PermissionService,PermissionMapService}.php
app/Http/Controllers/Admin/{AccountController,RoleController}.php
app/Http/Controllers/Auth/LoginController.php
app/Http/Requests/{Account,Role}/*.php
app/Http/Resources/{AccountResource,RoleResource,PermissionMapResource}.php
app/Exceptions/RoleInUseException.php（角色仍有帳號使用時刪除會擋下，422）
database/seeders/{CreateAdminSeeder,CreateNewAdminSeeder,SetPermissionSeeder}.php
resources/views/{layouts/app,auth/login,admin/accounts/index,admin/roles/index}.blade.php
resources/js/admin/{accounts,roles}.js（純 vanilla fetch，無框架）
```

## 已解決的原「待釐清」

- 角色模式 → **角色 + 權限 keyword**（非固定 enum、非全動態 DB 目錄）
- 一帳號可多角色（many-to-many）
- 這次一併補上最小登入/登出（原本專案完全沒有登入功能）

## 操作注意事項

- **本機測試前務必 `php artisan config:clear`**：`phpunit.xml` 用 `<server>` 覆寫 `APP_ENV=testing` 等設定，但這些覆寫在 config 被 cache（`php artisan optimize`/`config:cache`）後會失效，測試會跑錯環境並失敗。改完 route 依 `PROMPTS.md` 要求跑完 `optimize` 後，如果接著要跑測試，記得先 clear。
- **ADMIN_EMAIL/ADMIN_PASSWORD 走 `config('admin.*')`，不要直接 `env()`**：`env()` 在 config 被 cache 後於 config 檔案以外的地方會回傳 null，`config/admin.php` 是這兩個值的唯一合法讀取入口。
- 建這個功能時發現本機 laradock 環境的 nginx 沒有載入 `tripartite_gold_cs.conf`（container 已跑很久，vhost 檔案較新），需要 `docker exec laradock-nginx-1 nginx -s reload` 才會生效；另外 `.env` 的 `DB_HOST`/`REDIS_HOST`/`MEMCACHED_HOST` 原本是 `127.0.0.1`，在 workspace/php-fpm container 內連不到，已改成 service name（`mariadb`/`redis`/`memcached`）。DB 也從不存在的 `laravel` 改成新建的 `tripartite_gold_cs`（帳密 `root`/`root`，見 laradock 的 `MARIADB_ROOT_PASSWORD`）。
- `package.json` 新增了 `webpack: 5.76.0` 的明確 pin——`laravel-mix ^6.0.6` 宣告相容 `webpack ^5.60.0`，但 npm 實際解析到的最新 5.108.4 移除了 mix 依賴的內部模組（`webpack/lib/SizeFormatHelpers`），導致 `npm run dev` 直接炸掉。

## 額外帳號

`CreateNewAdminSeeder`——比照 `CreateAdminSeeder` 的模式，額外建一個 admin 角色帳號，帳密走 `config('admin.new_admin_email')`/`config('admin.new_admin_password')`（對應 `.env` 的 `NEW_ADMIN_EMAIL`/`NEW_ADMIN_PASSWORD`）。**密碼沒有預設值**——`.env` 沒設定 `NEW_ADMIN_PASSWORD` 時 seeder 會直接中止，避免真實密碼被寫進 `config/admin.php`/`.env.example` 等有進 git 的檔案。**沒有**掛進 `DatabaseSeeder` 的自動呼叫清單，要另外手動跑 `php artisan db:seed --class=CreateNewAdminSeeder`。

## 驗證方式

`php artisan migrate` + `php artisan db:seed` 後，`/login` 用 `.env` 的 `ADMIN_EMAIL`/`ADMIN_PASSWORD` 登入，`/admin/accounts`、`/admin/roles` 應可存取（admin bypass）。建立一個只帶 `account.view` 的角色並指派給測試帳號，該帳號打 `account.delete` 對應的 ajax 端點應收到 403。`tests/Feature/RbacTest.php` 覆蓋了這兩條路徑。
