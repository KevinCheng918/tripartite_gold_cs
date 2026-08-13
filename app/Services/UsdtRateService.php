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

        // 一根 4H K 線取 4H 最高最低
        $kline4h = $this->getKlines(240, 1);
        $high4h = 0;
        $low4h = 0;

        if (!empty($kline4h[0])) {
            $high4h = (float) $kline4h[0][2];
            $low4h = (float) $kline4h[0][3];
        }

        $avgRate = ($high4h > 0 && $low4h > 0) ? ($high4h + $low4h) / 2 : $currentRate;

        // 一根 1D K 線取今日最高最低
        $kline1d = $this->getKlines(1440, 1);
        $highDay = 0;
        $lowDay = 0;

        if (!empty($kline1d[0])) {
            $highDay = (float) $kline1d[0][2];
            $lowDay = (float) $kline1d[0][3];
        }

        // 15 分鐘 K 線畫曲線圖
        $klines15m = $this->getKlines(15, 16);
        $chartData = [];

        foreach ($klines15m as $k) {
            $chartData[] = [
                'time'  => date('H:i', $k[0]),
                'price' => (float) $k[4],
            ];
        }

        return [
            'current_rate' => $currentRate,
            'avg_rate'     => round($avgRate, 3),
            'high_4h'      => $high4h,
            'low_4h'       => $low4h,
            'high_day'     => $highDay,
            'low_day'      => $lowDay,
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
     * 取得 K 線資料
     *
     * @param int $period  K 線週期（分鐘）
     * @param int $limit   取幾根
     * @return array
     */
    private function getKlines($period = 240, $limit = 1)
    {
        try {
            $response = $this->client->get(self::KLINE_URL, [
                'query' => [
                    'market' => 'usdttwd',
                    'period' => $period,
                    'limit'  => $limit,
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true) ?: [];
        } catch (\Exception $e) {
            Log::error('USDT K 線取得失敗', ['error' => $e->getMessage()]);

            return [];
        }
    }
}
