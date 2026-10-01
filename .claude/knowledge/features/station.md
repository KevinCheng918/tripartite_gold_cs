# 站台管理

> 文件待補完 —— 目前只記錄列表篩選與狀態定義，站台新增／編輯、同步、
> Bot 群組偵測等細節尚未整理。

`resources/views/admin/station/index.blade.php`。這一頁有三個 tab：
站台列表、補點紀錄（見 [[station-topup]]）、申請紀錄。

## 狀態（`station.status`）

定義在 `config/constants.php` 的 `STATION.STATUS`：

| 常數 | 值 |
|---|---|
| `ACTIVE`（正常） | 1 |
| `FROZEN`（凍結） | 2 |
| `DISABLED`（停用） | 0 |

⚠ 停用是 **0** 不是 2 —— 跟一般「0=關閉、1=開啟、2=其他」的直覺不同，
凍結才是 2。寫篩選或判斷時容易搞反。

## 列表預設只看「正常」的站台

篩選是**後端渲染的 GET 表單**（`$filters`），不是前端 JS 篩的。
所以預設值要放 `StationController::index()`，不能只在 blade 寫 `selected` ——
那樣會變成「下拉顯示正常、列表卻是全部」。

```php
if (!$request->has('status')) {
    $params['status'] = (string) config('constants.STATION.STATUS.ACTIVE');
}
```

### 為什麼是 `has()` 而不是 `filled()`

要分的是「**沒帶這個參數**」與「**帶了空值**」：

| 進入方式 | query string | `has('status')` | 結果 |
|---|---|---|---|
| 第一次進頁面 | 無 | false | 預設正常 |
| 按「重置」 | 無（連到無參數網址） | false | 預設正常 |
| 統計卡連結 | `?system_id=2` | false | 該系統的正常站台 |
| 主動選「全部」 | `?status=` | **true** | **保持全部，不被覆蓋** |
| 主動選「凍結」 | `?status=2` | true | 凍結 |

用 `filled()` 的話，「主動選全部」送出的空字串會被判成沒填而套上預設值 ——
使用者就再也選不到「全部」了。

`$request->only()` 不會帶入沒出現在 query string 的 key，所以這兩種情況
在 `$params` 裡本來就分得開。

### 型別要是字串

blade 的 selected 判斷是 `($filters['status'] ?? '') === '1'`（嚴格比較），
所以塞進 `$params` 的必須是**字串** `'1'`，不是 config 回傳的 int `1`。
不然列表篩對了、下拉卻顯示「全部」。

> 💡 這個模式跟 [[staff-manage]] 的排序預設值是同一類問題，但解法相反：
> 那邊的篩選在前端，所以預設值放 HTML 的 `selected`，而「重設」按鈕要
> 讀回那個 `selected`；這邊的篩選在後端，預設值就必須放 Controller。
>
> **判斷準則：預設值要放在「真正決定結果」的那一層。**

## 異動檔案

- `app/Http/Controllers/Admin/StationController.php` — `index()` 的 status 預設值

## 相關

- [[station-topup]] — 補點紀錄 tab
- [[station-credit-alert]] — 餘點告警（只掃 `status=1` 的站台）
- [[staff-manage]] — 預設值放哪一層的對照組
