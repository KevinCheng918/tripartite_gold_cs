<?php

namespace App\Console\Commands;

use App\Services\TicketEscalationService;
use Illuminate\Console\Command;

/**
 * 求助單逾時升級
 *
 * 催到第 N 次（預設 10，約逾時 100 分鐘）還沒人處理時，打電話把當班人
 * 叫起來並私訊他；撥滿三通仍沒處理就私訊勾選清單。
 *
 * ⚠ **刻意不掛在求助單提醒那支裡面**：撥號重試是 5 分鐘一次、而提醒是
 * 10 分鐘一次，兩者對不上。獨立的每分鐘排程自己查，既有的提醒流程
 * 一行都不用動。
 *
 * ⚠ **「沒接」是看那張單還沒被處理**，不是看電話有沒有被接起 ——
 * 那通電話接起來一定會斷（沒有音訊層），通話狀態不可靠。
 *
 * 總開關預設關（通知設定頁可開啟），指令自己會短路。
 */
class TicketEscalateCommand extends Command
{
    protected $signature = 'ticket:escalate';

    protected $description = '求助單催到升級門檻還沒人處理時，打電話把當班人叫起來';

    /**
     * @param TicketEscalationService $escalationService
     * @return int
     */
    public function handle(TicketEscalationService $escalationService)
    {
        if (!$escalationService->isEnabled()) {
            $this->warn('逾時升級未啟用（通知設定頁可開啟）');

            return 0;
        }

        $count = $escalationService->run();

        $this->info("已處理 {$count} 張單的升級動作");

        return 0;
    }
}
