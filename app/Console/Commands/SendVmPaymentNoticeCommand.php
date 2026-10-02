<?php

namespace App\Console\Commands;

use App\Services\VmPaymentNoticeService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 每天自動發虛擬機繳款通知
 *
 * 排程每天 09:30 跑（見 Console\Kernel）—— 排在匯率報價 09:00 之後、
 * 餘點告警 10:00 之前。
 *
 * 兩批各自處理：
 *   - **未收的** → 發給客戶，應收日前 N 天開始，逾期繼續發到繳費為止
 *   - **待審核的** → 發內部群組催我方審核（不受應收日與主機狀態限制）
 *
 * 手動驗證用 --dry-run：印出「會發給誰、內容長什麼樣」但不真的送出去。
 * --billing= 可以只跑一筆。
 *
 * ⚠ 刻意**沒有防重複**：一天跑一次（排程 + withoutOverlapping），
 * 手動再跑一次就是再發一次 —— 這是需求方確認過的行為。
 */
class SendVmPaymentNoticeCommand extends Command
{
    protected $signature = 'vm:send-payment-notice
        {--dry-run : 只判斷並印出結果，不送出任何訊息}
        {--billing= : 只處理指定的帳單 ID}';

    protected $description = '發送虛擬機繳款通知（未收的給客戶、待審核的催內部審核）';

    /** @var array 跳過原因對應的說明文字 */
    private const REASON_LABELS = [
        VmPaymentNoticeService::SKIP_NO_STATION  => '這台主機沒綁站台，查不到繳款設定（已轉報內部群組）',
        VmPaymentNoticeService::SKIP_NO_CONFIG   => '這個系統沒有啟用中的繳款設定（已轉報內部群組）',
        VmPaymentNoticeService::SKIP_NO_TARGET   => '沒有可發送的群組（站台與內部支援群組都沒設）',
        VmPaymentNoticeService::SKIP_SEND_FAILED => '發送失敗，明天會再發一次',
        // 走到這個原因表示有沒被接住的例外，是要修的 bug —— 詳情在 log
        VmPaymentNoticeService::SKIP_ERROR       => '處理時發生錯誤，已跳過這一筆（詳見 log）',
    ];

    private $noticeService;

    public function __construct(VmPaymentNoticeService $noticeService)
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
            $this->warn('── 空跑模式：不會送出任何訊息 ──');
        }

        $results = $this->noticeService->sendPaymentNotices([
            'dry_run'    => $dryRun,
            'billing_id' => $this->option('billing'),
        ]);

        if (blank($results)) {
            $this->info('沒有需要發送的帳單（未收且應收日在 '
                . config('constants.VM.NOTICE.DAYS_AHEAD') . ' 天內的，或待審核的）');

            return 0;
        }

        $this->renderTable($results);
        $this->renderPreviews($results, $dryRun);

        $sent = count(array_filter($results, function ($row) {
            return filled(Arr::get($row, 'target')) && blank(Arr::get($row, 'reason'));
        }));

        $total = count($results);

        if (!$dryRun) {
            Log::info('虛擬機繳款通知發送完成', ['checked' => $total, 'sent' => $sent]);
        }

        $this->info($dryRun
            ? "共 {$total} 筆，其中 {$sent} 筆會送出（空跑未發送）"
            : "共 {$total} 筆，送出 {$sent} 筆");

        return 0;
    }

    /**
     * 印出每筆的處理結果
     *
     * @param array $results
     * @return void
     */
    private function renderTable($results)
    {
        $rows = [];

        foreach ($results as $row) {
            $rows[] = [
                Arr::get($row, 'billing_id'),
                Arr::get($row, 'station'),
                Arr::get($row, 'hostname'),
                Arr::get($row, 'month'),
                Arr::get($row, 'amount'),
                $this->kindLabel($row),
                $this->statusLabel($row),
            ];
        }

        $this->table(['ID', '站台', '主機', '月份', '金額', '類型', '結果'], $rows);
    }

    /**
     * 空跑時把訊息內容印出來
     *
     * @param array $results
     * @param bool  $dryRun
     * @return void
     */
    private function renderPreviews($results, $dryRun)
    {
        if (!$dryRun) {
            return;
        }

        foreach ($results as $row) {
            $text = Arr::get($row, 'text');

            if (blank($text)) {
                continue;
            }

            $this->line('');
            $this->warn('── ' . Arr::get($row, 'station') . ' / ' . Arr::get($row, 'month')
                . ' → ' . $this->targetLabel($row) . ' ──');

            $image = Arr::get($row, 'image_url');

            if (filled($image)) {
                $this->line("［附圖］{$image}");
            }

            $this->line($text);
        }
    }

    /**
     * 這筆是哪一類
     *
     * @param array $row
     * @return string
     */
    private function kindLabel($row)
    {
        return Arr::get($row, 'kind') === 'pending' ? '待審核（催內部）' : '未收（通知客戶）';
    }

    /**
     * 發送目標的說明文字
     *
     * @param array $row
     * @return string
     */
    private function targetLabel($row)
    {
        return Arr::get($row, 'target') === VmPaymentNoticeService::TARGET_STATION
            ? '站台群組'
            : '內部支援群組';
    }

    /**
     * 單筆的結果說明
     *
     * @param array $row
     * @return string
     */
    private function statusLabel($row)
    {
        $reason = Arr::get($row, 'reason');

        if (blank($reason)) {
            return '已送出 → ' . $this->targetLabel($row);
        }

        return Arr::get(self::REASON_LABELS, $reason, $reason);
    }
}
