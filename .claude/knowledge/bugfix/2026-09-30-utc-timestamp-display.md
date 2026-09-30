# 上傳時間顯示少 8 小時

## 現象

文件區的檔案上傳時間，顯示的比實際早 8 小時。

## 根因

`config/app.php` 的 `timezone` 是 `Asia/Taipei`，DB 存的也是台北時間 ——
**但 Model 轉成 JSON 時會被序列化成 UTC**。

Laravel 7+ 的 `Model::serializeDate()` 預設走 `Carbon::toJSON()`，輸出帶 `Z` 的 ISO 字串：

```
DB 原始值：              2026-09-07 22:05:31   ← 台北，正確
$file->created_at：      2026-09-07 22:05:31   ← Carbon，正確
json_encode($file)：     2026-09-07T14:05:31.000000Z   ← UTC，少 8 小時
```

前端拿到後直接切字串：

```js
// public/js/shared-file.js
f.created_at.substring(0, 16).replace('T', ' ')   // → 2026-09-07 14:05
```

`substring` 只是切字元，不會做時區換算，所以 UTC 就這樣顯示出去了。
**不會報錯，數字看起來也很正常**，只是全部少 8 小時。

同樣寫法的還有群發紀錄的 `sent_at`、忽略成員的 `ignored_at`。

## 修法

`public/js/common.js` 加 `window.formatDateTime()`，四個呼叫點改用它。

### 為什麼不改後端的 `serializeDate()`

改後端會一次修好所有地方，但有兩個問題：

1. 沒有共用的 Model 基底，要嘛每個 Model 各加一次、要嘛新開 trait，
   而且會動到**所有** API 的時間格式
2. 任務看板的封存剩餘天數是 `new Date(t.updated_at)` 算的 —— 目前的 UTC ISO
   在那裡是**正確**的，後端改成沒有時區標記的字串反而會讓 Safari 解析失敗（`NaN` 天）

前端加一支共用函式，範圍精準、零風險，而且邏輯只有一份。

### 兩種輸入要分開處理

| 後端給的 | 例子 | 怎麼處理 |
|---|---|---|
| 帶時區標記（Model 直接序列化） | `2026-09-07T14:05:31.000000Z` | 轉成台北 |
| 沒有時區標記（Resource 明確 format 過） | `2026-09-30 15:58:20` | **原樣顯示，不能再轉** |

第二種如果也丟給 `new Date()`，會被當成「瀏覽器本地時間」解析 ——
客服在別的時區登入就整個偏掉。所以先用
`/([Zz]|[+-]\d{2}:?\d{2})$/` 判斷有沒有時區標記再決定。

### ⚠ 不要借某個 locale 的預設格式

第一版用了常見的偷懶寫法：

```js
d.toLocaleString('sv-SE', { timeZone: 'Asia/Taipei' })   // 期望 2026-09-07 22:05:31
```

`sv-SE` 的預設格式剛好是 `YYYY-MM-DD HH:mm:ss`，看起來很省事 ——
**但 locale 不一定存在**。容器的 node 12 是 small-icu，只認得 `en-US`：

```
sv-SE + Asia/Taipei → 9/7/2026, 10:05:31 PM     ← locale 被無聲忽略
```

時區換算是對的（10:05 PM = 22:05），但排版變成 en-US。
瀏覽器有完整 ICU 所以實際上不會踩到，但這是不必要的脆弱依賴。

改用 `formatToParts()` 自己組：拿到的是結構化欄位（year / month / hour…），
跟 locale 的排版無關，時區換算照樣正確。

順帶一個 quirk：`hour12: false` 在部分實作會把午夜給成 `24` 而不是 `00`，要自己轉。

## 驗證

12 項在容器的 small-icu node 上實測全過（比瀏覽器嚴苛）：
UTC 轉台北、跨日、午夜不變成 24 點、`+08:00` 偏移、無時區標記原樣顯示、
空值與壞字串不炸。

## 教訓

> ⚠ **前端不要 `substring` API 回傳的時間字串。**
>
> Laravel 的 Model 序列化時間是 **UTC**，即使 `app.timezone` 設成台北。
> 切字串不會換算時區，錯誤也不會浮出來 —— 畫面上就是個看起來很正常、
> 但少 8 小時的時間。
>
> 用 `window.formatDateTime()`。
>
> 同一類的坑：後端如果自己 format（`toDateTimeString()`）就已經是台北時間，
> 再轉一次會多加 8 小時，所以要先判斷有沒有時區標記。

## 順帶發現

`public/js/station.js`（613 行）**沒有任何頁面載入**。

站台管理頁的 JS 全部內嵌在 `resources/views/admin/station/index.blade.php`
的 `@section('scripts')` 裡，是 jQuery 版；而 `station.js` 是另一套
vanilla JS + fetch 的完整實作（自帶 `apiFetch`、`loadStations`、`renderTable`）。

這次仍一併修了它裡面的兩處時間顯示 —— 成本很低，而日後若真的啟用，
不會又把這個 bug 帶回來。但**這個檔案本身該確認是否要刪**。

## 異動檔案

- `public/js/common.js` — 新增 `window.formatDateTime()`
- `public/js/shared-file.js` — 檔案上傳時間
- `public/js/broadcast.js` — 群發 `sent_at`
- `public/js/telegram-chat/ignore-member.js` — 忽略成員 `ignored_at`
- `public/js/station.js` — 同步時間兩處（此檔目前未被載入）

## 相關

- [[shared-file]] — 文件區
- [[broadcast]] — 群發紀錄
- [[ignore-member]] — 忽略成員
- [[station-credit-alert]] — `StationResource` 的 `synced_at` / `credit_alerted_at`
  是用 `toDateTimeString()` 明確 format 的，屬於「不能再轉」那一類
