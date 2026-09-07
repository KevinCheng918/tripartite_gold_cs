# 快速回覆題庫（Quick Reply）

## 現況

已完成，持續迭代。

> 本文件於 2026-09-07 補建，起因是「題庫改拖曳排序」這次改動。
> 功能範圍依路由、Model、權限回填，**部分細節尚未逐一查證**，後續動到相關程式時再補完。

## 功能概述

客服在 Telegram 對話視窗可叫出的常見問答，由客服自行維護（不寫死在語系或 config）。

- 兩層結構：**類別**（`quick_reply_category`）→ **問答**（`quick_reply_item`）
- 皆可新增／編輯／刪除／啟用停用／排序
- 聊天視窗的選單只顯示啟用中的項目；整個類別沒有啟用中的問答就不顯示該類別
- 類別底下還有問答時**不允許刪除**（回 422），避免一次誤刪整批

## 拖曳排序（2026-09-07）

原本是上移／下移按鈕（逐筆交換 `sort`），改為 SortableJS 拖曳。

- 端點 `ajax-reorder-categories` / `ajax-reorder-items`，接**整份順序的 id 陣列**，
  把 `sort` 重寫成 1..n，多筆寫入包在同一個 `DB::transaction`
- 前端送來的 id 若已被別人刪掉就跳過該筆，不因一筆不存在而整批失敗
- 拖完一律 `loadAll()` 重載，讓畫面與 DB 一致

### 手機版的兩個坑

> ⚠️ **不要用 `handle` 限定只能抓把手**：那個 grip 圖示在手機上太小，
> 手指幾乎按不中，拖曳會變得極難操作。
> 現在整列都可拖，把手只當視覺提示；編輯／刪除鈕用 `filter: 'button'`
> 排除（搭配 `preventOnFilter: false`，否則會吃掉按鈕的點擊）。

> ⚠️ **把手不能設 `touch-action: none`**：那會讓該區域完全無法捲動。
> 防止長按選到文字改用 `user-select: none` 加在整列上。

防誤觸沿用任務看板同一組設定：`delay: 500` + `delayOnTouchOnly` + `touchStartThreshold: 5`
（長按才拖、手指移動超過 5px 改判為捲動、桌機滑鼠不受延遲影響）。

> ⚠️ **重綁前一定要 `destroy()` 舊實例**：`renderCategories()` / `renderItems()`
> 每次都會呼叫 `bindSortable()`，不先銷毀的話同一個容器上會疊出多個 Sortable 實例，
> 它們互相搶同一批 touch 事件 —— **手機上拖過一次之後就再也拖不動**。
> 任務看板用 `sortableInstances` 陣列處理，這裡用容器 id 當 key。

### 手機版點類別像沒反應

版面是 `col-md-4` / `col-md-8`，**手機上會上下堆疊**，問答區在類別清單下方。
點了類別但畫面不動，看起來就像沒作用 —— 因此 768px 以下要
`scrollToItemsOnMobile()` 自動捲到問答區。桌機左右並排則不捲。

### 移除的舊實作

上下移相關全數刪除，未留相容層：
`ajaxMoveCategory` / `ajaxMoveItem`、`moveCategory` / `moveItem`、`swapSort`、
`findAdjacentCategory` / `findAdjacentItem`、`MoveRequest.php`、兩條路由、
三語系的 `action_move_up` / `action_move_down`。

## 架構檔案

- `app/Models/QuickReplyCategory.php`、`app/Models/QuickReplyItem.php`
- `app/Http/Controllers/Admin/QuickReplyController.php`
- `app/Services/QuickReplyService.php` — `reorderCategories()` / `reorderItems()` / `applyOrder()`
- `app/Repositories/QuickReplyRepository.php` — `getCategoriesByIds()` / `getItemsByIds()`
- `app/Http/Requests/QuickReply/ReorderRequest.php`
- `resources/views/admin/quick-reply/index.blade.php` — SortableJS 由 CDN 載入（與任務看板同一份）
- `public/js/quick-reply-admin.js`
- 樣式在 `public/css/app.css`（`.qr-drag-handle` / `.qr-drag-ghost`）

## 注意事項

- 權限 keyword：`quick_reply.view` / `quick_reply.edit`
- 題庫初始資料由 seeder 從舊的 `config/quick_reply.php` 匯入
- 聊天視窗端的實作在 `public/js/telegram-chat/quick-reply.js`，見 [[telegram-chat]]
