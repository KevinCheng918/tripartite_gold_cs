<?php

namespace App\Console\Commands;

use App\Services\WalletNoticeService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * 查監聽中的錢包有沒有新的轉帳，有就私訊通知
 *
 * 排程每分鐘跑一次（見 Console\Kernel）。
 *
 * 手動驗證用 --dry-run：印出「會發哪幾則、長什麼樣」但不送出，
 * 否則要驗這支只能等真的有人轉帳。--address 可以只跑一個錢包。
 *
 * ⚠ --dry-run **仍然會寫資料庫**（鏈上資料照樣記一筆），只是不發通知。
 * 跟 `station:sync-credit --dry-run` 的「完全唯讀」不一樣 ——
 * 不寫的話游標不會前進，下一輪又會把同一批重撈一次。
 */
class WatchWalletTransferCommand extends Command
{
    protected $signature = 'wallet:watch-transfers
        {--dry-run : 只印出會發什麼，不送出（鏈上資料照樣記）}
        {--address= : 只處理這一個錢包地址}';

    protected $description = '查錢包有沒有新的 TRC20 轉帳，有就私訊通知';

    /** @var array 跳過原因對應的說明文字 */
    private const REASON_LABELS = [
        WalletNoticeService::SKIP_DISABLED     => '沒有設定收件人，不發送',
        WalletNoticeService::SKIP_BAD_ADDRESS  => '地址格式不對（TRON 是 T 開頭、34 碼）',
        WalletNoticeService::SKIP_QUERY_FAILED => '問不到鏈上資料，下一輪會重試（詳見 log）',
        WalletNoticeService::SKIP_BASELINE     => '第一次監聽，這一輪只建立基準、不發通知',
        WalletNoticeService::SKIP_NO_NEW       => '沒有新的轉帳',
    ];

    private $noticeService;

    public function __construct(WalletNoticeService $noticeService)
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

        if ($dryRun) {
            $this->warn('── 空跑模式：不會送出任何訊息（鏈上資料照樣記一筆）──');
        }

        $results = $this->noticeService->run([
            'dry_run' => $dryRun,
            'address' => $this->option('address'),
        ]);

        /*
         * 回空陣列有三種可能：功能沒開、沒設錢包、--address 指到不存在的。
         * 三種都不是錯誤，但要講出來 —— 不然「跑了沒反應」查不出原因。
         */
        if (blank($results)) {
            $this->line('沒有要處理的錢包（功能沒開、沒設定錢包，或 --address 指到的不在清單裡）');

            return 0;
        }

        $sent = 0;

        foreach ($results as $result) {
            $sent += (int) Arr::get($result, 'sent');
            $this->report($result, $dryRun);
        }

        $this->info($dryRun ? '空跑結束，沒有送出任何訊息' : "完成，共送出 {$sent} 則");

        return 0;
    }

    /**
     * 印一個錢包的結果
     *
     * @param array $result
     * @param bool  $dryRun
     * @return void
     */
    private function report(array $result, $dryRun)
    {
        $label = (string) Arr::get($result, 'label');
        $address = (string) Arr::get($result, 'address');
        $title = filled($label) ? "{$label}（{$address}）" : $address;

        $this->line('');
        $this->line("── {$title} ──");

        $reason = Arr::get($result, 'reason');

        if (filled($reason)) {
            $this->line('　' . Arr::get(self::REASON_LABELS, $reason, $reason));
        }

        $previews = (array) Arr::get($result, 'previews');

        // 空跑時把內容整則印出來，不然看不出排版與數字對不對
        if ($dryRun && filled($previews)) {
            foreach ($previews as $preview) {
                $this->line('');
                $this->line(strip_tags($preview));
            }

            return;
        }

        if (!$dryRun && (int) Arr::get($result, 'sent') > 0) {
            $this->line('　送出 ' . Arr::get($result, 'sent') . ' 則');
        }
    }
}
