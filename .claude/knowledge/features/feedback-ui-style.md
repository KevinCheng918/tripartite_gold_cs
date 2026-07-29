# UI 樣式注意事項

## 現況

多次出現 CSS 選擇器遺漏 `input[type="password"]` 和 `input[type="text"]` 的問題。

## 規則

- 在 modal 或 form 中新增 input 時，確認 CSS 選擇器包含該 type
- 常見需要涵蓋的 type：`text`, `password`, `email`, `number`, `date`, `time`
- 使用者不要 alert，所有操作回饋用 modal
