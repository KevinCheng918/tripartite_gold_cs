<?php

namespace App\Console\Commands;

use App\Services\VmService;
use Illuminate\Console\Command;

/**
 * 每月自動產生 VM 帳單
 */
class GenerateVmBilling extends Command
{
    protected $signature = 'vm:generate-billing {--month= : 指定月份 YYYY-MM，預設當月}';

    protected $description = '產生所有啟用中虛擬機的月度帳單';

    public function handle(VmService $vmService)
    {
        $month = $this->option('month') ?: null;
        $result = $vmService->generateMonthlyBillings($month);

        $this->info("帳單產生完成：新增 {$result['generated']} 筆，跳過 {$result['skipped']} 筆");

        return 0;
    }
}
