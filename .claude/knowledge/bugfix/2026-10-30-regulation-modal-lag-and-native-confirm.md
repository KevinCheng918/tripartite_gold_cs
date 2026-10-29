# 規章「看內容／編輯」按下去卡一拍、刪除用瀏覽器 confirm

**日期**：2026-10-30（v3.49）
**回報**：「按下看內容按鈕跟編輯按鈕都會卡一下才出現 modal」、
「系統規章刪除怎麼會用 alert，不對吧」

## 症狀一：按下去畫面整整卡一拍什麼都不動

點「看內容」或「編輯」之後，要等一下視窗才跳出來 —— 中間畫面完全沒有反應，
使用者不知道自己按到了沒，常常連按好幾次。

## 原因

兩支都寫成「**等 API 回來才 `showBsModal()`**」：

```js
$('.js-regulation-view').on('click', function () {
    fetchRegulation($(this).data('id'), function (r) {
        $('#modal-regulation-view-title').text(r.title);
        $('#modal-regulation-view-body').text(r.content || '');
        showBsModal('modal-regulation-view');   // ← 整趟 request 之後才開
    });
});
```

規章列表**刻意沒帶 `content`**（規章可能上萬字，一次輸出十幾份會把頁面撐爆），
所以點開必然要多一趟 —— 那一趟就是那一拍。

## 修法：先開視窗，再把內容填進去

標題、狀態、排序**列表本來就有**，從 `data-*` 先擺上去；只有內容那一格在等，
期間顯示 `project.loading`（「載入中…」）。

- 看內容：`data-title` 先填標題，內容區先寫「載入中…」，開視窗，再撈
- 編輯：`data-title`／`data-project`／`data-status`／`data-sort` 先填好，
  開視窗，再撈內容

⚠ **內容還沒到之前，textarea 要 `disabled`、儲存鈕也要關掉**：
不關的話使用者可以在空白框裡打字然後按儲存 ——
那會把整份規章**覆蓋成他剛打的那幾個字**。這是改這一題時新引入的風險，
不是原本就有的。

⚠ 撈回來之後**標題／狀態／排序要用回傳的值覆蓋一次**：`data-*` 是頁面載入
當下的快照，萬一別人剛改過，以撈回來的那份為準。

⚠ 撈失敗要把已經開著的視窗關掉（`fetchRegulation` 多收一個 `onFail`），
不然會留一個空白的編輯視窗在畫面上。

## 症狀二：刪除確認用 `window.confirm`

```js
if (!window.confirm('{{ trans("project.regulation_delete_confirm") }}')) { return; }
```

原本的註解寫「這個頁面沒有既有的確認視窗元件，寧可用土法」——
**那個前提是錯的**：`task-board` 早就有 `modal-delete-comment-confirm`
／`modal-delete-attachment-confirm` 這一套可以照抄。

## 為什麼不能用原生 confirm

1. 這個系統**所有對使用者的回饋一律走 modal**（見 features/feedback-ui-style）
2. 原生 confirm 的樣式不受控，**套不到深色模式**
3. ⚠ 最嚴重的：部分瀏覽器／設定會直接擋掉原生對話框，
   **被擋掉時 `confirm()` 直接回 `false`** —— 刪除會「安靜地不發生」，
   使用者按了沒反應，也不會有任何錯誤訊息

## 修法

照 `task-board` 的樣子加 `#modal-regulation-delete`（`modal-sm`，
取消／刪除兩顆鈕），並在裡面**顯示要刪的規章標題** ——
一個專案可以有好幾份，只問「確定要刪除嗎」的話刪錯了也不會知道。

## 順帶：整頁 JS 抽到 `public/js/project.js`

`resources/views/admin/project/index.blade.php` 原本整段 JS 內嵌在
`@section('scripts')` 裡，違反 PROMPTS.md「有 Blade：CSS 分 `public/css`、
JS 分 `public/js`」。這次一併抽出來，照 `shared-file` 的做法：

- 權限、語系、負責人候選名單由 `#project-app` 的 `data-*` 帶進去
- 原本寫死在 `<script>` 裡的中文（「新增專案」「操作失敗」⋯⋯）收進
  `resources/lang/{tw,cn,en}/project.php`，外部 JS 查不到 `trans()`
- `<script src>` **一定要帶 `?v={{ filemtime(...) }}`** ——
  漏掉的話改完 JS 使用者的瀏覽器還是跑舊的那一份
  （v3.48 點數報表就是漏了這個）

## 相關檔案

| 檔案 | 說明 |
|---|---|
| `public/js/project.js` | **新增**。整頁 JS ＋ 上面兩個修正 |
| `resources/views/admin/project/index.blade.php` | `#project-app` 的 data-*、刪除確認 modal、按鈕補 `data-*`、改掛外部 JS |
| `resources/lang/{tw,cn,en}/project.php` | `project_add`／`project_edit`／`saved`／`deleted`／`action_failed`／`loading` |
