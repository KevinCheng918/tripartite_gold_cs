<?php

namespace App\Services;

use App\Models\Station;
use App\Repositories\VmRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 站台的點數夠不夠扣這期虛擬機費用
 *
 * 「我 lv-uat 的點數夠扣這期的虛擬機費用嗎」
 *
 * ```
 * 這期繳了沒 → 繳了就不用算
 *            ↘ 沒繳 → 這期要多少 USDT
 *                   → 同步一次站台點數（不拿舊的判斷）
 *                   → 換算成點數 → 試算剩多少
 * ```
 *
 * ⚠ **單位不一樣，一定要換算。** 虛擬機費用是 **USDT**（繳款通知與 VPN
 * 續約通知都明寫），站台餘點是**點**。補點那邊的關係是
 * 「USDT ＝ 點數 ÷ 匯率」，反過來就是 **點數 ＝ USDT × 匯率**。
 *
 * 直接拿 800 點比 800 USDT 會差三十幾倍 —— 那會給出「夠扣」但其實遠遠不夠
 * 的答案，而這個答案是拿來決定要不要補點的。
 *
 * ⚠ **算不出來就說算不出來**，不要湊一個數字。沒有匯率、同步失敗、
 * 沒有帳單 —— 每一種都有自己的回報，理由同餘點告警的
 * 「絕不拿舊點數判斷」。
 */
class VmAffordabilityService
{
    /** @var string 這期已經繳掉了，不用算 */
    const RESULT_PAID = 'paid';

    /** @var string 這期的帳單還在等我們審核客戶上傳的證明 */
    const RESULT_PENDING = 'pending';

    /** @var string 這個站台這期沒有帳單 */
    const RESULT_NO_BILLING = 'no_billing';

    /** @var string 主系統沒回點數 */
    const RESULT_SYNC_FAILED = 'sync_failed';

    /** @var string 今日匯率沒決定，4H 均價也拿不到 */
    const RESULT_NO_RATE = 'no_rate';

    /** @var string 算出來了 */
    const RESULT_OK = 'ok';

    /** @var string 匯率來源：今天決定的對客報價 */
    const RATE_DECIDED = 'decided';

    /** @var string 匯率來源：4H 均價（今日報價還沒決定時的退路） */
    const RATE_AVG_4H = 'avg_4h';

    private $vmRepository;
    private $stationService;
    private $dailyRateService;
    private $usdtRateService;

    public function __construct(
        VmRepository $vmRepository,
        StationService $stationService,
        DailyRateService $dailyRateService,
        UsdtRateService $usdtRateService
    ) {
        $this->vmRepository = $vmRepository;
        // 同步點數走站台頁「同步」按鈕的同一支
        $this->stationService = $stationService;
        // 今天對客的報價（還沒決定時回 null）
        $this->dailyRateService = $dailyRateService;
        // 退路：4H 均價
        $this->usdtRateService = $usdtRateService;
    }

    /**
     * 試算
     *
     * @param Station $station
     * @param string  $month YYYY-MM
     * @return array{
     *     result: string,
     *     fee: float,
     *     credits: float,
     *     needed: float,
     *     remaining: float,
     *     enough: bool,
     *     rate: float,
     *     rate_source: string,
     *     synced_at: \Illuminate\Support\Carbon|null,
     *     count: int
     * }
     *
     * `count` 是**這筆金額由幾台加總而來**，不是站台的機器總數 ——
     * 一台已繳一台未繳時，`fee` 只含未繳那台，`count` 也只能算一台。
     */
    public function evaluate(Station $station, $month)
    {
        $servers = $this->vmRepository->getServersByStation($station->id, $month);
        $bills = $this->collectBillings($servers);

        // 0 筆：這期真的沒有要收的（關機不出帳，或帳單還沒產）
        if ($bills['total'] < 1) {
            return $this->result(self::RESULT_NO_BILLING);
        }

        /*
         * ⚠ 已經繳掉的不用算 —— 問「夠不夠扣」的前提是還要扣。
         * 需求方指定的流程第一步就是「先去查看這期繳費了沒」。
         */
        if ($bills['owed'] < 1) {
            return $this->result(self::RESULT_PAID, [
                'fee'   => $bills['paid'],
                'count' => $bills['total'],
            ]);
        }

        // 待審核的錢還沒入帳，所以算進「還要扣」；但要讓呼叫端講清楚是哪一種
        $fee = $bills['unpaid'] + $bills['pending'];
        $state = $bills['unpaid'] > 0 ? self::RESULT_OK : self::RESULT_PENDING;
        $count = $bills['owed'];

        $credits = $this->freshCredits($station);

        if (blank($credits)) {
            return $this->result(self::RESULT_SYNC_FAILED, ['fee' => $fee, 'count' => $count]);
        }

        $rate = $this->rate();

        if (blank($rate)) {
            return $this->result(self::RESULT_NO_RATE, [
                'fee'       => $fee,
                'credits'   => $credits,
                'synced_at' => $station->synced_at,
                'count'     => $count,
            ]);
        }

        $needed = $fee * (float) $rate['value'];

        return $this->result($state, [
            'fee'         => $fee,
            'credits'     => $credits,
            'needed'      => $needed,
            'remaining'   => $credits - $needed,
            'enough'      => $credits >= $needed,
            'rate'        => (float) $rate['value'],
            'rate_source' => (string) $rate['source'],
            'synced_at'   => $station->synced_at,
            'count'       => $count,
        ]);
    }

    /**
     * 這期的帳單加起來是多少
     *
     * ⚠ 這裡**要加總**（跟卡片一台一段不一樣）：問的是「總共要扣多少」，
     * 不是「哪一台多少」。
     *
     * ⚠ **關機機器的「未收」不算**（需求方 2026-10-13）。關機不收錢，
     * 繳款通知（`getBillingsForNotice()`）與未收清單都不會去催那一筆 ——
     * 算進「要扣多少」的話，試算會說不夠扣、害人多補了用不到的點。
     *
     * ⚠ **待審核的照算，不分電源。** 那是客戶已經付錢等我們審的款，
     * 機器後來關機不代表那筆錢不存在。
     *
     * ⚠ `owed` 與 `total` 要分開數。訊息上的「（N 台）」是在說**這筆金額
     * 是幾台加起來的** —— 用全部筆數的話，一台已繳一台未繳時會寫成
     * 「這期費用：80.00 USDT（2 台）」，但那 80 只來自一台。
     *
     * @param \Illuminate\Database\Eloquent\Collection $servers
     * @return array{
     *     total: int, owed: int, unpaid: float, pending: float, paid: float
     * }
     */
    private function collectBillings($servers)
    {
        $total = 0;
        $owed = 0;
        $unpaid = 0.0;
        $pending = 0.0;
        $paid = 0.0;

        foreach ($servers as $server) {
            foreach ($server->billings as $billing) {
                $amount = (float) $billing->amount;

                if ((int) $billing->paid === (int) config('constants.VM.BILLING.PAID')) {
                    $total++;
                    $paid += $amount;

                    continue;
                }

                if ((int) $billing->paid === (int) config('constants.VM.BILLING.PENDING')) {
                    $total++;
                    $owed++;
                    $pending += $amount;

                    continue;
                }

                // 未收 ＋ 關機 ＝ 不會去收，所以也不算進「要扣多少」
                if (!$server->isPoweredOn()) {
                    continue;
                }

                $total++;
                $owed++;
                $unpaid += $amount;
            }
        }

        return ['total' => $total, 'owed' => $owed, 'unpaid' => $unpaid, 'pending' => $pending, 'paid' => $paid];
    }

    /**
     * 同步一次，拿現在的點數
     *
     * ⚠ **不能用資料庫裡那個值。** 它可能是好幾天前的，而這個數字是拿來
     * 決定「要不要補點」的 —— 用舊的判斷正是餘點告警
     * （`SKIP_SYNC_FAILED`）一直在防的事。
     *
     * @param Station $station
     * @return float|null null = 主系統沒回
     */
    private function freshCredits(Station $station)
    {
        try {
            $info = $this->stationService->syncInfo($station);
        } catch (\Exception $e) {
            Log::error('試算餘點：同步失敗', ['station_id' => $station->id, 'error' => $e->getMessage()]);

            return null;
        }

        if (blank($info)) {
            Log::warning('試算餘點：主系統沒回資料', ['station_id' => $station->id]);

            return null;
        }

        // syncInfo() 的 select 不含 synced_at，重撈才拿得到同步時間
        $station->refresh();

        return (float) $station->credits;
    }

    /**
     * 換算用的匯率
     *
     * 今天決定的對客報價優先；還沒決定就退到 4H 均價（需求方 2026-10-13）。
     *
     * ⚠ **回報時一定要講用的是哪一個。** 兩個數字會不一樣，而試算結果
     * 直接影響「要不要補點」—— 看的人得知道這是定案的報價還是浮動的均價。
     *
     * ⚠ 不退到「昨天的匯率」：那是過期的，比照
     * `DailyRateService::customerAnswer()`（寧可請對方稍候也不給舊匯率）。
     *
     * @return array{value: float, source: string}|null
     */
    private function rate()
    {
        $decided = $this->dailyRateService->todayRate();

        if (filled($decided) && (float) $decided > 0) {
            return ['value' => (float) $decided, 'source' => self::RATE_DECIDED];
        }

        try {
            $data = $this->usdtRateService->getRateWithHistory();
        } catch (\Exception $e) {
            Log::warning('試算餘點：取 4H 均價失敗', ['error' => $e->getMessage()]);

            return null;
        }

        $avg = (float) Arr::get((array) $data, 'avg_rate');

        if ($avg <= 0) {
            return null;
        }

        return ['value' => $avg, 'source' => self::RATE_AVG_4H];
    }

    /**
     * 統一回傳形狀，缺的補預設
     *
     * ⚠ 每個欄位都給預設值，呼叫端就不必為每一種 result 各判一次有沒有。
     *
     * @param string $result
     * @param array  $values
     * @return array
     */
    private function result($result, array $values = [])
    {
        return array_merge([
            'result'      => $result,
            'fee'         => 0.0,
            'credits'     => 0.0,
            'needed'      => 0.0,
            'remaining'   => 0.0,
            'enough'      => false,
            'rate'        => 0.0,
            'rate_source' => '',
            'synced_at'   => null,
            'count'       => 0,
        ], $values);
    }
}
