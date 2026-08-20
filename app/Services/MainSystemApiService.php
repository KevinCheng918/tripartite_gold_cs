<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * 主系統 API Service
 *
 * 封裝對主系統 tripartite_gold 的 API 呼叫。
 * 每個站台有自己的 api_url 和 api_key，存在 station 表中。
 *
 * API：POST /api/spider/site-info
 * 認證：需帶 sign（admin api_key）
 *
 * 回傳格式：
 * {
 *     "data": {
 *         "admin_credit": 100000,
 *         "system_rate": 0.002,
 *         "system_rate_withdraw": 0.002,
 *         "usdt_deposit": true,
 *         "atm_deposit": true,
 *         "cvs_deposit": true,
 *         "cc_deposit": true,
 *         "qr_deposit": true,
 *         "withdraw": true,
 *         "guarantee_start_at": "2025-04-01",
 *         "store_guarantee_start_at": "2025-04-01",
 *         "support_shop": true,
 *         "store_initial_credit": 2000,
 *         "store_expired_days": 10,
 *         "store_guarantee_credit": 7500,
 *         "score_runner": true
 *     },
 *     "code": 1,
 *     "version": "1.3.6"
 * }
 */
class MainSystemApiService
{
    private $client;

    public function __construct()
    {
        $this->client = new Client(['timeout' => 10]);
    }

    /**
     * 取得站台資訊（點數、費率、功能開關等）
     *
     * @param string $apiUrl 站台的 API 網址
     * @param string $apiKey 站台的 API Key（admin api_key，作為 sign）
     * @return array|null API 回傳的 data 區塊，失敗回傳 null
     */
    public function getStationInfo($apiUrl, $apiKey)
    {
        if (blank($apiUrl) || blank($apiKey)) {
            Log::warning('MainSystemApi: api_url 或 api_key 未設定');

            return null;
        }

        try {
            $response = $this->client->post(rtrim($apiUrl, '/') . '/api/spider/site-info', [
                'headers' => [
                    'Accept' => 'application/json',
                ],
                'form_params' => [
                    'sign' => $apiKey,
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            // code=1 表示成功
            if (($body['code'] ?? 0) !== 1) {
                Log::warning('MainSystemApi: API 回傳非成功狀態', [
                    'api_url' => $apiUrl,
                    'code'    => $body['code'] ?? null,
                ]);

                return null;
            }

            return $body['data'] ?? null;
        } catch (\Exception $e) {
            Log::error('MainSystemApi: 取得站台資訊失敗', [
                'api_url' => $apiUrl,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 站台加點/扣點
     *
     * @param string $apiUrl    站台 API 網址
     * @param string $apiKey    站台 API Key
     * @param float  $amount    點數（正=加點，負=扣點）
     * @param string $column    credit 或 shop_credit
     * @return array|null API 回傳，失敗回傳 null
     */
    public function addCredit($apiUrl, $apiKey, $amount, $column = 'credit')
    {
        if (blank($apiUrl) || blank($apiKey)) {
            Log::warning('MainSystemApi: api_url 或 api_key 未設定（補點）');

            return null;
        }

        try {
            $timestamp = time();
            $encryptKey = config('app.key', '');
            $sign = hash('sha256', $apiKey . $encryptKey . $timestamp);

            $response = $this->client->post(rtrim($apiUrl, '/') . '/api/admin/credit-add', [
                'headers' => [
                    'Accept' => 'application/json',
                ],
                'json' => [
                    'amount'    => $amount,
                    'column'    => $column,
                    'timestamp' => $timestamp,
                    'sign'      => $sign,
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (($body['code'] ?? 0) !== 1) {
                Log::warning('MainSystemApi: 補點 API 回傳非成功', [
                    'api_url' => $apiUrl,
                    'code'    => $body['code'] ?? null,
                    'msg'     => $body['msg'] ?? null,
                ]);

                return $body;
            }

            return $body;
        } catch (\Exception $e) {
            Log::error('MainSystemApi: 補點失敗', [
                'api_url' => $apiUrl,
                'amount'  => $amount,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }
    }
}
