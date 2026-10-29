# 系統規章管理

> 需求（2026-10-30）：「我想要做一個系統規章管理的功能，做在專案管理這邊，
> 那每個專案不限定只有一個規章，可以有多個，那要有規章所屬專案、規章標題、
> 規章內容，我在小幫手這邊要可以取得規章，例如：三方規章、跑分員規章、
> 商城規章」
>
> 四題裁示：**對標題、撞名才問專案**／**自動分段連發**／
> **新增 `telegram_chat.regulation`**／**只記「誰最後改的」不做版本紀錄**。

## ⚠ 做的時候踩到兩個既有的洞（都已修）

功能本身是新的，但掛上去時發現兩件原本就壞著的事：

1. **專案管理完全沒照「參與專案」過濾** —— 只要勾了 `project.view`
   就看得到全部專案。規章掛在專案分頁裡，不修的話規章會跟著外洩。見下一節。
2. **小幫手所有指令的權限檢查被繞過**（`null == 0`）——
   驗「下指令的人有沒有權限」時發現的，見
   [[2026-10-30-telegram-command-permission-bypass]]。

第 2 個是這次最重要的產出：**為了新功能去讀既有的那一段，才看見它壞了。**

## ⚠ 可見範圍：參與專案，而且 API 也要擋

需求方問的就是這個：「我人員這邊有開啟檢視專案功能，但是如果沒有開參與
專案的話，一樣要看不到專案。」查下去發現**本來不是這個行為**。

| 身份 | 看得到 |
|---|---|
| 管理者 | 全部 |
| 有勾參與專案 | 只有勾到的那幾個 |
| **沒勾任何參與專案** | **一個都沒有** |

規則抄任務看板既有的那一套（`TaskBoardController::index()`）。

⚠ **判準只有一份**：`User::canAccessProject()`。組清單的
`ProjectRepository::getVisibleTo()` 與擋單筆 API 的 `canTouchProject()`
兩邊都問它 —— 第一版各寫了一次 `in_array(… project_ids …)`，
那是同一條規則的兩份實作，分岔的那一邊就是資料外洩。

⚠ `project_ids` 是 JSON 欄位，裡面**可能是字串**（`["1","3"]`）——
`User::projectIdList()` 統一 `intval` 正規化。不正規化而用
`in_array(…, true)` 嚴格比對的話會全部對不上，
結果是「明明勾了卻還是看不到」。

⚠ **最後那一列是重點**：沒勾等於空集合，不是「不限制」。
寫成「沒勾就看全部」的話，新帳號一建立就看得到所有專案的規章。

⚠ **只擋畫面是擋不住的。** 規章的網址只有一個 id，沒參與那個專案的人照樣
打得到 —— 所以四支規章 API **每一支都再過一次** `canTouchProject()`，
連唯讀的「看內容」也要。`ajax-list` 也換成同一套過濾。

⚠ 「沒參與這個專案」與「沒有權限」的文案要分得開：前者去找管理者加參與
專案，後者去要權限。回同一句的話，被擋的人會去要錯東西。

## 資料表 `project_regulation`

| 欄位 | 說明 |
|---|---|
| `project_id` | 所屬專案 |
| `title` | 規章標題（例如「三方規章」）|
| `content` | 規章內容（`longtext`）|
| `status` | 啟用／停用 |
| `sort` | 同一個專案底下的排序 |
| `created_by` / `updated_by` | 誰建的、誰最後改的 |

⚠ **一個專案多筆**（需求方明講），所以是一對多，不是在 `project` 表上加欄位。

⚠ `content` 用 **`longtext` 不是 `text`**：`text` 上限 65,535 **位元組**，
中文一個字佔 3 個 —— 實際只放得下約 2 萬字。規章一不小心就超過，
而超過時 MySQL 非 strict mode 會**安靜截斷**：存檔看起來成功，
後面少一段卻沒有人會發現。

⚠ 另外在 `config/rules.php` 擋 5 萬字（`REGULATION_CONTENT_MAX`）——
不是怕欄位放不下，是**不讓人把一整本手冊貼進來**：小幫手要把它分段發到
Telegram，20 萬字會變成 50 則訊息。

⚠ 只記「誰最後改的、什麼時候」，**不做版本比對**（需求方決定）。
規章是會被拿來當依據的東西，至少要答得出「這版是誰改的」。

## 後台：放在每個專案的分頁裡

專案管理頁本來就是一個專案一個分頁，規章放進去最自然，不必另開一頁。
權限沿用既有的 `project.view` / `project.edit`。

⚠ **列表不帶 `content`**（`LIST_COLUMNS` 根本沒撈它）——
規章可能上萬字，一次輸出十幾份會把頁面撐爆。點「看內容」或「編輯」
才去撈全文。

⚠ 規章**一次撈完**（`getByProjects()`），不要在 Blade 裡逐專案查 —— 那是 N+1。

⚠ 看內容的 modal 用 `text()` 不是 `html()`：規章是使用者輸入的，
塞進 HTML 就是 XSS。而且要 `white-space: pre-wrap` 保留換行 ——
規章是條列式的，壓成一段就看不懂了。

⚠ **更新不給改 `project_id`**（`UpdateRegulationRequest` 根本沒有這一欄）：
改掛到別的專案等於換了一份文件的歸屬，而小幫手是用「標題＋專案」回答的
—— 改錯會讓人拿到別個專案的規章。要搬就刪了重建。

⚠ 內容用 textarea、不做富文本 —— 小幫手送出去的是純文字，
後台存 HTML 只會讓兩邊對不起來。

## 小幫手取規章

排在分派器第九棒（spider_setting 之後、預約提醒之前）。
誰先誰後其實都對得起來，排後面是因為它比較少被用到。

⚠ **找不到就不猜，列出有哪些規章可以問**。這是規章 ——
回一份錯的比回「找不到」糟得多。

⚠ **撞名時列出來問是哪一個專案**，不可以挑第一筆。不同專案底下可以有同名
規章，挑錯的後果是有人拿著別個專案的規則去對客戶。有講專案時才先收斂。

⚠ 標題用 **`LIKE` 不是完全相等**：使用者講「三方規章」時，後台可能存成
「三方規章（2026 版）」。完全相等會變成「後台有、小幫手找不到」。

⚠ 解析器的 `project` 欄位**只有使用者明講時才填**，prompt 裡明寫
「不要從規章名字去猜專案」—— 「三方規章」不保證就在「三方」專案底下。

### ⚠ 分段：Telegram 單則 4096 字，超過整則送不出去

不是截斷，是**整則發不出去**。所以：

- `CHUNK_SIZE` 抓 **3500**，留餘裕給標題那幾行、頁碼，以及 HTML 標籤
- **盡量從換行處切**，不要切在句子中間（規章是條列式的，從行尾切幾乎都乾淨）
- 切點太靠前（不到一半）就硬切，不然會切出一則很短的
- 用 **`mb_substr` 不是 `substr`** —— 中文一個字三個位元組，
  用位元組切會把字切成亂碼
- 每則標 `（1/3）`，**只有一則時不標**（那是噪音）
- 標題只掛第一則

## 新增/修改的檔案

| 檔案 | 說明 |
|---|---|
| `database/migrations/2026_10_30_000001_create_project_regulation_table.php` | **新增** |
| `app/Models/ProjectRegulation.php` | **新增** |
| `app/Repositories/ProjectRegulationRepository.php` | **新增** |
| `app/Services/ProjectRegulationService.php` | **新增**（後台的增修查）|
| `app/Services/RegulationCommandService.php` | **新增**（小幫手那一棒，含分段）|
| `app/Services/AutoReply/RegulationCommandParser.php` | **新增** |
| `app/Http/Resources/ProjectRegulationResource.php` | **新增** |
| `app/Http/Requests/Project/{Store,Update}RegulationRequest.php` | **新增** |
| `app/Services/TelegramCommandRouter.php` | 多一棒 |
| `app/Http/Controllers/Admin/ProjectController.php`／`routes/web.php` | 四支 ajax ＋ `canTouchProject()` |
| `app/Repositories/ProjectRepository.php` | **新增 `getVisibleTo()`**（參與專案過濾）|
| `app/Models/User.php` | **新增 `canAccessProject()` / `projectIdList()`**（唯一判準）|
| `config/permissionMap.php` | `telegram_chat.regulation` |
| `config/constants.php`／`config/rules.php` | 文案、字數上限、`CHUNK_SIZE` |
| `resources/views/admin/project/index.blade.php` | 規章區塊 ＋ 兩個 modal |
| `resources/lang/{tw,cn,en}/project.php` | **新增**（這個頁面原本沒有語系檔）|
| `resources/lang/{tw,cn,en}/permission.php` | 新權限的說明 |

## 驗證

**20 種情境**（解析器／Repository／UserRepository／支援群組全部假物件，
不碰 DB 也不發訊息）：

- 不是在要規章 → 回 `false` 讓下一棒；模型不可用 → 回一句說明
- 沒綁系統帳號、沒權限各自擋下來
- 找到一份：只發一則、有標題與專案、內容原樣、**只有一則時不標頁碼**
- **找不到：不猜，列出有哪些**
- **撞名：不挑，問是哪個專案，而且不會把內容發出去**
- 有講專案時收斂到那一份
- 沒講哪一份 → 列出有哪些
- 分段（實測 9,892 字）：分成多則、**每則都在 4096 內**、有頁碼、
  只有第一則有標題、**分段之後內容沒有掉字**
- 切點落在換行（不切在句子中間）、中文沒有被切壞

**參與專案的判斷 12 種情境**：管理者沒勾也碰得到、有勾的碰得到勾到的、碰不到沒勾的、**`project_ids` 是字串也對得上**、空陣列與 null 都是「什麼都碰不到」、**沒 select `level` 的半套 Model 不會被當成管理者**、傳字串 id 也對得上。

另外確認：容器解得開五個新類別、四支路由都掛上、`permissionMap` 有新 keyword、
三語系 `project.php` 28 個 key 完全對齊、blade 內嵌 JS 通過 `node --check`。

⚠ **沒有真的在 Telegram 上取過規章**，也沒有在後台新增過規章
（本機沒有專案資料）。

⚠ **migration 還沒跑**（依專案規則不自己動 DB）。

## 待釐清

- 規章沒有「複製到其他專案」的功能。要把同一份規章給兩個專案用，
  目前只能建兩筆。
- 分段是**連發**，群組裡會連刷好幾則。規章真的很長（超過 3 萬字）時
  可能需要改成「給連結」，但那要先有一個可以公開看的頁面。
