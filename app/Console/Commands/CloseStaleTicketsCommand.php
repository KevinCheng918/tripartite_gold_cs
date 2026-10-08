<?php

namespace App\Console\Commands;

use App\Services\AutoReplySupportService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * 把放太久還沒人處理的求助單收掉
 *
 * ⚠ **這支預設不排程**（`constants.AUTO_REPLY.STALE_DAYS` 預設 0）——
 * 自動關掉客人的問題是不可逆的，不該預設開啟。要自動化的話把那個值改成天數，
 * 並在 `Console\Kernel` 加上排程。
 *
 * 存在的理由：2026-10-06 群組升級成 supergroup 之後，一批單因為引用掛不上而
 * 永遠回不了，在待接手清單裡積了兩週 —— 那種情況需要一次清掉。
 */
class CloseStaleTicketsCommand extends Command
{
    protected $signature = 'ticket:close-stale
                            {--days= : 開單超過幾天就收掉，預設讀 constants.AUTO_REPLY.STALE_DAYS}
                            {--dry-run : 只列出會收掉哪些，不實際關閉}';

    protected $description = '把放太久還沒人處理的求助單收掉，讓待接手清單只剩真正要處理的';

    private $supportService;

    public function __construct(AutoReplySupportService $supportService)
    {
        parent::__construct();

        $this->supportService = $supportService;
    }

    /**
     * @return int
     */
    public function handle()
    {
        $option = $this->option('days');
        $days = filled($option) ? (int) $option : (int) config('constants.AUTO_REPLY.STALE_DAYS');

        /*
         * ⚠ 沒給天數就什麼都不做，不要自己挑一個預設值 ——
         * 這支會關掉客人的問題，猜錯天數的代價是把還該處理的單一起收掉。
         */
        if ($days < 1) {
            $this->warn('請指定天數，例如：php artisan ticket:close-stale --days=7');
            $this->line('（或把 constants.AUTO_REPLY.STALE_DAYS 設成天數後再跑）');

            return 0;
        }

        $dryRun = (bool) $this->option('dry-run');
        $result = $this->supportService->closeStaleTickets($days, $dryRun);
        $tickets = (array) Arr::get($result, 'tickets', []);

        if (blank($tickets)) {
            $this->info("沒有超過 {$days} 天還沒處理的求助單");

            return 0;
        }

        $this->info(($dryRun ? '會收掉' : '已收掉') . ' ' . Arr::get($result, 'count', 0) . ' 張（超過 ' . $days . ' 天）：');

        $this->table(
            ['#', '客人群組', '問題', '已等', '催過'],
            array_map(function ($ticket) {
                return [
                    Arr::get($ticket, 'id'),
                    Arr::get($ticket, 'group'),
                    Arr::get($ticket, 'question'),
                    Arr::get($ticket, 'waited'),
                    Arr::get($ticket, 'reminded') . ' 次',
                ];
            }, $tickets)
        );

        if ($dryRun) {
            $this->line('');
            $this->warn('這是空跑，什麼都沒改。確認沒問題後把 --dry-run 拿掉再跑一次。');
        }

        return 0;
    }
}
