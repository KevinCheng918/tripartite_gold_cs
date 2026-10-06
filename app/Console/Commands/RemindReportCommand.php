<?php

namespace App\Console\Commands;

use App\Services\RemindReportService;
use App\Services\StaffDmService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * 每日超時提醒統計
 *
 * 兩種收件人：設定頁勾選的人收**全部人的**，所有在職同仁各收**自己那份**
 * （被提醒到的是統計，沒被提醒到的是一句肯定）。
 *
 * 排程在 08:30（`Kernel`）。`--date` 是補發用，也方便上線前拿真實資料看排版。
 */
class RemindReportCommand extends Command
{
    protected $signature = 'remind:report
                            {--date= : 統計哪一天（Y-m-d），預設昨天}
                            {--dry-run : 只印出內容，不實際發送}';

    protected $description = '私訊前一天的超時提醒統計：勾選的人收全部人的，被提醒到的人各收自己那份';

    /** @var array 沒送出時的原因說明 */
    private const REASONS = [
        StaffDmService::SKIP_NO_RECIPIENT => '沒有勾選收件人（到通訊管理 → 通知設定 → 超時提醒統計指定）',
        StaffDmService::SKIP_NOT_BOUND    => '勾選的收件人都還沒私訊過機器人',
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
            $sent = (int) Arr::get($result, 'sent', 0);

            if ($sent > 0) {
                $this->info("完整版：已送出 {$sent} 則（" . implode('、', (array) Arr::get($result, 'names', [])) . '）');
            } else {
                $reason = (string) Arr::get($result, 'reason');
                $this->warn('完整版：沒送出 —— ' . Arr::get(self::REASONS, $reason, $reason));
            }

            $this->info('個人版：' . Arr::get($result, 'personal_sent', 0) . ' 則');

            // 有人收到、有人沒收到也要講 —— 不然那幾個人會無聲消失
            $failed = (array) Arr::get($result, 'failed', []);

            if (filled($failed)) {
                $this->warn('沒收到的人：' . implode('、', $failed));
            }
        }

        // 空跑時把內容印出來，不然看不到排版對不對
        if ($dryRun) {
            $this->line('');
            $this->line('--- 完整版（勾選的人收到的）---');
            $this->line(strip_tags((string) Arr::get($result, 'text')));

            if (filled(Arr::get($result, 'personal_text'))) {
                $this->line('');
                $this->line('--- 個人版（第一筆）---');
                $this->line(strip_tags((string) Arr::get($result, 'personal_text')));
            }

            $this->line('');
            $this->info('個人版會發給 ' . Arr::get($result, 'personal_sent', 0) . ' 位在職同仁'
                . '（被提醒到的收統計，沒被提醒到的收一句肯定）');
            $this->line('⚠ 空跑的那句肯定是公版 —— 實際發送時由模型逐人生成');
        }

        return 0;
    }
}
