<?php

namespace App\Console\Commands;

use App\Services\StationCreditAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 同步站台餘點，低於門檻發告警
 *
 * 排程每天跑一次（見 Console\Kernel）。
 *
 * 手動驗證用 --dry-run：印出「誰會被告警、訊息長什麼樣」但不真的送出去，
 * 否則要驗這支得等到排程時間。--station 可以只跑一個站台。
 *
 * ⚠ --dry-run 是**完全唯讀**的：它只問主系統 API，不走 syncInfo()，
 * 所以 station 的 credits / synced_at 都不會被更新，站台管理頁看到的還是舊值。
 * 要讓頁面顯示最新點數，跑正常的（不加 --dry-run）或按頁面上的「同步」按鈕。
 */
class SyncStationCreditCommand extends Command
{
    protected $signature = 'station:sync-credit
        {--dry-run : 完全唯讀：只判斷並印出結果，不送告警也不更新點數}
        {--station= : 只處理指定的站台 ID}';

    protected $description = '同步各站台的系統餘點，低於門檻時發送告警';

    /** @var array 跳過原因對應的說明文字 */
    private const REASON_LABELS = [
        StationCreditAlertService::SKIP_SYNC_FAILED     => '主系統 API 沒回資料，跳過（不拿舊點數判斷）',
        StationCreditAlertService::SKIP_NOT_CHARGED     => '不收費，餘點不會被扣',
        StationCreditAlertService::SKIP_ABOVE_THRESHOLD => '點數充足',
        StationCreditAlertService::SKIP_NO_TARGET       => '沒有可發送的群組（站台與內部支援群組都沒設）',
        StationCreditAlertService::SKIP_SEND_FAILED     => '發送失敗，下一輪會重試',
        // 走到這個原因表示有沒被接住的例外，是要修的 bug —— 詳情在 log
        StationCreditAlertService::SKIP_ERROR           => '處理時發生錯誤，已跳過這一站（詳見 log）',
    ];

    private $alertService;

    public function __construct(StationCreditAlertService $alertService)
    {
        parent::__construct();

        $this->alertService = $alertService;
    }

    /**
     * @return int
     */
    public function handle()
    {
        $dryRun = (bool) $this->option('dry-run');
        $stationId = $this->option('station');

        if ($dryRun) {
            $this->warn('── 空跑模式：不會送出任何訊息，也不會更新站台的點數 ──');
        }

        $results = $this->alertService->run([
            'dry_run'    => $dryRun,
            'station_id' => $stationId,
        ]);

        /*
         * 指定單一站台時不查「未設定」清單 —— 那是全量檢查才有意義的總結，
         * 跑 --station=2 卻列出別的站台缺設定會看得莫名其妙。
         */
        $unconfigured = filled($stationId)
            ? collect()
            : $this->alertService->unconfiguredStations();

        if (blank($results)) {
            $this->info('沒有需要同步的站台（啟用中且 api_url / api_key 都有值的才會被撈出來）');
            $this->reportUnconfigured($unconfigured, $dryRun);

            return 0;
        }

        $this->renderTable($results);
        $this->renderPreviews($results, $dryRun);

        /*
         * 空跑不會真的發送，`alerted` 一律是 false —— 這時要數的是
         * 「條件都符合、只差沒送出」的那些，也就是有 target 又沒有跳過原因的。
         */
        $alerted = count(array_filter($results, function ($row) use ($dryRun) {
            if (!$dryRun) {
                return Arr::get($row, 'alerted') === true;
            }

            return filled(Arr::get($row, 'target')) && blank(Arr::get($row, 'reason'));
        }));

        if (!$dryRun) {
            Log::info('站台餘點同步完成', [
                'checked'      => count($results),
                'alerted'      => $alerted,
                'unconfigured' => $unconfigured->count(),
            ]);
        }

        $checked = count($results);
        $this->info($dryRun
            ? "共檢查 {$checked} 個站台，其中 {$alerted} 個符合告警條件（空跑未發送）"
            : "共檢查 {$checked} 個站台，送出 {$alerted} 則告警");

        $this->reportUnconfigured($unconfigured, $dryRun);

        return 0;
    }

    /**
     * 印出每個站台的處理結果
     *
     * @param array $results
     * @return void
     */
    private function renderTable($results)
    {
        $rows = [];

        foreach ($results as $row) {
            $rows[] = [
                Arr::get($row, 'station_id'),
                Arr::get($row, 'station'),
                number_format((float) Arr::get($row, 'credits', 0), 2, '.', ''),
                number_format((float) Arr::get($row, 'threshold', 0), 2, '.', ''),
                $this->statusLabel($row),
            ];
        }

        $this->table(['ID', '站台', '餘點', '門檻', '結果'], $rows);
    }

    /**
     * 空跑時把訊息內容印出來，讓人確認公版套完長什麼樣
     *
     * 給客戶的是兩則（告警／補點訊息），所以分段印並標上「第 N 則」——
     * 併成一段印的話看不出客戶那邊會跳幾次通知，也看不出圖掛在哪一則上。
     * 發到內部群組的只有一則，就不標序號。
     *
     * @param array $results
     * @param bool  $dryRun
     * @return void
     */
    private function renderPreviews($results, $dryRun)
    {
        if (!$dryRun) {
            return;
        }

        foreach ($results as $row) {
            $text = Arr::get($row, 'text');

            if (blank($text)) {
                continue;
            }

            $station = Arr::get($row, 'station');
            $target = $this->targetLabel($row);
            $topup = Arr::get($row, 'topup_text');

            $this->line('');
            $this->warn("── {$station} → {$target} ──");

            if (blank($topup)) {
                $this->line($text);

                continue;
            }

            $this->line('［第 1 則｜餘點告警］');
            $this->line($text);

            $image = Arr::get($row, 'image_url');

            $this->line('');
            $this->line('［第 2 則｜補點訊息］' . (filled($image) ? "附圖 {$image}" : '無圖'));
            $this->line($topup);
        }
    }

    /**
     * 印出並記錄「啟用中卻沒設 API」的站台
     *
     * 這些站台在 SQL 就被篩掉了，不會出現在上面的表格裡。少了這一段，
     * 「某個站台的 api_key 被清空、從此不再被檢查」這件事沒有任何地方看得到 ——
     * 而它的點數用完照樣會停用客戶的後台。
     *
     * 記 warning 而不是 info：啟用中卻檢查不到是需要有人處理的狀態。
     * 真的不打算接主系統的站台，把狀態改成停用就不會再被列出來，
     * 那是消除這個警告的正確方式。
     *
     * @param \Illuminate\Database\Eloquent\Collection $stations
     * @param bool                                     $dryRun
     * @return void
     */
    private function reportUnconfigured($stations, $dryRun)
    {
        if (blank($stations)) {
            return;
        }

        $count = $stations->count();
        $describe = function ($station) {
            return $station->name . '（缺 ' . implode('、', $this->missingFields($station)) . '）';
        };

        $this->warn("另有 {$count} 個站台未檢查：" . $stations->map($describe)->implode('；'));
        $this->line('  這些站台啟用中但同步不到餘點。不打算接主系統的話，把狀態改成停用就不會再列出來。');

        if ($dryRun) {
            return;
        }

        Log::warning('有啟用中的站台因未設定 API 而未檢查餘點', [
            'count'    => $count,
            'stations' => $stations->map(function ($station) {
                return [
                    'id'      => $station->id,
                    'name'    => $station->name,
                    'missing' => $this->missingFields($station),
                ];
            })->all(),
        ]);
    }

    /**
     * 這個站台缺哪些設定
     *
     * 印出來與寫 log 都要用，所以抽出來。
     *
     * @param \App\Models\Station $station
     * @return array
     */
    private function missingFields($station)
    {
        $missing = [];

        if (blank($station->api_url)) {
            $missing[] = 'API 網址';
        }

        if (blank($station->api_key)) {
            $missing[] = 'API 金鑰';
        }

        return $missing;
    }

    /**
     * 發送目標的說明文字
     *
     * 抽出來是因為兩個地方都要印，而且每多一種 target 就得兩邊都改 ——
     * 漏掉一邊的話，新的 target 會被當成「站台群組」顯示，
     * 看起來像是發給了客戶（實際上沒有）。
     *
     * @param array $row
     * @return string
     */
    private function targetLabel($row)
    {
        $target = Arr::get($row, 'target');

        if ($target === StationCreditAlertService::TARGET_INTERNAL_PENDING) {
            return '內部支援群組（有補點單待審核）';
        }

        if ($target === StationCreditAlertService::TARGET_INTERNAL) {
            return '內部支援群組';
        }

        return '站台群組';
    }

    /**
     * 單一站台的結果說明
     *
     * @param array $row
     * @return string
     */
    private function statusLabel($row)
    {
        $target = $this->targetLabel($row);

        if (Arr::get($row, 'alerted') === true) {
            return "已告警 → {$target}";
        }

        $reason = Arr::get($row, 'reason');

        // 空跑時沒有 reason 也沒送出，代表「條件符合、只是沒真的發」
        if (blank($reason)) {
            return "符合告警條件 → {$target}（空跑未發送）";
        }

        return Arr::get(self::REASON_LABELS, $reason, $reason);
    }
}
