<?php

namespace App\Services;

use App\Presenters\NumberPresenter;
use App\Repositories\VmRepository;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 虛擬機管理 Service
 */
class VmService
{
    /** @var string 跳過原因：這台主機沒有綁站台，查不到繳款設定 */
    const SKIP_NO_STATION = 'no_station';

    /** @var string 跳過原因：這個系統沒有啟用中的繳款設定 */
    const SKIP_NO_CONFIG = 'no_config';

    /** @var string 跳過原因：站台沒設群組，內部支援群組也沒設 */
    const SKIP_NO_TARGET = 'no_target';

    /** @var string 跳過原因：發送時噴錯 */
    const SKIP_SEND_FAILED = 'send_failed';

    /**
     * @var string 跳過原因：處理到一半噴了預期外的錯
     *
     * 最後防線。走到這裡表示有沒被接住的例外，**那是要修的 bug**，
     * 但它只能毀掉這一筆帳單，不能讓整輪通知中斷。
     */
    const SKIP_ERROR = 'error';

    /** @var string 發送目標：站台自己的群組（客戶） */
    const TARGET_STATION = 'station';

    /** @var string 發送目標：內部支援群組 */
    const TARGET_INTERNAL = 'internal';

    private $vmRepository;
    private $usdtRateService;
    private $paymentConfigService;
    private $chatService;
    private $supportGroup;

    public function __construct(
        VmRepository $vmRepository,
        UsdtRateService $usdtRateService,
        PaymentConfigService $paymentConfigService,
        TelegramChatService $chatService,
        SupportGroupService $supportGroup
    ) {
        $this->vmRepository = $vmRepository;
        $this->usdtRateService = $usdtRateService;
        // 繳款通知的文案與圖都在繳款設定裡
        $this->paymentConfigService = $paymentConfigService;
        $this->chatService = $chatService;
        $this->supportGroup = $supportGroup;
    }

    // ---------------------------------------------------------------
    //  VM Server
    // ---------------------------------------------------------------

    /**
     * 查詢 VM 列表（分頁）
     *
     * @param array $params
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function listServers($params)
    {
        $criteria = [
            'keyword'      => $params['keyword'] ?? null,
            'station_id'   => $params['station_id'] ?? null,
            'system_id'    => $params['system_id'] ?? null,
            'hostname'     => $params['hostname'] ?? null,
            'internal_ip'  => $params['internal_ip'] ?? null,
            'external_ip'  => $params['external_ip'] ?? null,
            'status'       => $params['status'] ?? null,
            'power_status' => $params['power_status'] ?? null,
        ];

        return $this->vmRepository->paginateServers($criteria, (int) ($params['per_page'] ?? config('constants.PAGINATION.DEFAULT', 10)));
    }

    /**
     * 新增 VM
     *
     * @param array $params
     * @return \App\Models\VmServer
     */
    public function createServer($params)
    {
        return $this->vmRepository->createServer($params);
    }

    /**
     * 更新 VM
     *
     * @param \App\Models\VmServer $server
     * @param array $params
     * @return \App\Models\VmServer
     */
    public function updateServer($server, $params)
    {
        return $this->vmRepository->updateServer($server, $params);
    }

    /**
     * 切換開關機狀態
     *
     * @param \App\Models\VmServer $server
     * @return \App\Models\VmServer
     */
    public function togglePower($server)
    {
        $newStatus = $server->power_status === 1 ? 0 : 1;
        $attrs = ['power_status' => $newStatus];

        if ($newStatus === 0) {
            $attrs['powered_off_at'] = now()->toDateString();
        } else {
            $attrs['powered_off_at'] = null;
        }

        return $this->vmRepository->updateServer($server, $attrs);
    }

    // ---------------------------------------------------------------
    //  VM Billing
    // ---------------------------------------------------------------

    /**
     * 查詢帳單列表（分頁）
     *
     * @param array $params
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function listBillings($params)
    {
        $criteria = [
            'billing_month' => $params['billing_month'] ?? null,
            'paid'          => $params['paid'] ?? null,
            'overdue'       => $params['overdue'] ?? null,
            'shutdown'      => $params['shutdown'] ?? null,
            'vm_server_id'  => $params['vm_server_id'] ?? null,
            'system_id'     => $params['system_id'] ?? null,
            'station_id'    => $params['station_id'] ?? null,
        ];

        return $this->vmRepository->paginateBillings($criteria, (int) ($params['per_page'] ?? config('constants.PAGINATION.DEFAULT', 10)));
    }

    /**
     * 上傳繳款證明（客服用）
     *
     * @param \App\Models\VmBilling $billing
     * @param \Illuminate\Http\UploadedFile $file
     * @return \App\Models\VmBilling
     */
    public function uploadProof($billing, $file)
    {
        $path = $file->store('vm-proof', 'public');

        // 取得 4H 均價匯率
        $rateData = $this->usdtRateService->getRateWithHistory();
        $avgRate = $rateData['avg_rate'] ?? 0;

        return $this->vmRepository->updateBilling($billing, [
            'proof_image'   => $path,
            'paid'          => 2, // 待審核
            'exchange_rate' => $avgRate > 0 ? $avgRate : null,
        ]);
    }

    /**
     * 審核繳款證明 — 標記已收款（管理者用）
     *
     * @param \App\Models\VmBilling $billing
     * @return \App\Models\VmBilling
     */
    public function approvePaid($billing)
    {
        return $this->vmRepository->updateBilling($billing, [
            'paid'    => 1,
            'paid_at' => now(),
        ]);
    }

    /**
     * 直接標記帳單已收款（管理者用，無需證明）
     *
     * @param \App\Models\VmBilling $billing
     * @return \App\Models\VmBilling
     */
    public function markPaid($billing)
    {
        return $this->vmRepository->updateBilling($billing, [
            'paid'    => 1,
            'paid_at' => now(),
        ]);
    }

    /**
     * 產生指定月份的帳單（所有啟用中的 VM）
     * 如果帳單已存在且金額不同，會列入 mismatches 供前端確認
     *
     * @param string|null $month        YYYY-MM，預設當月
     * @param bool        $forceUpdate  是否強制更新金額不同的帳單
     * @return array { generated: int, skipped: int, updated: int, mismatches: array }
     */
    public function generateMonthlyBillings($month = null, $forceUpdate = false)
    {
        $billingMonth = $month ?: now()->format('Y-m');
        $servers = $this->vmRepository->getActiveServers();

        $generated = 0;
        $skipped = 0;
        $updated = 0;
        $mismatches = [];

        foreach ($servers as $server) {
            // 關機的 VM 不產生帳單
            if ($server->power_status === 0) {
                continue;
            }
            $totalFee = (float) $server->monthly_fee + (float) $server->vpn_fee + (float) $server->google_fee;

            $existing = $this->vmRepository->findBillingByMonth($server->id, $billingMonth);

            if ($existing) {
                $oldAmount = (float) $existing->amount;
                // 金額不同且未收款
                if (abs($oldAmount - $totalFee) > 0.001 && (int) $existing->paid === 0) {
                    if ($forceUpdate) {
                        $this->vmRepository->updateBilling($existing, ['amount' => $totalFee]);
                        $updated++;
                    } else {
                        $stationName = $server->station ? $server->station->name : '-';
                        $mismatches[] = [
                            'billing_id'  => $existing->id,
                            'station'     => $stationName,
                            'hostname'    => $server->hostname,
                            'old_amount'  => number_format($oldAmount, 2),
                            'new_amount'  => number_format($totalFee, 2),
                        ];
                    }
                }
                $skipped++;
                continue;
            }

            // 新增帳單
            $year = (int) substr($billingMonth, 0, 4);
            $monthNum = (int) substr($billingMonth, 5, 2);
            $day = min($server->billing_day, Carbon::createFromDate($year, $monthNum, 1)->daysInMonth);
            $dueDate = Carbon::createFromDate($year, $monthNum, $day)->toDateString();

            $this->vmRepository->createBilling([
                'vm_server_id'  => $server->id,
                'billing_month' => $billingMonth,
                'amount'        => $totalFee,
                'due_date'      => $dueDate,
            ]);

            $generated++;
        }

        Log::info("VM 帳單產生完成", [
            'month'     => $billingMonth,
            'generated' => $generated,
            'skipped'   => $skipped,
            'updated'   => $updated,
        ]);

        return [
            'generated'  => $generated,
            'skipped'    => $skipped,
            'updated'    => $updated,
            'mismatches' => $mismatches,
        ];
    }

    // ---------------------------------------------------------------
    //  繳款通知
    // ---------------------------------------------------------------

    /**
     * 組繳款通知的文案與圖
     *
     * 排程與手動發送**共用這一支** —— 兩邊各寫一份的話，改了文案規則
     * 只會改到一邊。原本這段邏輯寫在 `VmController::ajaxSendPaymentNotice()` 裡。
     *
     * ⚠ **完全不碰匯率。** 虛擬機收的是 USDT，`vm_billing.amount` 就是客戶要付的
     * 數字，直接用。`exchange_rate` 那一欄是客戶上傳繳款證明當下記的 4H 均價
     * （見 uploadProof()），用途是事後對帳，跟「要通知他付多少」無關。
     *
     * 公版留空就退回「繳款資訊」本身 —— 沿用手動發送原本的行為。
     *
     * @param int   $systemId
     * @param array $vars ['station' => ..., 'amount' => ..., 'month' => ..., 'due_date' => ...]
     * @return array{text: string, image_url: string|null}
     *         這個系統沒有啟用中的繳款設定時 text 是空字串
     */
    public function renderPaymentNotice($systemId, $vars)
    {
        $config = $this->paymentConfigService->getActiveBySystem((int) $systemId)->first();

        if (blank($config)) {
            return ['text' => '', 'image_url' => null];
        }

        $template = filled($config->template) ? $config->template : $config->content;

        return [
            'text' => $this->paymentConfigService->renderTemplate($template, [
                'station'  => Arr::get($vars, 'station', ''),
                'amount'   => Arr::get($vars, 'amount', ''),
                'month'    => Arr::get($vars, 'month', ''),
                'due_date' => Arr::get($vars, 'due_date', ''),
                'content'  => $config->content,
            ]),
            'image_url' => filled($config->image) ? asset("storage/{$config->image}") : null,
        ];
    }

    /**
     * 每天自動發繳款通知
     *
     * 兩批各自處理：
     *   1. **未收的** → 發給客戶（應收日前 N 天開始，逾期繼續發到繳費為止）
     *   2. **待審核的** → 發內部群組催我方審核（不受應收日與主機狀態限制）
     *
     * 每一筆各自 try/catch —— 一筆噴錯不能讓整輪中斷，否則一個不相關的小問題
     * 就讓所有客戶都收不到通知（這條教訓見
     * [[2026-10-02-rate-failure-blocks-credit-alert]]）。
     *
     * @param array $options ['dry_run' => bool 只判斷不發送, 'billing_id' => int|null 只跑一筆]
     * @return array 每筆一個結果，給 Command 印出來看
     *
     * @phpstan-return array<int, array{billing_id:int, station:string, hostname:string,
     *     month:string, amount:string, kind:string, target:string|null,
     *     reason:string|null, text:string|null, image_url:string|null}>
     */
    public function sendPaymentNotices($options = [])
    {
        $dryRun = (bool) Arr::get($options, 'dry_run', false);
        $billingId = Arr::get($options, 'billing_id');
        $daysAhead = (int) config('constants.VM.NOTICE.DAYS_AHEAD');

        $unpaid = $this->vmRepository->getBillingsForNotice($daysAhead);
        $pending = $this->vmRepository->getPendingBillingsForReminder();

        // --billing= 只跑那一筆，但不另外寫一條查詢 —— 兩批撈完再篩，資料量很小
        if (filled($billingId)) {
            $match = function ($billing) use ($billingId) {
                return (int) $billing->id === (int) $billingId;
            };

            $unpaid = $unpaid->filter($match);
            $pending = $pending->filter($match);
        }

        $results = [];

        foreach ($unpaid as $billing) {
            $results[] = $this->guardBilling($billing, 'unpaid', $dryRun);
        }

        foreach ($pending as $billing) {
            $results[] = $this->guardBilling($billing, 'pending', $dryRun);
        }

        return $results;
    }

    /**
     * 包一層 try/catch 再處理單筆
     *
     * @param \App\Models\VmBilling $billing
     * @param string                 $kind unpaid|pending
     * @param bool                   $dryRun
     * @return array
     */
    private function guardBilling($billing, $kind, $dryRun)
    {
        try {
            return $kind === 'pending'
                ? $this->handlePendingBilling($billing, $dryRun)
                : $this->handleUnpaidBilling($billing, $dryRun);
        } catch (\Exception $e) {
            Log::error('虛擬機繳款通知處理失敗，跳過這一筆', [
                'billing_id' => $billing->id,
                'error'      => $e->getMessage(),
            ]);

            return $this->noticeResult($billing, $kind, null, self::SKIP_ERROR);
        }
    }

    /**
     * 未收的帳單 —— 發給客戶
     *
     * @param \App\Models\VmBilling $billing
     * @param bool                   $dryRun
     * @return array
     */
    private function handleUnpaidBilling($billing, $dryRun)
    {
        $station = filled($billing->vmServer) ? $billing->vmServer->station : null;
        $month = (string) $billing->billing_month;
        $amount = $this->noticeAmount($billing);
        $dueDate = $this->noticeDueDate($billing);

        /*
         * 沒綁站台就沒有 system_id，連該用哪一筆繳款設定都不知道 ——
         * 組不出文案，只能把情況報給客服。
         */
        if (blank($station) || blank($station->system_id)) {
            $text = strtr((string) config('constants.VM.NOTICE.NO_STATION_TEXT'), [
                '{hostname}' => filled($billing->vmServer) ? $billing->vmServer->hostname : '—',
                '{month}'    => $month,
                '{amount}'   => $amount,
                '{due_date}' => $dueDate,
            ]);

            return $this->sendInternalOnly($billing, 'unpaid', self::SKIP_NO_STATION, $text, $dryRun);
        }

        $notice = $this->renderPaymentNotice((int) $station->system_id, [
            'station'  => $station->name,
            'amount'   => $amount,
            'month'    => $month,
            'due_date' => $dueDate,
        ]);

        if (blank(Arr::get($notice, 'text'))) {
            $text = strtr((string) config('constants.VM.NOTICE.NO_CONFIG_TEXT'), [
                '{station}'  => $station->name,
                '{month}'    => $month,
                '{amount}'   => $amount,
                '{due_date}' => $dueDate,
            ]);

            return $this->sendInternalOnly($billing, 'unpaid', self::SKIP_NO_CONFIG, $text, $dryRun);
        }

        // 站台有群組就發客戶，沒有就退到內部群組並說明為什麼沒發給客戶
        if (blank($station->telegram_group_id)) {
            $text = strtr((string) config('constants.VM.NOTICE.INTERNAL_PREFIX'), [
                '{station}' => $station->name,
                '{month}'   => $month,
            ]) . Arr::get($notice, 'text');

            return $this->sendInternalOnly($billing, 'unpaid', null, $text, $dryRun);
        }

        if ($dryRun) {
            return $this->noticeResult($billing, 'unpaid', self::TARGET_STATION, null,
                Arr::get($notice, 'text'), Arr::get($notice, 'image_url'));
        }

        try {
            $this->sendToStation(
                (int) $station->telegram_group_id,
                Arr::get($notice, 'text'),
                Arr::get($notice, 'image_url')
            );
        } catch (\Exception $e) {
            Log::error('虛擬機繳款通知發送失敗', [
                'billing_id' => $billing->id,
                'group_id'   => $station->telegram_group_id,
                'error'      => $e->getMessage(),
            ]);

            return $this->noticeResult($billing, 'unpaid', self::TARGET_STATION, self::SKIP_SEND_FAILED);
        }

        Log::info('虛擬機繳款通知已送出', [
            'billing_id' => $billing->id,
            'station'    => $station->name,
            'month'      => $month,
            'amount'     => $amount,
        ]);

        return $this->noticeResult($billing, 'unpaid', self::TARGET_STATION, null,
            Arr::get($notice, 'text'), Arr::get($notice, 'image_url'));
    }

    /**
     * 待審核的帳單 —— 催我方審核
     *
     * **永遠只發內部群組**，而且不附圖：客戶已經付款上傳證明了，
     * 要催的是我們自己去審核，對客戶再發一次繳款通知只會造成困擾。
     *
     * @param \App\Models\VmBilling $billing
     * @param bool                   $dryRun
     * @return array
     */
    private function handlePendingBilling($billing, $dryRun)
    {
        $station = filled($billing->vmServer) ? $billing->vmServer->station : null;

        $text = strtr((string) config('constants.VM.NOTICE.PENDING_TEXT'), [
            // 沒綁站台時用主機名稱 —— 催審核不需要站台，有個識別就夠了
            '{station}'     => filled($station) ? $station->name : (filled($billing->vmServer) ? $billing->vmServer->hostname : '—'),
            '{month}'       => (string) $billing->billing_month,
            '{amount}'      => $this->noticeAmount($billing),
            /*
             * 用 updated_at 當「上傳時間」—— 沒有專門記上傳時刻的欄位，
             * 而 uploadProof() 寫入 proof_image 時這個欄位就會更新。
             * 審核前通常不會有別的更新，所以是夠準的近似值
             * （paid_at 不能用：那是審核通過才設的收款時間）。
             */
            '{uploaded_at}' => filled($billing->updated_at) ? $billing->updated_at->format('n/j H:i') : '—',
        ]);

        return $this->sendInternalOnly($billing, 'pending', null, $text, $dryRun);
    }

    /**
     * 只發內部支援群組（不附圖）
     *
     * 內部群組是要客服去處理事情，附一張付款地址圖沒有幫助。
     *
     * @param \App\Models\VmBilling $billing
     * @param string                 $kind
     * @param string|null            $reason 為什麼沒發給客戶（純催審核時是 null）
     * @param string                 $text
     * @param bool                   $dryRun
     * @return array
     */
    private function sendInternalOnly($billing, $kind, $reason, $text, $dryRun)
    {
        if (!$this->supportGroup->isConfigured()) {
            Log::warning('虛擬機繳款通知沒有可發送的群組', [
                'billing_id' => $billing->id,
                'kind'       => $kind,
                'reason'     => $reason,
            ]);

            return $this->noticeResult($billing, $kind, null, self::SKIP_NO_TARGET, $text);
        }

        if ($dryRun) {
            return $this->noticeResult($billing, $kind, self::TARGET_INTERNAL, $reason, $text);
        }

        try {
            $this->supportGroup->send($text);
        } catch (\Exception $e) {
            Log::error('虛擬機繳款通知發送失敗（內部群組）', [
                'billing_id' => $billing->id,
                'error'      => $e->getMessage(),
            ]);

            return $this->noticeResult($billing, $kind, self::TARGET_INTERNAL, self::SKIP_SEND_FAILED, $text);
        }

        return $this->noticeResult($billing, $kind, self::TARGET_INTERNAL, $reason, $text);
    }

    /**
     * 發到站台自己的 Telegram 群組
     *
     * mark_replied = false：這是系統主動發的通知，不該把客戶還在等回覆的
     * 提問標記成已回覆。is_auto = true 讓對話列表顯示機器人圖示。
     *
     * @param int         $groupId
     * @param string      $text
     * @param string|null $imageUrl
     * @return void
     */
    private function sendToStation($groupId, $text, $imageUrl)
    {
        $this->chatService->sendReply(
            $groupId,
            $text,
            null,
            (string) config('constants.VM.NOTICE.SENDER_NAME'),
            ['mark_replied' => false, 'is_auto' => true, 'image_url' => $imageUrl]
        );
    }

    /**
     * 通知文案裡的金額
     *
     * 去掉無意義的尾零（1200.00 → 1200），跟頁面上顯示的一致。
     *
     * @param \App\Models\VmBilling $billing
     * @return string
     */
    private function noticeAmount($billing)
    {
        return NumberPresenter::trimZeros($billing->amount, 2);
    }

    /**
     * 通知文案裡的應收日
     *
     * 用不補零的 `10/5` —— 對客訊息習慣這樣寫，與補點訊息的 `{date}` 一致。
     *
     * @param \App\Models\VmBilling $billing
     * @return string
     */
    private function noticeDueDate($billing)
    {
        return filled($billing->due_date) ? $billing->due_date->format('n/j') : '';
    }

    /**
     * 組單筆結果
     *
     * @param \App\Models\VmBilling $billing
     * @param string                 $kind     unpaid|pending
     * @param string|null            $target
     * @param string|null            $reason
     * @param string|null            $text
     * @param string|null            $imageUrl
     * @return array
     */
    private function noticeResult($billing, $kind, $target, $reason = null, $text = null, $imageUrl = null)
    {
        $server = $billing->vmServer;
        $station = filled($server) ? $server->station : null;

        return [
            'billing_id' => (int) $billing->id,
            'station'    => filled($station) ? $station->name : '—',
            'hostname'   => filled($server) ? $server->hostname : '—',
            'month'      => (string) $billing->billing_month,
            'amount'     => $this->noticeAmount($billing),
            'kind'       => $kind,
            'target'     => $target,
            'reason'     => $reason,
            'text'       => $text,
            'image_url'  => $imageUrl,
        ];
    }
}
