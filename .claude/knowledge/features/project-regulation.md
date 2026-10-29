# 系統規章管理（設計稿）

> 需求（2026-10-30）：「我想要做一個系統規章管理的功能，做在專案管理這邊，
> 那每個專案不限定只有一個規章，可以有多個，那要有規章所屬專案、規章標題、
> 規章內容，我在小幫手這邊要可以取得規章，例如：三方規章、跑分員規章、
> 商城規章」

⚠ **這份還是設計稿，尚未實作**。下面標「待確認」的幾題請需求方先裁示。

## 資料表 `project_regulation`

| 欄位 | 說明 |
|---|---|
| `project_id` | 所屬專案（外鍵到 `project`）|
| `title` | 規章標題（例如「三方規章」）|
| `content` | 規章內容（`longtext`）|
| `status` | 啟用／停用 |
| `sort` | 同一個專案底下的排序 |
| `created_by` / `updated_by` | 誰建的、誰最後改的 |

⚠ **一個專案多筆**（需求方明講），所以是 `project` 一對多，不是在
`project` 表上加欄位。

⚠ `content` 用 `longtext` 不是 `text`：`text` 上限 65,535 **位元組**，
中文一個字佔 3 個位元組 —— 實際只放得下約 2 萬字。規章這種東西
一不小心就超過，而超過時 MySQL 非 strict mode 會**安靜截斷**。

## 後台：放在每個專案的分頁裡

專案管理頁現在就是**一個專案一個分頁**，每個分頁顯示該專案的資訊 ——
規章放進去最自然，不必另開一頁。

每個分頁底下加一區「系統規章」：列出該專案的規章（標題 + 更新時間），
可以新增／編輯／停用。內容用 textarea（不做富文本 —— 小幫手送出去的是
純文字，後台存 HTML 只會讓兩邊對不起來）。

權限沿用既有的 `project.view` / `project.edit`。

## 小幫手取規章

排在分派器的某一棒，解析器判斷「這是不是在要規章、要哪一份」。

### ⚠ 待確認 1：怎麼對到那一份規章？

需求方給的例子（三方規章／跑分員規章／商城規章）看起來都是**標題**，
不是專案名。但同一個標題可能出現在不同專案底下。

| 做法 | 行為 |
|---|---|
| A. 只對標題（建議） | 「給我三方規章」→ 找標題像「三方規章」的。**撞名時列出來問是哪一個專案的** |
| B. 一定要講專案 | 「三方專案的三方規章」才找得到 —— 多打一串，但不會有歧義 |
| C. 標題優先、撞名才問專案 | ＝ A 加上一層 fallback |

⚠ 無論哪一種，**找不到時不要猜**。這是規章 ——
回一份錯的比回「找不到」糟得多。找不到就列出有哪些規章可以問。

### ⚠ 待確認 2：內容超過 Telegram 單則上限怎麼辦？

Telegram 單則 **4096 字，超過整則送不出去**（不是截斷）。
規章很可能超過。

| 做法 | 代價 |
|---|---|
| A. 自動分段連發（建議） | 群組裡會連刷好幾則 |
| B. 截斷 + 「完整內容請見後台」 | 要的人還是看不到完整的 |
| C. 存成檔案當附件送 | 手機上要再點開，而且沒辦法直接複製貼給客戶 |

⚠ 偏好 A：規章的用途多半是**整段貼給客戶或同仁看**，截斷等於沒用。
分段時要在每則標「(1/3)」，不然看的人不知道還有沒有下文。

### ⚠ 待確認 3：要不要獨立權限？

其他小幫手指令都有自己的 keyword。規章是**內部規則**，不含客戶資料、
不會動到任何東西 —— 但它會被整段貼到群組裡。

| 做法 | |
|---|---|
| A. 新增 `telegram_chat.regulation`（建議） | 跟其他指令一致，而且規章內容不見得適合所有人轉貼 |
| B. 沿用 `project.view` | 後台看得到規章的人就能用小幫手取 |
| C. 不驗權限 | 內部群組本來就只有自己人 |

## 會改到的檔案（預估）

| 檔案 | 說明 |
|---|---|
| `database/migrations/*_create_project_regulation_table.php` | **新增** |
| `app/Models/ProjectRegulation.php` | **新增** |
| `app/Repositories/ProjectRegulationRepository.php` | **新增** |
| `app/Services/ProjectRegulationService.php` | **新增**（後台的增修查）|
| `app/Services/RegulationCommandService.php` | **新增**（小幫手那一棒）|
| `app/Services/AutoReply/RegulationCommandParser.php` | **新增** |
| `app/Services/TelegramCommandRouter.php` | 多一棒 |
| `app/Http/Controllers/Admin/ProjectController.php`／`routes/web.php` | 規章的 ajax 路由 |
| `app/Http/Requests/Project/*` | 新增／更新的驗證 |
| `app/Http/Resources/ProjectRegulationResource.php` | **新增** |
| `resources/views/admin/project/index.blade.php`／`public/js/*` | 規章區塊 |
| `config/permissionMap.php` | 新權限（看待確認 3）|
| `config/constants.php`／`config/rules.php` | 文案、字數上限 |
| `resources/lang/{tw,cn,en}/*` | 三語系 |
| `config/changelog.php` | 版本紀錄 |

## 待釐清（請需求方裁示）

1. **怎麼對到那一份規章？**（只對標題／一定要講專案／標題優先撞名才問）
2. **內容超過 4096 字怎麼送？**（分段連發／截斷／檔案）
3. **要不要獨立權限？**
4. 規章改動要不要留修改紀錄（誰在什麼時候改了什麼）？——
   規章是會被拿來當依據的東西，但做版本比對是另一個題目，先問要不要。
