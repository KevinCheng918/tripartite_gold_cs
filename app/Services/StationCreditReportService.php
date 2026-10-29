<?php

namespace App\Services;

use App\Models\StationCreditLog;
use App\Repositories\StationCreditLogRepository;
use App\Repositories\StationRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * 站台點數報表（餘點曲線圖）
 *
 * 資料來自 `station_credit_log` —— 每次同步寫一筆，見
 * `StationService::syncInfo()`。
 */
class StationCreditReportService
{
    /**
     * 沒指定期間時看最近幾天
     *
     * ⚠ 需求方 2026-10-26 指定**一週**。每天同步一次的話，一週大約七個點 ——
     * 看得出這幾天的走勢又不會擠成一團；要看更長的用上面的快捷鈕。
     *
     * @var int
     */
    const DEFAULT_DAYS = 7;

    private $creditLogRepository;
    private $stationRepository;

    public function __construct(
        StationCreditLogRepository $creditLogRepository,
        StationRepository $stationRepository
    ) {
        $this->creditLogRepository = $creditLogRepository;
        $this->stationRepository = $stationRepository;
    }

    /**
     * 某站台某區間的報表資料
     *
     * ⚠ **系統點數與商城點數是兩條線**（需求方 2026-10-30）：主系統補點 API
     * 回的餘額是「被補的那一種」的餘額 —— 混在一條線上，一筆商城補點會變成
     * 系統點數憑空大跳。
     *
     * ⚠ 商城那條線目前**只有補點那幾筆**：主系統 `siteInfo()` 只回
     * `admin_credit`，同步取樣不到商城餘額。所以 `shop_points` 常常是空的，
     * 空的時候前端不要畫那條線（畫出來是一條空軸，看的人會以為壞了）。
     *
     * @param int   $stationId
     * @param array $params 可帶 start／end（Y-m-d）
     * @return array{station: array, range: array, points: array, shop_points: array, failures: array, summary: array}
     */
    public function build($stationId, array $params)
    {
        [$start, $end] = $this->resolveRange($params);

        $logs = $this->creditLogRepository->getByStationAndRange(
            $stationId,
            $start->format('Y-m-d H:i:s'),
            $end->format('Y-m-d H:i:s')
        );

        $points = [];
        $shopPoints = [];
        $failures = [];

        foreach ($logs as $log) {
            /*
             * ⚠ **同步失敗的那幾筆不能進曲線**：`credits` 是 null，
             * 畫進去會變成掉到 0 的假跌。改成另外列成清單，
             * 讓人看得出「那個時間點同步失敗了」。
             */
            if (blank($log->credits)) {
                $failures[] = [
                    'at'     => $log->created_at->format('Y-m-d H:i'),
                    'source' => (string) $log->source,
                ];

                continue;
            }

            $point = [
                'at'      => $log->created_at->format('Y-m-d H:i'),
                'credits' => (float) $log->credits,
                'source'  => (string) $log->source,
            ];

            /*
             * 補點那幾筆多帶「原本／補多少／現在」。
             *
             * ⚠ `before` 一定要用 `現在 − 異動` 算，**不能拿前一個點**：
             * 前一次同步到補點之間客戶照樣在消耗，前一個點不是補點前一刻的餘額。
             */
            if (filled($log->change_amount)) {
                $point['change'] = (float) $log->change_amount;
                $point['before'] = (float) $log->credits - (float) $log->change_amount;
            }

            if ((string) $log->credit_type === StationCreditLog::CREDIT_TYPE_SHOP) {
                $shopPoints[] = $point;

                continue;
            }

            $points[] = $point;
        }

        $station = $this->stationRepository->find($stationId);

        return [
            'station' => [
                'id'   => (int) $stationId,
                'name' => filled($station) ? (string) $station->name : '',
            ],
            'range' => [
                'start' => $start->format('Y-m-d'),
                'end'   => $end->format('Y-m-d'),
            ],
            'points'      => $this->withDiff($points),
            'shop_points' => $this->withDiff($shopPoints),
            'failures'    => $failures,
            'summary'     => $this->summary($points, $failures),
        ];
    }

    /**
     * 每個點補上「跟前一個點差多少」
     *
     * 需求方 2026-10-30：「每一天的點，都要顯示跟昨天正負多少。」同步是一小時
     * 一次，所以實際比的是**前一個點**而不是「昨天」—— 兩個點之間差幾個小時，
     * 圖上的時間標籤看得出來。
     *
     * ⚠ 第一個點的 `diff` 是 **null 不是 0**：區間的第一筆沒有「前一個點」
     * 可比，填 0 會在畫面上顯示「±0」，看的人會以為那段時間點數真的沒動。
     *
     * ⚠ 同步失敗那幾筆已經在上面被挑掉了，所以這裡比的是「前一次成功取樣」——
     * 中間失敗過的話差值會跨得比較久，那正是實際知道的全部。
     *
     * @param array $points
     * @return array
     */
    private function withDiff(array $points)
    {
        $previous = null;

        foreach ($points as $index => $point) {
            $current = (float) Arr::get($point, 'credits');
            $points[$index]['diff'] = filled($previous) ? $current - $previous : null;
            $previous = $current;
        }

        return $points;
    }

    /**
     * 期間：沒給就看最近 N 天
     *
     * ⚠ `end` 要**含當天整日**（`endOfDay`）—— 用 `Y-m-d 00:00` 當上界的話，
     * 今天早上同步的那一筆會被排除在外，而那正是最常要看的一筆。
     *
     * @param array $params
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveRange(array $params)
    {
        $start = Arr::get($params, 'start');
        $end = Arr::get($params, 'end');

        if (blank($start) || blank($end)) {
            return [
                now()->subDays(self::DEFAULT_DAYS)->startOfDay(),
                now()->endOfDay(),
            ];
        }

        return [
            Carbon::parse($start)->startOfDay(),
            Carbon::parse($end)->endOfDay(),
        ];
    }

    /**
     * 摘要：期間的起訖點數與變化
     *
     * @param array $points
     * @param array $failures
     * @return array{first: float|null, last: float|null, diff: float|null, count: int, failed: int}
     */
    private function summary(array $points, array $failures)
    {
        $count = count($points);

        // 一筆都沒有時不要硬算差值 —— 回 null 讓畫面顯示「這段期間沒有紀錄」
        if ($count < 1) {
            return ['first' => null, 'last' => null, 'diff' => null, 'count' => 0, 'failed' => count($failures)];
        }

        $first = (float) Arr::get($points, '0.credits');
        $last = (float) Arr::get($points, ($count - 1) . '.credits');

        return [
            'first'  => $first,
            'last'   => $last,
            'diff'   => $last - $first,
            'count'  => $count,
            'failed' => count($failures),
        ];
    }

    /**
     * 來源代碼對應的顯示文字
     *
     * @return array<string, string>
     */
    public function sourceLabels()
    {
        return [
            StationCreditLog::SOURCE_SCHEDULE => trans('station.credit_source.schedule'),
            StationCreditLog::SOURCE_MANUAL   => trans('station.credit_source.manual'),
            StationCreditLog::SOURCE_COMMAND  => trans('station.credit_source.command'),
            StationCreditLog::SOURCE_ALERT    => trans('station.credit_source.alert'),
            StationCreditLog::SOURCE_AFFORD   => trans('station.credit_source.afford'),
            StationCreditLog::SOURCE_TOPUP    => trans('station.credit_source.topup'),
        ];
    }
}
