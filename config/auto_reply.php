<?php

/**
 * 自動回覆 —— 開發者維護的設定
 *
 * ⚠ Claude 憑證、內部支援群組、對客話術模板**不在這裡**，
 * 它們存在 app_setting 表、由後台「全域設定」頁維護（要能隨時改，不該改檔案重新部署）。
 *
 * 這份檔案放的是不開放後台修改的東西：CLI 指令細節、模型清單、單價。
 */

return [

    /*
     * Claude Code CLI 的執行檔路徑。
     *
     * 走訂閱制，所以是叫 CLI 而不是打 API —— 官方 PHP SDK 需要 php ^8.1，
     * 本專案是 7.4，裝不起來。
     */
    'cli_path' => env('CLAUDE_CLI_PATH', 'claude'),

    /*
     * 可選模型清單（設定頁的下拉選項）。
     *
     * key 是傳給 --model 的值，value 是顯示名稱。
     * CLI 接受別名（opus / sonnet / haiku），用別名才不會因為改版要回來改這裡。
     */
    'models' => [
        'opus'   => 'Opus（判斷最準，額度消耗最快）',
        'sonnet' => 'Sonnet（平衡）',
        'haiku'  => 'Haiku（最省，適合當備援）',
    ],

    /*
     * effort 等級。挑選題目是分類任務，用 low 就夠，也省額度與時間。
     */
    'effort' => env('AUTO_REPLY_EFFORT', 'low'),

    /*
     * max_tokens。
     *
     * ⚠ 這是「思考 + 回應」的總量上限，不是只算回應。
     * 雖然我們只要一個 item_id，但設太小會在思考階段就被截斷、拿到空回應。
     */
    'max_tokens' => 2048,

    /*
     * CLI 逾時秒數。逾時一律當作未命中，不重試也不切備援
     * （逾時通常不是額度問題，切過去只是再等一次）。
     */
    'timeout' => 60,

    /*
     * 題庫 prompt 的本地快取。
     *
     * 題庫異動（含支援群組回填）時會主動清掉，TTL 只是保險。
     * key 放這裡讓 Matcher 與 QuickReplyService 共用，不必互相 import。
     *
     * prompt 內容改版時要一起換 key（結尾的版號），否則舊的會留到 TTL 到期才換掉。
     */
    'prompt_cache_key'     => 'auto_reply.prompt.v2',
    'prompt_cache_seconds' => 300,

    /*
     * 「AI 回覆中」狀態的快取。
     *
     * 一個群組一個 key —— 用單一 key 存整包 group id 會在兩個 Job 同時改時互相覆蓋。
     *
     * TTL 必須比 Job 的 timeout（120 秒）長：worker 被砍掉、機器重開這種
     * 連 failed() 都來不及觸發的情況，只能靠 TTL 自己收掉，
     * 否則對話會一直卡在「AI 回覆中」而沒有人收得掉。
     */
    'progress_cache_prefix'  => 'auto_reply.running.',
    'progress_cache_seconds' => 180,

    /*
     * 比對時要一起送進去的對話脈絡。
     *
     * 客人常常分兩則講一件事：先貼一長串系統異常訊息，再問「這是什麼錯誤呢」。
     * 只送後面那一句進去，模型看不到任何可比對的內容，必然轉人工。
     *
     * ⚠ 時間窗是必要的：隔了半小時再問的那一句通常是新問題，
     * 把舊對話帶進去只會讓模型往錯的方向找。
     *
     * 題庫走 prompt cache、脈絡放在 user message 這一側，所以不會讓快取失效。
     */
    'context' => [
        'limit'     => 6,    // 往前幾則（不含客人現在這一則）
        'minutes'   => 15,   // 只看這段時間內的
        'max_chars' => 600,  // 單則截斷。系統異常訊息約 400 字，要能完整帶到
        'total'     => 3000, // 整段脈絡上限，超過從最舊的開始丟
        'ticket'    => 2,    // 求助單附的前情則數（送進 Telegram，太長會洗版）
    ],

    /*
     * 備援 API 的單價（USD / 每百萬 token），用來估算備援花了多少錢。
     *
     * 訂閱那邊沒有金額可算，只看呼叫次數。
     * 改版調價時要回來更新，否則統計會失真。
     */
    'pricing' => [
        'opus'   => ['input' => 5.0, 'output' => 25.0],
        'sonnet' => ['input' => 3.0, 'output' => 15.0],
        'haiku'  => ['input' => 1.0, 'output' => 5.0],
    ],

    /*
     * 費用換算成台幣用的匯率。純顯示用，不影響任何邏輯。
     */
    'usd_to_twd' => 32,

    /*
     * 呼叫紀錄保留天數，超過由排程清理。
     */
    'usage_log_days' => 180,

];
