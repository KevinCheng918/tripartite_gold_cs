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
     * @param int   $stationId
     * @param array $params 可帶 start／end（Y-m-d）
     * @return array{station: array, points: array, failures: array, summary: array}
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

            $points[] = [
                'at'      => $log->created_at->format('Y-m-d H:i'),
                'credits' => (float) $log->credits,
                'source'  => (string) $log->source,
            ];
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
            'points'   => $points,
            'failures' => $failures,
            'summary'  => $this->summary($points, $failures),
        ];
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
        ];
    }
}
