# Bug 修復：任務留言按一次表情符號插入好幾個

## 問題描述

任務看板點開任務 → 留言區按表情符號，一次會插入多個相同的表情。
開越多次任務，插入的數量越多（開過 5 次任務就插入 5 個）。
重新整理頁面後恢復正常，直到又開了幾次任務。

## 根因

`resources/views/admin/task-board/index.blade.php` 的 `loadPanel()` 每次開任務都會
把 `#side-panel-body` 的 HTML **整個重建**：

```js
$('#side-panel-body').html(html);
```

但表情符號的 click 是**委派在 `document`** 上：

```js
$(document).on('click', '.js-emoji-item', function () { ... });   // ← 在 loadPanel 內
```

面板 DOM 被換掉了，`document` 上的 handler 卻不會跟著消失。
`loadPanel()` 每跑一次就再掛一個，於是同一次點擊觸發 N 個 handler，插入 N 個表情。

同一段的「點外面關閉 picker」也有相同問題，只是症狀不明顯（重複 hide 看不出來）。

## 修法

| 處理 | 做法 |
|------|------|
| 表情符號 click | 改委派在 `#emoji-grid` — 這個容器每次 `loadPanel` 都重新產生，舊的連同 handler 一起被回收，天生不會累積 |
| 點外面關閉 | 非綁 `document` 不可（要偵測面板外的點擊），改用命名空間 `click.taskCommentEmoji`，重綁前先 `.off()` |

`loadPanel()` 內其餘 6 個 `.on()` 綁定的目標都在 `#side-panel-body` 內、每次重建，
舊元素連同 handler 一起被丟棄，不會累積，因此不需處理。

## 判斷準則：委派綁在哪裡

**在會重複執行的函式裡註冊事件時**：

- 目標元素**每次都重建** → 委派在最近的那個「也會重建」的容器上（如本例的 `#emoji-grid`），
  或直接綁在元素本身。舊 handler 隨舊 DOM 一起消失，天生安全
- **非綁 `document` / `window` 不可**（偵測外部點擊、鍵盤、resize 等）→
  一律用命名空間並在重綁前 `.off('event.namespace')`
- 只需綁一次的，移到頂層或 IIFE，不要放在會重跑的函式裡

用縮排就能快速掃出可疑處 —— 這個檔案裡頂層綁定縮排 4 格，
`loadPanel()` 內的是 16 格。

## 同類問題

這是本專案第二次遇到「重複綁定累積」：

- 快速回覆題庫的拖曳排序，`bindSortable()` 每次重繪都呼叫卻沒先 `destroy()`，
  多個 SortableJS instance 互搶觸控事件，手機版只能拖一次
  （見 [features/quick-reply.md](../features/quick-reply.md)）

症狀特徵：**第一次操作正常，之後越來越怪；重新整理就好**。
遇到這種描述先查有沒有重複註冊。
