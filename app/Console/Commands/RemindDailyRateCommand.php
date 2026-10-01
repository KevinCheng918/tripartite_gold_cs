<?php

namespace App\Console\Commands;

use App\Services\DailyRateService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * 提醒今日匯率還沒決定
 *
 * 排程每 30 分鐘跑一次、到當天結束為止（見 Console\Kernel）。
 * 已經有人回覆、或今天根本還沒報價的話，這支什麼都不會做。
 */
class RemindDailyRateCommand extends Command
{
    protected $signature = 'rate:remind';

    protected $description = '今日匯率還沒決定時，在內部支援群組提醒並 tag 主管';

    private $rateService;

    public function __construct(DailyRateService $rateService)
    {
        parent::__construct();

        $this->rateService = $rateService;
    }

    /**
     * @return int
     */
    public function handle()
    {
        $result = $this->rateService->remind();

        if (Arr::get($result, 'sent') !== true) {
            $reason = Arr::get($result, 'reason');

            // nothing_to_remind 是常態（匯率定了、或還沒到報價時間），不用大驚小怪
            $this->line($reason === 'nothing_to_remind'
                ? '沒有待決定的匯率，不用提醒'
                : "沒有送出：{$reason}");

            return 0;
        }

        $this->info('已送出提醒（第 ' . Arr::get($result, 'count') . ' 次）');

        return 0;
    }
}
