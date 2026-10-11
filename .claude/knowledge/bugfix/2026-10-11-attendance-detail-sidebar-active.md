# 出勤明細頁的側邊選單亮在「打卡出勤」

## 現象

「出勤明細 / 個人每日出勤狀況」這一頁已經搬到「報表 → 打卡報表」底下
（唯一的入口是報表列點下去，頁面左上的返回鍵也是回報表），
但進到這一頁時，側邊選單亮的仍然是**打卡出勤**，不是**報表**。

## 起因

頁面搬了，**路由名稱沒搬**：

```php
// routes/web.php
Route::prefix('attendance')->name('attendance.')->group(function () {
    …
    Route::get('/detail/{userId}', [AttendanceController::class, 'detail'])
        ->middleware('can:attendance.report')->name('detail');   // = admin.attendance.detail
});
```

而側邊選單兩個項目的判斷是按路由名稱前綴比對的：

```blade
{{-- 打卡出勤 --}}  request()->routeIs('admin.attendance.*')
{{-- 報表 --}}      request()->routeIs('admin.report.*')
```

`admin.attendance.detail` 命中的是前者，所以亮在打卡出勤。

## 修法

只改側邊選單的判斷，**不搬路由**：

- 打卡出勤：`routeIs('admin.attendance.*') && ! routeIs('admin.attendance.detail')`
- 報表：`routeIs('admin.report.*', 'admin.attendance.detail')`（`routeIs()` 吃多個參數）

> **為什麼不乾脆把 route 搬到 `report.` 群組底下。**
> 這一頁的權限是刻意留在 `attendance.report` 的（見 `config/permissionMap.php`
> 的註解：只勾 `report.attendance` 的人看得到彙總但點不進明細）。
> 把路由名搬過去，權限 keyword 就會跟路由群組對不起來，
> 而換來的只是「名字好看」—— 還得連帶改 `public/js/report.js` 裡寫死的
> `/admin/attendance/detail/` 與使用者既有的連結。

## 教訓

> ⚠️ **把一頁搬到另一個選單底下時，`routeIs()` 的前綴不會跟著搬。**
> 畫面上的入口改了、返回鍵改了、權限註解也寫了，就是沒人會想到側邊選單
> 是用路由名稱認人的 —— 這個專案的每一個選單項目都是 `routeIs('xxx.*')`，
> 只要頁面的歸屬跟路由前綴不一致，就會亮錯。
>
> 判斷方式：**問「這一頁是從哪個選單點進去的、返回鍵回哪裡」**，
> 答案就是該亮的那一個，跟它的 URL 長什麼樣無關。

## 異動檔案

- `resources/views/layouts/app.blade.php` — 打卡出勤、報表兩個選單項目的 `mm-active` 判斷

## 相關

- [[attendance]] — 打卡出勤
- [[report-page]] — 報表頁（打卡報表 / 超時提醒統計）
