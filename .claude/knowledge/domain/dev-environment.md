# 本機開發環境（laradock）

`tripartite_gold_cs` 與其他多個專案（含主系統 `tripartite_gold`）共用同一組本機 laradock（`/Users/zheng-kai-wen/Documents/laradock`）。這組 laradock 的容器已經跑很久（週級），且同時有其他專案的真實流量在跑，操作時要注意：

## 容器與路徑

- 專案掛載於 workspace / php-fpm 容器內的 `/var/www/tripartite_gold_cs`（對應本機 `/Users/zheng-kai-wen/Documents/tripartite_gold_cs`）。
- nginx vhost：`laradock/nginx/sites/tripartite_gold_cs.conf`，`server_name tripartite_gold_cs.com`。**這個 vhost 檔案比 nginx container 的啟動時間新**，所以 nginx 進程原本沒載入它（Host 打 `tripartite_gold_cs.com` 會落到其他 vhost，出現不相關的畫面/redirect）。改完 nginx vhost 設定後要 `docker exec laradock-nginx-1 nginx -s reload`（graceful reload，不會斷其他站的連線）。
- 本機測試 HTTP：`curl -H "Host: tripartite_gold_cs.com" http://127.0.0.1/...`（不需改 `/etc/hosts`）。

## `.env` 連線設定

專案原本 `.env`/`.env.example` 的 `DB_HOST`/`REDIS_HOST`/`MEMCACHED_HOST` 是 `127.0.0.1`——這只在「從 host 機器連」才對，從 workspace/php-fpm **容器內部**連要用 docker-compose service name：`DB_HOST=mariadb`、`REDIS_HOST=redis`、`MEMCACHED_HOST=memcached`。已修正為 service name。

`DB_DATABASE` 原本設 `laravel`（不存在）；已改用專屬的 `tripartite_gold_cs` 資料庫（帳密沿用 laradock 的 `MARIADB_ROOT_PASSWORD`：`root`/`root`，見 `laradock/.env`）。

## 前端建置

`package.json` 的 `laravel-mix ^6.0.6` 沒有明確 pin `webpack` 版本，npm 會解析到過新的 5.x（5.108+），其中 mix 依賴的內部模組 `webpack/lib/SizeFormatHelpers` 已被移除，導致 `npm run dev`/`npm run watch` 直接掛掉。已在 `package.json` 明確加上 `"webpack": "5.76.0"` 解決；日後若要升級 laravel-mix 版本，這個 pin 可能需要一併調整或移除。

## Log 設定（2026-08-29 對齊主系統）

`config/logging.php` 的檔案類 channel 刻意**不使用 `env('LOG_LEVEL')`，一律寫死 `'debug'`**。

原因：正式環境若把 `LOG_LEVEL` 設成 `error`，`Log::info()` / `Log::warning()` 會被整個濾掉。
2026-08-29 追查「補點審核通過沒發 Telegram 通知」時就卡在這裡 ——
診斷用的 log 一條都寫不進去，花了很久才發現不是程式沒執行，而是 log 被等級過濾。
主系統 `tripartite_gold` 也是寫死 `debug`。

其他對齊主系統之處：

| 項目 | 設定 | 理由 |
|------|------|------|
| `stack` 的 channel | `daily`（原本是 `single`） | single 會讓 `laravel.log` 無限成長；daily 每天一檔、保留 14 天 |
| `allowInlineLineBreaks` | `true` | 例外堆疊、多行訊息保持原樣，不被壓成一行 |
| `ignoreEmptyContextAndExtra` | `true` | 沒有 context 時不印出多餘的 `[] []` |
| `dateFormat` | `Y-m-d H:i:s.u` | 帶微秒，同一秒內多筆記錄仍可分辨先後 |

> 檔名因此變成 `laravel-YYYY-MM-DD.log`，不再是 `laravel.log`。
> 舊的 `laravel.log` 不會再被寫入，可自行保留或清除。

### 寫 log 時的等級選擇

- 「該做卻沒做」（例如通知被略過）→ **`warning`**，不要用 `info`
- 正常流程記錄 → `info`
- 外部 API 失敗、例外 → `error`

## Config 快取陷阱

`php artisan optimize`（`PROMPTS.md` 規定改 route 後要跑）會把 `.env` 的值連同 `config()` 一起 cache 進 `bootstrap/cache/config.php`。這會產生兩個後果：
1. `env()` 若在 `config/*.php` 以外的地方呼叫，cache 後一律回傳 `null`——所有需要讀 `.env` 值的地方都要包成 `config('xxx.yyy')`，`.env` 值只在對應的 `config/*.php` 裡用 `env()` 讀一次。
2. **測試會被影響**：`phpunit.xml` 用 `<server>` 覆寫 `APP_ENV=testing`、`CACHE_DRIVER=array` 等值，但這些覆寫在 config 被 cache 後不會生效（用的是 cache 當下的值）。本機跑 `php artisan test` 前，若剛跑過 `optimize`，要先 `php artisan config:clear`。
