# 任務描述的圖片／檔案改成 Notion 式內嵌

> 需求（2026-10-30）：「任務看板這邊的任務描述，我要像 Notion 一樣，
> 我圖片、檔案插入就是在那一行，不要都集中在底下，反而不好閱讀。」

**狀態：已實作（v3.50，2026-10-30）。五題全部採用建議選項 (a)。**

| 題目 | 裁示 |
|---|---|
| 附件區 | **保留為唯讀彙總**，只拔上傳入口 —— 舊任務的檔案還看得到、還能刪 |
| 新增視窗 | **也拔掉「附件」欄位**，一律走描述 |
| 孤兒檔案 | **不管它**（Notion 也是這樣）|
| 留言 | **這次不動**（還是排在留言底下）|
| 貼上 | **只做拖放**，貼上只保證圖片 |

## ⚠ 關鍵發現：想要的功能**已經有了**，只是被另一個入口蓋住

任務看板現在有**兩條**放檔案的路，而它們的結果完全不同：

| 路徑 | 入口 | 檔案出現在哪 |
|---|---|---|
| **A. 描述編輯器內嵌** | TinyMCE 工具列的圖片鈕、📎 鈕，以及**貼上／拖曳圖片** | **游標那一行**（就是需求要的） |
| **B. 附件欄位** | 新增視窗標著「附件」的 file input；詳情面板描述底下的 file input | **描述底下**一整格縮圖／檔案卡片 |

路徑 A 其實**已經是 Notion 式的**了 —— `getTinyConfig()` 裡
`paste_data_images: true` ＋ `automatic_uploads: true` ＋
`images_upload_handler`，貼上或拖一張圖進描述就會上傳並插在游標處；
工具列的 📎（`tbfile`）則是 `uploadEditorFile()`，在游標處插
`<p><a>📎 檔名</a></p>`。CSS 也早就備好了（`task-content.css` 的
`.tb-content img` 與 `.mce-content-body img` 都有 `max-width: 100%`）。

**所以這個需求不是「做一個新功能」，是「把兩條路收成一條」。**

⚠ 為什麼使用者會走到 B：

1. **B 的入口顯眼得多** —— 它是一個標著「附件」的表單欄位；
   A 的 📎 只有一個圖示加 tooltip，藏在 `toolbar_mode: 'sliding'` 的工具列裡
2. **非圖片檔案在 A 只能用工具列那顆鈕** —— TinyMCE 的
   `automatic_uploads` 只接圖片 blob，拖一個 PDF 進描述不會有任何反應，
   試過一次沒用就會回去找「附件」欄位
3. 兩條並存時，沒有任何提示說「這兩個放進去的位置不一樣」

## 現況的資料結構

```
task.description  longtext  ← 路徑 A 的檔案在這裡面，就是 HTML 裡的 <img>／<a>
task.images       JSON      ← 路徑 B 的檔案在這裡，一個路徑字串陣列
```

路徑 B 這一格目前還掛著幾件事，**拔掉之前要先想清楚**：

| 功能 | 在哪 | 拔掉 B 之後 |
|---|---|---|
| 附件刪除（含實體檔案 ＋ 活動紀錄）| `TaskBoardService::deleteAttachment()`、`ajax-delete-attachment` | 內嵌的檔案**沒有這一套** |
| 「這個任務有哪些檔案」一眼看完 | 描述底下那一格 | 散在描述各處，要自己滑 |
| 驗證規則（20MB ＋ 26 種副檔名黑名單）| `HasAttachmentRules`，**四處共用** | 路徑 A 的兩支上傳端點本來就吃同一份，不受影響 ✅ |

⚠ **`images` 欄位名稱是舊名，實際收各類檔案**（見 task-board.md），
不要被名字誤導成「只有圖片」。

## ⚠ 內嵌之後最大的新問題：孤兒檔案

路徑 B 刪一個附件，`deleteAttachment()` 會同時移掉 `images` 的參照
**與實體檔案**。

路徑 A 的內嵌圖片／連結，使用者在編輯器裡按 delete 只是把 HTML 裡那段拿掉
—— `storage/app/public` 底下那個實體檔案**會永遠留著**，而且沒有任何紀錄
知道它還在。

這不是「要不要修」的問題，是「打算怎麼辦」的問題。三種做法：

| 做法 | 代價 |
|---|---|
| **(a) 不管它**（Notion 也是這樣：刪掉的圖還在它的 CDN 上）| 磁碟長期只增不減 |
| (b) 存檔時比對前後兩份 HTML，少掉的就刪實體檔 | ⚠ **危險**：同一個檔案的 URL 可能被貼到別的任務或留言裡，刪了那邊就破圖 |
| (c) 做一支排程，掃全部 description／images／留言都沒參照到的檔案才刪 | 要寫一支清理指令，而且掃錯一次就是刪掉使用者的檔案 |

⚠ 傾向 **(a)**：這個系統的附件是客服工作紀錄，誤刪的代價遠高於磁碟成本。
真要清理也該是 (c) 而且單獨一個題目，不混在這次。

## 建議做法

**路徑 A 升級成唯一入口，路徑 B 的上傳入口收掉、顯示降級成唯讀彙總。**

### 1. 讓任何檔案都能拖放／貼上進描述

TinyMCE 自己只處理圖片，所以要在 `setup` 裡攔 `drop` 與 `paste`：

```js
editor.on('drop', function (e) {
    var files = e.dataTransfer && e.dataTransfer.files;
    if (!files || !files.length) { return; }

    // 圖片交給 TinyMCE 內建的那一套（automatic_uploads），不要搶
    var others = Array.prototype.filter.call(files, function (f) {
        return f.type.indexOf('image/') !== 0;
    });
    if (!others.length) { return; }

    e.preventDefault();
    others.forEach(function (f) { uploadEditorFile(f, editor); });
});
```

⚠ **圖片一定要讓 TinyMCE 自己處理**：搶過來自己上傳的話，
`automatic_uploads` 那一套的進度列與失敗復原就失效了，而且會上傳兩次。

⚠ `paste` 的非圖片檔案走 `e.clipboardData.files` —— 但實務上從檔案總管
複製一個 PDF 再貼進瀏覽器，多數瀏覽器不會給 `files`，所以**這一條要能
優雅地沒反應**，不要寫成「貼上必定有效」的提示。

### 2. 把 📎 從「只有圖示」改成看得見的入口

工具列那顆 `tbfile` 加上文字（`text: '檔案'`），並把 `image` 與 `tbfile`
移到 `toolbar` 前段 —— `toolbar_mode: 'sliding'` 在窄面板會把後段收起來，
現在這兩顆正好在會被收掉的位置。

### 3. 附件欄位改掉

- **新增視窗**：拔掉 `#task-images` 那一格，改在描述欄位底下加一行提示
  「圖片與檔案直接拖進描述，就會插在您放的那一行」
- **詳情面板**：拔掉 `#panel-upload-images` 這個上傳入口

### 4. 既有資料怎麼顯示

`images` 已經有資料的任務，描述底下那一格**保留為唯讀彙總**
（不再有上傳入口，但縮圖、檔案卡片、刪除鈕都留著）。

⚠ **不要寫 migration 把 `images` 搬進 `description`**：
那是改使用者的資料而且不可逆 —— 搬進去的位置一定是接在最後面，
跟「插在該在的那一行」沒有差別，等於為了資料整齊去動一份動不得的東西。
讓舊任務維持舊樣子，新放的檔案走新路，兩邊並存不衝突。

## 要改的東西（初估）

| 檔案 | 說明 |
|---|---|
| `resources/views/admin/task-board/index.blade.php` | `setup` 加 drop／paste 攔截；`tbfile` 加文字並調整 toolbar 順序；拔掉兩個上傳入口；附件區加「唯讀彙總」的標題 |
| `resources/lang/{tw,cn,en}/task_board.php` | 描述欄的新提示、附件區改標題、📎 的文字 |
| `public/css/task-board.css` | 附件區降級成彙總後的樣式微調 |
| `config/changelog.php` | 版本紀錄 |

⚠ **後端幾乎不用動**：`ajax-upload-editor-image`／`ajax-upload-editor-file`
兩支端點、`HasAttachmentRules` 的驗證、`description` 欄位都已經到位。
這次是前端把入口收成一條。

⚠ 這一頁的 JS 仍然內嵌在 Blade（2000 多行），PROMPTS.md 規定
「JS 分 `public/js`」—— 但**這次不要順手整頁搬**：那是一個獨立的重構，
混進來的話這次的改動會完全看不出來。要做應該是另一個 commit。

## 實作後補記（2026-10-30）

### 實際改的東西

| 檔案 | 說明 |
|---|---|
| `resources/views/admin/task-board/index.blade.php` | `setup` 加 `editor.on('drop')`；`tbfile` 加 `text`；toolbar 把 `image tbfile` 移到前段；拔掉 `#task-images`／`#task-image-previews`／`taskImageFiles` 與 `#panel-upload-images` 的上傳處理；附件區加標題與說明、條件改成「有附件才顯示」 |
| `resources/lang/{tw,cn,en}/task_board.php` | `file_button`／`description_drop_hint`／`attachment_legacy_title`／`attachment_legacy_hint`（各 47 個 key）|
| `config/changelog.php` | v3.50 |

後端**完全沒有動** —— 兩支 editor 上傳端點與 `HasAttachmentRules` 本來就到位。
`config/rules.php` 也沒有要加的東西。

### 實作時才確認的幾件事

⚠ **附件區的顯示條件要從「有附件 `||` 有編輯權限」改成「有附件」**：
留著 `|| canUpdate` 的話，沒有舊附件的任務會多出一格空白區塊
（原本那一格一定有東西，因為裡面有上傳欄位）。

⚠ **`drop` 事件只有在真的有非圖片檔案時才 `preventDefault()`**：
無條件擋掉的話，拖圖片進來就不會進 TinyMCE 內建的
`automatic_uploads`，等於把本來就會動的那一半弄壞。

⚠ **CSS 一行都沒加**：彙總區用 `fw-bold`／`text-muted` 這些既有的
Bootstrap class 就夠，`task-board.css` 的 `.attachment-*` 原封不動。

⚠ 這一頁的 JS 仍然內嵌在 Blade，**照計畫沒有順手整頁搬**（那是獨立重構）。
驗證方式是把 `<script>` 抽出來、把 Blade 運算式換成字面值再 `node --check`。

## 裁示紀錄（2026-10-30，五題皆採 (a)）

### 1. 附件區（描述底下那一格）怎麼處理？

- (a) **保留為唯讀彙總**，只拔上傳入口 —— 舊任務的檔案還看得到（**建議**）
- (b) 整個拔掉，舊任務的 `images` 就看不到了（要配 migration，不建議）
- (c) 保留上傳入口，但上傳完「插入到描述游標處」而不是進 `images`

### 2. 新增任務時也要能內嵌嗎？

新增視窗的描述已經是 TinyMCE，所以技術上現在就可以。

- (a) **是，新增視窗也拔掉「附件」欄位**，一律走描述（**建議**，兩邊行為一致）
- (b) 新增時保留附件欄位（還沒有任務 id，比較單純），只有詳情面板改

### 3. 孤兒檔案（內嵌後刪掉，實體檔還在）

- (a) **不管它**（Notion 也是這樣）（**建議**）
- (b) 存檔時比對前後 HTML，少掉的就刪實體檔（⚠ 同一個 URL 被貼到別處就會破圖）
- (c) 另做一支排程清理（建議當成獨立題目，不混這次）

### 4. 留言的附件要不要也內嵌？

留言現在是「文字 ＋ 底下一排圖」，而且**留言編輯刻意只能改文字、圖片維持
原樣**（見 task-board.md）。

- (a) **這次不動留言**（**建議**，留言是短訊息，排在底下不影響閱讀）
- (b) 留言也改成內嵌（那留言的編輯規則要一起重新想）

### 5. 非圖片檔案「貼上」支援到什麼程度？

- (a) **只做拖放，貼上只保證圖片**（**建議**，瀏覽器對非圖片剪貼簿的支援不一致）
- (b) 盡力支援貼上，拿不到 `files` 就安靜無反應
