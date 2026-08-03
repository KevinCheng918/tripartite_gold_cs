# WebSocket（soketi）部署指南

## 概述

客服後台的 Telegram 即時聊天功能需要 WebSocket 服務來做即時訊息推送。
使用 **soketi**（開源、免費、Pusher 協議相容），獨立服務不影響現有專案。

---

## 一、服務資訊

| 項目 | 說明 |
|------|------|
| 服務名稱 | soketi（WebSocket Server） |
| 用途 | 客服後台即時訊息推送（Telegram 聊天） |
| 監聽 Port | 6001（可自訂） |
| 資源需求 | 極低（~50-128MB RAM） |
| 影響範圍 | 僅 tripartite_gold_cs 使用，不影響其他專案 |

---

## 二、Docker 部署（推薦）

### 2.1 獨立啟動

```bash
docker run -d \
  -p 6001:6001 \
  -e SOKETI_DEFAULT_APP_ID=app-id \
  -e SOKETI_DEFAULT_APP_KEY=app-key \
  -e SOKETI_DEFAULT_APP_SECRET=app-secret \
  --name soketi \
  --restart always \
  quay.io/soketi/soketi:latest
```

### 2.2 加入現有 docker-compose

在 `docker-compose.yml` 加入：

```yaml
soketi:
  image: quay.io/soketi/soketi:latest
  ports:
    - "6001:6001"
  environment:
    SOKETI_DEFAULT_APP_ID: "app-id"
    SOKETI_DEFAULT_APP_KEY: "app-key"
    SOKETI_DEFAULT_APP_SECRET: "app-secret"
  restart: always
```

> **注意：** `APP_ID`、`APP_KEY`、`APP_SECRET` 可自訂任意字串，
> 但必須與 tripartite_gold_cs 的 `.env` 完全一致。

### 2.3 驗證服務啟動

```bash
# 確認容器正在運行
docker ps | grep soketi

# 測試連線（應回傳 OK 或空白）
curl http://127.0.0.1:6001
```

---

## 三、Nginx 設定（HTTPS 環境必須）

如果前端是透過 HTTPS 訪問後台，瀏覽器不允許從 HTTPS 頁面連到 HTTP 的 WebSocket。
需要在 nginx 加一個 reverse proxy 把 `/ws` 路徑轉發到 soketi。

在 tripartite_gold_cs 的 nginx vhost 設定中加入：

```nginx
# WebSocket reverse proxy（soketi）
location /ws {
    proxy_pass http://soketi:6001;  # Docker 內用 service name；非 Docker 用 127.0.0.1
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_read_timeout 86400;  # WebSocket 長連線不超時
}
```

設定完後 reload nginx：

```bash
# Docker 內
docker exec laradock-nginx-1 nginx -s reload

# 或直接
nginx -s reload
```

---

## 四、Laravel .env 設定

### 4.1 HTTP 環境（內網 / 開發）

soketi 與 Laravel 在同一台機器或 Docker network：

```env
BROADCAST_DRIVER=pusher
PUSHER_APP_ID=app-id
PUSHER_APP_KEY=app-key
PUSHER_APP_SECRET=app-secret
PUSHER_HOST=127.0.0.1
PUSHER_PORT=6001
PUSHER_SCHEME=http
```

> Docker 環境中 `PUSHER_HOST` 改為 soketi 的 service name（如 `soketi`）

### 4.2 HTTPS 環境（正式上線，走 nginx proxy）

```env
BROADCAST_DRIVER=pusher
PUSHER_APP_ID=app-id
PUSHER_APP_KEY=app-key
PUSHER_APP_SECRET=app-secret
PUSHER_HOST=你的域名
PUSHER_PORT=443
PUSHER_SCHEME=https
```

### 4.3 清除快取

```bash
php artisan config:clear
```

---

## 五、安全性

- soketi 的 6001 port **不需要對外開放**（透過 nginx proxy 即可）
- APP_KEY / APP_SECRET 是內部認證用，不要外洩
- 前端 JS 只用到 APP_KEY（公開的），APP_SECRET 只在後端使用

---

## 六、監控

```bash
# 查看 soketi 日誌
docker logs soketi

# 查看連線數等資訊
curl http://127.0.0.1:6001/usage
```

---

## 七、故障處理

| 問題 | 排查方式 |
|------|----------|
| 前端收不到即時訊息 | 確認 soketi 容器是否運行：`docker ps \| grep soketi` |
| 瀏覽器 console 報 WebSocket 錯誤 | HTTPS 環境確認 nginx proxy 設定是否正確 |
| 後端 Broadcasting 報錯 | 確認 `.env` 的 PUSHER_HOST/PORT 與 soketi 一致 |
| soketi 不影響的功能 | 即時推送失效時，前端有 30 秒 polling fallback，聊天功能仍可用 |

---

## 八、FAQ

**Q：soketi 掛了會影響其他專案嗎？**
A：不會。soketi 是獨立服務，跑在獨立的 port，與 nginx、php、mariadb 完全無關。

**Q：soketi 掛了客服聊天還能用嗎？**
A：可以。前端有每 30 秒自動查詢的 fallback 機制，只是不是即時推送。

**Q：需要額外的 Redis 嗎？**
A：不需要。soketi 使用內建的記憶體模式。

**Q：APP_ID / KEY / SECRET 要怎麼設？**
A：可以自訂任意字串，例如 `app-id`、`app-key`、`app-secret`。只要 soketi 啟動參數和 Laravel `.env` 一致即可。
