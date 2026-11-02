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
        // ⚠ 只算一次：摘要要用同一份（它需要每個點拆好的補點／消耗）
        $withDiff = $this->withDiff($points);

        return [
            'station' => [
                'id'   => (int) $stationId,
                'name' => filled($station) ? (string) $station->name : '',
            ],
            'range' => [
                'start' => $start->format('Y-m-d'),
                'end'   => $end->format('Y-m-d'),
            ],
            'points'      => $withDiff,
            'shop_points' => $this->withDiff($shopPoints),
            'failures'    => $failures,
            'summary'     => $this->summary($withDiff, $failures),
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
            $diff = filled($previous) ? $current - $previous : null;

            $points[$index]['diff'] = $diff;

            /*
             * ⚠ **把變化拆成「補點」與「消耗」**（需求方 2026-11-01：
             * 「點數報表的變化，要把補點跟消耗分開計算分開顯示」）。
             *
             * 兩個取樣點之間發生的事其實是兩件：客戶一直在消耗，
             * 中間可能有人補了點。所以
             *
             *     現在 = 前一點 + 消耗 + 補點
             *     → 消耗 = 變化 − 補點
             *
             * ⚠ **補點金額只能用 `change_amount`**（補點當下存下來的），
             * 不能拿變化去推 —— 那正是這兩者混在一起的原因。
             *
             * ⚠ 扣點也算在「補點」這一欄（`change_amount` 是負的）：
             * 那是**人為異動**，跟客戶消耗是兩回事，混進消耗會讓「平均每天
             * 消耗」被一次手動扣點拉歪。
             *
             * ⚠ 第一個點的 `diff` 是 null，所以 `consume` 也必須是 null ——
             * 填 0 會讓它被算進平均裡，而那一天其實沒有資料。
             */
            $topup = (float) Arr::get($point, 'change', 0);

            $points[$index]['topup'] = $topup;
            $points[$index]['consume'] = filled($diff) ? $diff - $topup : null;

            $previous = $current;
        }

        return $points;
    }

    /**
     * 平均每天消耗多少
     *
     * 需求方 2026-11-01：「還要有平均每天消耗多少，用每天 10 點同步的點數去
     * 平均就好。」
     *
     * 用每天 10 點那一筆當錨點，兩個錨點之間的**消耗**（不是變化）加總，
     * 再除以實際跨了幾天。
     *
     * ⚠ **算的是消耗不是變化**。直接拿兩天 10 點的餘額相減的話，中間補過點
     * 的那一天會算出「消耗是負的」（看起來像客戶把點數還回來）——
     * 而這整個需求的前提就是要把補點拆出去。
     *
     * ⚠ **除以「實際跨幾天」不是「錨點數量 − 1」**：某一天沒有 10 點那一筆
     * （同步失敗、或那天還沒上線）時，相鄰兩個錨點會跨 2 天以上。
     * 用數量去除會把那一段當成一天，平均被高估。
     *
     * ⚠ 不滿兩個錨點就回 null，不要回 0 —— 「算不出來」跟「每天消耗 0」
     * 是兩回事。
     *
     * @param array $points 已經過 `withDiff()`
     * @return array{avg: float|null, days: int, anchors: int}
     */
    private function dailyConsume(array $points)
    {
        $hour = (int) config('constants.STATION.CREDIT_REPORT.DAILY_ANCHOR_HOUR');
        $anchors = [];

        foreach ($points as $index => $point) {
            $at = (string) Arr::get($point, 'at');
            $date = substr($at, 0, 10);

            // 一天只取第一筆 10 點的
            if (Arr::has($anchors, $date)) {
                continue;
            }

            if ((int) substr($at, 11, 2) !== $hour) {
                continue;
            }

            $anchors[$date] = $index;
        }

        if (count($anchors) < 2) {
            return ['avg' => null, 'days' => 0, 'anchors' => count($anchors)];
        }

        $dates = array_keys($anchors);
        $total = 0.0;
        $previousIndex = null;

        foreach ($dates as $date) {
            $index = $anchors[$date];

            if (filled($previousIndex)) {
                /*
                 * ⚠ 區間是**左開右閉**：前一個錨點本身的消耗屬於再前一段。
                 * 含進來的話每一段都會多算一次最前面那個點。
                 */
                for ($i = $previousIndex + 1; $i <= $index; $i++) {
                    $total += (float) Arr::get($points, "{$i}.consume", 0);
                }
            }

            $previousIndex = $index;
        }

        $days = Carbon::parse(Arr::first($dates))->diffInDays(Carbon::parse(Arr::last($dates)));

        // 理論上到不了（錨點 ≥ 2 代表至少跨一天），但除以 0 的代價太大
        if ($days < 1) {
            return ['avg' => null, 'days' => 0, 'anchors' => count($anchors)];
        }

        return ['avg' => $total / $days, 'days' => $days, 'anchors' => count($anchors)];
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
     * @param array $points 已經過 `withDiff()`
     * @param array $failures
     * @return array{first: float|null, last: float|null, diff: float|null, topup: float|null,
     *     consume: float|null, daily_consume: float|null, daily_consume_days: int,
     *     count: int, failed: int}
     */
    private function summary(array $points, array $failures)
    {
        $count = count($points);

        // 一筆都沒有時不要硬算差值 —— 回 null 讓畫面顯示「這段期間沒有紀錄」
        if ($count < 1) {
            return [
                'first'   => null,
                'last'    => null,
                'diff'    => null,
                'topup'   => null,
                'consume' => null,
                'daily_consume'      => null,
                'daily_consume_days' => 0,
                'count'   => 0,
                'failed'  => count($failures),
            ];
        }

        $first = (float) Arr::get($points, '0.credits');
        $last = (float) Arr::get($points, ($count - 1) . '.credits');

        /*
         * ⚠ 這裡收到的 `$points` **已經過 `withDiff()`**（`build()` 只算一次
         * 再分給兩邊）。不能拿 `$last - $first` 去拆補點與消耗 ——
         * 那只知道總變化，拆不出中間補了多少。
         */
        $withDiff = $points;
        $topup = 0.0;
        $consume = 0.0;

        foreach ($withDiff as $point) {
            $topup += (float) Arr::get($point, 'topup', 0);
            $consume += (float) Arr::get($point, 'consume', 0);
        }

        $daily = $this->dailyConsume($withDiff);

        return [
            'first'   => $first,
            'last'    => $last,
            'diff'    => $last - $first,
            'topup'   => $topup,
            'consume' => $consume,
            'daily_consume'      => Arr::get($daily, 'avg'),
            'daily_consume_days' => (int) Arr::get($daily, 'days'),
            'count'   => $count,
            'failed'  => count($failures),
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
