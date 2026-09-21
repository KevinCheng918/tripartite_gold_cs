# 自動回覆（Auto Reply）

## 現況

**已完成首版實作，尚未上線。** 程式碼已全部完成，migration 已執行。
**上線前還需要三項人工前置作業**（見最後一章「上線前置作業」），沒做完功能不會生效。

## 目標

降低客服回覆的出錯率、讓客人有被好好招待的感受，並讓題庫會自己長大。

正確答案已經都在快速回覆題庫裡（`quick_reply_item`，目前 85 則），
但現在靠客服自己找、自己貼，會挑錯、會打錯、也慢。

改成三段式：

```
客人問 → Claude 從題庫挑一則 → 送題庫原文（外層包禮貌話術）
      → 沒把握            → 客氣地反問客人是哪一題
      → 題庫裡沒有         → 轉到內部支援群組問自己人
                            → 自己人回答 → 轉給客人 → 選類別後加進題庫
                                                      ↑
                                            下次同樣的問題就答得出來
```

## 已確認的需求

| 項目 | 決定 |
|------|------|
| 比對方式 | **用 LLM**，不做本地關鍵字比對 |
| 串接方式 | **主要走 Claude 訂閱 + Claude Code CLI**（不計費） |
| 備援 | 訂閱撞上限或失效時，**自動改用備援 API Key 接手**，兩把憑證都在設定頁 |
| 模型 | `opus`（設定頁可改） |
| 比對範圍 | 題目標題 + **答案內文**（答案裡常寫著標題沒提到的資訊） |
| 模型能做的事 | **只能從題庫挑一則 id，不能生成任何回覆文字** |
| 語氣 | **所有對客訊息都要有尊榮感、賓至如歸**，模板在後台可改 |
| 重複問同一題 | **照回，不因為剛回過就不理** |
| 沒把握時 | **客氣地反問客人是哪一題**，反問一次不成就往支援流程走 |
| 題庫裡沒有 | **轉發到內部支援群組**，自己人回答後可回覆客人並加入題庫 |
| 加入題庫 | **要選類別**，寫入後顯示「類別／問題／答案」，之後可到後台人工修改 |
| 求助單沒人回 | **第一次 tag 當班人員，再沒回就 tag 主管與老闆**（一律跳過工程） |
| 支援群組的 bot | **下拉選單**，選項來自 `system` 表 |
| 用量統計 | **只要有呼叫到 Claude 就計一次**（成功、失敗、撞限額都算） |
| 設定位置 | **後台新開兩頁**：全域設定（Claude 憑證、支援群組、用量）與對客話術，**分開授權** |
| 權限 | **走既有 RBAC**（`setting.view` / `setting.manage`），誰能用由管理者指派 |
| 開關形式 | 對話視窗一個**勾選框**，**預設不勾**（＝人工） |
| 開關作用域 | **每個對話各自獨立**，狀態存 DB |
| 勾選後 | **鎖住全部人工操作**：文字輸入、送出、快速回覆、傳圖／檔、表情回應 |
| 推播與告警 | **原有流程全部不變**，照常推播、照常告警 |
| 自動回覆署名 | 固定 **`-A`** |

## 核心契約：只准挑，不准寫

> **這是不可退讓的設計**：模型只回傳 `item_id`，答案內容一律取自題庫 `answer` 原文。
> 讓模型改寫答案就失去「客戶收到的內容 = 題庫內容」的可稽核性，
> 也等於把金流話術的正確性交給模型。

技術上靠 CLI 的 `--json-schema` 強制輸出格式，不是靠提示詞拜託它。
**禮貌話術是外層包裝，不是改寫** —— 前後綴是我們自己的模板，中間夾的答案一個字都不動。

## 架構

```
app/Contracts/AutoReplyMatcher.php           介面：resolve($text, $context) → {item_id, confidence, candidate_ids}
app/Services/AutoReply/ClaudeCodeMatcher.php 唯一實作，呼叫 Claude Code CLI
app/Jobs/AutoReplyJob.php                    佇列工作（CLI 很慢，不能卡 webhook）
app/Services/AutoReplyService.php            決策：信心判斷、反問、話術包裝、狀態流轉
app/Services/AutoReplySupportService.php     內部支援群組：發問、收答案、按鈕互動、回填題庫
app/Services/AppSettingService.php           全域設定讀寫（含加密、快取）
```

留著 Matcher 介面是為了日後想換成 API 或加本地預篩時不必改呼叫端。

---

# 一、走訂閱 + Claude Code CLI

## 整體流程

```
Telegram webhook（PHP）
   ↓ 立刻回 200，把工作丟進佇列
Laravel queue worker
   ↓ Symfony Process 執行 claude CLI
claude -p --output-format json --json-schema ...
   ↓ 解析 JSON
   ↓ 套話術 → 送回 Telegram
```

> ⚠️ **webhook 絕對不能同步等 CLI。** 啟動一個 Claude Code process 到拿到結果要數秒到數十秒，
> Telegram 等不到回應會重送，會造成重複回覆。所以一定要走佇列。

## 指令形狀

```bash
claude -p "<客人的話>" \
  --model opus \
  --effort low \
  --output-format json \
  --json-schema '<挑選結果的 schema>' \
  --system-prompt '<挑選規則 + 題庫全文>' \
  --disable-slash-commands \
  --setting-sources ''
```

| 參數 | 為什麼要 |
|---|---|
| `-p` | 非互動模式，印完就結束 |
| `--output-format json` | 拿到結構化結果（含 usage、耗時、是否出錯），不必 parse 自然語言 |
| `--json-schema` | **強制輸出格式**，這是「只准挑不准寫」能成立的技術基礎 |
| `--system-prompt` | 整份題庫與挑選規則（覆蓋預設系統提示，不要用 append） |
| `--effort low` | 分類任務不需要深思，省時間也省額度 |
| `--disable-slash-commands` / `--setting-sources ''` | 不要載入專案的 skill 與設定，避免無關內容混進來 |
| 停用所有工具 | 這個任務不需要讀檔、不需要 bash。實作時用 `--disallowedTools` 或 `--settings` 明確關掉 —— **正確語法以實作當下的 `claude --help` 為準** |

> ⚠️ **不要用 `--bare`。** 它看起來很適合（跳過 hooks、CLAUDE.md、plugin），
> 但它的認證是「嚴格只讀 `ANTHROPIC_API_KEY`，永不讀 OAuth 與 keychain」——
> 走訂閱的話用了它會直接認證失敗。

## 輸出 schema

```json
{
  "item_id":       <題庫 id 或 null>,
  "confidence":    "high" | "low",
  "candidate_ids": [<id>, ...]        // 低信心時給 2–3 個，供反問用
}
```

## 認證：token 貼進設定頁，不在伺服器上登入

`claude setup-token` 會產生一把**長效 OAuth token**（Pro / Max / Team / Enterprise 訂閱皆可用），
CLI 讀的環境變數是 **`CLAUDE_CODE_OAUTH_TOKEN`**。

所以認證完全可以頁面化，伺服器不需要互動式登入：

```
你在自己電腦跑 claude setup-token
   ↓ 拿到 token 字串
貼進後台設定頁（加密存 app_setting）
   ↓
queue worker 執行 CLI 時，把它設成 CLAUDE_CODE_OAUTH_TOKEN 環境變數帶進去
```

| 事項 | 做法 |
|---|---|
| 存放 | `app_setting`，`Crypt::encrypt` 加密（沿用 `User` 密碼的既有慣例） |
| 顯示 | **永不回傳明文**，只給遮罩（前綴 + 後 4 碼） |
| 更換 | 貼新的 → **先驗證再存**，驗不過不寫入、原本的照舊 |
| 驗證方式 | 用新 token 跑一次極小的 `claude -p "ok" --output-format json`，能回就算過 |
| 傳遞 | Symfony Process 的 `env` 參數，**不要寫進 shell 指令字串**（會出現在 process list） |
| 記錄 | 絕不寫進任何 log |

> **token 綁定產生它的那個人的訂閱** —— 官方文件明講。所以要用你的訂閱帳號產生，
> 而且那個人的訂閱若到期或取消，token 就失效。

**失效偵測**：設定頁顯示「最後驗證成功時間」，並由排程每天驗證一次；
驗不過就在內部支援群組通知一次，不要等客人問了才發現自動回覆早就停擺。

伺服器端只剩一件事：在 laradock 的 workspace container 裡安裝 Node 與 claude CLI。
**不需要在 container 裡登入，也不需要把 `~/.claude` 掛 volume。**

## 三級降級：訂閱 → 備援 API Key → 人工

訂閱是**額度制**（5 小時滾動視窗）。撞到上限不該讓自動回覆整段時間停擺，
所以設定頁可以另外放一把 **Claude API Key** 當備援。

```
第一級：訂閱 token（CLAUDE_CODE_OAUTH_TOKEN）
   ↓ 撞額度上限 / token 失效
第二級：備援 API Key（ANTHROPIC_API_KEY）      ← 真的會扣錢
   ↓ 也失敗，或已達當日備援上限
第三級：送稍等模板 + 開求助單 → 人工接手
```

**備援怎麼跑**：同一支 `ClaudeCodeMatcher`、同一個指令，**只是換一個環境變數** ——
帶 `ANTHROPIC_API_KEY` 而不帶 `CLAUDE_CODE_OAUTH_TOKEN`。程式碼幾乎不用分岔。

> ⚠️ **兩把憑證不要同時帶進去。** CLI 同時看到兩者時用哪一把沒有保證，
> 會讓「這次到底花了誰的額度」變成謎。每次執行只帶當下該用的那一把。

## 備援的防呆（必須）

備援是會扣錢的，訂閱掛掉一整天可能無聲燒掉一筆。所以：

| 機制 | 說明 |
|---|---|
| **每日備援上限** | 設定頁可設（例如 200 次／日）。超過就直接走第三級，不再呼叫 API |
| **備援模型可另設** | 訂閱用 `opus`，備援可以設 `haiku` 省錢 —— 兩個欄位分開 |
| **總開關** | 備援可以整個關掉，只留訂閱 + 人工 |
| **啟用時通知一次** | 第一次切到備援時，在內部支援群組發一則通知（當日只發一次），<br>讓大家知道「訂閱撞牆了，現在在花 API 的錢」 |
| **分開統計** | `llm_usage_log` 記 `source`，設定頁分開顯示訂閱幾次、備援幾次 |

## 其他失敗情況

| 狀況 | 行為 |
|---|---|
| CLI 逾時（預設 60 秒） | 不重試、不切備援（逾時通常不是額度問題），直接走第三級 |
| JSON 解析失敗 / `item_id` 不在題庫 | 同上，並寫 error log |
| 備援也失敗 | 走第三級，寫 error log |

> **第三級是安全的** —— 客人不會收不到東西，只是變回「稍等」＋人工接手，
> 也就是現在的做法。

**併發要壓住**：queue worker 只開 **1 個**，序列處理。同時開多個 claude process 會更快燒完額度，
而且彼此搶同一份額度沒有任何好處。

## 這會用掉多少額度

每次呼叫都要把整份題庫（約 15,000 tokens）送進 `--system-prompt`，
Claude Code 這邊我們控制不了 prompt caching，所以每則訊息都是一次完整的中等大小請求。

**額度消耗跟訊息量成正比，而且題庫越大越兇** —— 題庫從 85 則長到 300 則，
每次請求的 token 就是三倍，能跑的次數大約剩三分之一。

實際能撐多少要上線後看，設定頁的流量統計就是為了讓你看得到這件事。

---

# 二、話術與語氣

**客人收到的每一則訊息都要像是一位有禮貌的專員在招呼他**，不是機器吐資料。
做法是把題庫答案夾在固定的禮貌前後綴中間 —— 答案原文不動，包裝由我們控制。

**模板全部放在後台設定頁，可以隨時看、隨時改**（不寫死在程式或 config）。

## 四段模板（預設值，之後可在設定頁改）

**命中答案：**

```
您好，感謝您的詢問 😊

{答案}

若還有任何不清楚的地方，都歡迎再告訴我們，很高興為您服務！ -A
```

**沒把握、要反問：**

```
您好，為了提供給您最準確的說明，想先跟您確認一下是哪一項呢？

{選項}

麻煩您回覆編號，或直接描述一下您遇到的狀況，我們立刻為您處理 🙏 -A
```

**題庫裡沒有（稍等）：**

```
您好，感謝您的詢問 🙏

這部分我幫您向相關同仁確認一下，稍後馬上回覆您，
感謝您的耐心等候，造成您的等待不好意思！ -A
```

**支援群組的回答轉給客人：**

```
您好，久等了，已為您確認完畢 😊

{答案}

還有任何問題都歡迎再告訴我們，很樂意為您服務！ -A
```

模板用 `{答案}`、`{選項}` 當變數，設定頁要說明哪些變數可用，存檔時驗證變數名稱正確。

## 連續回覆不要變罐頭

每一則都「您好，感謝您的詢問」會很快顯得虛假。
用「問候語間隔」（預設 30 分鐘）判斷：

| 距離上次自動回覆 | 用哪一版 |
|---|---|
| 超過 30 分鐘（或這串對話的第一則） | **完整版** |
| 30 分鐘內的連續對話 | **精簡版**（省略開頭問候，只留結尾一句） |

兩版模板都在設定頁維護。

## 重複問同一題照回

客人重複問同一件事，就重複答 —— 那是他的權利，不是要被節流的雜訊。
**命中答案沒有任何冷卻限制。**

唯一保留冷卻的是「稍等」：同一件事已經說過要幫他確認，5 分鐘內再說一次只會顯得敷衍，
而且求助單也已經開了，重開沒有意義。

---

# 三、決策流程

## 四種結果

| 模型回傳 | 送給客人 | 同時 | `mark_replied` |
|---|---|---|---|
| `high` + 有效 `item_id` | 題庫答案（含禮貌包裝） | — | `true` |
| `low` + 2–3 個 `candidate_ids` | 反問模板 + 候選標題 | — | **`false`** |
| `null`（題庫裡沒有） | 稍等模板 | **開求助單，轉發到內部群組** | **`false`** |
| CLI 失敗／撞限額 | 稍等模板 | 開求助單 | **`false`** |

> **只有真的回答了才算已回覆。** 反問、稍等都要讓既有的超時告警照常響 ——
> 推播與告警的原有流程一律不動。

## 反問後的接續

群組上存 `auto_reply_pending`（候選 id，逗號分隔；null = 沒在等回答）。
下一則訊息進來時：

```
有 pending 且在時限內（10 分鐘）？
  ├─ 訊息是純數字且對得上候選  → 直接送該題答案，清 pending
  ├─ 其他內容                  → 重跑一次，把上一輪反問與候選帶進 prompt 當上下文
  │                               └─ 這次再低信心 → 開求助單轉內部群組，清 pending
  └─ 超過時限                  → 當成全新問題處理，清 pending
```

> **反問只做一次。** 客人答不出來就往支援流程走 —— 機器人跟客人來回鬼打牆很失禮。

## 觸發點

接在 `TelegramChatService::handleIncomingMessage()` 最後，訊息存檔與推播**之後**：

```
收訊 → 存訊息 → broadcast → web push（全部照舊）
  → group.auto_reply 關著？ → 結束
  → 客人只傳圖片／影片沒打字 → 一律當未命中（截圖通常要人工看）
  → dispatch(AutoReplyJob)   ← webhook 到此為止，立刻回 200

[queue worker]
  → pending 判斷 + 執行 claude CLI
  → answer / clarify / support / wait
  → 套話術模板 → sendReply(...)（署名 -A、is_auto=1）
  → 更新群組狀態、寫 llm_usage_log
```

---

# 四、內部支援群組（知識回填迴路）

答不出來的問題不該只是回一句「稍等」就算了 —— 那是題庫該長大的訊號。

## 流程

```
1. 判定題庫裡沒有
       ↓
2. 系統在內部支援群組發一則求助訊息，並記下 ticket
       ↓
3. 自己人「引用回覆」那則求助訊息，打出正確答案
       ↓
4. 系統回一則帶按鈕的訊息：要怎麼處理這個回答？
       ↓
5. 選了要加題庫 → 再選類別 → 寫入 → 顯示「類別／問題／答案」結果
```

求助訊息：

```
🔔 題庫裡找不到答案

站台：優信（群組：優信客服群）
客人：王小明
問題：你們那個虛擬機可以綁公司的固定 IP 嗎

請「引用回覆」本則訊息提供答案。
⚠️ 回答內容會原文轉給客戶，請用可以直接給客戶看的語氣。
```

> 最後那行提醒很重要 —— 系統不改寫回答，打「我看一下」按了回覆就會直接送到客戶眼前。

## 超時沒人回：分兩級 tag

一支排程（每分鐘跑）掃描狀態還是「待回答」且超時的求助單：

| 階段 | 時機 | tag 誰 |
|---|---|---|
| **第一次** | 超過 N 分鐘（預設 10） | **當下排班的人員** |
| **第二次** | 再過 N 分鐘還是沒人回 | **主管與老闆** |
| 之後 | — | 不再 tag，避免變成定時轟炸 |

```
@alice @bob
⏰ 上面這題已經等 10 分鐘了，客人還在線上等回覆，麻煩協助看一下 🙏
```

**tag 對象怎麼決定：**

| 階段 | 來源 |
|---|---|
| 第一次 | 排班系統當下班別的人員，沿用既有的值班判斷邏輯（`getOnDutyUsers()` 那一套） |
| 第二次 | `level <= LEADER` 的帳號 —— 也就是管理者(0)、老闆(1)、主管(2) |

> ⚠️ **兩個階段都要跳過工程（`level = 3`）。** 第二級的 `level <= LEADER` 天然不含工程；
> 第一級要特別濾 —— 排班表裡若排到工程師，不該因為客服問題被 tag。

**要 tag 人必須有 Telegram username**，而 `user` 表目前只有 `telegram_nickname`（署名用），
所以要**新增 `telegram_username` 欄位**，在帳號管理頁填寫：

- 沒填的人直接跳過（不會壞，只是不會被 tag 到）
- 第一級如果一個人都 tag 不到（沒人排班、或排班的都沒填 username），
  直接升級成第二級，不要靜默什麼都不做

## 兩層按鈕

**第一層 —— 決定怎麼處理：**

```
收到 @小陳 的回覆，要怎麼處理？

[① 只回覆客人]      [② 回覆客人並加入題庫]
[③ 只加入題庫]      [④ 忽略]
```

| 按鈕 | 行為 |
|---|---|
| ① 只回覆客人 | 把 `answer` 原文包進「久等了」模板送到客人群組，`status = 3`，結束 |
| ② 回覆客人並加入題庫 | 先送客人，再進第二層選類別 |
| ③ 只加入題庫 | 不送客人（適用客服已經自己回過了），直接進第二層選類別 |
| ④ 忽略 | `status = 0`，什麼都不做 |

**第二層 —— 選類別**（按了 ② 或 ③ 才出現，原訊息就地編輯成）：

```
請選擇要把這題歸到哪個類別：

[虛擬機]      [admin設定]
[總代理]      [商戶管理]
[金流設定]    [其他類別…]
[待整理]      [取消]
```

- 類別按鈕**從 `quick_reply_category` 動態撈**（只列啟用中的），每列兩顆
- 「**待整理**」永遠排在最後 —— 不確定歸哪就丟這裡，事後再整理

> `callback_data` 有 **64 bytes** 上限，所以只放 id 不放文字：
> 第一層 `ar:{action}:{ticket_id}`、第二層 `arc:{ticket_id}:{category_id}`。

## 寫入後的結果訊息

選完類別，原訊息就地編輯成：

```
✅ 已加入題庫

類別：虛擬機
問題：你們那個虛擬機可以綁公司的固定 IP 嗎
答案：可以的，請提供需要連線的兩組 IP，我們會為您設定白名單，
      設定作業預計需要幾個工作天安排與完成。

（已同時回覆客人）
如需調整內容，請至後台「快速回覆題庫」頁面編輯。
```

**把三個欄位攤出來的用意**：加進去的東西是什麼，群組裡所有人當下就看得到，
發現歸錯類別或答案要修，直接去後台改 —— 題庫頁面本來就能編輯，不需要額外功能。

按完之後按鈕一律移除，避免同一張單被按兩次。

## 加入題庫時存什麼

| 欄位 | 值 |
|---|---|
| `category_id` | **自己人在第二層選的類別** |
| `label` | **客人的原問題** |
| `answer` | 自己人的回答原文 |
| `status` | 啟用 |
| `sort` | 該類別的最後一個 |

> **`label` 刻意用客人的原話**，不做美化。這一欄的用途是讓模型認得「客人會怎麼問」，
> 口語的原話反而比工整的標題更有參考價值。要整理成漂亮的題目，到後台改就好。

寫入後要**清掉題庫的本地 prompt 快取**，下一則訊息才吃得到新題目。

## Webhook 路由（重要）

內部支援群組的訊息會進**同一個** `/api/telegram/webhook`。
`handleIncomingMessage()` 現在會把任何沒見過的群組自動建成客服對話 ——
不處理的話，內部群組會出現在客服對話列表裡，自己人的討論也會被存成客戶訊息。

所以要在 `handleIncomingMessage()` **最前面**攔截：

```
chat_id === 設定頁填的支援群組 chat_id ?
    → AutoReplySupportService::handleSupportMessage()   （只處理引用回覆，其餘忽略）
    → return（絕對不要往下走客服對話流程）
```

`callback_query` 事件則在 `TelegramWebhookController` 新增分支。

> ⚠️ **按鈕需要多收一種 webhook 事件**：`TelegramSetWebhookCommand` 的
> `allowed_updates` 目前是 `['message', 'edited_message', 'message_reaction']`，
> 要再加 `callback_query`，並重新執行 `php artisan telegram:set-webhook`。
> 沒加的話按鈕按下去完全沒反應，而且不會有任何錯誤訊息。

## 其他要處理的情況

| 情況 | 處理 |
|---|---|
| 客服在等待期間自己人工回覆了客人 | `sendReply()` 時把該群組待處理的 ticket 標成忽略 |
| 同一群組連續多則都答不出來 | 5 分鐘內不重複開單，避免洗版內部群組 |
| 自己人回覆的不是引用回覆 | 忽略（內部群組本來就會有閒聊），不回錯單 |
| 引用回覆的是一張已處理完的單 | 回一則提示「這張單已處理」，不覆蓋 |
| 選類別時類別剛好被刪掉 | 提示並退回第一層重選 |
| 客人問的是圖片／截圖 | 不開單 —— 截圖本來就要人工看，直接回稍等模板 |

---

# 五、兩個後台新頁面

專案目前沒有任何全域設定的機制（`system` 表是站台系統、`payment_config` 是繳款方式），
所以要新建。**刻意拆成兩頁、兩組權限**。

## 為什麼拆開

話術是客服會想自己調的東西（語氣、措辭），憑證是管理者才該碰的東西。
放同一頁就代表「要讓客服改語氣，就得把 token 頁一起開給他」——
拆開之後這兩件事可以分別授權。

## 頁面 1：全域設定（`/admin/setting`）

| 區塊 | 欄位 |
|---|---|
| **Claude（主要）** | 訂閱 OAuth Token（遮罩 + 更換 + 驗證）、最後驗證成功時間、模型（下拉）<br>＋「如何取得訂閱 Token？」展開式步驟說明 |
| **Claude（備援）** | 備援開關、API Key（遮罩 + 更換 + 驗證）、備援模型（下拉）、每日備援上限次數<br>＋「如何取得 API Key？」展開式步驟說明 |

> **取得步驟寫在頁面上而不是只寫在文件裡** —— 換 token 的人不一定是讀過這份文件的人。
> 安裝指令刻意不放語系檔：指令不需要翻譯，放進去只會被翻壞。
| **內部支援群組** | 群組 chat_id、**Bot（下拉選單，選項來自 `system` 表有 bot_token 的系統）**、<br>第一次／第二次提醒的分鐘數、「發送測試訊息」按鈕 |
| **用量流量** | 今日／本月：**呼叫次數**、成功／失敗／撞限額次數、平均耗時、備援估算費用 |

## 頁面 2：對客話術（`/admin/reply-template`）

四種情境 × 完整版／精簡版，共 8 個欄位：

| 情境 | 何時送出 |
|---|---|
| 命中題庫 | 從題庫挑到答案時 |
| 反問客人 | 同時像好幾題，請客人確認是哪一項 |
| 稍等 | 題庫裡沒有答案，同時把問題轉到支援群組 |
| 支援群組回答 | 同仁回答後按下「回覆客人」 |

每個欄位標示必須保留的變數（`{答案}` / `{選項}`），**後端也會驗一次** ——
少了變數的模板會讓客人收到一則沒有內容的客套話，那在畫面上看不出來。

## 權限

兩組 keyword，分別對應兩頁。**權限頁不另開一張卡片** ——
對客話術掛在既有的「Telegram 客服」群組底下，因為它本來就是客服功能的一部分：

| 權限群組 | keyword | 用途 |
|---|---|---|
| Telegram 客服 | `telegram_chat.template_view` / `telegram_chat.template_manage` | 對客話術 |
| 全域設定 | `setting.view` / `setting.manage` | Claude 憑證、支援群組、用量 |

> keyword 前綴跟著群組走（`telegram_chat.*`），沿用專案既有慣例 ——
> 前綴與群組不一致的話，日後看到 `reply_template.*` 會找不到對應的群組。
>
> 頁面的文案語系檔仍叫 `reply_template.php`（那是頁面內容，與權限無關）。

**完全走既有 RBAC** —— 路由掛 `can:setting.view` / `can:setting.manage`，
誰有權限由管理者在帳號管理裡指派，程式裡**不寫任何角色判斷**。

> ✅ **不需要跑任何 seeder。** 規劃階段誤以為要跑 `SetPermissionSeeder`，
> 但這個專案根本沒有那支 seeder —— 權限清單是 `PermissionMapService` 直接讀
> `config/permissionMap.php` 得到的，新增群組後就會自動出現在帳號管理的勾選清單裡，
> 完全不碰 DB。

## 用量統計

**只要有呼叫到 Claude 就記一筆** —— 成功、失敗、撞限額、逾時全部都算，
因為它們都消耗了額度或至少嘗試消耗。

兩種來源分開看，因為意義完全不同：

| 來源 | 要盯什麼 |
|---|---|
| **訂閱** | 今天跑了幾次、**撞了幾次限額** —— 撞得越頻繁代表訂閱越撐不住 |
| **備援 API** | 今天跑了幾次、**估算花了多少錢**、離每日上限還有多少 |

備援有 token 數可以換算金額（單價放 `config/auto_reply.php`），
訂閱那邊沒有金額可算，就只看次數。

---

# 六、資料表異動

六張表。**題庫的兩張表結構不動**（只會新增資料）。

```
app_setting（新表）
  id / key(unique) / value(text, 敏感值加密) / is_secret / updated_by / timestamps

llm_usage_log（新表）
  id / used_on(date, index) / telegram_group_id(nullable)
  source（1=訂閱, 2=備援 API） / model
  duration_ms / is_error / is_rate_limited / input_tokens / output_tokens / created_at

auto_reply_ticket（新表）
  id / telegram_group_id / message_id / question
  ask_message_id（求助訊息的 telegram message_id，索引） / answer / answered_by / answered_at
  remind_count（0=未提醒, 1=已 tag 當班, 2=已 tag 主管） / last_reminded_at
  status（1=待回答, 2=已回答待處理, 3=已回覆客人, 4=已加入題庫, 0=忽略）
  quick_reply_item_id / timestamps

user
  + telegram_username    varchar(50) null     用來 @ 人（帳號管理可填）

telegram_group
  + auto_reply           tinyint default 0    1=開, 0=關
  + auto_reply_at        datetime null        最後一次自動回覆時間（問候語間隔、稍等冷卻、pending 時限共用）
  + auto_reply_item_id   bigint null          最後一次命中的題庫 id
  + auto_reply_pending   varchar(200) null    反問中的候選 id（逗號分隔）

telegram_message
  + is_auto              tinyint default 0    1=自動回覆送出的訊息
```

`auto_reply_item_id` 與 `quick_reply_item_id` **都不設外鍵**：
題庫被刪掉時留著舊 id 也無妨，不該因此連動改動群組或工單資料。

---

# 七、新的維運需求（重要）

走 CLI + 佇列，會多出幾件現在沒有的事：

| 項目 | 說明 |
|---|---|
| **佇列要真的跑起來** | 專案目前 `QUEUE_CONNECTION` 是 `sync`（同步執行）。要改成 `database` 或 `redis`，<br>並用 supervisor 常駐 `php artisan queue:work`，**只開 1 個 worker** |
| **Node + claude CLI** | 要裝進 laradock 的 workspace container，並在 image／compose 裡固定下來 |
| **憑證要顧** | 訂閱 token 綁在產生它的人身上，那個人訂閱到期就失效。<br>排程每天驗一次，失效會在支援群組通知 —— 但**換新 token 要有人去設定頁貼** |
| **worker 掛掉要有人知道** | queue worker 停了，自動回覆會整個靜默失效（客人收不到任何東西）。<br>supervisor 設自動重啟，並考慮加一個健康檢查 |

---

# 八、前端行為

`public/js/telegram-chat/input.js`

- 勾選框放在輸入區上方的功能鈕那一列，標籤「自動回覆」
- 狀態來自 `ajax-groups` 回傳的 `auto_reply`，切換群組時重新套用
- 勾選 → 打 `ajax-toggle-auto-reply` 存 DB → **成功才鎖 UI**（失敗就把勾勾彈回去）
- 鎖定時：textarea `disabled` 並換 placeholder（「自動回覆中，取消勾選才能人工輸入」）、
  送出／檔案／文件／快速回覆／表情鈕全部 `disabled`，貼上與拖曳截圖一併擋掉
- 未完成 Claude 認證時勾選框 `disabled` 並提示去全域設定頁
- 提示訊息走既有 modal／行內錯誤區，**不用 `alert()`**

`public/js/telegram-chat/messages.js` — `is_auto` 的訊息加「自動」標示。

`public/js/setting-admin.js`（新）— 設定頁：認證測試、支援群組測試發送、
話術模板編輯與預覽、流量表格。

`telegram_username` 欄位加在**兩個地方**，都沿用各自既有的權限：

| 頁面 | 誰能改 |
|---|---|
| **個人資訊** | 每個人改自己的（跟改密碼同一頁，不必麻煩管理者） |
| **帳號管理** | 有帳號管理權限的人改別人的（補漏用） |

與既有的 `telegram_nickname`（署名用）並列，欄位說明要寫清楚差別：
暱稱是**簽在客戶訊息結尾的名字**，username 是**在內部群組被 @ 到的帳號**。

---

# 九、測試工具

`php artisan auto-reply:test "客人的話"` —— 在終端看挑了哪一則、信心多少、
候選是什麼、**實際會送出去的完整訊息長什麼樣**、耗時多久。
**調話術和 prompt 不必真的跑去 Telegram 試。**

加 `--dry` 只看結果不寫狀態、不發訊息；`--pending=12,34` 模擬反問後的第二輪。

---

# 十、實際異動檔案

> 以下清單與實作結果一致。與規劃的差異只有一處：
> **`setting` 權限不需要 seeder**（權限讀 config，見第五章）。

| 類別 | 檔案 |
|---|---|
| Migration（新） | `..._create_app_setting_table.php`<br>`..._create_llm_usage_log_table.php`<br>`..._create_auto_reply_ticket_table.php`<br>`..._add_telegram_username_to_user_table.php`<br>`..._add_auto_reply_to_telegram_group_table.php`<br>`..._add_is_auto_to_telegram_message_table.php` |
| Seeder（新） | `AutoReplyCategorySeeder.php`（「待整理」類別）、`AppSettingSeeder.php`（話術模板預設值） |
| Config（權限） | `config/permissionMap.php` 新增 `setting` 群組，並在既有 `telegram_chat` 群組加兩個話術 keyword ——<br>**不需要 seeder**，權限清單直接讀 config |
| 介面（新） | `app/Contracts/AutoReplyMatcher.php` |
| Service（新） | `AutoReply/ClaudeCodeMatcher.php`（CLI 呼叫、題庫 prompt 組裝、限額偵測）<br>`AutoReplyService.php`、`AutoReplySupportService.php`、`AppSettingService.php`<br>`SettingService.php`、`ReplyTemplateService.php`、`LlmUsageService.php` |
| Job（新） | `app/Jobs/AutoReplyJob.php` |
| Command（新） | `AutoReplyTestCommand.php`、`AutoReplyTicketRemindCommand.php`（分級 tag） |
| Model（新） | `AppSetting.php`（加密 mutator）、`LlmUsageLog.php`、`AutoReplyTicket.php` |
| Repository（新） | `AppSettingRepository.php`、`LlmUsageRepository.php`、`AutoReplyTicketRepository.php` |
| Controller（新） | `Admin/SettingController.php`（憑證、支援群組、用量）<br>`Admin/ReplyTemplateController.php`（對客話術） |
| Request（新） | `Setting/UpdateClaudeRequest.php`（訂閱 token）、`Setting/UpdateFallbackRequest.php`（備援 API Key）、<br>`Setting/UpdateSupportRequest.php`、`ReplyTemplate/UpdateTemplateRequest.php`、<br>`TelegramChat/ToggleAutoReplyRequest.php` |
| Resource（新） | `AppSettingResource.php`、`LlmUsageResource.php` |
| View（新） | `resources/views/admin/setting/index.blade.php`<br>`resources/views/admin/reply-template/index.blade.php` |
| Kernel（改） | `app/Console/Kernel.php` — 排程超時提醒 |
| Service（改） | `TelegramChatService.php`（觸發點、支援群組攔截、`sendReply()` 支援固定署名與 `is_auto`、<br>人工回覆時關單、值班查詢擴充成可取得 user）<br>`TelegramBotService.php`（inline keyboard、`editMessageText`、`answerCallbackQuery`）<br>`QuickReplyService.php`（回填題目、清 prompt 快取）<br>`AccountService.php`（`telegram_username`） |
| Controller（改） | `TelegramWebhookController.php`（`callback_query` 分支）、`TelegramChatController.php`、<br>`AccountController.php`（`telegram_username`） |
| Command（改） | `TelegramSetWebhookCommand.php` — `allowed_updates` 加 `callback_query` |
| Model／Repository（改） | `User.php`、`TelegramGroup.php`、`TelegramMessage.php`、`QuickReplyRepository.php`、<br>`TelegramRepository.php`、`UserRepository.php`（主管／老闆名單）、`StationRepository.php`（bot 下拉） |
| Route（改） | `routes/web.php` ⚠️ 改完要跑 `php artisan optimize` |
| Config | `permissionMap.php`（改）、`constants.php`（改）、`auto_reply.php`（新）、`rules.php`（改） |
| 前端（改／新） | `public/js/setting-admin.js`（新）、`public/js/reply-template-admin.js`（新）、<br>`telegram-chat/input.js`、`messages.js`、`state.js`、帳號管理頁、個人資訊 modal |
| 語系（改／新） | `resources/lang/{tw,cn,en}/setting.php`（新）、`reply_template.php`（新）、<br>`permission.php`、`telegram_chat.php`、`account.php`、`profile.php` |
| 文件 | 本檔、`INDEX.md`、`config/changelog.php` |

---

# 十一、上線前置作業

程式碼已全部完成，但**以下事情沒做完，功能不會生效**。

## 必做（沒有這些就不會動）

| # | 事項 | 誰來做 |
|---|---|---|
| 1 | **執行 seeder** 建立話術模板預設值與「待整理」類別（見下方指令） | 需要授權 |
| 2 | **容器裝 Node 與 claude CLI** —— `ClaudeCodeMatcher` 是叫 CLI 的，沒裝就一律降級成「稍等」 | 工程 |
| 3 | **佇列要跑起來**：`QUEUE_CONNECTION` 從 `sync` 改成 `database` 或 `redis`，<br>並用 supervisor 常駐 `php artisan queue:work`，**只開 1 個 worker** | 工程 |
| 4 | **重新設定 webhook**：`allowed_updates` 多了 `callback_query`，<br>不重設的話支援群組的按鈕按下去完全沒反應，而且不會報錯 | 工程 |
| 5 | **改過 routes 要跑 `php artisan optimize`**（在 laradock 容器內，不要在本機跑） | 工程 |
| 6 | **建內部支援群組**：把 bot 拉進去、**關掉 Group Privacy**，chat_id 填進設定頁。<br>⚠️ **必須是一個獨立的群組，不能填現有客戶對話的 chat_id** —— webhook 會先判斷<br>「這則來自支援群組」並攔掉，那個群組的客戶訊息會全部被當成自己人的討論吃掉，<br>不存訊息、不自動回覆，**而且完全不報錯**（已實際踩過）。<br>設定頁已加驗證（`unique:telegram_group,chat_id`）擋住這種填法 | 你 |
| 7 | **訂閱 token**：自己電腦跑 `claude setup-token`，產生的 token 貼進設定頁。<br>**設定頁上就有完整取得步驟**（點「如何取得訂閱 Token？」展開） | 你 |
| 8 | **在帳號管理勾權限**：「全域設定」卡片的 `setting.*`（憑證與支援群組）、<br>「Telegram 客服」卡片新增的兩項對客話術權限，沒勾就看不到對應的頁面 | 你 |

```bash
# 1. seeder（會寫入 DB，需要你授權）
cd ../laradock && docker-compose exec workspace bash -c "cd /var/www/tripartite_gold_cs && \
  php artisan db:seed --class=AppSettingSeeder && \
  php artisan db:seed --class=AutoReplyCategorySeeder"

# 4. 重設 webhook（allowed_updates 加了 callback_query）
cd ../laradock && docker-compose exec workspace bash -c "cd /var/www/tripartite_gold_cs && php artisan telegram:set-webhook"

# 5. 路由快取
cd ../laradock && docker-compose exec workspace bash -c "cd /var/www/tripartite_gold_cs && php artisan optimize"
```

## 選配

- **備援 API Key**：Claude Console 開一把貼進設定頁。不設定的話訂閱撞牆就只能走人工
- **大家的 Telegram username**：分級 tag 要用。沒填的人不會被 tag，而且**是靜默的不會報錯**，
  上線前要確認該被 tag 的人都填了（個人資訊頁可自己填，帳號管理可代填）
- **話術模板**：seeder 寫了一份預設值，上線前建議到設定頁逐字看過 —— 這是客人唯一看得到的東西
- **題庫答案的語氣**：包裝只能包外層，中間的答案若寫得生硬（例如「請洽詢相關人員。」）
  客人還是會感覺到。這是改題庫資料不是改程式

## 驗證方式

```bash
# 不用真的跑去 Telegram 試，終端就能看決策與實際會送出的訊息
php artisan auto-reply:test "虛擬機連不上怎麼辦"
php artisan auto-reply:test "第一個" --pending=12,34   # 模擬反問後的第二輪
```

⚠️ 這支指令會真的呼叫 Claude、消耗額度。

### 本機跑完整流程（不需要公開網址）

`auto-reply:test` 只驗比對決策。要連同「收訊 → 存訊息 → 推播 → 送出」整條走一遍，
本機沒有公開網址收不到 webhook，直接自己打進去就好：

```bash
U=http://tripartite_gold_cs/api/telegram/webhook
D='{"message":{"chat":{"id":-1002553314897,"title":"測試"},"text":"虛擬機多少錢"}}'
curl --json "$D" $U
```

- 網址用 nginx 的 `server_name`（hosts 已指向 127.0.0.1），不是 `localhost`
- `.env` 沒設 `TELEGRAM_WEBHOOK_SECRET` 時不必帶驗證 header
- `QUEUE_CONNECTION=sync` 時 `AutoReplyJob` 同步跑，curl 會等到比對完才回應
- 欄位只有 `chat.id`、`chat.title`、`text` 是必要的。`title` 不能省 ——
  缺了會把群組名稱覆寫成 `Chat <chat_id>`
- **回應永遠是 `{"ok":true}`**（Telegram 要求），失敗只會進 log，不要用它判斷成功
- ⚠️ 別在本機跑 `telegram:set-webhook`：一支 bot 只能有一個 webhook URL，
  本機一設，正式環境就收不到訊息了

按鈕（`callback_query`）本機測不了，要等 Telegram 推過來。

---

# 十二、風險與限制

- **訂閱額度撞牆時會開始花錢**。備援 API Key 解掉了「尖峰時段整段停擺」的問題，
  但代價是那段時間在按 token 計費。每日上限與支援群組通知就是為了讓這件事不會靜悄悄發生 ——
  **上線後要盯「備援啟用次數」，那是訂閱撐不撐得住的溫度計**
- **題庫變大會加速消耗額度**。每次呼叫都要送整份題庫，85 則約 15,000 tokens，
  長到 300 則就是三倍，能跑的次數大約剩三分之一 —— 知識回填做得越成功，額度撐得越短
- **回應慢**。啟動一個 CLI process 是數秒到數十秒，客人不會馬上收到回覆。
  這也是為什麼一定要走佇列
- **多了兩個會靜默失效的環節**：queue worker 掛掉、CLI 認證過期。
  兩者都不會有人自動發現，客人只會覺得「今天都沒人回」
- **一律回原文**，不論是題庫答案還是自己人在支援群組打的字，系統都不改寫。
  這是刻意的 —— 客戶收到的內容永遠等於某個人寫過的內容，才有可稽核性。
  **代價是自己人在支援群組要用「可以直接給客戶看」的語氣回答**
- **禮貌包裝救不了生硬的答案內容**。外層再客氣，中間夾一句「請洽詢相關人員。」
  客人還是會覺得被打發 —— 見待確認第 6 點
- **加了包裝，每則訊息會變長**。精簡版模板是為了緩解這件事，但連續對話時
  客人仍會看到重複的結尾語，上線後要觀察會不會顯得囉唆
- **回填進來的題目品質取決於當下怎麼回**，而且會被原封不動、更高頻率地送給後續客戶。
  結果訊息把類別／問題／答案攤出來就是為了讓人當下能發現不對勁，但仍需要定期回頭盤點
- 客人一次問**兩個問題**時只會挑一則。模型應該會判成低信心而反問，但不保證
- 勾選後全鎖，客服要臨時插話必須先取消勾選 —— 這是你要的行為，但實際用起來可能會嫌多一步
