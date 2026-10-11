<?php

namespace App\Console\Commands;

use App\Services\FollowupReportService;
use App\Services\StaffDmService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * 待追蹤事項的每日統計
 *
 * 只有一種收件人（設定頁勾選的人）—— 待追蹤事項的決定權在當班人員，
 * 而「誰今天當班」每天不同，逐人發「你昨天被問了幾次」沒有人需要據此行動。
 *
 * 排程在 10:30（`Kernel`）。`--date` 是補發用，也方便上線前拿真實資料看排版。
 */
class FollowupReportCommand extends Command
{
    protected $signature = 'followup:report
                            {--date= : 統計哪一天（Y-m-d），預設前一天。補發用}
                            {--dry-run : 只印出內容，不實際發送}';

    protected $description = '私訊待追蹤事項統計（新增幾筆、有結果幾筆、還掛著幾筆、拖最多次的是誰）';

    /** @var array 沒送出時的原因說明 */
    private const REASONS = [
        StaffDmService::SKIP_NO_RECIPIENT => '沒有勾選收件人（到通訊管理 → 通知設定 → 待追蹤事項指定）',
        StaffDmService::SKIP_NOT_BOUND    => '勾選的收件人都還沒私訊過機器人',
        StaffDmService::SKIP_SEND_FAILED  => 'Telegram 送出失敗，詳見 log',
    ];

    private $reportService;

    public function __construct(FollowupReportService $reportService)
    {
        parent::__construct();

        $this->reportService = $reportService;
    }

    /**
     * @return int
     */
    public function handle()
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $this->reportService->run($this->option('date'), $dryRun);

        $this->info('統計日期：' . Arr::get($result, 'date') . ($dryRun ? '（空跑，沒有實際發送）' : ''));
        $this->line('');
        $this->line((string) Arr::get($result, 'text'));

        $sent = (int) Arr::get($result, 'sent', 0);
        $reason = Arr::get($result, 'reason');

        if (filled($reason)) {
            $this->warn('沒有送出：' . Arr::get(self::REASONS, $reason, $reason));

            return 0;
        }

        $this->info("已送給 {$sent} 位");

        $failed = (array) Arr::get($result, 'failed', []);

        if (filled($failed)) {
            $this->warn('這幾位沒收到（請他們先私訊機器人一次）：' . implode('、', $failed));
        }

        return 0;
    }
}
