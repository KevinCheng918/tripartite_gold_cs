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

];
