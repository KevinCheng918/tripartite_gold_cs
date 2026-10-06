# 改完卡片後，看板上的指派人變成「未指派」

## 現象

任務看板點進一張卡片、改任何一個欄位（標題、狀態、負責人…），
關掉面板後**看板上那張卡的指派人變成「未指派」**。重新整理就恢復。

## 起因

`TaskResource` 用一份 **static 快取**把 `assignee_ids` 轉成名字：

```php
foreach ($assigneeIds as $id) {
    if (isset(self::$userCache[$id])) {   // ← 沒命中就「跳過」
        $assignees[] = ['id' => $id, 'nickname' => self::$userCache[$id]];
    }
}
```

快取由 `preloadUsers()` 整批填（避免 N+1），而**四個回傳點只有三個呼叫它**：

| 方法 | 有沒有 preload |
|---|---|
| `index()` | ✅ |
| `ajaxTaskDetail()` | ✅ |
| `ajaxArchivedList()` | ✅ |
| **`ajaxUpdateTask()`** | ❌ |

所以更新後回傳的那筆 `assignees` 是**空陣列**，前端照著畫就是「未指派」。
重新整理走的是 `index()`（有 preload），所以又正常了。

⚠ **沒有任何錯誤訊息** —— 「跳過」是正常的程式流程，只是結果少了東西。

## 修法

**在 `toArray()` 裡自己呼叫 `preloadUsers()`**，不去補那一支：

```php
$assigneeIds = (array) $this->assignee_ids;

self::preloadUsers($assigneeIds);   // ← 加這行
```

理由是「日後任何新的單筆回傳點都不必記得」。補 `ajaxUpdateTask()` 只修掉
這一次，下一個寫單筆回傳的人會再踩一次。

**不會退化成 N+1**：`preloadUsers()` 用 `array_diff` 跳掉已經在快取裡的，
所以列表那條路徑（已經整批預載）一次都不會多查 —— 驗證實測連續轉換 5 筆、
額外查詢 0 次。

> 呼叫端的批次 `preloadUsers()` **保留**：那是「一次查完所有人」，
> 跟這裡的「確保自己要的那幾個在快取裡」是兩件事。

## 教訓

> ⚠️ **「找不到就跳過」是會靜默掉資料的寫法。**
>
> 這類快取查表至少要在找不到時留一條路：自己補查（這次的做法）、
> 或至少記一筆 log。什麼都不做的話，錯誤會以「欄位變空」的形式出現，
> 而空值在畫面上長得就像「本來就沒有」。
>
> 同一類問題在這個專案出現過好幾次（關聯 select 漏欄位、config 快取沒更新
> 導致設定讀成 null）—— 共通點都是**缺資料時沒有任何聲音**。

## 異動檔案

- `app/Http/Resources/TaskResource.php` — `toArray()` 開頭自己 preload

## 相關

- [[task-board]] — 任務看板本體
