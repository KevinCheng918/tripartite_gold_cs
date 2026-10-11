# 置中彈窗後面多一塊「白板」

## 症狀

開啟任何用 `modal-dialog-centered` 的彈窗時，畫面上會多出一條**縱貫上下、
約 500px 寬的亮色長條**，對話框浮在它中間，像是彈窗後面壓了一塊板子。
一般（非置中）的彈窗沒事。

## 病根

`vendors/architect-ui/styles/css/base.css:12705` 把四層陰影下在 **`.modal-dialog`** 上：

```css
.modal-dialog {
  box-shadow: 0 0.76875rem 2.4875rem rgba(52,58,64,0.3), ... ;
}
```

Architect 的假設是「dialog 的高度就是內容高度」—— 那樣陰影剛好繞著對話框，
看起來正常，**十三個非置中的彈窗都是這樣，所以一直沒人發現**。

但 Bootstrap 的 `.modal-dialog-centered` 是：

```css
.modal-dialog-centered { display: flex; align-items: center; min-height: calc(100% - 3.5rem); }
```

dialog 於是變成一個**幾乎滿版、本身透明**的大矩形。陰影照樣沿著它的邊畫，
那圈暗邊就把中間「沒有陰影」的區域框了出來 —— 看起來像亮色長條，
其實是**外面被陰影壓暗**，不是裡面多了東西。

## 排查時的岔路

`getComputedStyle` 查 `.modal` 與 `.modal-dialog` 的 `backgroundColor`
都是 `rgba(0,0,0,0)`，一度以為不是它們。**背景透明不代表不會畫出東西** ——
`box-shadow` 畫在元素邊界外，跟背景色無關。要連 `boxShadow` 一起看。

## 修法

陰影搬到真正看得見的那塊（`custom.css`）：

```css
.modal-dialog { box-shadow: none; }
.modal-content { box-shadow: /* 原本那四層 */ ; }
```

⚠ 不加 `!important`：深色模式另有一條帶 `!important` 的
`.modal-content` 金色光暈，要讓它繼續贏。

## 驗證

| 情境 | 結果 |
|---|---|
| 置中彈窗（淺色） | 白板消失 |
| 置中彈窗（深色） | 白板消失，金色光暈還在（`rgba(212,175,55,0.15) 0 0 20px`） |
| 一般彈窗 | `dialogHeight === contentHeight`，陰影搬家前後看起來一樣 |

全站有五個頁面用 `modal-dialog-centered`：followup、quick-reply、report、
shared-file、layouts/app。
