<?php

namespace App\Console\Commands;

use App\Services\StaffDmService;
use App\Services\TaskNoticeService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * 每天早上私訊任務卡狀況
 *
 * 排程在 08:00（`Kernel`）。所有在職同仁收自己那份、設定頁勾選的人收全部人的。
 */
class NotifyDailyTaskCommand extends Command
{
    protected $signature = 'task:notify-daily
                            {--date= : 以哪一天為準（Y-m-d），預設今天}
                            {--dry-run : 只印出內容，不實際發送}';

    protected $description = '私訊任務卡狀況：已過期／今日到期／進行中，另發一份總覽給勾選的人';

    /** @var array 沒送出時的原因說明 */
    private const REASONS = [
        StaffDmService::SKIP_NO_RECIPIENT => '沒有勾選收件人（到通訊管理 → 通知設定 → 任務卡通知指定）',
        StaffDmService::SKIP_NOT_BOUND    => '勾選的收件人都還沒私訊過機器人',
        StaffDmService::SKIP_SEND_FAILED  => 'Telegram 送出失敗，詳見 log',
    ];

    private $noticeService;

    public function __construct(TaskNoticeService $noticeService)
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
        $this->info('未結案的卡：' . Arr::get($result, 'total', 0) . ' 張');

        $sent = (int) Arr::get($result, 'manager_sent', 0);

        if ($sent > 0) {
            $names = (array) Arr::get($result, 'manager_names', []);
            $this->info("總覽：已送出 {$sent} 則" . (filled($names) ? '（' . implode('、', $names) . '）' : ''));
        } else {
            $reason = (string) Arr::get($result, 'manager_skip');
            $this->warn('總覽：沒送出 —— ' . Arr::get(self::REASONS, $reason, $reason));
        }

        $this->info('個人版：' . Arr::get($result, 'personal_sent', 0) . ' 則');

        $failed = (array) Arr::get($result, 'failed', []);

        if (filled($failed)) {
            $this->warn('沒收到的人：' . implode('、', $failed));
        }

        // 空跑時把內容印出來，不然看不到排版對不對
        if ($dryRun) {
            $this->line('');
            $this->line('--- 總覽（勾選的人收到的）---');
            $this->line(strip_tags((string) Arr::get($result, 'manager_text')));

            if (filled(Arr::get($result, 'personal_text'))) {
                $this->line('');
                $this->line('--- 個人版（第一筆）---');
                $this->line(strip_tags((string) Arr::get($result, 'personal_text')));
            }
        }

        return 0;
    }
}
