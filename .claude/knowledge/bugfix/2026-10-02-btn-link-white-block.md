# 圖示按鈕在深色模式變成白方塊（`btn-sm btn-link`）

## 現象

消耗品流水每一列右邊的「編輯」「刪除」圖示鈕，在深色模式下是兩塊白色圓角方塊，
圖示看不見。

## 起因

病根**不在深色模式**，在 `public/css/app.css` 第 60 行那條：

```css
/* Buttons — JS 使用的舊 class */
.btn-sm {
    ...
    border: 1px solid #dee2e6;
    background: #fff;     /* ← 這裡 */
    color: #495057;
}
```

`.btn-sm` 是 **Bootstrap 的尺寸 class**，這裡卻被加上了完整外觀（底色＋外框）。
於是任何 `btn-sm` 只要沒有另一個 `btn-*` 顏色 class 蓋過它，就會拿到白底灰框。

`btn-link` 正好是「不該有底色與外框」的那一種 —— vendor 的 `.btn-link`
（`architect-ui/base.css:2639`）只設 `font-weight` / `color` / `text-decoration`，
不碰 `background`，所以蓋不掉。

載入順序讓情況無法自救：

```blade
{{-- resources/views/layouts/app.blade.php --}}
37: custom.css
38: app.css      ← 在 custom.css 之後
```

`app.css` 在後，`custom.css` 裡同特異性的規則一律輸。

**淺色模式下白底與卡片同色，只看得到一圈淺灰框，沒人覺得是 bug** ——
深色模式才把它現形成白方塊。

### 不只這一頁

全站 `btn-sm btn-link` 共五處，**五處都一樣壞**：

| 位置 | 按鈕 |
|---|---|
| `public/js/staff-manage-consumable.js` | 消耗品流水的編輯／刪除 ← 這次回報的 |
| `public/js/shared-file.js` | 共用檔案的「移回」 |
| `public/js/quick-reply-admin.js` | 題庫的刪除說法 |
| `public/js/telegram-chat/quick-reply.js` | 快速回覆面板的「返回」 |
| `public/js/telegram-chat/layout.js`（無 `btn-sm`） | 不受影響 |

另外兩處純 `btn-link`（`layouts/app.blade.php` 的推播鈕與主題切換鈕）沒帶
`btn-sm`，所以一直是正常的。

## 修法

補在 `public/css/custom.css` 的按鈕區段：

```css
.btn.btn-link,
.btn.btn-link:hover,
.btn.btn-link:focus {
    background: transparent;
    border-color: transparent;
    box-shadow: none;
}
```

用 `.btn.btn-link`（兩個 class，特異性 `(0,2,0)`）壓過 app.css 的 `.btn-sm`
（`(0,1,0)`）；hover 是 `(0,2,1)` vs `(0,1,1)`，同樣贏。**不需要 `!important`，
也不必動 app.css 那條全站 28 個檔案在用的舊規則。**

兩個模式一起修，不是只補深色 —— 淺色模式的灰框本來也是錯的。

### 刻意不設 `color`

這些按鈕都搭了 `text-muted` 或 `text-danger`，文字色自有深色配對規則
（見 [[2026-10-01-badge-bg-light-dark-mode]] 的後記）。在這裡設 `color` 會蓋掉
它們 —— 刪除鈕會從紅色變成 `btn-link` 的藍。

`telegram-chat/quick-reply.js` 那顆沒帶 `text-*`，深色模式下是 vendor 的
`#3f6ad8`，偏暗但讀得到，不在這次回報範圍內，不動。

## 教訓

> ⚠️ **深色模式下的白方塊，先問「淺色模式那裡是什麼顏色」。**
>
> 這次查的第一個方向是「`custom.css` 少了 `[data-theme="dark"] .btn-link`」——
> 錯的。真正的問題是淺色模式本來就給錯了（`btn-link` 不該有底色），
> 只是白底藏在白卡片裡沒人看見。
>
> **若只補深色配對，淺色模式那圈不該存在的灰框會永遠留著**，而且下一個
> 寫 `btn-sm` 的人會再踩一次。

> ⚠️ **Bootstrap 的尺寸 class 不要加外觀。**
>
> `app.css` 把 `background` / `border` / `color` 塞進 `.btn-sm`，讓「我要一顆
> 小按鈕」和「我要一顆白底灰框按鈕」變成同一件事。這條規則全站 28 個檔案在用，
> 現在已經改不動了 —— 只能靠更高特異性去抵銷。
>
> 排查順序：**查到某個 class 行為詭異，先 grep `public/css/app.css` 有沒有
> 劫持它**，再去看 vendor 與深色覆寫。app.css 在最後載入，它說了算。

## 驗法

特異性靠手算就夠，但要算對兩件事：

1. **載入順序**（`layouts/app.blade.php` 的 `<link>` 次序）—— 同特異性時才看順序
2. **選擇器裡的 class 個數** —— `.btn.btn-link` 是 2 個，不是 1 個

掃「誰給了 `.btn` 背景」可以用選擇器＋宣告一起比對，比單純 grep `btn` 準：
找選擇器含 `.btn` 且宣告含 `background` 的規則，排除掉 `-primary` / `-danger`
等明確顏色變體，剩下的就是嫌犯。

## 異動檔案

- `public/css/custom.css` — `.btn.btn-link` 去底色去框一條（含註解說明病根）

## 相關

- [[2026-10-01-badge-bg-light-dark-mode]] — 文字色與 `alert-*` 的深色配對（這次刻意不碰 `color` 的原因）
- [[2026-09-30-btn-outline-info-light-mode]] — 「只補了一半模式」的另一個案例
- [[2026-10-02-fontawesome-6-icon]] — 同一個分頁的另外兩個版面問題
