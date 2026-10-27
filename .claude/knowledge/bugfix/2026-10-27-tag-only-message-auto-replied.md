# 客人只 tag 了他自家同事，小幫手卻回「已請專員為您確認」

> 我們主動發餘點告警 →
> 客人（引用回覆那則）只回了一則：`@sl0168888` →
> 小幫手回：「老闆，這個帳號的部分已經請專員為您確認，再麻煩您稍等一下 -A」
>
> 需求方 2026-10-27：「我們主動告知客人要補點，他是 tag 其他人，這個不用轉確認」
>
> 補充：「如果是 tag 我們自己人含機器人的話，要轉，如果不是的話，就不用了」

**客人根本沒有問我們任何事。** 他是把自家同事叫進來看我們的通知，
小幫手卻把這則當成一個問題，憑空生出一句「已請專員為您確認」——
客人會以為有人在查什麼，同仁那邊也多了一張不存在的待辦。

跟 [[2026-10-14-done-reply-treated-as-request]] 同一類：
**不是每一則 inbound 都是在問我們問題。**

## 判斷寫在派 Job 之前

`TelegramChatService` 的四道閘門（`isAutoReplyOn` → `isStaff` → `isIgnored`
→ 新增的 `isTagToOutsidersOnly`），連 AI 都不叫。

⚠ **不要改成「教 prompt 看懂」** —— 一則只有 `@xxx` 的訊息送進去，
模型只能猜；而且猜錯的成本是直接送給客人。這種結構上就判得出來的事
不要交給模型。

## 三道都成立才擋

1. **整則都是 tag** —— `@sl0168888 這個帳號不能登入` 還是要回。
2. **tag 的不是我們的人** —— `@我們的客服`、`@機器人` 都要轉，
   哪怕一個字都沒寫。客人 tag 我們就是在叫我們。
3. 前兩道都過了才擋，並寫一行 log（不然查「為什麼沒回」會查不到）。

## ⚠ entity 的 offset／length 是 UTF-16 code unit

不是位元組、也不是 mb 字元 —— **emoji 佔兩個單位**。
拿 `mb_substr` 去切 tag 的位置，整句有 emoji 時會整段偏掉
（`🙏 @sl0168888 幫我看一下` 會切錯）。

作法是整句先 `mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')`，
照 `offset * 2`／`length * 2` 剪，再轉回 UTF-8。
**而且要由後往前剪**，不然剪掉前面那段之後，後面每個 offset 都位移了。

## ⚠ 「還有沒有話」不能只看 trim 後是不是空的

`@sl0168888 🙏` 把 tag 挖掉之後剩一個 emoji —— 那不是問題，是拜託一下。
標點、空白、emoji 都不算話，只有 `\p{L}` 或 `\p{N}` 才算。

第一版用「tag 長度 + 空白長度 >= 全長」去算，就是栽在這裡
（emoji 兩個單位，怎麼算都不會相等）。改成「挖掉再看剩下有沒有字」
之後，emoji、標點、全形符號一次全部處理掉。

## ⚠ 不能自己用正則拆 @

- `text_mention`（對方沒有 username 時）**文字裡根本沒有 `@`**，拆不出來
- `service@example.com` 反而會被 `@` 的正則吃掉

所以一律以 `entities` 為準，正則只當「拿不到 entities」時的退路，
而且退路認不出 `text_mention` 這件事要寫在註解裡。

## ⚠ isOurSide() 不能直接用 isStaff()

兩支問的是不同的問題：

| | `isStaff()` | `isOurSide()`（新增） |
|---|---|---|
| 問題 | **他講的話**要不要自動回覆 | **被 tag 到的人**是不是我們的人 |
| 對話放行例外 | 看 | **不看** |
| 回填 telegram id | 會 | **不會** |

放行例外是「這個對話把某位同事當客人看」的設定。套到 tag 判斷上，
會讓「客人 tag 到一位被放行的同事」被當成外人而不回 —— 方向正好相反。

不回填是因為被 tag 不等於本人在場發言，`mention` 這種 entity
連 user 物件都沒有，拿不到可信的 id 與 username 配對。

## ⚠ 順手修：Bot username 的快取 key 沒帶 Bot id

`TelegramBotService::getMyUsername()` 原本存在 `telegram.bot.username`。
這個專案是**多 Bot**（`switchBotToken()` 依站台換 token），
共用一個 key 的話第二支 Bot 會拿到第一支的名字，「有沒有 tag 到我」整個判錯。
改成 `telegram.bot.username.{botId}`。

⚠ 另外，原本用 `Cache::remember` 而失敗時回 `null` —— **remember 存 null 等於沒存**，
Telegram 連不上時每一則訊息都要再等一次 10 秒逾時，而這支是在 webhook
的同步流程裡。改成失敗也寫快取（空字串，TTL 10 分鐘讓它自己復原）。

這支方法在這次之前**沒有任何呼叫端**，所以這兩個問題一直沒被發現。

## 新增/修改的檔案

| 檔案 | 說明 |
|---|---|
| `app/Services/TelegramChatService.php` | 第四道閘門 ＋ `parseMentions`／`stripMentions`／`sliceUtf16`／`utf16Length`／`hasWords`／`tagsOurSide` |
| `app/Services/StaffIgnoreService.php` | 新增 `isOurSide()` |
| `app/Services/TelegramBotService.php` | 快取 key 帶 Bot id、失敗也寫快取 |

## 驗證

19 種情境（reflection 叫 private method，`botService` 與 `staffIgnoreService`
用假物件，不碰 DB 也不打 Telegram）：

- 擋：只 tag 外人／tag 兩個外人／tag 外人 + emoji／`text_mention` 外人／
  caption 的 tag／**Bot 名字查不到時 tag 外人**
- 要回：只 tag 客服／只 tag 機器人／外人 + 我們的人／`text_mention` 我們的人
  （靠 `user.id`）／tag + 真的問題／問題 + tag／emoji + tag + 問題／正常問題／
  email 不算 tag／**Bot 名字查不到但 tag 客服**
- 退路（無 entities）：只有 `@外人` 擋、只有 `@我們的人` 要回、`@外人` + 問題要回

⚠ **沒有在真的 Telegram 上發過訊息驗證**，entity 的形狀是照官方文件
（`offset`／`length`／`type`／`user`）自己組的。
