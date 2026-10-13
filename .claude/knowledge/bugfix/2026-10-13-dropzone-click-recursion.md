# 公告管理的拖曳區點下去就當掉（Maximum call stack size exceeded）

## 現象

瀏覽器主控台跳 `Uncaught RangeError: Maximum call stack size exceeded`，
那一頁的 JS 整個停住。

## 起因：click 冒泡回自己

公告管理的附件拖曳區，`<input type="file">` **藏在拖曳區裡面**
（刻意的 —— 鍵盤使用者要 tab 得到）：

```html
<label for="tpl-files">附件</label>
<div id="tpl-dropzone">
    …
    <input type="file" id="tpl-files" class="d-none">   ← 在 dropzone 裡
</div>
```

```js
$dropzone.on('click', function () {
    $('#tpl-files').trigger('click');     // ← 問題在這
});
```

於是：

```
點 dropzone
  → trigger input.click()
     → 那個 click 冒泡回 dropzone
        → dropzone 的 handler 又跑
           → trigger input.click()
              → …（無限）
```

點上方那個 `<label for="tpl-files">` 也走同一條路 —— label 會觸發 input 的
click，一樣冒泡到 dropzone。

## 修法

擋在 **input 那一側**：

```js
$('#tpl-files').on('click', function (e) {
    e.stopPropagation();
});
```

⚠ **不要改成在 dropzone 裡判斷 `e.target`**。擋在 input 這一側的好處是
「不論 click 是誰觸發的（使用者點、label 觸發、程式 trigger）都只會往下開
檔案視窗」—— 判斷 `e.target` 只蓋得住「剛好點在 input 上」那一種。

## 教訓

> ⚠️ **「點容器 → trigger 裡面某個元素」是一個遞迴陷阱。**
> DOM 事件會冒泡，而被 trigger 的元素就在容器裡 —— 冒泡回來正好再觸發一次。
>
> 看到 `$container.on('click', … $childInside.trigger('click'))` 這個形狀，
> 就要問「這個 child 在不在 container 裡」。在的話一定要擋冒泡。
>
> ⚠️ 症狀是**整頁的 JS 停住**而不只是那個按鈕壞掉 —— call stack 爆掉之後
> 後面的程式都不會跑。所以回報通常是「這一頁壞了」，不會指向拖曳區。

## 異動檔案

- `resources/views/admin/telegram-broadcast/index.blade.php` — input 的 click 擋冒泡

## 相關

- [[broadcast-template]] — 公告管理（拖曳區屬於這個功能）
