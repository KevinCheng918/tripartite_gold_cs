<?php

namespace App\Console\Commands;

use App\Services\VmService;
use Illuminate\Console\Command;

/**
 * 每月自動產生 VM 帳單
 *
 * 排程跑兩次（見 Console\Kernel）：
 *
 * | 時機 | 產生哪個月 | 為什麼 |
 * |---|---|---|
 * | 月底最後兩天 | **下個月** | 繳費日在 1、2 號的要提前通知得到 |
 * | 每月 1 號 | 當月 | 保險：月底那次失敗、或機器是月中才開機的 |
 */
class GenerateVmBilling extends Command
{
    protected $signature = 'vm:generate-billing
        {--month= : 指定月份 YYYY-MM，預設當月}
        {--next : 產生下個月的帳單（月底提前產生用）}';

    protected $description = '產生所有啟用中虛擬機的月度帳單';

    /**
     * @param VmService $vmService
     * @return int
     */
    public function handle(VmService $vmService)
    {
        $month = $this->option('month') ?: null;

        if (blank($month) && $this->option('next')) {
            $month = $this->nextMonth();
        }

        $result = $vmService->generateMonthlyBillings($month);

        $this->info("帳單月份：{$result['month']}");
        $this->info("帳單產生完成：新增 {$result['generated']} 筆，跳過 {$result['skipped']} 筆");

        return 0;
    }

    /**
     * 下個月是哪一個月
     *
     * ⚠ **一定要先 `startOfMonth()`**。直接 `now()->addMonth()` 在月底會跳過
     * 一整個月 —— 1 月 30 日加一個月，因為「2 月 30 日」不存在，Carbon 會溢位
     * 成 **3 月 2 日**，`format('Y-m')` 就變成 `2026-03`。
     *
     * 而這支正好**只在月底最後兩天跑** —— 踩中那個溢位的機率是 100%，
     * 二月更是年年都中。
     *
     * @return string YYYY-MM
     */
    private function nextMonth()
    {
        return now()->startOfMonth()->addMonth()->format('Y-m');
    }
}
