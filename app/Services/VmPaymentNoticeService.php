<?php

namespace App\Services;

use App\Models\VmBilling;
use App\Presenters\NumberPresenter;
use App\Presenters\TelegramUsernamePresenter;
use App\Repositories\UserRepository;
use App\Repositories\VmRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 虛擬機繳款通知
 *
 * 每天 09:30 把該收的錢通知出去（見 SendVmPaymentNoticeCommand），
 * 以及手動發送時共用的文案組裝。
 *
 * 從 VmService 拆出來的 —— 那邊管的是主機與帳單的 CRUD，這邊管的是
 * 「什麼時候、要跟誰、用什麼內容收錢」。兩件事會各自長大，混在一起之後
 * VmService 有一半的篇幅在講通知。形狀比照 StationCreditAlertService
 * 獨立於 StationService。
 *
 * 兩批各自處理：
 *   - **未收的** → 發給客戶，應收日前 N 天開始，逾期繼續發到繳費為止
 *   - **待審核的** → 發內部群組催我方審核（不受應收日與主機狀態限制）
 *
 * ⚠ **完全不碰匯率。** 虛擬機收的是 USDT，`vm_billing.amount` 就是客戶要付的
 * 數字。`exchange_rate` 那一欄是客戶上傳繳款證明當下記的 4H 均價
 * （`VmService::uploadProof()`），用途是事後對帳，跟「要通知他付多少」無關。
 *
 * ⚠ 每一筆各自 try/catch —— 一筆噴錯不能讓整輪中斷，否則一個不相關的小問題
 * 就讓所有客戶都收不到通知（這條教訓見
 * [[2026-10-02-rate-failure-blocks-credit-alert]]）。
 */
class VmPaymentNoticeService
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

    /** @var int Telegram 圖說上限。超過整則會失敗，不是截斷 */
    private const CAPTION_MAX = 1024;

    private $vmRepository;
    private $paymentConfigService;
    private $chatService;
    private $supportGroup;
    private $userRepository;

    public function __construct(
        VmRepository $vmRepository,
        PaymentConfigService $paymentConfigService,
        TelegramChatService $chatService,
        SupportGroupService $supportGroup,
        UserRepository $userRepository
    ) {
        $this->vmRepository = $vmRepository;
        // 繳款通知的文案與圖都在繳款設定裡
        $this->paymentConfigService = $paymentConfigService;
        $this->chatService = $chatService;
        $this->supportGroup = $supportGroup;
        // 催審核那則要 tag 主管以上
        $this->userRepository = $userRepository;
    }

    /**
     * 要 tag 的主管（含換行）
     *
     * 重用 `getManagersForMention()`（level <= 主管、狀態正常、有填 Telegram
     * 帳號）—— 跟匯率提醒、求助單升級、補點催審核 tag 的是同一批人。
     *
     * ⚠ 沒人可 tag 時回**空字串而不是空白行** —— 文案裡的 `{mentions}` 緊貼
     * 著下一行，換行跟著 mention 一起出現，不然會多一行空的。
     *
     * @return string
     */
    private function managerMentions()
    {
        $line = TelegramUsernamePresenter::mentionLine(
            $this->userRepository->getManagersForMention()->pluck('telegram_username')
        );

        return filled($line) ? "{$line}\n" : '';
    }

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
     * @param VmBilling $billing
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
     * @param VmBilling $billing
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
            $prefix = strtr((string) config('constants.VM.NOTICE.INTERNAL_PREFIX'), [
                '{station}' => $station->name,
                '{month}'   => $month,
            ]);

            /*
             * 拆兩則：說明一則、繳款文案＋該系統的繳款圖一則（需求方
             * 2026-10-03 指定）。客服要把後面那則**原封不動轉傳**給客戶 ——
             * 併成一則的話他得先手動編輯掉「這則沒有發給客戶」那段說明。
             */
            return $this->sendInternalOnly(
                $billing, 'unpaid', null, Arr::get($notice, 'text'), $dryRun,
                Arr::get($notice, 'image_url'), $prefix
            );
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
     * @param VmBilling $billing
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
            // 這則是要人去後台審核的，不點名容易變成「大家都看到、沒人動手」
            '{mentions}'    => $this->managerMentions(),
        ]);

        return $this->sendInternalOnly($billing, 'pending', null, $text, $dryRun);
    }

    /**
     * 只發內部支援群組
     *
     * 站台沒設群組時客服要拿著這則去手動通知客戶，所以**該系統的繳款圖片
     * 要一起附上** —— 不然客服還得自己去繳款設定翻一次圖，而且可能翻錯系統的。
     * 做法與補點訊息的 `StationCreditAlertService::sendTopupToSupportGroup()` 一致。
     *
     * 沒有圖可附的情況（主機沒綁站台、系統沒有啟用中的繳款設定）本來就查不到
     * 設定，`$imageUrl` 會是 null，退回純文字。
     *
     * ⚠ 催審核那條（`kind = pending`）刻意不傳圖：客戶已經付款上傳證明了，
     * 要催的是我方去審核，附付款地址圖沒有幫助。
     *
     * ⚠ Telegram 的圖說上限 1024 字，**超過會整則失敗**。文案是客服自己在
     * 繳款設定裡寫的，加上開頭那段說明後若超過上限，這則就會發不出去 ——
     * 補點訊息那邊是同樣的條件，兩邊要一起處理才有意義（見
     * [[2026-10-03-internal-notice-payment-image]]）。
     *
     * @param VmBilling   $billing
     * @param string      $kind
     * @param string|null $reason 為什麼沒發給客戶（純催審核時是 null）
     * @param string      $text
     * @param bool        $dryRun
     * @param string|null $imageUrl 該系統的繳款圖片，null 就只發文字
     * @param string|null $prefix   開頭說明，獨立發一則（見下方）
     * @return array
     */
    private function sendInternalOnly($billing, $kind, $reason, $text, $dryRun, $imageUrl = null, $prefix = null)
    {
        if (!$this->supportGroup->isConfigured()) {
            Log::warning('虛擬機繳款通知沒有可發送的群組', [
                'billing_id' => $billing->id,
                'kind'       => $kind,
                'reason'     => $reason,
            ]);

            return $this->noticeResult($billing, $kind, null, self::SKIP_NO_TARGET, $text, $imageUrl);
        }

        if ($dryRun) {
            // 預覽要看得到完整內容，所以把兩則接起來顯示
            return $this->noticeResult($billing, $kind, self::TARGET_INTERNAL, $reason,
                filled($prefix) ? "{$prefix}{$text}" : $text, $imageUrl);
        }

        try {
            /*
             * 開頭說明**獨立一則** —— 客服要把後面那則原封不動轉傳給客戶，
             * 併在一起的話他得先編輯掉說明（需求方 2026-10-03 指定）。
             *
             * 催審核那條不傳 $prefix：它不轉傳，整則就是要給客服看的。
             */
            if (filled($prefix)) {
                $this->supportGroup->send($prefix);
            }

            $this->sendInternalBody($text, $imageUrl);
        } catch (\Exception $e) {
            Log::error('虛擬機繳款通知發送失敗（內部群組）', [
                'billing_id' => $billing->id,
                'has_image'  => filled($imageUrl),
                'error'      => $e->getMessage(),
            ]);

            return $this->noticeResult($billing, $kind, self::TARGET_INTERNAL, self::SKIP_SEND_FAILED, $text, $imageUrl);
        }

        return $this->noticeResult($billing, $kind, self::TARGET_INTERNAL, $reason, $text, $imageUrl);
    }

    /**
     * 內部群組那則本體（繳款文案＋圖）
     *
     * ⚠ Telegram 的圖說上限是 **1024 字，超過整則會失敗**（不是截斷）。
     * 文案是客服自己在繳款設定裡維護的，寫長一點很正常 —— 超過就退回
     * 「先發圖、再發文字」，寧可多一則也不要整則發不出去。
     * 與補點訊息的 `StationCreditAlertService::sendTopupToInternal()`
     * 及匯率報價的 `DailyRateService::sendAsk()` 同一個做法。
     *
     * @param string      $text
     * @param string|null $imageUrl
     * @return void
     */
    private function sendInternalBody($text, $imageUrl)
    {
        if (blank($imageUrl)) {
            $this->supportGroup->send($text);

            return;
        }

        if (mb_strlen($text) <= self::CAPTION_MAX) {
            $this->supportGroup->sendPhoto($imageUrl, $text);

            return;
        }

        Log::info('虛擬機繳款文案超過圖說上限，改成先發圖再發文字', [
            'length' => mb_strlen($text),
            'limit'  => self::CAPTION_MAX,
        ]);

        $this->supportGroup->sendPhoto($imageUrl);
        $this->supportGroup->send($text);
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
     * @param VmBilling $billing
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
     * @param VmBilling $billing
     * @return string
     */
    private function noticeDueDate($billing)
    {
        return filled($billing->due_date) ? $billing->due_date->format('n/j') : '';
    }

    /**
     * 組單筆結果
     *
     * @param VmBilling $billing
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
