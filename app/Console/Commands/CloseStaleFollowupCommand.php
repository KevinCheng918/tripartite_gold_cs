<?php

namespace App\Console\Commands;

use App\Services\CustomerFollowupService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * 把放太久還沒結果的待追蹤事項收掉
 *
 * ⚠ **這支不排程**（`constants.FOLLOWUP.STALE_DAYS` 預設 0 = 停用）。
 * 需求方 2026-10-11 指定「不自動結案」—— 既然如此，就不該有一條悄悄替他
 * 收單的路。但後台列表不能無限長，所以指令寫好、排程不掛，
 * 要清的時候自己跑。
 *
 * 比照 `ticket:close-stale`，連「沒給天數就什麼都不做」都一樣：
 * 猜錯天數的代價是把還該追的事項一起收掉。
 */
class CloseStaleFollowupCommand extends Command
{
    protected $signature = 'followup:close-stale
                            {--days= : 計時起點超過幾天就收掉，預設讀 constants.FOLLOWUP.STALE_DAYS}
                            {--dry-run : 只列出會收掉哪些，不實際關閉}';

    protected $description = '把放太久還沒結果的待追蹤事項收掉';

    private $followupService;

    public function __construct(CustomerFollowupService $followupService)
    {
        parent::__construct();

        $this->followupService = $followupService;
    }

    /**
     * @return int
     */
    public function handle()
    {
        $option = $this->option('days');
        $days = filled($option) ? (int) $option : (int) config('constants.FOLLOWUP.STALE_DAYS');

        if ($days < 1) {
            $this->warn('請指定天數，例如：php artisan followup:close-stale --days=14');
            $this->line('（預設不自動收 —— 需求方要求待追蹤事項不自動結案）');

            return 0;
        }

        $dryRun = (bool) $this->option('dry-run');
        $result = $this->followupService->closeStale($days, $dryRun);
        $items = Arr::get($result, 'items');

        if (blank($items)) {
            $this->info("沒有超過 {$days} 天還沒結果的待追蹤事項");

            return 0;
        }

        $this->info(($dryRun ? '會收掉' : '已收掉') . ' ' . Arr::get($result, 'count', 0) . " 筆（超過 {$days} 天）：");

        $rows = [];
        $labels = (array) config('constants.FOLLOWUP.SOURCE_LABEL');

        foreach ($items as $item) {
            $rows[] = [
                $item->id,
                (string) Arr::get($labels, $item->source),
                filled($item->group) ? $item->group->title : "#{$item->telegram_group_id}",
                (string) $item->sender_name,
                (string) $item->wait_text,
                "{$item->wait_count} 次",
                "{$item->ask_round} 次",
            ];
        }

        $this->table(['#', '來源', '對話', '誰說的', '原句', '已拖', '已追問'], $rows);

        if ($dryRun) {
            $this->line('');
            $this->warn('這是空跑，什麼都沒改。確認沒問題後把 --dry-run 拿掉再跑一次。');
        }

        return 0;
    }
}
