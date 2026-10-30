# 異動紀錄顯示系統內部的值（ISO 日期、id 數字）

**日期**：2026-10-30（v3.50）
**回報**：「異動紀錄的到期日顯示也不對，他現在會顯示
`2026-10-29T16:00:00.000000Z`」、「異動紀錄的指派人員修改前跟修改後，
只會有數字，這個要是名字」

## 症狀

| 欄位 | 顯示出來 | 應該是 |
|---|---|---|
| 到期日 | `2026-09-03T16:00:00.000000Z` | `2026-09-04` |
| 指派人員 | `6` | `localCS01` |
| 站台 | `3` | `LV` |
| 專案 | `1` | `測試` |

（站台與專案沒有被回報，但是**同一個原因、同一個迴圈**，一起修掉了。）

## 原因：`changes` 直接存 model 的原始屬性

`TaskBoardService::updateTask()` 原本是：

```php
foreach ($params as $key => $value) {
    if (isset($labelMap[$key]) && $task->{$key} != $value) {
        $changes[$labelMap[$key]] = ['from' => $task->{$key}, 'to' => $value];
    }
}
```

`$task->{$key}` 是**原始屬性**，而 `changes` 是 JSON 欄位：

- `due_date` 的 cast 是 `date` → 取出來是 **Carbon**，序列化成 JSON 就是
  ISO-8601 的 **UTC** 字串
- `assignee_ids` 的 cast 是 `array` → 存的就是 **id 陣列**
- `station_id`／`project_id` 本來就是 id

## ⚠ 還有第三個沒人注意到的問題：每次編輯都多一筆假的「到期日」異動

`$task->due_date != $value` 是拿 **Carbon 物件**跟 `'2026-10-30'` 這種
**字串**比。Carbon 轉字串是 `'Y-m-d H:i:s'`，所以
`'2026-10-30 00:00:00' != '2026-10-30'` → **永遠成立**。

> 結果是：只要編輯任務（連標題都好），就會多記一筆
> 「到期日 從 … 改成 …」，而其實根本沒動它。

正規化之後兩邊都是 `'Y-m-d'` 字串，這個假異動自然消失。

## 修法（兩層）

### 第一層：寫入時就存顯示用的值

新增 private `activityValue($key, $value)`，`updateTask()` 的比對與儲存
**都用它的輸出**：

```php
$before = $this->activityValue($key, $task->{$key});
$after = $this->activityValue($key, $value);

if ($before == $after) { continue; }
```

⚠ **為什麼在寫入時翻譯而不是在畫面上翻譯**：這是一份**異動紀錄**——
「當時指派給誰」不應該因為那個人後來改了暱稱、停用或離職就跟著變。
站台與專案同理。而且既有設計本來就把中文欄位標籤存進 `changes` 了，
存顯示值是一致的做法。

⚠ 指派人員**要排序**：不排的話，同一批人只因為勾選順序不同就會被判成
「有變更」，多記一筆假的異動。

⚠ 名字用 `UserRepository::getNamesByIds()` —— 它**不加任何狀態條件**，
所以已停用或離職的人留下的紀錄仍然顯示得出是誰，異動紀錄要的正是這個。
（不要用 `getMentionableByIds()`，那支會排除工程又要求有 telegram_username。）

⚠ 站台／專案查不到就**回原本的 id**，不要回 null —— 那筆資料可能已經被
刪掉了，留 id 至少還查得出是哪一筆，回 null 會變成「從空的改成空的」。

### 第二層：舊紀錄也要能看（`translateLegacyChanges()`）

只修寫入的話，**既有的紀錄全部維持原樣** —— 使用者打開任何一個舊任務，
看到的還是 ISO 字串與數字。所以 `getActivities()` 多一層翻譯。

⚠ **只改記憶體裡的值，不寫回資料庫**：為了好看去改寫歷史資料是另一回事，
而且不可逆。

⚠ **要能重複套用**：新紀錄已經是 `'Y-m-d'` 與名字陣列了，碰到它們必須
原樣放過（靠 `is_numeric()` 區分 id 與名字）。

⚠ 名字**一次批次查完再分配**：逐筆查就是 N+1，而活動紀錄是一次撈全部的。
沒有舊紀錄時 `$ids` 是空的，直接短路、連查詢都不下。

⚠ **舊日期一定要先轉時區再取日期**：舊值是 UTC，
`2026-10-29T16:00:00.000000Z` 其實是台北時間的 **2026-10-30** ——
直接取前 10 個字會**少一天**。所以是
`Carbon::parse($v)->setTimezone(config('app.timezone'))->format('Y-m-d')`。

## 順帶：`FIELD_LABELS` 收成類別常數

原本 `$labelMap` 是方法內的區域變數，但現在**寫入與讀取兩邊都要用**
（讀取那邊要靠標籤去找舊紀錄裡的 `到期日`／`指派人員`）。
各寫一份的話，哪天改了其中一個標籤，舊紀錄的翻譯就會安靜地失效。

⚠ 常數的 value 是**存進資料庫的中文標籤**，改 value 等於改資料格式 ——
既有紀錄的 key 還是舊標籤，翻譯會對不上。

## 驗證

這個 dev 資料庫裡**沒有**到期日／指派人員的異動紀錄（26 筆 `changes`
只有 `狀態`、`附件`、`描述`），所以沒辦法用現成資料重現 ——
改用反射直接呼叫 `activityValue()` 比對修前修後：

```
due_date     舊: "2026-09-03T16:00:00.000000Z"  新: "2026-09-04"
assignee_ids 舊: [6]                            新: ["localCS01"]
station_id   舊: 3                              新: "LV"
project_id   舊: 1                              新: "測試"
```

## 還沒處理的事

⚠ 前端 `renderActivities()` 把 `cr.from`／`cr.to` **直接串進 HTML**。
標題與描述的異動會原樣注入 —— 這一頁已經有 `escapeHtml()` 可用
（留言那邊就是為了同樣的問題才改的）。這次沒動，是獨立的題目。

## 相關檔案

| 檔案 | 說明 |
|---|---|
| `app/Services/TaskBoardService.php` | `FIELD_LABELS` 常數；`updateTask()` 改用正規化後的值比對與儲存；新增 `activityValue()`／`assigneeNames()`／`relatedName()`／`translateLegacyChanges()`／`mapBothSides()`／`legacyAssigneeNameMap()`／`legacyAssigneeSide()`／`legacyDate()` |
