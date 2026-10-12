# AI 寫的文案送出去變成 `{"text":"…"}`

## 現象

客人收到的訊息長這樣，**連大括號一起**：

```
{"text":"老闆，超商後台這塊我已轉給專員為您確認，麻煩您稍等一下。"}
```

追問客人那句則是 `{"message":"想跟您確認一下，先前提到的…"}`（key 不一樣）。

## 起因：prompt 與 `--json-schema` 各講了一次格式

CLI 是這樣叫的（`ClaudeCodeMatcher::buildProcess()`）：

```
claude -p … --output-format json --json-schema '{"type":"object","properties":{"text":{"type":"string"}},"required":["text"]}'
```

輸出格式**已經由 schema 強制**。而 prompt 的最後一行又寫著：

```
只回 JSON：{"text":"你寫的那一句"}
```

於是模型照做了兩次 —— 它把「要輸出的那個 JSON」寫進 `text` 欄位的內容裡：

```jsonc
// 實測（2026-10-12，opus）
"result": "{\"text\":\"{\\\"text\\\":\\\"您好，關於超商後台開通的流程…\\\"}\"}"
"structured_output": {"text": "{\"text\":\"您好，關於超商後台開通的流程…\"}"}
```

`generateText()` 取 `text` 拿到的就是那一整串，原封不動送給客人。

追問客人那支的 prompt 寫的是「只輸出 JSON，不要解釋。」**連 key 都沒指定**，
所以模型自己挑了 `message` —— 那就是 `{"message":…}` 的由來。

## 為什麼現在才出現

因為以前**根本走不到這段**。`generateStructured()` 的失敗判斷原本是
`filled(Arr::get($envelope, 'is_error'))`，而 `filled(false)` 是 **true**
（見 [[2026-10-12-filled-on-boolean]]），所以每一次呼叫都被判成失敗而回 null，
全部的文案都退公版。

修好 `is_error` 之後，成功的回應第一次真正走完解析 —— 這個從上線就存在的
雙層問題才浮出來。

> ⚠️ **修好一個擋住一切的 bug，會讓它後面所有沒被走過的路一次曝光。**
> 那些路從來沒有在真實資料上跑過，等於全新的程式。

## 修法

把 prompt 裡講格式的那一行拿掉，改成只描述「要寫什麼」。五處文案 ＋ 一處判斷：

| 位置 | 原本 |
|---|---|
| `AUTO_REPLY.STRANGER`（陌生人回覆） | 只回 JSON：{"text":…} |
| `AUTO_REPLY.WAIT`（轉人工的稍等句） | 只回 JSON：{"text":…} |
| `AUTO_REPLY.REMIND.REPORT.ENCOURAGE`（個人／團隊各一） | 只回 JSON：{"text":…} |
| `FOLLOWUP.ASK_CUSTOMER`（追問客人） | 只輸出 JSON，不要解釋。 |
| `FOLLOWUP.DETECT`（等待詞確認） | 只輸出 JSON，不要解釋。 |

最後一支要的是布林（`is_waiting`），schema 塞不進一整串 JSON，所以沒出事 ——
但理由相同，一起改掉。

驗證（同一組參數實跑 CLI）：

- 帶「只回 JSON」→ `{"text": "{\"text\":\"…\"}"}` ← 重現
- 改成「直接寫那一句話本身」→ `{"text": "請稍候一下，我馬上為您查詢…"}` ← 正常

## 教訓

> ⚠️ **格式只能由一個地方決定。** 用了 `--json-schema`（或任何 structured
> output）之後，**prompt 裡就不要再提輸出格式** —— 模型會把它當成「內容的
> 一部分」而照做兩次。prompt 只負責「寫什麼」，schema 負責「長什麼樣」。
>
> ⚠️ 「只輸出 JSON，不要解釋」這句在**沒有** schema 的年代是必要的，
> 加上 schema 之後就從「必要」變成「有害」。改架構時要回頭刪掉那些
> 已經被取代的指示 —— 它們不會報錯，只會安靜地做錯事。

## 異動檔案

- `config/constants.php` — 六處 prompt 的最後一行

## 相關

- [[2026-10-12-filled-on-boolean]] — 擋住這一切的那個 bug
- [[auto-reply]] — Claude CLI 的呼叫與 schema
- [[customer-followup]] — 追問客人那一支
