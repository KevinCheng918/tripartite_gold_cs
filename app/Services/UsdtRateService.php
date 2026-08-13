<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * USDT 匯率 Service
 *
 * 使用 MAX 交易所 API 取得 USDT/TWD 即時匯率和 K 線資料。
 */
class UsdtRateService
{
    private $client;

    /** @var string MAX 交易所即時價格 API */
    private const PRICE_URL = 'https://max-api.maicoin.com/api/v3/wallet/m/index_prices';

    /** @var string MAX 交易所 K 線 API */
    private const KLINE_URL = 'https://max-api.maicoin.com/api/v2/k';

    public function __construct()
    {
        $this->client = new Client(['timeout' => 10]);
    }

    /**
     * 取得即時匯率 + 4 小時 K 線歷史
     *
     * @return array
     */
    public function getRateWithHistory()
    {
        $currentRate = $this->getCurrentRate();
        $klines = $this->getKlines();

        // 計算 4 小時最高+最低/2
        $high = 0;
        $low = PHP_FLOAT_MAX;
        $chartData = [];

        // debug: 記錄第一筆 K 線格式
        if (!empty($klines)) {
            Log::info('USDT K 線格式', ['first' => $klines[0]]);
        }

        foreach ($klines as $k) {
            // MAX API K 線格式：[timestamp, open, high, low, close, volume]
            $time = $k[0];
            $kHigh = (float) $k[2];
            $kLow = (float) $k[3];
            $kClose = (float) $k[4];

            if ($kHigh > $high) {
                $high = $kHigh;
            }
            if ($kLow < $low) {
                $low = $kLow;
            }

            $chartData[] = [
                'time'  => date('H:i', $time),
                'price' => $kClose,
            ];
        }

        if ($low === PHP_FLOAT_MAX) {
            $low = 0;
        }
        $avgRate = ($high > 0 && $low > 0) ? ($high + $low) / 2 : $currentRate;

        return [
            'current_rate' => $currentRate,
            'avg_rate'     => round($avgRate, 2),
            'high'         => $high,
            'low'          => $low,
            'chart'        => $chartData,
            'updated_at'   => now()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * 取得即時 USDT/TWD 匯率
     *
     * @return float
     */
    private function getCurrentRate()
    {
        try {
            $response = $this->client->get(self::PRICE_URL);
            $body = json_decode($response->getBody()->getContents(), true);

            return (float) ($body['usdttwd'] ?? 0);
        } catch (\Exception $e) {
            Log::error('USDT 即時匯率取得失敗', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * 取得 4 小時 K 線（每 15 分鐘一根，共 16 根）
     *
     * @return array
     */
    private function getKlines()
    {
        try {
            $response = $this->client->get(self::KLINE_URL, [
                'query' => [
                    'market' => 'usdttwd',
                    'period' => 15,       // 15 分鐘
                    'limit'  => 16,       // 4 小時 = 16 根
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true) ?: [];
        } catch (\Exception $e) {
            Log::error('USDT K 線取得失敗', ['error' => $e->getMessage()]);

            return [];
        }
    }
}
