<?php

namespace App\Console\Commands;

use App\Services\DailyRateService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * 在內部支援群組報今日匯率
 *
 * 排程每天早上跑一次（見 Console\Kernel）。週末假日照報 —— 每天都要有匯率。
 *
 * 補報用 --force：預設同一天只會問一次，重複跑不會洗版。
 */
class AskDailyRateCommand extends Command
{
    protected $signature = 'rate:ask {--force : 今天已經問過也重送一次}';

    protected $description = '在內部支援群組報今日匯率，等自己人回覆決定對客報價';

    /** @var array 沒送出時的原因說明 */
    private const REASONS = [
        // 問過但還沒人決定時仍然會報 —— 只有「已經決定」才跳過
        'already_decided'  => '今天的匯率已經決定了（要重送請加 --force）',
        'no_support_group' => '未設定內部支援群組，沒有地方可以報',
        'send_failed'      => 'Telegram 送出失敗，詳見 log',
    ];

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
        $result = $this->rateService->ask((bool) $this->option('force'));

        if (Arr::get($result, 'sent') !== true) {
            $reason = Arr::get($result, 'reason');
            $this->warn('沒有送出：' . Arr::get(self::REASONS, $reason, $reason));

            $rate = Arr::get($result, 'rate');

            if (filled($rate)) {
                $this->line("  今日匯率已經是 {$rate}");
            }

            return 0;
        }

        $this->info('已送出今日匯率報價');
        $this->table(['4H 均價', '建議報價', '上次報價'], [[
            Arr::get($result, 'reference'),
            Arr::get($result, 'suggested'),
            Arr::get($result, 'previous', '—'),
        ]]);

        $this->line('');
        $this->line(Arr::get($result, 'text'));

        return 0;
    }
}
