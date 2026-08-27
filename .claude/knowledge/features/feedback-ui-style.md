# UI 樣式注意事項

## 現況

多次出現 CSS 選擇器遺漏 `input[type="password"]` 和 `input[type="text"]` 的問題。

## 規則

- 在 modal 或 form 中新增 input 時，確認 CSS 選擇器包含該 type
- 常見需要涵蓋的 type：`text`, `password`, `email`, `number`, `date`, `time`
- 使用者不要 alert，所有操作回饋用 modal

## 暗黑模式：選中狀態不要只靠 `bg-light`

`bg-light` 在暗黑模式下是**白底**，整列會反白、文字看不見
（2026-08-27 在快速回覆題庫管理頁踩到）。

列表項目要標示「選中」時，除了加 `bg-light`，還要給它一個樣式用的 class
並在 `custom.css` 補 dark 規則 —— 比照既有的 `.tg-group-item`：

```css
[data-theme="dark"] .xxx-item { border-color: rgba(255,255,255,0.06) !important; color: #e0e0e0 !important; }
[data-theme="dark"] .xxx-item:hover { background: rgba(212,175,55,0.08) !important; }
[data-theme="dark"] .xxx-item.bg-light { background: rgba(212,175,55,0.15) !important; }
```

> `js-` 開頭的 class 只作為 JS hook，樣式另外用語意 class（如 `qr-category-item`），不要混用。

## 刪除按鈕

**不要用 `btn-outline-danger`**，在本專案會顯示成淺底淺字、幾乎看不見
（2026-08-27 在快速回覆題庫管理頁踩到；`custom.css` 只針對 `btn-outline-secondary`
與 `btn-danger` 做了樣式，`outline-danger` 沒被涵蓋）。

列表中的刪除鈕照 `task-board` 的做法：外框用 `btn-outline-secondary`（與同排其他按鈕一致），
靠 `text-danger` 表達危險語意。

```html
<button class="btn btn-sm btn-outline-secondary js-xxx-delete">
    <i class="fas fa-trash text-danger me-1"></i><span class="text-danger">刪除</span>
</button>
```

確認 Modal 裡的「確定刪除」則用實心 `btn btn-danger`（`payment-config`、`finance` 等皆是）。

## 訊息／確認 Modal 的 markup

**不要用 `modal-dialog-centered`。** 它帶有 `min-height: calc(100% - 1rem)`，
會把 dialog 撐成整個視窗高度，畫面上會看到一條貫穿上下的白框把訊息夾在中間
（2026-08-27 在快速回覆題庫管理頁踩到）。

照既有頁面的寫法（`payment-config`、`staff-manage`）：按鈕放在 `modal-body` 內，
不另開 `modal-footer`。

```html
<div class="modal fade" id="modal-xxx-msg" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-body text-center py-4">
                <p id="modal-xxx-msg-text" class="mb-3"></p>
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>
```

## Modal 接續開啟（關一個、開另一個）

**關掉一個 modal 後不能立刻開下一個**，否則兩層會疊在一起、backdrop 也會打架
（畫面上會看到「兩個視窗」）。全域的 `showBsModal` / `hideBsModal`
（`layouts/app.blade.php`）是**手動管理 backdrop** 的，Bootstrap 的自動堆疊不適用。

既有寫法有兩種，都可以：

```js
// A. 關閉後固定延遲（staff-manage / finance / task-board）
hideBsModal(document.getElementById('modal-xxx'));
setTimeout(function () { showMsg(body.message); }, 400);

// B. 依現況判斷（payment-config / vm）
var hasBackdrop = document.querySelectorAll('.modal-backdrop').length > 0;
if (hasBackdrop) { setTimeout(function () { showBsModal('modal-msg'); }, 400); }
else { showBsModal('modal-msg'); }
```

> 寫 B 時**不能只檢查 `.modal-backdrop`**：`hideBsModal()` 會立刻移除 backdrop，
> 但 `.modal.show` 要等淡出動畫結束才拿掉。若訊息是在 `hideBsModal()` 之後才顯示，
> 必須連 `.modal.show` 一起檢查（見 `public/js/quick-reply-admin.js` 的 `showMsg()`）。
