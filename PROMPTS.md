# PROMPTS — Tripartite Gold CS（技術客服系統）

## 環境版本

| 技術 | 版本 |
|------|------|
| PHP | 7.4.* |
| Laravel | v8.83.29 |
| Node | 10.16.* |

> **知識庫路徑**：`.claude/knowledge/INDEX.md`（主索引）→ 按需載入子文件

---

## 系統目標（Roadmap）

`tripartite_gold_cs` 是三方金流主系統（`tripartite_gold`）的技術客服子系統，目前為空白 Laravel 骨架，以下六項為規劃中的核心功能，細節與待釐清問題見各自文件：

1. **分帳號、有權限** — [.claude/knowledge/features/rbac.md](.claude/knowledge/features/rbac.md)
2. **排班功能**（使用者申請/設定 → 有權限帳號審核）— [.claude/knowledge/features/scheduling.md](.claude/knowledge/features/scheduling.md)
3. **客服對話窗，連結 Telegram** — [.claude/knowledge/features/telegram-chat.md](.claude/knowledge/features/telegram-chat.md)
4. **打卡（管理者可查看）** — [.claude/knowledge/features/attendance.md](.claude/knowledge/features/attendance.md)
5. **版本紀錄**（左下角，功能新增/修改/刪除通知技術客服）— [.claude/knowledge/features/changelog.md](.claude/knowledge/features/changelog.md)

實作任一功能前，先讀對應文件的「待釐清」項目，必要時先跟需求方確認再動工。

---

## 基礎 Style 規則

### 1. 架構職責

| 層 | 職責 | 禁止 |
|----|------|------|
| Controller | 接收 Request → 呼叫 Service → 回傳 Response | 商業邏輯、直接 DB 呼叫 |
| Service | 核心商業邏輯與調度 | HTTP 請求處理 |
| Repository | 所有 DB 操作與複雜查詢；條件用 `Criteria`（`app/Criteria/`）封裝 | ad-hoc where 散落、Service 直接 `Model::where(...)` |
| Model | Relations、Mutators/Accessors、Scope | 商業邏輯 |

- PHPDoc：複雜陣列或不明確型別**必須**補齊
- 有新介面：補權限 keyword、route、語系（`config/permissionMap.php`、`resources/lang/*`；tw → cn → en 同步）
- 有 Request：補驗證檔（`app/Http/Requests/*`、`config/rules.php`）
- 有 Response：補資源檔（`app/Http/Resources/*`）
- 有 Blade：CSS 分 `public/css`、JS 分 `public/js`；需編譯時規劃 `webpack.mix.js`

### 2. 效能（前提：100 req/s、1 億筆、1000 人同時在線）

- DB Select **必須**明確指定欄位，禁止 `*`
- 嚴格消除 N+1（eager loading）
- config / cache 開關判斷**先於**物件實例化或 DB 查詢（Short-circuit）

### 3. 語意與排版

- 字串插值含變數一律 `"{$var}"`
- **Early Return** 原則，禁止多層 `if/else` 巢狀
- Ajax route 命名加前綴 `ajax-`（參考 `routes/web.php`）
- Controller 有 request 參數時，用 `$params` 陣列包裝；方法內只用 `$params`
- `$a !== null` 改用 `filled($a)`

### 4. 版本相容

- 禁止使用 PHP 7.4 已棄用的方法
- `collect fn` → `collect function`（PHP 7.4 無 arrow function）
- 參考 `composer.lock`（Laravel 實際版本為 v8.83.29，注意與 Laravel 6 語法不相容之處，例如 `Route`、`Model` 相關的變更）

### 5. 安全

- 多表寫入/更新必須在 `DB Transaction` 中
- 外部輸入或 API 回傳異常需有 Log（參考 `config/logging.php`）

### 6. 自我驗證

- 產出前確認：所有變數已定義、無死循環

### 7. 收尾指令

> 專案跑在 Docker laradock，需從 laradock 目錄執行：

```bash
# 改到 route：
cd ../laradock && docker-compose exec workspace bash -c "cd /var/www/tripartite_gold_cs && php artisan optimize"

# 改到 permission：
cd ../laradock && docker-compose exec workspace bash -c "cd /var/www/tripartite_gold_cs && php artisan db:seed --class=SetPermissionSeeder"
```

---

## 執行步驟（每次必須遵守）

> Claude 執行 PROMPTS.md 時，**無論需求為何，必須依序完成以下六步，缺一不可**：

1. **執行前**：列出本次將新增或修改的所有檔案清單
2. 載入 `.claude/knowledge/INDEX.md`，依功能範圍按需載入對應 domain / features / bugfix 文件
3. 依照上方 **基礎 Style 規則** 實作，每一條規則必須貫徹
4. 完成後逐項核對下方 **完成前自我檢核**
5. **收尾指令（必須執行）**：
   - 有異動 `routes/` 任何檔案 → **必須執行**：
     ```bash
     cd /Users/zheng-kai-wen/Documents/laradock && docker-compose exec workspace bash -c "cd /var/www/tripartite_gold_cs && php artisan optimize"
     ```
   - 有異動 permission → **必須執行**：
     ```bash
     cd /Users/zheng-kai-wen/Documents/laradock && docker-compose exec workspace bash -c "cd /var/www/tripartite_gold_cs && php artisan db:seed --class=SetPermissionSeeder"
     ```
6. **文件更新（必須執行）**：
   - 新增或更新 `.claude/knowledge/bugfix/*.md` 或 `.claude/knowledge/features/*.md`，記錄本次功能要點
   - 同步更新 `.claude/knowledge/INDEX.md` 對應索引行

---

## 需求說明

### 背景知識

三方金流代收/代付資料流細節（L1-L4 架構、群組單規則）見 [.claude/knowledge/domain/payment-flow.md](.claude/knowledge/domain/payment-flow.md)。

---

### 🔧 本次功能需求

> 在此填寫需求，執行 PROMPTS.md 時 Claude 以此為準。目前為空——下一個實際開發任務開始前，把需求填在這裡。

```
（尚無進行中的任務）

last: 必須執行完成前自我檢核
```

---

## 完成前自我檢核

### 架構層

- [ ] PHPDoc 已補齊（複雜陣列、不明確型別）
- [ ] Controller 無商業邏輯、無直接 DB 呼叫
- [ ] Service 無 HTTP 處理
- [ ] Repository 使用 Criteria 封裝；Service 無 `Model::where(...)`
- [ ] Model 無商業邏輯
- [ ] 無不相容 PHP 7.4 語法（如 `fn` arrow function）

### 周邊檔案

- [ ] Request 驗證檔（`app/Http/Requests/*`, `config/rules.php`）
- [ ] Response 資源檔（`app/Http/Resources/*`）
- [ ] Permission keyword + route + `config/permissionMap.php`
- [ ] 語系檔 `resources/lang/*` 已從 tw 同步至 cn / en

### Blade / 前端

- [ ] CSS/JS 分離（`public/css`, `public/js`）
- [ ] `webpack.mix.js` + `resources/sass/app.scss`（若需編譯）

### 效能

- [ ] SELECT 指定欄位（無 `*`）
- [ ] N+1 已消除（eager loading）
- [ ] Config/Cache 判斷在物件實例化或 DB 查詢之前

### 語意

- [ ] 字串插值使用 `{$var}`
- [ ] Early Return 已套用，無多層巢狀 `if/else`
- [ ] Ajax route 含 `ajax-` 前綴
- [ ] Controller params 用 `$params` 包裝

### 安全

- [ ] 多表寫入用 DB Transaction
- [ ] 外部輸入 / API 異常有 Log

### 收尾指令

- [ ] 改 route → 已執行 `cd ../laradock && docker-compose exec workspace bash -c "cd /var/www/tripartite_gold_cs && php artisan optimize"`
- [ ] 改 permission → 已執行 `cd ../laradock && docker-compose exec workspace bash -c "cd /var/www/tripartite_gold_cs && php artisan db:seed --class=SetPermissionSeeder"`
- [ ] 新增或更新 `.claude/knowledge/bugfix/*.md` 或 `.claude/knowledge/features/*.md`
- [ ] 若有新增文件，同步更新 `.claude/knowledge/INDEX.md` 對應索引表
