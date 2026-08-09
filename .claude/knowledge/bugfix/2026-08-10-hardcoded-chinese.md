# 待辦：Blade 寫死中文字串改用 trans() 語系檔

## 問題

多個 Blade 模板有寫死的中文字串，應改用 `trans()` 語系檔，確保 tw/cn/en 同步。

## 待修改清單

### station/index.blade.php
- 關鍵字、名稱或域名、系統、全部、狀態、搜尋、重置
- 費率（收/付）、同步、操作、域名
- 詳細、編輯、暫無資料

### accounts/index.blade.php
- 操作、暫無資料

### accounts/permissions.blade.php
- 返回帳號管理、全選、取消全選、儲存權限、權限已更新、儲存失敗

### attendance/detail.blade.php
- 暫無資料

### telegram-broadcast/index.blade.php
- 發送中...

### 共用
- Loading…（多個頁面）
