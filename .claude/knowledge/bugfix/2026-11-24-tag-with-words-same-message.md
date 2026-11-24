# 「龍 所以改好了嗎？」—— tag 跟話在同一則，硬規則接不住

> 需求方 2026-11-24（附截圖）：客人群組裡，對方的人發了
> 「**龍** 所以改好了嗎？」（「龍」是他們自己人，沒有 username
> 所以顯示成名字），小幫手回了
> 「老闆，這個狀態已經請專員為您確認，再麻煩您稍等一下 -A」。
>
> 「這個『龍』沒有 id，所以他會是名字，ai 會判斷它當作是問題，這個不對。」
>
> 修法的指示：「**要 AI 判斷一下上下文吧，不能寫死的**。」
>
> 追加（這一條推翻了前兩集的作法）：「不是講給我們的就靜默，
> **整則都是外人的話還是要判斷** —— 因為有時候是對方跟對方的人講說
> 可以跟我們求助，這樣只會標他們的人，不該被靜默。」

這是同一條線的第三集，而且是**把前兩集的硬規則收掉**的一集：

| | 情境 | 當時的作法 | 現在 |
|---|---|---|---|
| [[2026-10-27-tag-only-message-auto-replied]] | 整則只有 `@外人` | 硬規則：挖掉 tag 沒剩字就擋 | **已移除** |
| [[2026-11-13-tag-outsider-next-message]] | 先 tag、下一則才講話 | 硬規則：60 秒靜默窗 | **已移除** |
| 這次 | tag 跟話在同一則 | 接不住 | 三種都改由模型判 |

## 為什麼不是再加一條規則

`isTagToOutsidersOnly()` 的第一關是「挖掉 tag 之後還有沒有話」——
`所以改好了嗎？` 有話，所以放行。靜默窗也點不燃（它的點火條件正是
「整則都是 tag」那一則，這次客人沒有單獨發那一則）。

要再用規則接，只能寫成「句首是 tag 外人就擋」。而**同樣的結構會有相反的
意思**：

```
龍 所以改好了嗎？          ← 問他同事的，不該回
@sl0168888 這個帳號不能登入 ← 問我們的，要回
阿明 這個你跟客服說一下     ← 叫自己人來找我們，更要回
```

第三種是需求方特別點出來的：**他 tag 自己人，但那一則反而是要我們接手的。**
規則分不出來，而猜錯的方向是「客人真的在求助卻被靜音」。

## 作法：事實歸程式，語意歸模型

分工要切乾淨，兩邊都不要做對方的事。

| | 誰負責 | 為什麼 |
|---|---|---|
| 這則 tag 了誰、是不是我方 | 程式（`entities`） | 事實。模型猜不出「龍」是人名還是東西，更不知道他是哪一邊的 |
| 這句在等誰回答 | 模型（看上下文） | 語意。規則寫不出來 |

### 1. 新的 intent：`not_for_us`

`AUTO_REPLY.INTENT` 多一個值，`decide()` 走 **SILENT**：不回客人、不開求助單。

⚠ **不標記已回覆** —— 判錯時那則還掛在未回覆上，既有的未回覆告警照樣會響，
客服會接手。這是唯一的安全網，不能拿掉
（不然就是 [[2026-11-08-silent-black-hole]] 的形狀）。

⚠ **不開求助單是刻意的**：判斷是模型做的、一定會判錯，對方同事之間的每一句
話都開一張單，支援群組會被淹掉 —— 淹掉之後連真的單也沒人看。

### 2. 餵給模型的是事實，不是結論

`<這則訊息 tag 了誰>` 自成一個區塊，跟客人那句話分開：

```
<這則訊息 tag 了誰>
龍（不是我們的人，是對方那邊的）
</這則訊息 tag 了誰>

<客人的問題>
所以改好了嗎？
</客人的問題>
```

⚠ **不要在這裡寫「所以他在跟自己人講話」** —— 那就等於把判斷做掉了，
退回硬規則。prompt 裡另外明寫「tag 到對方的人**不等於**這句不是問我們」，
並附一張對照表（含「這個你跟客服說一下」那種）。

⚠ **tag 要跟客人的話分開區塊，不要混進那句話裡**：混進去的話模型會把我們的
註記當成客人打的字，承接句裡就會冒出「不是我們的人」這種客人沒說過的字眼。

### 3. ⚠⚠ 脈絡裡也要帶 tag，否則第二集會退化

人幾乎不會把「叫人」跟「要講的話」塞進同一則 —— 11/13 那個案例
（先發一則只有 `Amy 2.0`、下一則才問「所有人給你嗎？」）真正要判的是
**下一則**，而那一則身上一點 tag 痕跡都沒有。

光看文字，脈絡裡的「Amy 2.0」只是一個普通句子。實測（用線上那份 prompt
打真的模型）：

| 脈絡 | 判成 | 結果 |
|---|---|---|
| `客人 RM：Amy 2.0` | `unclear` | 回一句「稍等」＋ 開求助單 ✗ |
| `客人 RM：Amy 2.0　〔這則 tag 了：Amy 2.0（不是我們的人）〕` | `not_for_us` | 完全不回 ✓ |

所以 tag **必須落地**：`telegram_message` 新增 `mentions` 欄位，
`buildHistory()` 把它寫成每一行後面的 `〔這則 tag 了：…〕`。

⚠ **不能事後從文字拆回來**：`text_mention`（對方沒有 username）文字裡根本
沒有 `@`，存進 `content` 之後誰都看不出「龍」是在叫人。這是「只有 webhook
那一刻算得出來」的資料，跟 `$quote['ours']` 同一類。

## 順手修到的兩處

- **退路正則會把 email 當成 tag**：`service@example.com` 裡的 `@example`。
  以前這條的輸出只拿來算「挖掉 tag 還有沒有字」，多抓一個無害；現在它會變成
  一行「@example（不是我們的人）」送進模型 —— **那是在餵假情報**。
  改成要求 `@` 前面是行首或空白（跟輸入框那支 `mention.js` 同一條規則）。
- **`$quote['ours']` 只認得機器人發的**：同仁本人也可能在群組裡用自己的
  Telegram 帳號講話，客人引用那一則時他一樣是在回我們。改走
  `repliesToOurSide()`（比 Bot username，再比我方名單）。

## 驗證（2026-11-24）

**解析層**（reflection 叫 private method，`botService`／`staffIgnoreService`
用假物件，不碰 DB 也不打 Telegram）：

| 情況 | 結果 |
|---|---|
| `text_mention` 外人（「龍」） | 龍→外人 ✓ |
| emoji 前綴（UTF-16 偏移） | 切字正確 ✓ |
| `@sl0168888` | →外人 ✓ |
| `@cs_mei`（我方 username） | →我方 ✓ |
| `@lv_cs_bot`（機器人自己） | →我方 ✓ |
| `text_mention` 我方同仁（靠 user id） | →我方 ✓ |
| caption 的 tag | 認得 ✓ |
| 一則 tag 兩個人（一方一個） | 分別標對 ✓ |
| 退路：`@abc123`（無 entities） | →外人 ✓ |
| 退路：`service@example.com` | **不算 tag** ✓ |
| 其他 entity（bold） | 不算 tag ✓ |

**模型判斷**（倒出線上那份 system prompt 與 schema，用 `claude` CLI 以
相同參數實打，opus／effort low）：

| 送進去的 | 判成 | 期望 |
|---|---|---|
| 龍（外人）＋「所以改好了嗎？」 | `not_for_us` | ✓ |
| `@sl0168888`（外人）＋「這個帳號不能登入」 | `question`（命中題庫 #109） | ✓ |
| 阿明（外人）＋「這個你跟客服說一下，昨天那筆代付還沒到」 | `request` | ✓ 要我們接手 |
| 整則只有 `@sl0168888` | `not_for_us` | ✓ 與舊硬規則同結果 |
| `@cs_mei`（我方）＋「麻煩幫我看一下」 | `unclear` → 轉人工 | ✓ |
| 無 tag 的正常提問「代收費率怎麼算」 | `question` | ✓ 沒有過度觸發 |
| 11/13 案例（脈絡無標注） | `unclear` | ✗ → 促成上面第 3 點 |
| 11/13 案例（脈絡有標注） | `not_for_us` | ✓ |

⚠ **沒有在真的 Telegram 上發過訊息**：本機 laradock 的 workspace 容器裡
沒有 `claude` CLI，entity 的形狀照官方文件自己組。

## 新增／修改的檔案

| 檔案 | 說明 |
|---|---|
| `config/constants.php` | `AUTO_REPLY.INTENT` 新增 `NOT_FOR_US` |
| `app/Services/AutoReply/ClaudeCodeMatcher.php` | prompt 新增「這句話是講給誰聽的」一節與對照表、`<這則訊息 tag 了誰>` 區塊、`mentionLines()` |
| `app/Services/AutoReplyService.php` | `decide()` 新分支 → SILENT；`handle()`／`preview()` 收 mentions；`buildHistory()` 加 `mentionNote()`；SILENT 的 log 補 `intent` |
| `app/Services/TelegramChatService.php` | **移除**第四道閘門與靜默窗（`isTalkingToOutsiders`／`markTagToOutsiders`／`inTagOutsiderSilence`／`tagOutsiderKey`／`isTagToOutsidersOnly`／`stripMentions`／`hasWords`／`utf16Length`）；新增 `describeMentions()`／`isOurSideMention()`；存訊息時落地 `mentions`；`$replyToOurs` 改走 `repliesToOurSide()` |
| `app/Jobs/AutoReplyJob.php` | 多帶 `$mentions`（有預設值，升版時佇列裡在飛的 Job 不會壞） |
| `app/Contracts/AutoReplyMatcher.php` | `$context` 加 `mentions` |
| `app/Models/TelegramMessage.php`、`app/Repositories/TelegramRepository.php` | `mentions` cast 與脈絡查詢的 select |
| `database/migrations/2026_11_24_000005_add_mentions_to_telegram_message_table.php` | 新欄位 |
| `app/Console/Commands/AutoReplyTestCommand.php` | `--tag`／`--our-tag` 讓這條路徑在終端測得到 |
| `config/auto_reply.php` | 刪 `tag_outsider_silence`；prompt 快取 key → v6 |
| `config/changelog.php` | v4.13 |

## 這次的一般化教訓

**「結構上判得出來的事不要交給模型」有邊界。**

10/27 與 11/13 兩份都寫了「不要改成教 prompt 看懂」，那在當時是對的：
一則只有 `@xxx` 的訊息，模型手上沒有任何可判的東西。

但「他在跟誰講話」**本來就是語意問題** —— 一旦規則要處理
「tag ＋ 一句話」的組合，它就在猜語意了，只是猜得比模型差。
真正該守的分界不是「規則 vs 模型」，而是：

- **只有那一刻拿得到的事實** → 程式負責抓下來、存下來（entities、是不是我方）
- **這些事實代表什麼意思** → 模型負責

規則與模型的分界錯了，症狀就是「規則越加越多，而且每加一條就多一種
相反意思被吃掉」。
