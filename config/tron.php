<?php

/**
 * TRON 鏈上查詢設定
 *
 * ⚠ **資料來源是 TronGrid，不是 tronscan.org 那一頁。**
 *
 * `tronscan.org/address/{地址}/transfers` 是 React SPA，HTML 裡沒有交易清單，
 * 爬不到東西；它背後的 `apilist.tronscanapi.com` 要 API key，而且有個長年未修的
 * 問題：`limit` 給多少都只回 20 筆。
 *
 * TronGrid 是 TRON 官方的節點 API，拿的是同一份鏈上資料，而且有
 * `min_timestamp` —— 正好是增量輪詢要的游標。
 *
 * 通知裡附的連結仍然指向 tronscan.org（需求方習慣看那個畫面）。
 */

return [

    'api_base' => env('TRON_API_BASE', 'https://api.trongrid.io'),

    /*
     * TronGrid 的 API key（`TRON-PRO-API-KEY` 標頭）。
     *
     * ⚠ 沒填也能跑，只是免費額度較低。錢包多或轉帳頻繁時建議申請一把
     * （https://www.trongrid.io/），否則會開始收到 429。
     */
    'api_key' => env('TRON_API_KEY'),

    /*
     * 給人看的交易頁網址樣板。
     *
     * ⚠ tronscan 的交易頁是 hash route（`#/transaction/...`），
     * 少了那個 `#` 會導到首頁。
     */
    'tx_url' => 'https://tronscan.org/#/transaction/',

    /*
     * ⚠ **沒有「錢包轉帳紀錄頁」的設定，是刻意的。**
     *
     * 原本想截 `tronscan.org/#/address/{地址}/transfers` 附在通知上，
     * 但那個網域在 **Cloudflare 後面，一般請求直接回 403 挑戰頁**
     * （實測 `server: cloudflare` ＋ `challenge-platform`）——
     * headless Chrome 配機房 IP 幾乎過不了，而且挑戰頁上等不到表格，
     * 會白等滿 30 秒逾時才放棄。需求方 2026-10-27 決定不附圖。
     *
     * ⚠ 這跟上面的 `api_base` **沒有關係**：資料是打 TronGrid 的 JSON API，
     * 不是解析網頁，沒有機器人驗證的問題。
     */

    /*
     * 一次最多拿幾筆。TronGrid 的上限就是 200。
     *
     * ⚠ 不要調小：第一次建立基準時要靠它一次把最新一批撈回來。
     */
    'page_size' => 200,

    /*
     * 一個錢包一輪最多翻幾頁。
     *
     * ⚠ 這是**跑不完的保險**，不是正常情況會碰到的數字。正常一分鐘內
     * 不會有 1000 筆轉帳；真的碰到上限時剩下的下一輪會接著處理
     * （游標是鏈上時間，不會漏）。
     */
    'max_pages' => 5,

    // HTTP 逾時（秒）。排程每分鐘跑一次，不能卡太久
    'timeout' => 15,

    /*
     * 兩次 TronGrid 呼叫之間至少間隔幾毫秒。
     *
     * ⚠ **這是為了不要撞 429。** 沒有 API key 時 TronGrid 的限制是
     * `allowed_rps(1)` —— 每秒只准一次，超過就回
     * 「request rate of (getTrc20TransactionsByAccount) exceeded the
     * allowed_rps(1), and the query server is suspended」。
     *
     * ⚠ 這個問題的症狀**很容易被誤判**：`WalletNoticeService::run()` 是
     * 背對背跑每個錢包的，所以**清單裡第一個錢包永遠成功、第二個永遠 429**
     * —— 看起來像「某個地址有問題」，實際上跟地址無關，只跟它排第幾有關。
     * （2026-11-01 從 production log 查出來的。）
     *
     * 1100 是 1 秒多一點的安全邊際。20 個錢包 ≈ 22 秒，排程一分鐘跑得完。
     *
     * ⚠ 設了 `TRON_API_KEY` 之後限制會高很多，這個值可以調小 ——
     * 但**不要調成 0**：節流是「沒有 key 也不會壞」的保險。
     */
    'min_interval_ms' => env('TRON_MIN_INTERVAL_MS', 1100),

    /*
     * 撞到 429 時，同一次查詢最多再試幾次（0 ＝ 不重試）。
     *
     * ⚠ **為什麼節流之外還需要重試**：節流只管得住「我們自己這一輪」。
     * `allowed_rps(1)` 是**按出口 IP** 算的 —— 同一個 NAT 後面只要還有別的
     * 服務在打 TronGrid，我們把自己壓到每秒 0.9 次也還是會偶發撞上。
     * 那種碰撞等一下就過去了，退避重試正好治它。
     * （2026-11-01：節流上線後頻率明顯下降但沒有歸零，就是這個原因。）
     *
     * ⚠ **只有 429 會重試**，逾時與其他錯誤一律當場放棄 —— 逾時重試會吃掉
     * 兩倍的 `timeout`，而排程只有一分鐘。
     */
    'rate_limit_retries' => env('TRON_RATE_LIMIT_RETRIES', 2),

    /*
     * 重試前額外多等幾毫秒（實際等待 ＝ 這個值 × 第幾次重試 ＋ 隨機抖動）。
     *
     * ⚠ **抖動不是可選的**：撞 429 代表有別人也在用這個額度。固定等一樣久
     * 會讓兩邊維持同樣的相位差，每次重試又剛好撞在一起。
     *
     * ⚠ 實際等待由退避一次等滿，**它自己會把 `min_interval_ms` 算進去** ——
     * 不是「節流 ＋ 退避」相加（節流是從上次送出起算的，兩者是 `max()`）。
     * 預設值下：第一次重試約 1.8～2.5 秒，第二次約 2.5～3.2 秒。
     */
    'rate_limit_backoff_ms' => env('TRON_RATE_LIMIT_BACKOFF_MS', 700),

    /*
     * 一輪排程**總共**最多重試幾次。
     *
     * ⚠ **這是「不要讓重試把一輪拖垮」的保險，不是正常會用到的數字。**
     * 如果額度是被別的服務整個吃掉的，那會是每一次查詢都撞 ——
     * 每個錢包各重試兩次的話，20 個錢包會從 22 秒變成兩三分鐘，
     * 排程被 `withoutOverlapping` 卡著跳過好幾分鐘，通知反而更慢。
     * 額度用完就快速失敗，讓 log 去講真話（那時候該做的是設 API key）。
     */
    'rate_limit_retry_budget' => env('TRON_RATE_LIMIT_RETRY_BUDGET', 10),

];
