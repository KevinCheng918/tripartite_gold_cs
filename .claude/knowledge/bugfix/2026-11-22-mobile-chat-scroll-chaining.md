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
