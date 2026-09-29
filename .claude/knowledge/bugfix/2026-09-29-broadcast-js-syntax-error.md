# broadcast.js 有語法錯誤，群發頁的 JS 整個沒在跑

## 現象

`public/js/broadcast.js` 解析失敗：

```
SyntaxError: Unexpected token }
```

JS 檔一有語法錯誤就**整份不執行** —— 群發頁的分頁切換、送出、紀錄載入
全部都是死的。

## 起因

`9474c2d`（JS 維持頁面全面改用 Bootstrap class）那次把自製 modal 換成
Bootstrap Modal，刪掉了兩段關閉監聽器，但**只刪了開頭，留下結尾的 `});`**：

```diff
     // modal 關閉
-    document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
-        btn.addEventListener('click', function () {
-            btn.closest('.modal-overlay').style.display = 'none';
-        });
     });
-    document.querySelectorAll('.modal-overlay').forEach(function (overlay) {
-        overlay.addEventListener('click', function (e) {
-            if (e.target === overlay) { overlay.style.display = 'none'; }
-        });
     });
```

留下來的就是兩個孤兒 `});`，整份檔案多了 2 個 `}` 和 2 個 `)`。

改用 Bootstrap Modal 之後關閉是 `data-bs-dismiss` 在管，那兩段本來就該整段刪掉，
只是刪不乾淨。

## 修法

把那兩行孤兒刪掉。這是 2026-09-29 處理 session 問題、替 8 支 apiFetch
加攔截時順手發現的 —— 檢查語法才發現它本來就是壞的。

## 教訓

> ⚠️ **改完 JS 一定要跑語法檢查。**
> `docker exec laradock-workspace-1 node --check /var/www/tripartite_gold_cs/public/js/*.js`
>
> 這個錯誤從 `9474c2d` 一路活到現在都沒被發現，因為瀏覽器只會在 console
> 印一行紅字，頁面看起來就只是「按了沒反應」。

> ⚠️ **`docker exec` 要檢查 stdin 重導時記得加 `-i`。**
> 追這個 bug 時第一次用 `docker exec ... node -e "readFileSync('/dev/stdin')" < file`
> 逐版檢查，結果每一版都回報 OK —— 因為沒加 `-i`，stdin 根本沒傳進容器，
> 等於在檢查空字串。差點下錯結論說「是我改壞的」。
> 要嘛加 `-i`，要嘛把檔案寫到容器看得到的路徑再用 `node --check <path>`。

## 順帶

同一次掃了 `public/js/` 與 `public/js/telegram-chat/` 底下所有檔案，
其餘都沒有語法錯誤。

## 相關

- [[broadcast]] — 群發公告
