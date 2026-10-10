<?php

namespace App\Console\Commands;

use App\Services\AttendanceReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * 打卡週報表／月報表通知
 *
 * 一支指令吃 `--type`，不是兩支 —— 兩者只差在統計區間怎麼算，
 * 其餘（收件人、文案、發送）完全一樣。拆兩支就是兩份一模一樣的程式碼。
 *
 * ```
 * php artisan attendance:report --type=weekly
 * php artisan attendance:report --type=monthly --date=2026-11-01 --dry-run
 * ```
 */
class AttendanceReportCommand extends Command
{
    protected $signature = 'attendance:report
                            {--type=weekly : weekly（上週一～日）或 monthly（上個月）}
                            {--date= : 以哪一天往回推（Y-m-d），預設今天。補發用}
                            {--dry-run : 只印出內容，不實際發送}';

    protected $description = '發送打卡週報表／月報表';

    private $reportService;

    public function __construct(AttendanceReportService $reportService)
    {
        parent::__construct();

        $this->reportService = $reportService;
    }

    /**
     * @return int
     */
    public function handle()
    {
        $type = (string) $this->option('type');

        if (!in_array($type, [AttendanceReportService::TYPE_WEEKLY, AttendanceReportService::TYPE_MONTHLY], true)) {
            $this->error("--type 只能是 weekly 或 monthly，收到的是「{$type}」");

            return 1;
        }

        $dryRun = (bool) $this->option('dry-run');
        $result = $this->reportService->run($type, $this->option('date'), $dryRun);
        $range = (array) Arr::get($result, 'range');

        $this->info(
            '統計區間：' . Arr::get($range, 'start') . ' ～ ' . Arr::get($range, 'end')
            . '（' . Arr::get($result, 'people') . ' 位有資料）'
            . ($dryRun ? '（空跑，沒有實際發送）' : '')
        );

        $this->line('');
        $this->line('--- 完整版（勾選的人收到的）---');
        $this->line((string) Arr::get($result, 'manager_text'));

        $skip = Arr::get($result, 'manager_skip');

        if (filled($skip)) {
            $this->warn("完整版沒有送出：{$skip}");
        } else {
            $this->info('完整版送給 ' . Arr::get($result, 'manager_sent') . ' 位');
        }

        $sample = Arr::get($result, 'personal_text');

        if (filled($sample)) {
            $this->line('');
            $this->line('--- 個人版（第一筆）---');
            $this->line($sample);
        }

        $this->info('個人版送給 ' . Arr::get($result, 'personal_sent') . ' 位');

        if ($dryRun) {
            $this->warn('⚠ 空跑的勉勵話是公版 —— 實際發送時由模型逐人生成');
        }

        $failed = (array) Arr::get($result, 'failed');

        if (filled($failed)) {
            $this->warn('發送失敗：' . implode('、', $failed));
        }

        return 0;
    }
}
