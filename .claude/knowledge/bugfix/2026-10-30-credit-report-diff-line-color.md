# 點數報表：差值要換行顯示、＋紅色 −綠色

**日期**：2026-10-30（v3.49）
**需求**：「點數報表的與前一點差值要換行顯示，＋的用紅色表示，−的用綠色表示」

## 原本的樣子

差值是接在餘額同一行後面的括號裡：

```js
function tooltipLabel(item) {
    var text = item.dataset.label + '：' + formatNumber(item.parsed.y);
    // ...
    text += '（' + i18n.tooltip_diff + ' ' + signed(point.diff) + '）';
    return text;
}
```

## ⚠ 為什麼不能只改 callback：Chart.js 的 tooltip 是逐「項目」上色，不是逐「行」

Chart.js 3.9.1 的 tooltip 畫在 **canvas** 上，顏色能調的只有：

| 選項 | 作用範圍 |
|---|---|
| `bodyColor` | 整個 body 一種顏色 |
| `footerColor` | 整個 footer 一種顏色 |
| `callbacks.labelTextColor` | **一個 tooltip 項目一種顏色** |

換行很容易（`callbacks.label` 回傳 `string[]` 就是多行），但那幾行屬於
**同一個項目**，`labelTextColor` 會把它們染成同一色 ——
做不到「餘額那行用原色、差值那行另一個顏色」。

`afterBody`／`footer` 也不行：它們各自只有一種顏色，而
`interaction.mode: 'index'` 之下兩條線會同時被 hover 到，
兩個差值的正負號可能相反，一種顏色表達不了。

## 修法：改用 `external` 自己畫 HTML tooltip

```js
tooltip: {
    enabled: false,          // ⚠ 不關的話兩個 tooltip 會疊著畫
    external: renderTooltip
}
```

`renderTooltip(context)` 把 DOM 掛在 **canvas 的父層**
（Blade 那邊給了它 `position:relative` 與固定高度，絕對定位才有基準點；
掛到 `body` 上就得自己換算整頁捲動），用 `model.caretX／caretY` 定位。

長這樣：

```
2026-10-30 14:00
■ 系統點數：1,234,567
  跟前一點 +12,000          ← 自己一行，紅色
  來源：自動同步
```

## 配色（需求方指定，不要「順手改成比較直覺的那個」）

照**華人金融市場**的慣例，跟歐美的紅跌綠漲剛好相反：

| 情況 | class | 顏色 |
|---|---|---|
| `diff > 0`（增加）| `is-up` | 紅 `#ff6b81` |
| `diff < 0`（減少）| `is-down` | 綠 `#4ddb92` |
| `diff === 0`（沒變）| `is-flat` | 灰 `#adb5bd` |

⚠ **0 要染灰**：染紅或綠都會讓人以為動了。

⚠ **`diff` 是 `null` 的時候整行不要出現**：區間第一個點沒有「前一點」可比，
顯示成「±0」是錯的 ——「沒變」跟「沒得比」是兩回事。

## 順帶改掉的一件事

來源與補點明細（原本／異動／現在）原本在 `afterBody`，也就是**堆在全部
餘額行的最後面**。兩條線同時被 hover 到時，那會變成四行孤立的「來源：…」，
看不出哪行屬於哪條線。改成 external 之後接在**各自那個點底下**。

## 自己畫 tooltip 的三個坑

1. ⚠ CSS 要 **`pointer-events: none`**：tooltip 蓋在曲線上，吃得到滑鼠的話
   游標一移到它上面，圖表就判定「離開了」—— tooltip 會瞬間消失又出現，整個抖起來
2. ⚠ 一律用 **`textContent`** 不要 `innerHTML`：站台名稱與來源是外部資料
3. ⚠ **重畫前要先把它藏起來**：它是掛在父層的 DOM，`chart.destroy()`
   不會帶走它 —— 換日期查詢的那一刻會留一個貼在舊位置、寫著舊數字的浮框

## 相關檔案

| 檔案 | 說明 |
|---|---|
| `public/js/station-credit-report.js` | `tooltipLabel`／`tooltipAfterBody` → `renderTooltip`／`tooltipElement`／`appendSubLine`／`diffClass` |
| `public/css/station-credit-report.css` | **新增**。`.cr-tooltip` 一整套 |
| `resources/views/admin/station/credit-report.blade.php` | 載入上面那支 CSS（`<link>` 放 content 開頭，理由見檔內註解）|

> 語系 key 沿用既有的 `station.credit_report.tooltip_*`，沒有新增。
