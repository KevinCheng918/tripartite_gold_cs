# 手機上滑對話，滑著滑著變成在滑整個頁面

> 需求方 2026-11-22：「我 Telegram 對話窗手機版，有時候要滑對話訊息都會
> 卡住，都會變成滑到外面的上下滑動，而不是對話裡面的上下滑動，但是我這個
> 對話窗裡面，應該是要滿版的對話，不應該滑動到外面的上下才對。」

手指明明放在訊息上，動的卻是背景 —— 而且「有時候」才會，所以更難查。

## 兩個獨立的原因，疊在一起

### 一、底下有一塊跟螢幕差不多高的空白佔位

```css
/* 全域（桌機版寫的） */
.chat-layout .app-inner-layout__wrapper {
    height: calc(100vh - 220px) !important;
}
```

而手機版的 sidebar 與 content **都是 `position: fixed`** —— 它們不佔空間。
於是 `wrapper` 變成一塊空的、高度接近整個螢幕的佔位，**整個頁面因此比
視窗還長、可以捲**。

使用者以為自己在滑訊息，其實滑的是底下那塊什麼都沒有的空白。

⚠ 這解釋了「有時候」：手指落點剛好在訊息區上才會捲訊息，
落在別處（或訊息已經到頂／到底）就變成捲那塊空白。

修法：手機版把 `wrapper` 的高度歸零（`height: 0; min-height: 0; overflow: hidden`）
—— 它的子元素都 `fixed`，本來就不需要佔位。

### 二、捲到頂／底時，捲動會「交棒」給外層（scroll chaining）

這是瀏覽器的預設行為：內層捲到底之後繼續滑，剩下的捲動量交給父層。
所以訊息捲到最上面再往下滑，頁面就跟著動。

修法：`overscroll-behavior: contain`（訊息區與對話列表都要）。

## 第三道：對話開著時直接鎖住 body

```css
@media (max-width: 767.98px) {
    body.tg-chat-locked { overflow: hidden !important; }
}
```

class 由 `layout.js` 的 `setChatting()` 跟著 `tg-chatting` 一起加／拿掉。

⚠ **為什麼要第三道**：前兩道是針對「現在知道的兩個原因」，而這一道是
結構性的 —— 聊天區是滿版的 `fixed`，背景沒有任何東西需要看，
就算哪天又有別的元素把頁面撐高，背景也不會動。

⚠⚠ **離開對話一定要解鎖**，否則整個後台的其他頁面都捲不動。
兩個地方處理：`setChatting(false)`（按返回），以及 `renderLayout()`
開頭無條件先 `remove` 一次（萬一上次是在對話中離開頁面而殘留）。

⚠ 桌機不受影響：那條規則在 media query 裡，`body` 加了 class 也不生效。

## 驗證

本機沒有真的手機，而瀏覽器視窗縮放（`resize_window`）沒有真的改到
viewport，所以 media query 套不上去。改成**直接讀 CSSOM** 確認四條規則
都落在 `(max-width: 767.98px)` 裡、而且會覆蓋桌機那條 `calc(100vh - 220px)`：

| 選擇器 | media | 值 |
|---|---|---|
| `.chat-layout .app-inner-layout__wrapper` | （無） | `height: calc(-220px + 100vh)` ← 桌機版 |
| `.chat-layout .app-inner-layout__wrapper` | `max-width: 767.98px` | `height: 0px`、`overflow: hidden` ← 蓋過上面那條 |
| `.chat-layout.tg-chatting .chat-wrapper` | `max-width: 767.98px` | `overscroll-behavior: contain` |
| `.chat-layout .app-inner-layout__sidebar #tg-group-list` | `max-width: 767.98px` | `overscroll-behavior: contain` |
| `body.tg-chat-locked` | `max-width: 767.98px` | `overflow: hidden` |

⚠ **實際的滑動手感要在真的手機上才驗得出來。** 上線後請在手機確認：
訊息捲到最上面再往下滑，背景不會跟著動；按返回之後整個後台恢復正常捲動。

## 同一支 `setChatting()` 順手做的：進對話自動收合 sidebar

> 需求方 2026-11-22：「手機版……如果有打開 sidebar，我點旁邊的對話，
> 進入後，要自動收合 sidebar。」

⚠ **按鈕的 `is-active` 要一起拿掉**：展開狀態是**兩個 class 各記一份** ——
`.app-container.sidebar-mobile-open`（容器）與 `.mobile-toggle-nav.is-active`
（漢堡按鈕），Architect 的 `app.js` 是同時 toggle 兩者。

只清容器那個的話，按鈕會停在「已展開」的樣子 —— 下次按它等於再 toggle
一次，**選單反而打不開**。

驗過：模擬「已展開」狀態後點對話 ⇒ 兩個 class 都被清掉，
`tg-chatting` 與 `tg-chat-locked` 照常生效。

## ⚠⚠ 後續：手機版 header 跑到畫面中間（2026-11-23）

> 需求方：「我現在手機版的 Telegram 聊天視窗的 header 跑下來了。」
> 截圖：聊天 header（返回鍵、群組名、刪除對話）出現在畫面中間，
> **上面還露出一行訊息**。確認是「一進去就這樣、沒滑過」，Safari 瀏覽器。

### 成因：sticky 的捲動容器與它的 fixed 父層對不上

`#tg-header` 有一條全域的 `position: sticky; top: 0`（給桌機用的）。
而手機版的聊天區 `.app-inner-layout__content` 是 **`position: fixed`**。

| | 參考的是誰 |
|---|---|
| sticky 的位移計算 | 最近的**捲動容器** = `.app-main__inner`（`overflow-y: auto`） |
| header 的 containing block | **fixed 父層**（相對 viewport，不跟著捲） |

兩者對不上時，瀏覽器會把 header 往下推一段「它以為被捲掉的距離」——
聊天區本身不動，於是 header 卡在中間，空出來的地方露出底下的訊息。

### 修法：手機版讓它 static

```css
@media (max-width: 767.98px) {
    .chat-layout.tg-chatting #tg-header { position: static !important; }
}
```

⚠ 手機版**根本不需要 sticky**：聊天區是 fixed ＋ flex column，
header 是第一個子元素，本來就固定在最上面。

⚠ **桌機維持 sticky 不動** —— 那裡捲的確實是 `.app-main__inner`，
而聊天區不是 fixed，兩者一致。已確認桌機的 computed style 仍是 sticky。

### ⚠ 本機重現不出來，這是推論

`.app-main__inner` 在本機的捲動量是 0（而且前一次修正把 wrapper 壓成
0 高之後更難製造），所以**沒有在本機看到 header 真的跑掉**。
上面那段是從「sticky ＋ fixed 父層 ＋ 另一個捲動容器」這個結構推出來的，
修法本身則是確定安全的（手機版那個 sticky 本來就是多餘的）。

⚠ **要請需求方在手機上確認**。如果還是會跑，下一個要查的是
`.app-main__inner` 的捲動量 —— 手機版對話是滿版 fixed，
那個容器的捲動對它沒有意義，可以考慮在 `tg-chatting` 時一起鎖住。
