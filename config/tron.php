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

    // 錢包的轉帳紀錄頁 —— 截圖截的就是這一頁
    'transfers_url' => 'https://tronscan.org/#/address/{address}/transfers',

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
