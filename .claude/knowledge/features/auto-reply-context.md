# 自動回覆帶對話脈絡

## 現況

已完成。

## 問題

客人常常分兩則講一件事：

```
12:39  客人：<系統異常訊息>
              index: (代收異常)deposit-apply
              商店代號: ds888888
              異常說明: 支付通道異常(取不到通道)
              ...
12:39  客人：這是什麼錯誤呢
```

第一則完全命中題庫（支付通道異常 10003），第二則卻被判成未知問題轉人工。

原因在 `ClaudeCodeMatcher::buildProcess()`：

```php
'-p', $text,   // 只有這一句
```

送進去的只有「這是什麼錯誤呢」—— 沒有主詞、沒有錯誤碼、沒有任何可比對的內容。
模型判成「提問」是對的，找不到答案也是對的，它根本沒看到前一則。

同樣的狀況還有：「那這個要怎麼處理」「剛剛那個呢」「上面那筆」「所以是失敗嗎」。
客人只要用代名詞指前一則，就一定轉人工。

轉出去的求助單也一樣沒有脈絡 —— 客服收到的只有「問題：這是什麼錯誤呢」，
還得自己切回對話視窗往上翻，才知道在問什麼。

## 做法

比對前先撈同一個對話的近期訊息（`TelegramRepository::getRecentMessages()`），
在 `AutoReplyService::buildHistory()` 轉成一行一則的文字，
跟客人這一句一起送進去。

### 取哪些訊息

| 項目 | 值 | 理由 |
|---|---|---|
| 則數 | 往前 **6 則**（不含本則） | 夠涵蓋「貼錯誤訊息 → 追問」這種兩三則的組合，又不會把整場對話塞進去 |
| 時間窗 | **15 分鐘**內 | 昨天的對話跟現在這句無關，帶進去只會誤導。客人隔了半小時再問一句，那通常是新問題 |
| 方向 | 收、發**都要** | 客人常常是在追問客服或 AI 剛才說的話（「所以要改哪一個」） |
| 單則長度 | 截 **600 字** | 上面那則系統異常訊息約 400 字，600 字能完整帶到；再長的多半是貼整包 log |
| 總長度 | 截 **3000 字** | 超過**從最舊的開始丟** —— 離客人這句話越近的越可能是他在指的東西 |

設定在 `config/auto_reply.php` 的 `context`。`limit` 填 0 可以整個關掉。

**被忽略名單擋掉的成員，訊息照樣當脈絡。** 忽略名單只是「不替他自動回覆」，
訊息仍然進 DB。上面這個案例裡的系統異常訊息很可能就是 BOT 發的 ——
要是把它排除在脈絡外，這個功能對最常見的情境反而失效。

無文字的訊息（圖片、檔案、語音）帶 `MEDIA_LABELS` 的標籤進去，不要整則跳過：
客人問「這張圖是什麼意思」時，至少要讓模型知道上一則真的有一張圖。

每則的換行會被壓成空白，一則就是一行 —— 否則模型分不出哪裡是一則的開始。

### 送進去的格式

```
<前面的對話>
客人 陳小明：<系統異常訊息> index: (代收異常)deposit-apply 商店代號: ds888888 ...
客服：好的，我看一下
</前面的對話>

<客人的問題>
這是什麼錯誤呢
</客人的問題>
```

沒有脈絡時送的就是客人的原話，跟改版前完全一樣。

> 脈絡刻意放在 user message 這一側，**不放 system prompt**：
> 題庫那份是靠 prompt cache 省錢的，每則訊息都不同的內容混進去會讓快取整個失效。

### system prompt 補的規則

- **要判斷、要回應的永遠是 `<客人的問題>` 那一句**
- `<前面的對話>` 只用來看懂他在指什麼；客人貼了一長串錯誤訊息再問「這是什麼錯誤呢」，
  要去前面把那串錯誤訊息讀出來，當成他真正在問的東西
- 前面的對話**不是**要回答的題目，不要翻回去重答一次
- **客人這句話跟前面無關時，就當作沒有脈絡**

> ⚠️ 最後一條是必要的。少了它，客人說「謝謝」會被脈絡帶著去找答案，
> 寒暄被誤判成提問。

opening 那段也補了一條：不要複述前面的對話（「您剛剛提到的…」再把錯誤訊息唸一遍）。

> prompt 內容改了，`prompt_cache_key` 要跟著換版號（現在是 `auto_reply.prompt.v2`），
> 否則舊的 prompt 會留在快取裡直到 TTL 到期。

### 求助單的前情

`AutoReplySupportService::buildAskText()` 加一段，只帶**最後 2 則**、每則截 200 字：

```
🔔 題庫裡找不到答案

群組：ＣＳ測試
問題：這是什麼錯誤呢

前情：
  客人：<系統異常訊息> index: (代收異常)deposit-apply 商店代號: ds888888 ...

🤖 AI 判斷：提問
```

模型拿的是完整脈絡（越多越好判斷），同仁要的只是「這句在講什麼」，所以這裡另外砍一次 ——
客人貼的整包 log 原封不動轉過來會把內部群組洗版。

> 前情內容不必自己 escape：`TelegramBotService::sendMessage()` 會對整段做 `escapeHtml()`，
> `<系統異常訊息>` 這種角括號不會被 Telegram 當成 HTML 標籤。

## 兩個坑

> ⚠️ **`id <> NULL` 在 SQL 裡恆為 NULL**，整個 WHERE 會變成 false，一則都撈不到
> 而且不會報錯。`getRecentMessages()` 排除「客人現在這一則」時一定要先
> `filled($excludeId)` 判斷 —— `auto-reply:test` 這類沒有 message id 的呼叫會踩到。

> ⚠️ **config 快取沒更新就會整個靜默關閉。** `auto_reply.context.*` 讀不到時
> `limit` 是 0，脈絡完全不撈、不報錯、不寫 log，只是客人的追問又開始轉人工。
> 所以 `AutoReplyService::contextSetting()` 有一組 `FALLBACK_CONTEXT` 備用值
> （同 `AutoReplyProgressService` 的 `FALLBACK_PREFIX`）。
> 刻意填 0 關掉不會被蓋掉 —— `blank(0)` 是 false。

## 成本

多的是每次幾百個 token。換掉一次轉人工就回本了。

## 架構檔案

- `config/auto_reply.php` — `context.*`
- `app/Repositories/TelegramRepository.php` — `getRecentMessages()`
- `app/Services/AutoReplyService.php` — `buildHistory()` / `trimHistory()` / `contextSetting()`
- `app/Services/AutoReply/ClaudeCodeMatcher.php` — `buildUserPrompt()`、system prompt 的「你會收到什麼」
- `app/Services/AutoReplySupportService.php` — `tailHistory()`、`buildAskText()`
- `app/Contracts/AutoReplyMatcher.php` — `$context` 多吃一個 `history`

## 注意事項

- 後台的「預覽」（`AutoReplyService::preview()`）**不帶脈絡** —— 那是拿來測題庫比對的，
  沒有真實對話可撈
- 客服送出的訊息結尾會帶署名（`-A` 這類），會原樣進脈絡。
  對模型是雜訊但不影響判斷，沒有特別去掉

## 相關

- [[auto-reply]] — 自動回覆主體
- [[auto-reply-natural]] — intent 判斷與承接句
- [[ignore-member]] — 忽略名單成員的訊息仍會當脈絡
