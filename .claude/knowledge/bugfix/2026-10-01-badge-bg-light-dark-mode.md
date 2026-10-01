# 「不自動回覆」標籤在深色模式看不見

## 現象

深色模式下，對話視窗訊息上的灰色小標籤「不自動回覆」整顆消失（白底亮灰字）。

## 起因

**同一個洞的第三次。** 見 [[2026-09-30-qr-badge-dark-mode]] ——
那次就查明了病根並寫在教訓裡，但只修了題庫頁自己那組標記：

```css
[data-theme="dark"] .text-muted { color: rgba(255,255,255,0.4) !important; }
```

深色模式把 `.text-muted` 改成白色 40%，而 `.bg-light` **沒有對應的深色覆寫**
（只有 `.tg-group-item.bg-light`、`.qr-category-item.bg-light` 兩個特例），
仍然是白底 —— 白底配白字。

而 `badge bg-light text-muted` 是專案裡「中性小標籤」的通用寫法，全專案四處：

| 位置 | 標籤 |
|---|---|
| `public/js/telegram-chat/messages.js` | 訊息上的「不自動回覆」← 這次回報的 |
| `public/js/telegram-chat/ignore-member.js` | 忽略名單的「手動」 |
| `public/js/telegram-chat/quick-reply.js` | 快速回覆面板的句數 |
| `resources/views/admin/quick-reply/index.blade.php` | 題庫頁的句數 |

四處都壞，只是先被看到的是第一處。

## 修法

這次補的是**缺的那條配對規則**，不是再自訂一個 class：

```css
[data-theme="dark"] .badge.bg-light { background: rgba(255,255,255,0.1) !important; color: rgba(255,255,255,0.7) !important; }
[data-theme="dark"] .badge.bg-light.border { border-color: rgba(255,255,255,0.18) !important; }
```

顏色沿用同區段的 `.badge.bg-secondary` —— 中性標籤在深色模式該長一樣。

**四處一次全好，JS 與 blade 都不用動。** 特異性（attr + 2 class）高於
`[data-theme="dark"] .text-muted`（attr + 1 class），所以 `color` 吃得到。

兩個特例不受影響：它們的選擇器沒有 `.badge`，不匹配這條新規則。

`custom.css` 的 `?v=` 走 `filemtime()`，不需要手動改版號。

## 教訓

> ⚠️ **查到「某個 Bootstrap 語意色在深色模式沒有配對」時，當下就把那條配對補掉。**
>
> 上一次（[[2026-09-30-qr-badge-dark-mode]]）已經診斷出 `.bg-light` 缺配對，
> 但只替題庫頁自訂了 `.qr-badge` 繞過去 —— 剩下三處原封不動留在那裡等著被發現。
>
> 自訂 class 解決的是「這一顆標籤」，補配對規則解決的是「這個 class 在這個
> 主題下的行為」。病根在後者時，前者只是把洞挪到別人會踩的地方。
>
> 判準：**用到的是 Bootstrap 內建語意色，而且專案已經為同類色寫了一批深色覆寫**
> → 補配對。**要的顏色跟語意色無關**（例如題庫編號想用金色系）→ 自訂 class。

## 異動檔案

- `public/css/custom.css` — `.badge.bg-light` 的深色配對兩條

## 相關

- [[2026-09-30-qr-badge-dark-mode]] — 同一個洞，上一次只修了一處
- [[2026-09-30-btn-outline-info-light-mode]] — 「只補了一半模式」的另一面
- [[ignore-member]] — 「不自動回覆」標籤的功能本體
