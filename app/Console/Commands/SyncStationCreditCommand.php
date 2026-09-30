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
        StationCreditAlertService::SKIP_ABOVE_THRESHOLD => '點數充足',
        StationCreditAlertService::SKIP_COOLDOWN        => '冷卻期內，不重複告警',
        StationCreditAlertService::SKIP_NO_TARGET       => '沒有可發送的群組（站台與內部支援群組都沒設）',
        StationCreditAlertService::SKIP_SEND_FAILED     => '發送失敗，下一輪會重試',
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

        if (blank($results)) {
            $this->info('沒有需要同步的站台（啟用中且 api_url / api_key 都有值的才會被撈出來）');

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
                'checked' => count($results),
                'alerted' => $alerted,
            ]);
        }

        $checked = count($results);
        $this->info($dryRun
            ? "共檢查 {$checked} 個站台，其中 {$alerted} 個符合告警條件（空跑未發送）"
            : "共檢查 {$checked} 個站台，送出 {$alerted} 則告警");

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
            $target = Arr::get($row, 'target') === StationCreditAlertService::TARGET_INTERNAL
                ? '內部支援群組'
                : '站台群組';

            $this->line('');
            $this->warn("── {$station} → {$target} ──");
            $this->line($text);
        }
    }

    /**
     * 單一站台的結果說明
     *
     * @param array $row
     * @return string
     */
    private function statusLabel($row)
    {
        $target = Arr::get($row, 'target') === StationCreditAlertService::TARGET_INTERNAL
            ? '內部支援群組'
            : '站台群組';

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
