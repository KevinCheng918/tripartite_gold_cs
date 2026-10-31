# 補點／扣點申請點兩次就送出兩筆

**日期**：2026-10-31（v3.53）
**回報**：「我申請補點/扣點的彈窗，要有防連點機制，我發現我點兩次就變成申請兩筆」

## 原因：完全沒有鎖

`#form-topup-apply` 的 submit handler 直接發 ajax，**從按下去到伺服器回應
之間那顆鈕一直是可以按的**。

而這支申請會動到客戶的點數 —— `StationController::ajaxTopupStore()` 旁邊的
註解本來就寫著「重複的補點要是被誤審，那是真的會動到客戶的點數」。

## 修法：旗標 ＋ 鎖按鈕 ＋ 給回饋

```js
var topupSubmitting = false;

function lockTopupSubmit(locked) {
    topupSubmitting = locked;
    $('#btn-topup-submit').prop('disabled', locked).html(locked ? 轉圈 + '送出中⋯' : '送出申請');
}
```

### ⚠ 四個容易寫錯的地方

**1. 不能只靠把按鈕 `disabled`。** 在輸入框裡按 Enter 也會觸發 form 的
submit，那條路不經過按鈕。所以 handler 開頭要有
`if (topupSubmitting) { return; }` —— **旗標才是真正守門的那一道**，
`disabled` 只是讓使用者看得出來。

**2. 上鎖要擺在所有驗證都過了之後。** 擺在 handler 開頭的話，欄位沒填好被
擋下來（那幾個 `showMessage` ＋ `return`）就會把按鈕鎖死 ——
使用者改完也送不出去，變成比原本更糟的 bug。

**3. 一定要能解鎖，而且要在 `complete` 不是 `success`。**
這個 modal 是重複使用的（下次申請還是開同一個），只在失敗時解鎖的話，
**成功送出一次之後那顆鈕就永遠按不下去** —— 使用者得重新整理整頁才能申請
第二筆。這是加防連點時最常見的新 bug。

**4. 開啟 modal 時再保險解鎖一次。** 萬一有哪條路徑讓 `complete` 沒跑到
（例如請求還在路上使用者就關掉 modal、之後再開），按鈕會一直停在轉圈狀態。

### ⚠ 給回饋跟鎖住一樣重要

使用者會連點**正是因為按下去之後畫面完全沒有反應**。只鎖按鈕擋得住第二次
點擊，但他還是會想點；加上轉圈與「送出中⋯」才是真正解決焦慮的那一半。

## 明確沒做：伺服器端的防護（需求方 2026-10-31 裁示先不用）

前端這一層只擋得住「同一次送出被點兩下」。擋不住的情況：

- 開兩個分頁各送一次
- 網路層重試（瀏覽器／proxy）
- 按上上頁回到表單再送一次

要擋這些得在後端做。當時評估過兩個方案：

| 方案 | 代價 |
|---|---|
| **送出識別碼（idempotency token）** | 打開彈窗時產一組亂數跟著送，`credit_topup` 加一欄 ＋ unique 索引。**沒有誤擋**（重開彈窗＝新 token），但要 migration |
| 短時間重複檢查（同站台＋同類型＋同金額＋同一人，N 秒內） | 不用 migration，但**會誤擋**真的要連續申請兩筆同額的正常情況 |

⚠ 真要補的話選第一個 —— 第二個那種「看起來很像重複」的判斷，
在金額操作上誤擋的代價跟漏擋一樣高。

## 順帶記一筆：`station` 語系檔本來就缺 9 個 key

`resources/lang/{cn,en}/station.php` 比 tw 少 9 個（`field_system`、
`field_api_url`、`field_api_key`、`field_rate`、`field_features`、
`field_settings`、`action_sync`、`action_sync_all`、`action_sync_all_done`）。

這次新增的兩個 key 三語系都補齊了，**落差沒有變大**，但那 9 個仍然缺著 ——
是獨立的一次整理。

## 相關檔案

| 檔案 | 說明 |
|---|---|
| `resources/views/admin/station/index.blade.php` | 送出鈕加 `id="btn-topup-submit"` 並改用語系；`topupSubmitting` 旗標、`lockTopupSubmit()`、handler 開頭的守門、`complete` 解鎖、開 modal 時保險解鎖 |
| `resources/lang/{tw,cn,en}/station.php` | `topup_submit`／`topup_submitting` |
| `config/changelog.php` | v3.53 |
