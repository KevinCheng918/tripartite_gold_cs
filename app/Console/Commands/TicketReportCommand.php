<?php

namespace App\Console\Commands;

use App\Services\TicketReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * 凌晨 1:00 的求助單去向統計
 *
 * 回答的是「昨天轉人工的那些單，有幾張讓題庫長大了」——
 * 動機與分類見 `TicketReportService`。
 */
class TicketReportCommand extends Command
{
    protected $signature = 'ticket:report
                            {--date= : 統計哪一天（Y-m-d），預設前一天}
                            {--dry-run : 只印出內容，不實際發送}';

    protected $description = '把前一天轉人工的求助單去向統計發到內部支援群組';

    /** @var array 沒送出時的原因說明 */
    private const REASONS = [
        TicketReportService::SKIP_NO_GROUP    => '沒有設定內部支援群組（到通訊管理 → 通知設定指定）',
        TicketReportService::SKIP_SEND_FAILED => 'Telegram 送出失敗，詳見 log',
    ];

    private $reportService;

    public function __construct(TicketReportService $reportService)
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
        $stat = (array) Arr::get($result, 'stat', []);

        $date = (string) Arr::get($result, 'date');
        $opened = (int) Arr::get($stat, 'opened');
        $closed = (int) Arr::get($stat, 'closed');
        $grew = (int) Arr::get($stat, 'grew');
        $unknown = (int) Arr::get($stat, 'unknown');

        $this->info("日期：{$date}" . ($dryRun ? '（空跑，沒有實際發送）' : ''));
        $this->info("開了 {$opened} 張，已結案 {$closed} 張，其中讓題庫長大的 {$grew} 張");

        /*
         * ⚠ 「未分類」有數字時要當成警告而不是資訊：那代表有一種處理方式
         * 沒被歸類，三段的數字會加不起來（見 `unknownCount()`）。
         */
        if ($unknown > 0) {
            $this->warn("有 {$unknown} 張的處理方式沒有被歸類，請檢查 CLOSE_ACTION 的分組");
        }

        if (!$dryRun) {
            if (Arr::get($result, 'sent')) {
                $this->info('已發到內部群組');
            } else {
                $reason = (string) Arr::get($result, 'reason');
                $detail = (string) Arr::get(self::REASONS, $reason, $reason);
                $this->warn("沒發出去 —— {$detail}");
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
