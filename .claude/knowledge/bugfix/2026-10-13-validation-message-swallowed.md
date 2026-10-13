# 驗證失敗只看到「輸入資料無效」，看不到真正的原因

## 現象

在 Telegram 對話視窗傳 `.exe`，畫面顯示 **input data is invalid**
（或中文語系的「輸入資料無效」）。

那個檔案確實被擋了 —— `.exe` 在 `rules.UPLOAD_BLOCKED_EXTENSIONS` 裡，
而後端回的訊息是「**不支援此檔案類型**」。但使用者看不到那一句。

## 起因：422 的兩個訊息欄位拿錯了一個

Laravel 的驗證失敗回應長這樣：

```jsonc
{
  "message": "The given data was invalid.",   // 通用字串，說不出哪裡不對
  "errors": {
    "file": ["不支援此檔案類型"]               // ← 真正的原因在這裡
  }
}
```

前端的 `errorMessage()` 直接讀 `error.message`，於是永遠顯示那句通用字串。
自訂的驗證訊息寫得再清楚也送不到使用者眼前。

## ⚠ 這是第三次了

| 時間 | 位置 | 症狀 |
|---|---|---|
| 2026-10-11 | `public/js/report.js` | 「標記為已處理」失敗時看不到原因 |
| 2026-10-12 | `public/js/followup.js` | 同上（追蹤／結案） |
| 2026-10-13 | `public/js/telegram-chat/input.js` | 傳 .exe 看到「輸入資料無效」 |

三支都是各自寫一份 `errorMessage()` / `errorText()`，而且都只讀 `message`。

> ⚠️ **寫前端的錯誤處理時，`errors` 一定要優先於 `message`。**
> 後端花力氣寫的自訂訊息（`messages()` 裡那些）**全部都在 `errors` 裡** ——
> 只讀 `message` 等於把它們整批丟掉，而且不會有任何跡象：
> 畫面上有訊息、只是那句話沒有資訊。
>
> 判斷順序永遠是：`errors` 的第一句 → `message` → 自己的 fallback。

## 修法

```js
var errors = error.errors;

if (errors) {
    var first = Object.keys(errors)[0];

    if (first && errors[first] && errors[first].length) {
        return errors[first][0];
    }
}

if (error.message) {
    return error.message;
}
```

## 順帶：`.exe` 是刻意擋的，不是壞掉

`UPLOAD_BLOCKED_EXTENSIONS`（`config/rules.php`）擋掉可執行與腳本類副檔名，
理由寫在 `BlocksExecutableUploads`：**上傳目的地在 `storage/app/public` 底下、
對外可直接存取**，讓這類檔案進來等於開了一條執行任意程式碼的路。

任務看板附件、Telegram 傳檔、群發公告附件共用同一份清單。

> 要傳可執行檔給客戶，現成的做法是**壓成 zip**（`zip` 不在黑名單裡）。
> 真的需要直接傳 `.exe` 的話，得先把儲存位置搬離 public，不是單純把它
> 從清單裡拿掉。

## 異動檔案

- `public/js/telegram-chat/input.js` — `errorMessage()` 改成先讀 `errors`

## 相關

- [[telegram-chat]] — 客服對話視窗的傳檔
