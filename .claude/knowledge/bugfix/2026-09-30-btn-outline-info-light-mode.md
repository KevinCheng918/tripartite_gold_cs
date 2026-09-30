# 登入紀錄按鈕在淺色模式滑上去沒反應

## 現象

帳號管理頁的「登入紀錄」按鈕，**淺色模式**下游標移上去不會變色，
看起來像壞掉。深色模式正常。

## 起因

那顆按鈕用的是 `btn-outline-info`，而 `custom.css` **只替深色模式定義了它**：

```css
[data-theme="dark"] .btn-outline-info { … }
[data-theme="dark"] .btn-outline-info:hover { background-color: #d4af37 !important; … }
```

淺色模式沒有任何覆蓋，於是吃 Bootstrap 預設 —— 但這個主題把它蓋掉了，
結果就是滑上去什麼都沒發生。

當初的改動（版本紀錄裡找得到）是「帳號管理頁面金黃色主題調整：
登入紀錄按鈕 dark-mode 樣式」＋「Dark mode 新增 btn-outline-info 支援」——
**只做了深色模式那一半**。

## 修法

改用 `btn-outline-secondary`，跟同一列的「指派權限」「停用」一致。

> 這不會讓深色模式變醜：`btn-outline-info` 與 `btn-outline-secondary`
> 在深色模式的 hover **本來就一模一樣**（都是 `#d4af37` 金底 `#1a1200` 字）。
> 當初補 info 的深色樣式，目的就是讓它跟其他按鈕長得一樣，不是為了做出區別。
>
> 淺色模式則修好了：`.btn-outline-secondary:hover` 是 `#212529` 深黑底白字。

桌機表格與手機卡片各有一顆，兩處都要改。

## 教訓

> ⚠️ **在 `custom.css` 加 `[data-theme="dark"]` 樣式時，先確認淺色模式有沒有對應的一份。**
> 這個專案的按鈕樣式大量用 `!important` 覆蓋 Bootstrap 預設，
> 只補深色那一半，淺色就會退回「被蓋掉的 Bootstrap 預設」——
> 那通常等於沒有樣式。
>
> 更簡單的做法是**沿用同一區塊既有的 class**，不要為了配色另外挑一個 ——
> 挑了就得自己負責兩種模式。

## 異動檔案

- `resources/views/admin/accounts/index.blade.php` — 兩處按鈕的 class

## 相關

- [[login-log]] — 登入紀錄
