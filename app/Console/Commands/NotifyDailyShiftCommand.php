<?php

namespace App\Console\Commands;

use App\Services\ShiftNoticeService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * 每天早上私訊今日班表
 *
 * 排程在 08:00（`Kernel`）。`--date` 是補發用：昨天 worker 掛了想補一份時
 * 不必等明天，也方便上線前拿真實資料看排版。
 */
class NotifyDailyShiftCommand extends Command
{
    protected $signature = 'shift:notify-daily
                            {--date= : 指定日期（Y-m-d），預設今天}
                            {--dry-run : 只印出內容，不實際發送}';

    protected $description = '私訊今日班表：主管收完整班表、每個有班的人收自己那一份';

    /** @var array 沒送出時的原因說明 */
    private const REASONS = [
        ShiftNoticeService::SKIP_NO_MANAGER  => '沒有勾選收件人（到通訊管理 → 通知設定 → 班表通知指定）',
        ShiftNoticeService::SKIP_NOT_BOUND   => '勾選的收件人都還沒私訊過機器人',
        ShiftNoticeService::SKIP_SEND_FAILED => 'Telegram 送出失敗，詳見 log',
    ];

    private $noticeService;

    public function __construct(ShiftNoticeService $noticeService)
    {
        parent::__construct();

        $this->noticeService = $noticeService;
    }

    /**
     * @return int
     */
    public function handle()
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $this->noticeService->run($this->option('date'), $dryRun);

        $this->info('日期：' . Arr::get($result, 'date') . ($dryRun ? '（空跑，沒有實際發送）' : ''));

        $managerSent = (int) Arr::get($result, 'manager_sent', 0);

        if ($managerSent > 0) {
            $names = (array) Arr::get($result, 'manager_names', []);
            $this->info("完整班表：已送出 {$managerSent} 則"
                . (filled($names) ? '（' . implode('、', $names) . '）' : ''));
        } else {
            $reason = (string) Arr::get($result, 'manager_skip');
            $this->warn('完整班表：沒送出 —— ' . Arr::get(self::REASONS, $reason, $reason));
        }

        $this->info('個人那份：' . Arr::get($result, 'personal_sent', 0) . ' 則');

        if (filled(Arr::get($result, 'failed'))) {
            $this->warn('沒收到的人：' . implode('、', Arr::get($result, 'failed')));
        }

        // 空跑時把內容印出來，不然看不到排版對不對
        if ($dryRun) {
            $this->line('');
            $this->line('--- 完整班表（收件人收到的）---');
            $this->line(strip_tags((string) $result['manager_text']));

            if (filled($result['personal_text'])) {
                $this->line('');
                $this->line('--- 個人那份（第一筆）---');
                $this->line(strip_tags((string) $result['personal_text']));
            }
        }

        return 0;
    }
}
