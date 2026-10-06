<?php

namespace App\Console\Commands;

use App\Services\TicketHandoverService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * 早上 7:00 的待接手清單
 *
 * 把還沒人處理的求助單發到內部群組並 tag 當天早班 ——
 * 大夜班沒人排班時，深夜的提醒 tag 不到人，這則負責把它們交接出去。
 */
class TicketHandoverCommand extends Command
{
    protected $signature = 'ticket:handover
                            {--date= : 哪一天的早班（Y-m-d），預設今天}
                            {--dry-run : 只印出內容，不實際發送}';

    protected $description = '把還沒人處理的求助單發到內部群組，tag 當天早班接手';

    /** @var array 沒送出時的原因說明 */
    private const REASONS = [
        TicketHandoverService::SKIP_NO_GROUP    => '沒有設定內部支援群組（到通訊管理 → 通知設定指定）',
        TicketHandoverService::SKIP_SEND_FAILED => 'Telegram 送出失敗，詳見 log',
    ];

    private $handoverService;

    public function __construct(TicketHandoverService $handoverService)
    {
        parent::__construct();

        $this->handoverService = $handoverService;
    }

    /**
     * @return int
     */
    public function handle()
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $this->handoverService->run($this->option('date'), $dryRun);

        $this->info('日期：' . Arr::get($result, 'date') . ($dryRun ? '（空跑，沒有實際發送）' : ''));
        $this->info('待接手：' . Arr::get($result, 'pending', 0) . ' 題');

        $mentioned = (array) Arr::get($result, 'mentioned', []);

        if (filled($mentioned)) {
            $this->info('tag 到的早班人員：' . implode('、', $mentioned));
        } else {
            // 這不是 info —— 沒人被 tag 代表這份清單沒有 owner
            $this->warn('tag 不到人：今天早班沒人排班，或排了但沒填 Telegram 帳號');
        }

        if (!$dryRun) {
            if (Arr::get($result, 'sent')) {
                $this->info('已發到內部群組');
            } else {
                $reason = (string) Arr::get($result, 'reason');
                $this->warn('沒發出去 —— ' . Arr::get(self::REASONS, $reason, $reason));
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
