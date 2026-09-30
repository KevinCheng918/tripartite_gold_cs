# 題庫編號在深色模式看不見

## 現象

快速回覆題庫頁，每題前面的 `#編號` 標記在**深色模式**下整顆消失（白底白字）。

## 起因

兩個各自獨立的錯誤，剛好都在同一組標記上。

### 一、`bg-light` + `text-muted` 在深色模式互相抵消

```js
'<span class="badge bg-light text-muted border">#' + item.id + '</span>'
```

`custom.css` 把深色模式的 `.text-muted` 改成 **白色 40%**：

```css
[data-theme="dark"] .text-muted { color: rgba(255,255,255,0.4) !important; }
```

而 `.bg-light` 在深色模式**沒有對應的覆蓋**（只有 `.tg-group-item.bg-light`、
`.qr-category-item.bg-light` 兩個特例），仍然是白底 —— 白底配白字，整顆不見。

### 二、`bg-info-subtle` 那一組在本專案根本不存在

句數標記（`💬 N`）用了：

```js
'badge bg-info-subtle text-info-emphasis border border-info-subtle'
```

那是 **Bootstrap 5.3** 才有的 class，本專案用的是 **5.1** ——
寫了等於沒寫（透明背景、文字顏色直接繼承父層），兩種模式都不對。

## 修法

自訂 `.qr-badge` / `.qr-badge--phrasing`，在 `public/css/app.css` 明確定義
淺色與深色兩套，不依賴 Bootstrap 的語意色。深色模式用專案的金色系
（`#d4af37`），跟其他深色元素一致。

## 教訓

> ⚠️ **用 Bootstrap 的語意色 class 之前，先確認兩件事：**
>
> 1. **這個版本有沒有這個 class。** 專案是 Bootstrap **5.1**，
>    `*-subtle`、`*-emphasis` 系列（5.3 才有）一律不能用。
>    寫錯不會報錯，只會默默沒有樣式。
> 2. **深色模式下那組顏色會不會打架。** `custom.css` 用 `!important` 改了
>    一批語意色（`.text-muted`、`.btn-outline-*`…），但沒有全部配對 ——
>    像 `.bg-light` 就只改了兩個特例。
>
> 拿不準就自己定義一個 class 並寫好兩種模式，那比事後debug快。

同一天還修了另一個同類問題：[[2026-09-30-btn-outline-info-light-mode]]
（只補了深色模式、漏掉淺色模式）。兩件事是一體兩面。

## 異動檔案

- `public/css/app.css` — `.qr-badge` / `.qr-badge--phrasing`
- `public/js/quick-reply-admin.js` — 三處標記改用新 class

## 相關

- [[quick-reply]] — 快速回覆題庫
- [[auto-reply-learning]] — 句數標記是問法樣本的入口
