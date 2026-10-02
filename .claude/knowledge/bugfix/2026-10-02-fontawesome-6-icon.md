# 消耗品分頁的兩個版面問題

上線第一天回報的兩件事，原因完全不同。

## 一、分頁沒有 icon

人員管理與設備管理都有 icon，**消耗品那個是空的**。

## 起因

用了 `fa-boxes-stacked` —— 那是 **Font Awesome 6** 的名稱，
而專案載的是 **5.15.1**（`public/vendors/architect-ui/vendors/@fortawesome/
fontawesome-free/css/all.min.css` 的檔頭就寫著）。

FA5 裡這個 icon 叫 `fa-boxes`（FA6 改名為 `fa-boxes-stacked`）。

**寫錯不會報錯**，`<i>` 標籤照樣在、只是沒有字符 —— 看起來就像少了一塊。

## 修法

改成 `fa-boxes`，並在那一行留註解說明為什麼不是 `-stacked`。

## 這是同一類錯誤的第四次

| 時間 | 錯在哪 | 專案的版本 |
|---|---|---|
| [[2026-09-30-qr-badge-dark-mode]] | `bg-info-subtle` / `text-info-emphasis` 是 Bootstrap **5.3** | Bootstrap 5.1 |
| [[2026-10-01-badge-bg-light-dark-mode]] | `.bg-light` 在深色模式沒有配對覆寫 | — |
| [[ignore-staff]] 的 checkbox | `.form-check-input` 未勾選那半沒有深色覆寫 | — |
| 這一次 | `fa-boxes-stacked` 是 Font Awesome **6** | FA 5.15.1 |

> ⚠️ **前端 class 名稱寫錯不會噴錯，只會默默沒有效果。**
>
> 用任何 library 的 class 之前先確認**專案載的是哪個版本**：
>
> | Library | 版本 | 怎麼確認 |
> |---|---|---|
> | Bootstrap | 5.1 | `*-subtle`、`*-emphasis` 系列（5.3）不能用 |
> | Font Awesome | 5.15.1 | icon 改過名的要用舊名（`fa-boxes` 而非 `fa-boxes-stacked`） |
>
> 查法：`grep -c "\.fa-名稱:before" <那支 all.min.css>` ——
> 回 0 就是這個版本沒有。Bootstrap 同理在 `app.css` 裡找。

## 順手做的全專案掃描

比對 `all.min.css` 裡所有 `.fa-xxx:before` 與程式碼中用到的 `fa-xxx`，
確認沒有其他漏網的。結果只有這一個真問題，另外三類是假陽性：

| 掃到的 | 為什麼不是問題 |
|---|---|
| `fa-1f1e6` 等 | tinymce 的 emoji 資料檔（第三方 vendor），不是 FA icon |
| `fa-chevron-` | JS 字串拼接 `'fa-chevron-' + (open ? 'down' : 'right')` |
| `fa-boxes-stacked` | 這次留下的**註解文字** |

靜態掃描抓不到字串拼接出來的 class 名 —— 那三類要人看過才能排除，
不能只看工具的輸出就下結論。

## 二、消耗品分頁跑出人員的統計與搜尋區

切到消耗品分頁，中間多出「人員、身份、狀態、總計」，搜尋區還有「年資」——
那些都是**人員管理分頁**的東西。

### 起因：新的 tab-pane 插在 `.tab-content` 外面

數一次 `<div>` 的巢狀深度就看得出來：

```
  34  深度 0→1  <div class="tab-content">
  36  深度 1→2  <div class="tab-pane ..." id="tab-staff">
 131  深度 1→2  <div class="tab-pane ..." id="tab-equipment">
 251  深度 0→1  <div class="tab-pane ..." id="tab-consumable">   ← 深度 0！
 269  深度 0→-1 </div>{{-- end tab-content --}}                   ← 變成 -1
```

`.tab-content` 其實在第 241 行就關閉了，而那個標著 `end tab-content` 的
`</div>` 是**多出來的一個** —— 它會把更外層的容器提早關掉，於是各區塊的
從屬關係整個亂掉：人員分頁的統計與搜尋區跑到了不該出現的地方。

**兩個症狀（多出統計、搜尋區有年資）其實是同一個破洞。**

### 為什麼會插錯

那個區域連續六層 `</div>`，縮排也不是嚴格遞減 —— 肉眼找「tab-content 的
收尾在哪一行」很容易差一層。而且註解 `{{-- end tab-content --}}` 掛在**錯的**
那個 `</div>` 上，照著註解找就一定插錯。

> ⚠️ **在深層巢狀的 blade 裡插東西，先用程式數一次深度，不要靠縮排與註解。**
>
> ```python
> depth += len(re.findall(r'<div\b', line)) - len(re.findall(r'</div>', line))
> ```
>
> 插入後再數一次驗證：新的 pane 要跟它的兄弟**同深度**（這裡是 1→2），
> 而且檔案結束時深度要回到 0。
>
> 註解標錯位置比沒有註解更危險 —— 它看起來是權威的。

### 修法

把區塊移到 `.tab-content` 真正的收尾之前，並把 `end tab-content` 的註解
掛到對的那一個 `</div>` 上。修完三個 pane 都是深度 1→2、檔案結束時平衡。

## 異動檔案

- `resources/views/admin/staff-manage/index.blade.php` — icon 名稱 + tab-pane 位置

## 相關

- [[consumable]] — 消耗品管理
- [[2026-09-30-qr-badge-dark-mode]] — 同一類的第一次（Bootstrap 版本）
