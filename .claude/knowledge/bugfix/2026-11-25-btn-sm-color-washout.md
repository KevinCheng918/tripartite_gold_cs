# 「通過撤銷」按鈕整顆看不見（白底白字）

> 需求方 2026-11-25：「我審核撤銷請假的按鈕沒有套用到深色模式，
> modal 的淺色模式的按鈕跟深色模式的按鈕都不對」

## 現象

「審核撤銷請假」視窗裡：

| 按鈕 | 淺色 | 深色 |
|---|---|---|
| 通過撤銷（`btn-success btn-sm`） | **白底白字，整顆看不見** | 一樣 |
| 拒絕撤銷（`btn-danger btn-sm`） | 白底灰字（看得見，但不像危險動作） | 一樣 |

⚠ 兩個模式**長得一模一樣** —— 深色模式完全沒作用。

---

## ⚠⚠ 起因：「尺寸」class 身上掛了配色

`public/css/app.css` 第 61 行：

```css
.btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.8125rem;
    border: 1px solid #dee2e6;
    background: #fff;      /* ← 問題在這兩行 */
    color: #495057;
}
```

`.btn-sm` 應該只管**大小**，但它同時定義了底色與字色。
於是「小按鈕 ＋ 顏色變體」的組合會被洗白：

```
btn btn-danger           → 紅底白字（正常）
btn btn-danger btn-sm    → 白底灰字
btn btn-success btn-sm   → 白底 ＋ 白字 ＝ 整顆消失
```

`btn-success` 特別慘是因為**它的字色本來就是白的**
（base.css 的 `.btn-info, .btn-success { color: #fff }`），
底色被洗成白的之後就剩一塊空白。

⚠ **深色模式為什麼也不對**：根本不是深色模式的問題。
`.btn-sm` 那段白底沒有任何 `[data-theme="dark"]` 配對，
所以深色模式下照樣是白底 —— 跟淺色壞在同一個地方。

---

## 修法

在 `custom.css` 把兩個顏色變體補齊（**不動 `app.css` 的 `.btn-sm`**）：

```css
.btn.btn-success { background-color: #198754 !important; … }
.btn.btn-danger  { background-color: #d92550 !important; … }
```

### ⚠ 為什麼寫 `.btn.btn-success` 而不是 `.btn-success`

`app.css` 掛在 `custom.css` **後面**（見 `layouts/app.blade.php` 第 41-42 行）。
單一 class 特異性相同時後載入的贏，所以要多一層 class ＋ `!important`
—— 比照同檔案既有的 `.btn.btn-secondary`。

### ⚠ 為什麼不直接修 `app.css` 的 `.btn-sm`

全站有 **55 處** `btn-outline-secondary btn-sm`、還有一批只有 `btn-sm`
沒有顏色變體的按鈕，正是靠那段白底樣式在長。拔掉會波及它們。
把有顏色的那幾個補齊，是影響面最小的做法。

### ⚠ 順手補：`btn-success` 不在「所有 btn 大小統一」那組裡

稽核時發現的，跟顏色無關但同一個檔案：

```css
/* 原本只有三個，漏了 success */
.btn.btn-primary, .btn.btn-secondary, .btn.btn-danger { font-size: 0.875rem !important; }
```

漏掉的那個會沿用佈景原本的尺寸 —— 實測 modal **外面**
`btn-success` 是 **15px**，同一排的 `btn-danger` 是 **14px**。

⚠ **為什麼一直沒被發現**：`btn-success` 目前只用在兩個地方，
兩個都在 modal 裡，而 `.modal .btn` 有 `!important` 會把大小壓成一樣。
是潛伏的，不是現在看得到的。

⚠ **新增顏色變體時，那兩組（一般 ／ `btn-sm`）都要跟著加。**

---

## ⚠⚠ 教訓：實心底配白字，深色模式不需要另一組顏色

第一版我替深色模式補了一組「調亮一階」的顏色，**那是錯的**，實測：

| | 淺色（白字對比） | 我調亮後的深色 |
|---|---|---|
| success | 4.53 ✓ | #2ea36b → **3.20 ✗** |
| danger | 4.85 ✓ | #e03a5f → 4.25 ✗ |

**實心底 ＋ 白字的可讀性只跟按鈕自己的底色有關，跟頁面背景無關。**
把底色調亮只會讓白字更難看。

⚠ 這跟 [[2026-10-01-badge-bg-light-dark-mode]] 那種
「`.bg-light` 沒有深色配對」**是兩回事**：那種是**透明／淺底**元素，
底色會隨頁面變，才需要成對寫。**實心色塊不用。**

判準：
- 元素自己有不透明的底色 → 一組就夠，兩個模式共用
- 元素是透明底、或底色淺到會跟頁面融在一起 → 必須成對寫

---

## 已驗證（2026-11-25，claude-in-chrome 實測）

兩個模式都量過實際算繪出來的顏色與對比：

| | 底色 | 字色 | 對比 |
|---|---|---|---|
| 通過撤銷 | #198754 | #fff | **4.53** |
| 拒絕撤銷 | #d92550 | #fff | **4.85** |
| 取消（btn-secondary） | #6c757d | #fff | 4.69 |

⚠ 另外把全站**所有**「`btn-sm` ＋ 顏色變體」的組合掃過一遍
（`outline-secondary` 55、`primary` 27、`secondary` 10、`danger` 3、
`success` 1、`outline-primary` 2、`outline-warning` 1、`outline-danger` 1），
沒有別的看不見。

⚠ 但 `btn-outline-*`（warning／danger／primary）仍然會被 `.btn-sm`
洗成**灰字白底**，等於失去顏色語意 —— 讀得到，所以這次沒動。
要用彩色外框按鈕的話得知道這件事。

## 怎麼量

```js
// 在瀏覽器 console 量實際算繪結果，不要用肉眼判斷
const s = getComputedStyle(btn);   // s.backgroundColor / s.color
// 相對亮度 → 對比 = (亮+0.05)/(暗+0.05)，4.5 是一般文字的門檻
```

⚠ 設完 `data-theme` 要**等一個 tick** 再量 —— 同一個 tick 內讀到的
還是舊值，會誤判成「深色規則沒生效」（這次就被騙過一次）。
