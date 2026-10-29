<?php

namespace App\Console\Commands;

use App\Models\AttendanceReminder;
use App\Services\ClockReminderService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * 打卡提醒
 *
 * 排程每分鐘跑一次（見 Console\Kernel）。
 *
 * 手動驗證用 --dry-run：印出「這一刻會發給誰、內容長什麼樣」但不送出，
 * 否則要驗這支得真的等到上班前 15 分鐘。
 *
 * ⚠ `--now` 可以假裝「現在是某個時間」，這是唯一能把三個時間點
 * （上班前 15 分、下班後 30 分、再 30 分）一次驗完的辦法 ——
 * 只有搭 `--dry-run` 才有意義，正式跑不會也不該用。
 */
class RemindClockCommand extends Command
{
    protected $signature = 'attendance:remind
        {--dry-run : 只印出會發什麼，不送出}
        {--now= : 假裝現在是這個時間（Y-m-d H:i），只配 --dry-run 用}';

    protected $description = '上班前與下班後提醒同仁打卡';

    /** @var array 兩種提醒的顯示名稱 */
    private const TYPE_LABELS = [
        AttendanceReminder::TYPE_CLOCK_IN  => '上班前',
        AttendanceReminder::TYPE_CLOCK_OUT => '下班後',
    ];

    private $reminderService;

    public function __construct(ClockReminderService $reminderService)
    {
        parent::__construct();

        $this->reminderService = $reminderService;
    }

    /**
     * @return int
     */
    public function handle()
    {
        $dryRun = (bool) $this->option('dry-run');
        $now = $this->option('now');

        /*
         * ⚠ `--now` 不配 `--dry-run` 就擋下來。
         *
         * 那個選項會讓服務用假的時間去判斷「該不該發」，真的送出去的話
         * 同仁會在莫名其妙的時間收到提醒，而且狀態表會被寫進錯的紀錄 ——
         * 之後真正該發的那一則反而會被當成已發過而跳過。
         */
        if (filled($now) && !$dryRun) {
            $this->error('--now 只能搭 --dry-run 使用（避免用假時間真的發出通知）');

            return 1;
        }

        if ($dryRun) {
            $this->warn('── 空跑模式：不會送出任何訊息 ──');
        }

        $results = $this->reminderService->run([
            'dry_run' => $dryRun,
            'now'     => $now,
        ]);

        if (blank($results)) {
            $this->line($dryRun ? '這個時間點沒有人需要提醒' : '沒有送出任何提醒');

            return 0;
        }

        foreach ($results as $item) {
            $type = Arr::get(self::TYPE_LABELS, Arr::get($item, 'type'), Arr::get($item, 'type'));

            $this->line('');
            $this->line("── {$item['user']}　{$type}　出勤日期 {$item['date']} ──");
            // 空跑時要看得到完整內容，不然看不出排版與數字對不對
            $this->line(strip_tags((string) Arr::get($item, 'text')));
        }

        $this->line('');
        $this->info($dryRun
            ? '空跑結束，共 ' . count($results) . ' 則（沒有送出）'
            : '完成，共送出 ' . count($results) . ' 則');

        return 0;
    }
}
