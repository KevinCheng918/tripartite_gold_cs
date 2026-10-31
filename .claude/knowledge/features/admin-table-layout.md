# 後台表格與操作欄重排

> **狀態：已完成（2026-10-10）。** 純前端，沒有 DB、route、權限異動。

## 為什麼要做

後台每加一個功能就在「操作」欄多塞一顆 `btn btn-sm btn-outline-secondary`。
到這次動工前：

| 頁面 | 欄位數 | 每列按鈕數 |
|---|---|---|
| 帳號管理 | 7 | 5（編輯／調整狀態／設定權限／登入紀錄／綁定碼） |
| 站台管理 | 8 | 5（詳細／編輯／同步／補點通知／狀態） |
| 站台補點 | 11 | 4（圖片／備註／通過／拒絕） |
| 虛擬機 | 12 | 2 |
| 虛擬機帳單 | 9 | 最多 4 |

問題有兩層：

1. **一列五顆同樣灰框的方塊沒有主次**，寬度不夠時還會換行把列高撐成兩倍
2. **欄位數裡有一半在講同一件事** —— 帳號管理七欄裡有四欄（帳號／暱稱／
   TG 署名／TG 綁定）都在描述同一個人，橫向掃不出重點

## 做法

### 1. 操作按分類黏成幾段，用 theme 自己的按鈕語彙

```
[詳細]  [編輯│調整狀態]  [同步│補點通知]
 檢視         管理            通知
```

用的是 Architect theme 自己的 class（`vendors/architect-ui/styles/css/base.css`）：

| class | 作用 |
|---|---|
| `btn-group` | 同一類黏成一段，共用邊框、只有兩端圓角 |
| `btn-icon` + `btn-icon-wrapper` | 圖示鈕；圖示比文字大一號 |
| `btn-transition` | 灰字 + `#e9ecef` 淡框，**hover 才上色** |

原本是五顆深灰外框、各自獨立的 `btn-sm` 平鋪 —— 五顆長得一模一樣、看不出
哪幾顆是同一類。改法是「安靜下來 + 分段」：外框換成淡灰、同一類共用邊框、
段與段之間留 `0.5rem`。

`hover` 也在 `.row-actions` 裡覆寫成淡金色：全站的
`.btn-outline-secondary:hover` 是近黑色實心填滿（帶 `!important`），
那是給版面上獨立一兩顆按鈕用的，一列裡有五顆時滑過去整塊變黑太搶眼。

### ⚠ 這一欄的設計被退回兩次，不要再往那兩個方向改

| 做過的版本 | 需求方回饋（2026-10-10） |
|---|---|
| 只留兩顆常用的，其餘收進 `⋯` 選單 | 「介面上還是要有一些按鈕」→「按鈕不應該全部收合，有點醜」 |
| 純圖示鈕（`btn-icon-only`）+ `title` 當說明 | 「每個按鈕旁邊還是要有說明，不能隱藏」 |

所以現在的規則是：**每顆按鈕都留在畫面上，而且都帶文字。**
custom.css 刻意**不提供** `.btn-icon-only` 的樣式 —— 不是漏寫，
是免得又有人拿去用（註解裡有寫）。

收納選單那一版已經整個移除（`public/js/common.js` 的 `RowActions`、
custom.css 的 `.row-actions__*`）。如果以後真要重做收合，
有兩個當時踩到的坑值得先知道：

> 選單不能用 Bootstrap 的 dropdown，也不能用 `position: absolute` ——
> 表格外層的 `.table-responsive` 是 `overflow-x: auto`，依規範
> `overflow-y: visible` 會被算成 `auto`，它其實是個捲動容器，
> absolute 定位的選單會被它裁掉（最後一列尤其明顯）。
> 要用 `position: fixed` 自己算座標。
>
> 而且節點**不能** clone 或搬到 body 底下 —— 全站十幾個頁面的按鈕都是
> 直接綁定（`$('.js-x').on(...)`，沒有一處用 `$(document).on(...)` 委派，
> 唯一的例外是 `public/js/shared-file.js`），搬家會讓那些事件全部失聯。

### 2. 欄位數收斂

同一件事的兩欄合成一格，主要資訊粗體（`.cell-stack__main`）、
次要壓成灰色小字（`.cell-stack__sub`）：

| 頁面 | 從 | 到 | 怎麼併 |
|---|---|---|---|
| 帳號管理 | 7 | 6 | 帳號＋暱稱 → 成員；TG 署名＋TG 綁定 → Telegram |
| 站台補點 | 11 | 11 | 圖片＋備註 → 附件（兩個 `.cell-chip` 小 chip）；操作只留通過／拒絕 |
| 虛擬機 | 12 | 8 | 系統＋站台、主機＋機型、內網＋外網 IP、開關機＋啟用狀態 |
| 虛擬機帳單 | 9 | 7 | 系統併進站台；逾期天數併進收款狀態（逾期時 badge 本來就會變成「逾期」） |
| 群發公告 | 9 | 7 | 總數＋成功＋失敗 → 送達（手機卡片本來就是這樣顯示，兩邊終於一致） |

⚠ 「成員」那一格**暱稱與帳號相同時只顯示一次** —— 沒改過暱稱的帳號
（預設就等於帳號）會變成同一個字上下疊兩行，看起來像畫面壞掉。

### 3. 全站統一的表格樣式

`.data-table`（custom.css）：thead 字級縮小加字距、細底線、數字欄
`.col-num` 右對齊＋等寬數字、`.col-idx` 序號壓灰、`.col-actions` 靠右不換行、
`.col-tight` 窄欄、`.table-empty` 空狀態帶圖示與留白。

手機卡片同時統一成 `.data-card__head` / `.data-card__row` / `.data-card__text`，
取代原本每一列都手寫的
`d-flex justify-content-between mb-1 style="font-size:0.875rem"`。

## ⚠ 操作欄的四個坑（都是實際開瀏覽器看才發現的）

前三版都是憑想像排版，上線一看才知道哪裡醜。這四條是實測後才定下來的：

### 1. `flex-wrap: wrap` 會讓按鈕被擠成兩排

`.row-actions` 一開始寫 `flex-wrap: wrap`，結果五顆按鈕在 1180px 的表格裡
被擠成**兩排、而且兩排各自靠右對齊彼此對不齊**，列高變兩倍 —— 比原本還醜。

原因不是寬度不夠，而是 `flex-wrap: wrap` 等於告訴瀏覽器「這一格可以再窄」，
表格就把寬度分給了其他欄。改成 `nowrap` 之後這一欄拿到它需要的 ~500px，
其他欄自己縮，整列回到單行。**手機卡片才需要 wrap**（media query）。

### 2. `btn-transition` 的淡框在這裡沒有生效

Architect 的 `.btn-transition` 設了 `border-color: #e9ecef`，但 base.css
**第 11596 行又定義了一次 `.btn-outline-secondary`**（深灰 `#6c757d`），
位置比 `.btn-transition`（第 10993 行）晚、特異性相同 → 後來居上。
所以 `.row-actions` 裡要自己指定淡框，而且得 `!important`。

### 3. 圖示比文字大一號太突兀

`.btn-group-sm > .btn-icon.btn .btn-icon-wrapper` 是 16px，配 12.8px 的文字
頭重腳輕。覆寫的選擇器要跟它一樣深，只寫 `.row-actions .btn-icon-wrapper`
特異性不夠。

### 4. 停用的按鈕滑過去會亮起來

為了讓 `title` 顯示而開著 `pointer-events`（見下方「disabled 的按鈕看不到
停用原因」），代價是 hover 樣式也跟著生效。要擋的有**兩條**：

- `.row-actions` 自己那條金色 hover → 加 `:not(.disabled)`
- 全站那條 `.btn-outline-secondary:hover { background-color: #212529 !important }`
  → 它沒有排除 disabled，停用的按鈕滑過去會變近黑底，乘上 `opacity: 0.5`
  變成一塊灰色實心方塊，看起來反而更像「正在按」。只能再寫一條
  `.row-actions .btn.disabled:hover` 把它鎖回透明。

## ⚠ 過程中修掉的三個既有問題

### Bootstrap 的列底色是 box-shadow，不是 background-color

```css
.table > :not(caption) > * > * {
    box-shadow: inset 0 0 0 9999px var(--bs-table-accent-bg);
}
```

那層 box-shadow 蓋在 `background-color` 上面。所以**設 row 的底色一定要改
`--bs-table-accent-bg`**，直接設 `background-color` 完全看不到。
連帶發現兩件事：

- `.table` 的 accent-bg 預設是 `rgba(0,0,0,0.03)`，每一格本來就蒙著一層灰
- **`.table-hover` 的預設 hover 色是 `#e0f3ff` 淡藍** —— 深色模式早就覆寫成
  金色，淺色模式從來沒有人覆寫。一個號稱沒有藍色的介面，
  滑過任何一列都會亮一條淡藍

`.data-table` 把這兩個變數都收掉了。**收掉之後底色那一層就空出來給列狀態用**
（例如報表的「仍未處理」），所以在 `.data-table` 裡設 row 底色反而要用
`background-color` —— 跟上面那句相反，差別就在 accent-bg 有沒有被收掉。

### 列狀態的左條：顏色走 `--row-accent`，box-shadow 只寫一次

hover 的金線與「仍未處理」的紅條都是同一個 `td:first-child` 的 box-shadow，
而**一個元素只能有一組 box-shadow** —— 各寫一條規則的話會互相整條覆蓋，
然後就得為每個「狀態 × hover」的組合補一條覆寫，條數隨狀態數量平方成長。

```css
/* 只有這一條碰 box-shadow */
.table.data-table > tbody > tr > td:first-child {
    box-shadow: inset 3px 0 0 var(--row-accent, transparent),
                inset 0 0 0 9999px var(--bs-table-accent-bg);
}

.table.data-table > tbody > tr:hover      { --row-accent: #a67c00; }
.table.data-table > tbody > tr.is-pending { --row-accent: #dc3545; }
```

⚠ 狀態那條**必須寫在 `:hover` 之後**：兩者特異度相同，靠順序決勝。
寫在前面的話，滑鼠一經過紅條就會變成金條。

⚠ 第二層 shadow 是 Bootstrap 畫列底色的那層，不能省 ——
少了它，左條會把 hover 底色整個擦掉。

**要新增一種列狀態：加一條只設 `--row-accent` 的規則，不要再寫 box-shadow。**

### 金色文字在深色模式看不到

全站十處把金色寫成 inline style，其中財務頁七處是 `#a67c00`（淺色模式的金），
在深色卡片上幾乎看不到；帳號與同仁統計卡反過來誤用了深色模式的亮金 `#d4af37`，
在奶油底的卡片標題上糊成一片。收成 `.text-gold` 才有辦法一次補上另外一半。

### disabled 的按鈕看不到停用原因

站台的「補點通知」缺 API／群組時會停用，原因寫在 `title`。
但 **disabled 的元素不觸發 hover，title 永遠看不到** —— 使用者只會覺得
按鈕莫名壞掉。原本靠外包一層 `<span tabindex="0" title="...">` 解決，
但那層 span 會打斷 `btn-group` 的邊框收合（btn-group 靠 `> .btn` 選到子按鈕）。

改成用 `.disabled` **class** 而不是 `disabled` **屬性**：hover 照常觸發，
而那一版不掛 `js-send-topup-notice`，沒有任何 handler 會接，按了也不會有事。
Bootstrap 的 `.btn.disabled` 會關掉 pointer-events，
`custom.css` 的 `.row-actions .btn.disabled` 把它開回來並換成 `not-allowed` 游標。

## 動到的檔案

| 檔案 | 內容 |
|---|---|
| `public/css/custom.css` | `.data-table` 系列、`.row-actions`、`.cell-stack__*`、`.cell-chip`、`.data-card__*`、`.text-gold`、`.stat-card__*`，每一條都有深色配對 |
| `public/js/common.js` | （先加後移除）收納選單 `RowActions` |
| `public/js/shared-file.js` | 檔案列操作改分兩段；空狀態；多收一個 `data-row-actions` 語系 |
| `public/js/setting-admin.js` | 用量表格欄位 class |
| `resources/views/admin/accounts/partials/row-actions.blade.php`（新增） | 帳號的列操作，桌機與手機共用 |
| `resources/views/admin/station/partials/row-actions.blade.php`（新增） | 站台的列操作，桌機與手機共用 |
| `resources/views/admin/station/partials/topup-notice-button.blade.php` | 改成 btn-group 的直接子元素、停用改用 class |
| `resources/views/admin/{accounts,station,vm,staff-manage,telegram-broadcast,finance,daily-rate,dashboard,task-board,setting,shared-file,attendance}` | 套用 `.data-table` 與欄位合併 |
| `resources/views/layouts/app.blade.php` | 登入紀錄 Modal 的表格 |
| `resources/lang/{tw,cn,en}/common.php` | `row_actions.*`（btn-group 的 aria-label） |
| `resources/lang/{tw,cn,en}/{account,station,broadcast,vm}.php` | 合併後的新欄名 |

## 無障礙

每一段 `btn-group` 都要 `role="group"` 與 `aria-label`（取
`common.row_actions.*`）—— **視覺上的分段對讀螢幕的人不存在，
分類名稱是他唯一的線索**，不能因為「畫面上看不到」就省略。

按鈕本身不需要 `aria-label`：它們都帶可見文字了。

## 相關

- [[station]]、[[station-topup]]、[[vm]]、[[broadcast]]、[[shared-file]]、[[rbac]]
- [[telegram-bind-code]] — 帳號管理那兩顆 Telegram 綁定按鈕的來源

## 對齊：標題與文字置中、操作欄靠左（2026-11-01）

需求方分三次收斂到這個結論：

1. 「帳務紀錄，文字都讓他在欄位置中，不要偏左或偏右。」
2. 「操作按鈕不用置中，讓他靠左對齊就好。」
3. 「表格**一律**都是標題跟文字都置中，按鈕都是靠左對齊。」

所以最後是**全站規則**，寫在 `.data-table` 本身，不是某一頁的修飾 class。

| 位置 | 對齊 |
|---|---|
| `thead th` | 置中 |
| `tbody td` | 置中 |
| `.col-actions`（th 與 td）| **靠左** |

### ⚠ 操作欄為什麼不跟著置中

置中的是**資料欄位** —— 那些是要上下對齊著掃的。

按鈕不是資料：每一列的按鈕數量不一樣（有的三顆、關機的主機一顆都沒有），
置中會讓每一列的按鈕起點都落在不同位置，眼睛要重新找一次。
靠左，起點就固定了。

### ⚠ 三個會讓它悄悄失效的細節

**1. `.row-actions` 是 flex，`text-align` 管不到它。**
主軸要明確設 `justify-content: flex-start`，只改 `text-align` 的話按鈕
還是會停在原來的位置。

**2. `.col-actions` 的靠左規則必須排在它自己的 `text-align: right` 之後。**
兩邊都是兩個 class 的權重，平手時後面的贏 —— 搬到前面去就失效了。
（原本那條 `text-align: right` 已經拿掉，但 `width`／`white-space` 還在，
所以兩條規則仍然並存。）

**3. `.col-num` 原本的 `text-align: right` 要拿掉**，不是加一條置中去蓋它。
`tabular-nums` 要留著：它讓每個數字佔一樣寬，置中之後同一欄的位數至少
還是對齊的（少了它，1 和 8 寬度不同，整欄會參差）。

### ⚠ 範圍：14 個頁面

`data-table` 用在 attendance／staff-manage／followup／telegram-broadcast／
dashboard／vm／accounts／daily-rate／station／task-board／finance／
shared-file／setting 與 layout。**全部都會變。**

金額欄（`col-num`，用在 6 個頁面）原本靠右，現在也置中了。

⚠ **Blade 裡用 `text-start`／`text-end`／`text-center` 工具類的地方不受影響**
—— Bootstrap 那幾個是 `!important`，權重贏過這裡的全站規則。
全專案約 45 處（不全在表格裡）。要讓那些也跟著置中的話得逐一清掉，
這次沒做 —— 它們多半是刻意指定的。
