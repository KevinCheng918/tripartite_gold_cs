# 客人傳圖、或只打幾個字 → AI 既不回應也不轉人工

**日期**：2026-11-08（v3.80）
**回報**：「我發現有時候客人給了一張圖，然後給一個字，或是只有幾個字，
AI 沒有回應，也沒有轉回來」

## 兩個獨立的洞，結局一樣

客人等著，而**沒有任何人知道這件事**。

### 洞一：純媒體訊息連 Job 都不派送

`TelegramChatService` 的觸發條件是 `filled($rawText)` —— 客人只傳一張圖、
一個檔而沒打字時，`$rawText` 是空的，`AutoReplyJob` 根本不會被派送。
不回答、不開求助單、什麼都沒有。

而**錯誤畫面、對帳單、收據截圖本身就是他要問的東西** —— 這不是邊緣情況，
是最常見的提問方式之一。

### 洞二：SILENT 是黑洞，而它宣稱的安全網不存在

`decide()` 在 `intent === chat` 且沒有承接句時回 `SILENT`，
`dispatchResult()` 只記一行 Log 就 `return`。

那段註解寫著：

> 刻意不標記已回覆：萬一這是誤判（客人其實有問事情），
> 既有的**未回覆告警**仍會響，客服就會接手

⚠⚠ **那道安全網不存在。** 查證過：

| 檢查 | 結果 |
|---|---|
| 誰寫 `replied` | `markMessagesReplied()`（設成 true） |
| 誰讀 `replied` | 只有 `TelegramMessageResource`（丟給前端顯示） |
| 有查詢撈 `replied = false` 去告警嗎 | **沒有**。唯一的 `where('replied', false)` 是那支 update 自己 |
| 排程裡有未回覆告警嗎 | **沒有**。28 支指令沒有一支是 |

所以 SILENT 就是完全靜默，而且**沒有任何下游會發現**。

## 根因：intent enum 沒有「我不確定」這個選項

`AUTO_REPLY.INTENT` 原本只有三個值：

```
question / request / chat
```

模型拿到「一張圖」「一個字」「？」這種分不出意圖的訊息時，enum 逼它選一個
—— 而最像的就是 `chat`。接著 `chat` 又沒有承接句可寫，於是落到 `SILENT`。

⚠⚠ **「不確定」被轉成了「不用理」** —— 而這套系統其他每一處的原則都是
**「沒把握就轉人工」**（`confidence` 填 low 轉人工、題庫沒命中轉人工、
比對器掛了轉人工）。只有這一條路反過來。

## 修法

### 一、加 `unclear` intent，走轉人工

| 檔案 | 改什麼 |
|---|---|
| `constants.AUTO_REPLY.INTENT` | 加 `'UNCLEAR' => 'unclear'` |
| `AutoReplyService::decide()` | `unclear` → `WAIT`，**擺在 `chat` 判斷之前** |
| `ClaudeCodeMatcher::buildSchema()` | enum 自動帶入（`array_values($intents)`）；`intent` 的 description 明寫「分不出來就填 unclear，不要硬塞一個」 |
| 同上 `opening` 的 description | 補「**看不懂或資訊太少時不要填 null**，那種情況把 intent 填 unclear」 |
| `buildSystemPrompt()` | 新增 `unclear` 的判斷規則與例子 |

⚠ **schema 的 description 一定要講**，不能只把值加進 enum。只列舉可選值的話，
模型仍然會挑「最像的那個」。

⚠ **SILENT 沒有被拿掉**（需求方選的）。它現在有**正面依據**才成立：模型已經
有 `unclear` 可以表達「我分不出來」，所以它還是填 `chat` ＋ 不給承接句，
就真的是在說「客人只是在道謝／說收到」。客人說一句「好」就開求助單的話，
支援群組會被雜訊淹掉，而淹掉之後連真的單也沒人看。

### 二、純媒體也觸發，用標籤當問題

`TelegramChatService::autoReplyQuestion($rawText, $mediaType)`：
打了字用他的字，只傳媒體時用 `[圖片]`／`[檔案]` 這種標籤
（`TELEGRAM.MEDIA_LABELS`，`mergeRun()` 本來就是這樣做的）。

⚠ **貼圖與動圖排除**（`TELEGRAM.AUTO_REPLY_SKIP_MEDIA`）：那是寒暄，
為它們開求助單只會製造雜訊。

### ⚠⚠ 兩個修正有交互作用，少做一半會把 bug 做回來

補了洞一之後，模型**開始收到 `[圖片]` 這種輸入**。如果 prompt 沒教它怎麼處理，
它很可能把「只有一個標籤、看起來沒在問什麼」判成 `chat` → 又變 SILENT ——
洞一補了，但結局一模一樣。

所以 prompt 專門寫了一節：

> ⚠ **你看不到那張圖、那個檔的內容。** 客人傳錯誤畫面、對帳單、收據截圖
> 都很常見，而那張圖本身就是他要問的東西 —— 但你讀不到，所以**一個字都不要猜**。
> 這種情況一律 `unclear`，轉給同仁去看那張圖。
>
> ⚠ **絕對不要因為「只有一個標籤、看起來沒在問什麼」就判成 `chat`。**
> 客人傳了東西給我們，那不是寒暄。

### 三、prompt 快取 key 換版（v4 → v5）

`config/auto_reply.php` 的註解本來就寫明「prompt 內容改版時要一起換 key，
否則舊的會留到 TTL 到期才換掉」。改了 prompt 沒換 key 的話，
**上線後最多 5 分鐘內還是舊行為** —— 而那段時間剛好是在看修好了沒。

## 實測

### `decide()`：模型回什麼 → 系統做什麼

| 模型回傳 | 結局 | |
|---|---|---|
| `unclear` | `wait`（轉人工） | ✅ 本次重點 |
| `unclear` ＋ 有承接句 | `wait` | ✅ |
| `chat` ＋ 無承接句 | `silent` | ✅ 「好」「收到」仍不打擾 |
| `chat` ＋ 有承接句 | `reply` | ✅ |
| `request` | `wait` | ✅ |
| `question` ＋ low | `wait` | ✅ |
| `question` ＋ high 但題目已停用 | `wait` | ✅ |
| 空陣列（模型掛掉） | `wait` | ✅ |
| `intent` 是垃圾值 | `wait` | ✅ |

### `autoReplyQuestion()`：哪些訊息會觸發

| 訊息 | 丟給模型的問題 | |
|---|---|---|
| 客人打字 | 他的原文 | ✅ |
| 只傳圖片 | `[圖片]` | ✅ 本次重點 |
| 只傳檔案／語音／影片 | `[檔案]`／`[語音訊息]`／`[影片]` | ✅ |
| 只傳貼圖／動圖 | **不觸發** | ✅ 刻意 |
| 圖片＋文字 | 用文字 | ✅ |
| 兩者都沒有 | 不觸發 | ✅ |

### 其他

| 檢查 | 結果 |
|---|---|
| `unclear` 真的在送給模型的 schema enum 裡 | ✅ `question, request, chat, unclear` |
| prompt 含 `unclear`／`[圖片]`／「分不出來」／「你看不到那張圖」 | ✅ 四項都在 |
| prompt 快取 key | ✅ `auto_reply.prompt.v5` |
| `Arr::get` 重構後 decide() 九種結局 | ✅ 全部不變 |

## 收尾稽核（PROMPTS.md）

`decide()` 原本有 **9 處陣列下標**（`$intents['CHAT']`、`$actions['WAIT']` …），
其中 2 處是本次新增的。全部換成 `Arr::get()` —— 只換自己那兩行會讓同一個
方法內兩種寫法並存，而規則是 `陣列取值一律 Arr::get`。零行為變更，
九種結局重跑驗證過。

⚠ **prompt 字串裡的 `{$intents['UNCLEAR']}` 刻意保留下標**：那是字串插值
（`Arr::get()` 沒辦法寫在 `"{...}"` 裡而不另開變數），而且周圍四十幾行
既有的 prompt 全都是這個寫法。

## 還沒做（這次刻意不做）

**「未回覆告警」排程**。洞二的註解依賴它、而它不存在 —— 這是個真實的系統性
缺口：客人訊息超過 N 分鐘沒有人回（不論是 AI 靜默、客服漏看、還是 Job 掛掉）
都該有人被通知。本次是用「收斂 SILENT」把洞補起來，但那只擋得住 AI 這一條
路徑；客服自己漏看仍然沒有安全網。

需求方 2026-11-08 選的是「收斂 SILENT」而不是「補告警」，理由是漏回的那幾
分鐘客人仍在等。要做的話是另一個任務。

## 動到的檔案

| 檔案 | 說明 |
|---|---|
| `config/constants.php` | `INTENT.UNCLEAR`、`TELEGRAM.AUTO_REPLY_SKIP_MEDIA` |
| `config/auto_reply.php` | prompt 快取 key v4 → v5 |
| `app/Services/AutoReplyService.php` | `decide()` 加 `unclear` 分支；9 處下標改 `Arr::get` |
| `app/Services/AutoReply/ClaudeCodeMatcher.php` | prompt 的 `unclear` 與圖片規則；schema 兩個 description |
| `app/Services/TelegramChatService.php` | `autoReplyQuestion()`；觸發條件改用它 |
| `config/changelog.php` | v3.80 |

改了 `config/` → 已在容器內跑 `php artisan optimize`。

## 相關

- [[../features/auto-reply]] — 求助單流程與狀態機
- [[../features/auto-reply-natural]] — intent 判斷與承接句（`unclear` 是第四個 intent）
- [[../features/auto-reply-burst]] — 連發合併（客人連傳五張圖只會開一張單靠它）
- [[2026-11-07-ticket-blocked-by-group-ignore]] — 同一天的另一個「狀態被當成兩種意思」
