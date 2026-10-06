<?php

namespace App\Console\Commands;

use App\Services\RemindReportService;
use App\Services\StaffDmService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * 每日超時提醒統計
 *
 * 排程在 08:30（`Kernel`）。`--date` 是補發用，也方便上線前拿真實資料看排版。
 */
class RemindReportCommand extends Command
{
    protected $signature = 'remind:report
                            {--date= : 統計哪一天（Y-m-d），預設昨天}
                            {--dry-run : 只印出內容，不實際發送}';

    protected $description = '把前一天的求助單超時提醒統計私訊給指定主管';

    /** @var array 沒送出時的原因說明 */
    private const REASONS = [
        StaffDmService::SKIP_NO_RECIPIENT => '沒有設定收件人（到全域設定的內部支援群組區塊指定）',
        StaffDmService::SKIP_NOT_BOUND    => '收件人還沒私訊過機器人',
        StaffDmService::SKIP_SEND_FAILED  => 'Telegram 送出失敗，詳見 log',
    ];

    private $reportService;

    public function __construct(RemindReportService $reportService)
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

        if (!$dryRun) {
            if (Arr::get($result, 'sent')) {
                $this->info('已送出給：' . Arr::get($result, 'name'));
            } else {
                $reason = (string) Arr::get($result, 'reason');
                $this->warn('沒送出 —— ' . Arr::get(self::REASONS, $reason, $reason));
            }
        }

        // 空跑時把內容印出來，不然看不到排版對不對
        if ($dryRun) {
            $this->line('');
            $this->line(strip_tags((string) Arr::get($result, 'text')));
        }

        return 0;
    }
}
