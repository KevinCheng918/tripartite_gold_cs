# 知識庫索引

> 這是 `tripartite_gold_cs` 專案的知識庫主索引。執行任務時先讀這份索引，再依需求載入對應子文件——不要一次載入全部。
>
> **維護規則**：每次任務完成後，必須更新本索引 + 對應的子文件（新增/異動的功能寫進 `features/`，修的 bug 寫進 `bugfix/`）。細節見 `PROMPTS.md` 的「執行步驟」。

## 背景知識（domain/）

| 文件 | 內容 |
|------|------|
| [domain/payment-flow.md](domain/payment-flow.md) | 三方金流主系統的代收/代付四層架構、群組單規則 — 客服對話與工單判斷會用到 |
| [domain/dev-environment.md](domain/dev-environment.md) | 本機 laradock 環境的坑：nginx vhost 要手動 reload、.env host 要用 service name、webpack 版本 pin、config 快取會讓測試環境覆寫失效 |

## 功能規劃（features/）

> 目前皆為**規劃中、尚未實作**的功能。每個文件記錄需求現況與待釐清問題，實作後要更新為實際架構說明。

| 文件 | 功能 | 狀態 |
|------|------|------|
| [features/rbac.md](features/rbac.md) | 分帳號、權限管理（含最小登入/登出） | 已完成 |
| [features/scheduling.md](features/scheduling.md) | 排班（報班/換班/三班制） | 已完成 |
| [features/telegram-chat.md](features/telegram-chat.md) | 客服對話窗（Telegram 整合） | 已完成 |
| [features/attendance.md](features/attendance.md) | 打卡出勤 | 已完成（持續迭代） |
| [features/changelog.md](features/changelog.md) | 版本紀錄（左下角變更日誌） | 規劃中 |
| [features/login-log.md](features/login-log.md) | 登入紀錄（每帳號登入時間/IP/裝置/成敗） | 已完成 |

## Bug 修復紀錄（bugfix/）

> 目前無記錄。修完 bug 後在此新增 `bugfix/{yyyy-mm-dd}-{簡述}.md`，並在此表加一行。

| 文件 | 問題 |
|------|------|
| [bugfix/2026-08-07-overnight-shift-clock-out.md](bugfix/2026-08-07-overnight-shift-clock-out.md) | 跨日班下班打卡顯示遲到又早退 |
| [bugfix/2026-08-10-hardcoded-chinese.md](bugfix/2026-08-10-hardcoded-chinese.md) | 待辦：Blade 寫死中文改用 trans() 語系檔 |
