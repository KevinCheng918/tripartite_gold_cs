<?php

namespace App\Services;

use App\Repositories\StationRepository;
use App\Repositories\UserRepository;
use App\Repositories\VmRepository;
use App\Services\AutoReply\VmQueryCommandParser;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 用 Telegram 指令查虛擬機
 *
 * ```
 * 幫我查一下 LV_UAT 虛擬機        → 那個站台的費用／電源／當月帳單
 * 幫我確認 lv-uat 虛擬機規格      → 同上（講了站台就回完整狀況）
 * lv-uat 他是開機還是關機         → 同上
 * 幫我查一下誰還沒繳錢            → 跨站台的未收清單
 * 誰是開機還是關機                → 跨站台的電源清單
 * ```
 *
 * ⚠ **不做二次確認**（跟 [[station-credit-command]] 不一樣）。那一支要確認
 * 是因為它會打主系統 API 並寫回資料庫 —— 按鈕是在問「要不要執行這個動作」。
 * 這一支純讀本地資料、沒有副作用、不用等。
 *
 * ⚠ **不列主機名稱**（需求方 2026-10-13）。所以一個站台有多台機器時是
 * **加總**呈現，不是一台一行 —— 列了明細卻沒有名字，根本分不出哪行是哪台。
 *
 * ⚠ **要 `telegram_chat.vm_query` 權限**（獨立的一項，不是後台的 `vm.view`）。
 */
class VmQueryCommandService
{
    /** @var string 做這件事需要的權限 */
    const PERMISSION = 'telegram_chat.vm_query';

    private $parser;
    private $vmRepository;
    private $stationRepository;
    private $userRepository;
    private $nameMatcher;
    private $supportGroup;

    /**
     * 可以查的站台（一次指令內的暫存）
     *
     * @var \Illuminate\Database\Eloquent\Collection|null
     */
    private $stations;

    public function __construct(
        VmQueryCommandParser $parser,
        VmRepository $vmRepository,
        StationRepository $stationRepository,
        UserRepository $userRepository,
        StationNameMatcher $nameMatcher,
        SupportGroupService $supportGroup
    ) {
        $this->parser = $parser;
        $this->vmRepository = $vmRepository;
        $this->stationRepository = $stationRepository;
        // Telegram 使用者 → 系統帳號，才有權限可查
        $this->userRepository = $userRepository;
        // 「他說的名字是哪個站台」四支指令共用同一份規則
        $this->nameMatcher = $nameMatcher;
        $this->supportGroup = $supportGroup;
    }

    /**
     * 處理一句指令
     *
     * @param string   $text     已經去掉 @機器人
     * @param array    $from     Telegram 的 from（對到系統帳號驗權限用）
     * @param int|null $threadId
     * @return bool 有處理就回 true；false 代表「這不是在問虛擬機」，換下一個 handler
     */
    public function handle($text, array $from, $threadId)
    {
        $config = (array) config('constants.VM_QUERY_COMMAND');
        $parsed = $this->parser->parse($text);

        if (blank($parsed)) {
            $this->reply((string) Arr::get($config, 'UNAVAILABLE_TEXT'), $threadId);

            return true;
        }

        $intent = (string) Arr::get($parsed, 'intent');

        // 不是在問虛擬機 —— 讓分派器換下一個
        if ($intent === VmQueryCommandParser::INTENT_NOT_VM) {
            return false;
        }

        $denied = $this->permissionError($from, $config);

        if (filled($denied)) {
            $this->reply($denied, $threadId);

            return true;
        }

        if ($intent === VmQueryCommandParser::INTENT_UNPAID) {
            $this->replyUnpaid($threadId, $config);

            return true;
        }

        if ($intent === VmQueryCommandParser::INTENT_POWER) {
            $this->replyPower($threadId, $config);

            return true;
        }

        if ($intent !== VmQueryCommandParser::INTENT_STATION) {
            $this->reply((string) Arr::get($config, 'UNKNOWN_TEXT'), $threadId);

            return true;
        }

        $this->replyStation((string) Arr::get($parsed, 'station'), $threadId, $config);

        return true;
    }

    /**
     * 單一站台的虛擬機狀況
     *
     * @param string   $raw      使用者講的站台名稱
     * @param int|null $threadId
     * @param array    $config
     * @return void
     */
    private function replyStation($raw, $threadId, array $config)
    {
        $stations = $this->stations();
        $station = $this->nameMatcher->match($raw, $stations);

        if (blank($station)) {
            /*
             * ⚠ 模型偶爾會把兩個站台塞進同一個欄位（餘點那支實測過：
             * prompt 寫了「一次一個」照樣回 "LV_UAT、T01"）。完全相符
             * 找不到時，數這串字裡出現幾個已知站台名來分辨
             * 「講了兩個」與「名字不存在」。
             */
            $mentioned = $this->nameMatcher->mentioned($raw, $stations);

            if (count($mentioned) > 1) {
                $this->reply(strtr((string) Arr::get($config, 'MULTIPLE_TEXT'), [
                    '{names}' => implode('、', $mentioned),
                ]), $threadId);

                return;
            }

            $this->reply(strtr((string) Arr::get($config, 'NOT_FOUND_TEXT'), [
                '{station}' => (string) $raw,
                '{names}'   => $this->stationNames(),
            ]), $threadId);

            return;
        }

        $month = now()->format('Y-m');
        $servers = $this->vmRepository->getServersByStation($station->id, $month);

        if ($servers->isEmpty()) {
            $this->reply(strtr((string) Arr::get($config, 'STATION_TITLE'), [
                '{station}' => (string) $station->name,
                '{count}'   => 0,
            ]), $threadId);

            return;
        }

        $this->reply($this->renderStation($station, $servers, $month, $config), $threadId);
    }

    /**
     * 組單一站台的那張卡
     *
     * ⚠ **費用與帳單都是加總**，因為不列主機名稱 —— 一台一行卻沒有名字
     * 的話，看的人分不出哪行是哪台。
     *
     * @param \App\Models\Station                      $station
     * @param \Illuminate\Database\Eloquent\Collection $servers
     * @param string                                   $month
     * @param array                                    $config
     * @return string
     */
    private function renderStation($station, $servers, $month, array $config)
    {
        $text = strtr((string) Arr::get($config, 'STATION_TITLE'), [
            '{station}' => (string) $station->name,
            '{count}'   => $servers->count(),
        ]);

        $text .= strtr((string) Arr::get($config, 'STATION_SPEC'), [
            '{spec}' => $this->specText($servers),
        ]);

        $text .= $this->feeText($servers, $config);
        $text .= strtr((string) Arr::get($config, 'STATION_POWER'), [
            '{power}' => $this->powerText($servers, $config),
        ]);

        $text .= "\n" . strtr((string) Arr::get($config, 'BILLING_TITLE'), [
            '{month}' => $this->monthText($month),
        ]) . "\n";

        return $text . $this->billingText($servers, $config);
    }

    /**
     * 規格（多台就一台一個，用 ／ 隔開）
     *
     * @param \Illuminate\Database\Eloquent\Collection $servers
     * @return string
     */
    private function specText($servers)
    {
        $specs = [];

        foreach ($servers as $server) {
            if (filled($server->spec)) {
                $specs[] = (string) $server->spec;
            }
        }

        return filled($specs) ? implode(' ／ ', $specs) : '-';
    }

    /**
     * 費用（全部機器加總，並拆出三個項目）
     *
     * ⚠ 三項的加總方式跟產帳單那邊一樣
     * （`VmService::generateMonthlyBillings()`：主機 ＋ VPN ＋ Google）——
     * 這裡報的數字必須跟實際出帳的金額對得起來。
     *
     * @param \Illuminate\Database\Eloquent\Collection $servers
     * @param array                                    $config
     * @return string
     */
    private function feeText($servers, array $config)
    {
        $monthly = 0.0;
        $vpn = 0.0;
        $google = 0.0;

        foreach ($servers as $server) {
            $monthly += (float) $server->monthly_fee;
            $vpn += (float) $server->vpn_fee;
            $google += (float) $server->google_fee;
        }

        $text = strtr((string) Arr::get($config, 'STATION_FEE'), [
            '{fee}' => $this->money($monthly + $vpn + $google),
        ]);

        // 只有主機費時不必拆 —— 「（主機 30.00　VPN 0.00　Google 0.00）」是雜訊
        if ($vpn <= 0 && $google <= 0) {
            return $text;
        }

        return $text . strtr((string) Arr::get($config, 'STATION_FEE_BREAKDOWN'), [
            '{monthly}' => $this->money($monthly),
            '{vpn}'     => $this->money($vpn),
            '{google}'  => $this->money($google),
        ]);
    }

    /**
     * 電源（全開／全關／混合）
     *
     * @param \Illuminate\Database\Eloquent\Collection $servers
     * @param array                                    $config
     * @return string
     */
    private function powerText($servers, array $config)
    {
        $on = 0;
        $off = 0;
        $offSince = null;

        foreach ($servers as $server) {
            if ((int) $server->power_status === (int) config('constants.VM.POWER.ON')) {
                $on++;
                continue;
            }

            $off++;

            if (blank($offSince) && filled($server->powered_off_at)) {
                $offSince = $server->powered_off_at;
            }
        }

        if ($on > 0 && $off > 0) {
            return strtr((string) Arr::get($config, 'POWER_MIXED'), ['{on}' => $on, '{off}' => $off]);
        }

        if ($off < 1) {
            return (string) Arr::get($config, 'POWER_ON');
        }

        // 全部關機：只有一台時順便講從哪天開始關的
        if ($servers->count() === 1 && filled($offSince)) {
            return strtr((string) Arr::get($config, 'POWER_OFF_SINCE'), [
                '{date}' => $offSince->format('n/j'),
            ]);
        }

        return (string) Arr::get($config, 'POWER_OFF');
    }

    /**
     * 當月帳單（加總）
     *
     * ⚠ **三態要分開講。** `paid = 2` 是「客戶已上傳繳款證明，等我們審核」——
     * 講成「未繳費」是把我們的延誤說成客戶欠款。
     *
     * ⚠ 關機的機器**不出帳**（`generateMonthlyBillings()` 只對開機的產），
     * 所以沒帳單時要先看電源，才不會把「不該有帳單」說成「帳單還沒產」。
     *
     * @param \Illuminate\Database\Eloquent\Collection $servers
     * @param array                                    $config
     * @return string
     */
    private function billingText($servers, array $config)
    {
        $unpaid = 0.0;
        $pending = 0.0;
        $paid = 0.0;
        $due = null;
        $paidAt = null;
        $anyBilling = false;
        $anyOn = false;

        foreach ($servers as $server) {
            if ((int) $server->power_status === (int) config('constants.VM.POWER.ON')) {
                $anyOn = true;
            }

            foreach ($server->billings as $billing) {
                $anyBilling = true;
                $amount = (float) $billing->amount;

                if ((int) $billing->paid === (int) config('constants.VM.BILLING.PAID')) {
                    $paid += $amount;

                    if (blank($paidAt) && filled($billing->paid_at)) {
                        $paidAt = $billing->paid_at;
                    }

                    continue;
                }

                if ((int) $billing->paid === (int) config('constants.VM.BILLING.PENDING')) {
                    $pending += $amount;

                    continue;
                }

                $unpaid += $amount;

                // 多台時取最早的應收日 —— 要先處理的是最急的那一筆
                if (blank($due) || $billing->due_date->lessThan($due)) {
                    $due = $billing->due_date;
                }
            }
        }

        if (!$anyBilling) {
            return (string) Arr::get($config, $anyOn ? 'BILLING_NONE' : 'BILLING_POWERED_OFF');
        }

        $text = '';

        if ($unpaid > 0) {
            $text .= strtr((string) Arr::get($config, 'BILLING_UNPAID'), [
                '{amount}' => $this->money($unpaid),
                '{due}'    => filled($due) ? $due->format('n/j') : '-',
                '{when}'   => $this->dueText($due, $config),
            ]);
        }

        if ($pending > 0) {
            $text .= strtr((string) Arr::get($config, 'BILLING_PENDING'), [
                '{amount}' => $this->money($pending),
            ]);
        }

        if ($paid > 0) {
            $text .= strtr((string) Arr::get($config, 'BILLING_PAID'), [
                '{amount}'  => $this->money($paid),
                '{paid_at}' => filled($paidAt) ? $paidAt->format('n/j') : '-',
            ]);
        }

        return $text;
    }

    /**
     * 跨站台：誰還沒繳錢（只看當月）
     *
     * @param int|null $threadId
     * @param array    $config
     * @return void
     */
    private function replyUnpaid($threadId, array $config)
    {
        $month = now()->format('Y-m');
        $billings = $this->vmRepository->getUnpaidBillingsByMonth($month);

        if ($billings->isEmpty()) {
            $this->reply(strtr((string) Arr::get($config, 'UNPAID_EMPTY'), [
                '{month}' => $this->monthText($month),
            ]), $threadId);

            return;
        }

        $total = 0.0;

        foreach ($billings as $billing) {
            $total += (float) $billing->amount;
        }

        $text = strtr((string) Arr::get($config, 'UNPAID_TITLE'), [
            '{month}' => $this->monthText($month),
            '{count}' => $billings->count(),
            '{total}' => $this->money($total),
        ]);

        $max = (int) Arr::get($config, 'LIST_MAX', 20);
        $shown = 0;

        foreach ($billings as $billing) {
            if ($shown >= $max) {
                break;
            }

            $text .= $this->unpaidLine($billing, $config);
            $shown++;
        }

        if ($billings->count() > $shown) {
            $text .= strtr((string) Arr::get($config, 'LIST_MORE'), [
                '{count}' => $billings->count() - $shown,
            ]);
        }

        $this->reply($text, $threadId);
    }

    /**
     * 未收清單的一行
     *
     * @param \App\Models\VmBilling $billing
     * @param array                 $config
     * @return string
     */
    private function unpaidLine($billing, array $config)
    {
        $station = $this->stationNameOf($billing->vmServer, $config);
        $pending = (int) $billing->paid === (int) config('constants.VM.BILLING.PENDING');

        if ($pending) {
            return strtr((string) Arr::get($config, 'UNPAID_LINE_PENDING'), [
                '{station}' => $station,
                '{amount}'  => $this->money((float) $billing->amount),
            ]);
        }

        return strtr((string) Arr::get($config, 'UNPAID_LINE'), [
            '{station}' => $station,
            '{amount}'  => $this->money((float) $billing->amount),
            '{due}'     => filled($billing->due_date) ? $billing->due_date->format('n/j') : '-',
            '{when}'    => $this->dueText($billing->due_date, $config),
        ]);
    }

    /**
     * 跨站台：誰開機誰關機
     *
     * @param int|null $threadId
     * @param array    $config
     * @return void
     */
    private function replyPower($threadId, array $config)
    {
        $servers = $this->vmRepository->getAllServersWithStation();

        if ($servers->isEmpty()) {
            $this->reply((string) Arr::get($config, 'POWER_EMPTY'), $threadId);

            return;
        }

        $on = [];
        $off = [];

        foreach ($servers as $server) {
            if ((int) $server->power_status === (int) config('constants.VM.POWER.ON')) {
                $on[] = strtr((string) Arr::get($config, 'POWER_LINE'), [
                    '{station}' => $this->stationNameOf($server, $config),
                ]);

                continue;
            }

            $off[] = strtr((string) Arr::get($config, filled($server->powered_off_at) ? 'POWER_LINE_OFF' : 'POWER_LINE'), [
                '{station}' => $this->stationNameOf($server, $config),
                '{date}'    => filled($server->powered_off_at) ? $server->powered_off_at->format('n/j') : '-',
            ]);
        }

        $text = strtr((string) Arr::get($config, 'POWER_TITLE'), ['{count}' => $servers->count()]);

        if (filled($on)) {
            $text .= strtr((string) Arr::get($config, 'POWER_ON_TITLE'), ['{count}' => count($on)]);
            $text .= implode('', $on);
        }

        if (filled($off)) {
            $text .= strtr((string) Arr::get($config, 'POWER_OFF_TITLE'), ['{count}' => count($off)]);
            $text .= implode('', $off);
        }

        $this->reply($text, $threadId);
    }

    /**
     * 這台機器掛在哪個站台
     *
     * ⚠ 沒綁站台的機器也要看得到 —— 直接跳過的話它會從清單上消失，
     * 而「沒綁站台」本身就是要處理的狀況（繳款通知那邊也為它留了
     * `NO_STATION_TEXT`）。
     *
     * @param \App\Models\VmServer|null $server
     * @param array                     $config
     * @return string
     */
    private function stationNameOf($server, array $config)
    {
        if (blank($server) || blank($server->station)) {
            return (string) Arr::get($config, 'NO_STATION_NAME');
        }

        return (string) $server->station->name;
    }

    /**
     * 應收日還有多久（或逾期多久）
     *
     * ⚠ 三種都要有。只寫「還有 N 天」的話，逾期的會變成「還有 3 天」——
     * 比照 `VmPaymentNoticeService::renewalWhenText()` 踩過的坑。
     *
     * ⚠ 基準截到當天 00:00：`due_date` 是 date 欄位（時間是 00:00），
     * 而 `now()` 帶著時分秒 —— 不截的話今天到期會被算成「逾期 0 天」。
     *
     * @param \Illuminate\Support\Carbon|null $due
     * @param array                           $config
     * @return string
     */
    private function dueText($due, array $config)
    {
        if (blank($due)) {
            return '-';
        }

        $days = now()->startOfDay()->diffInDays($due, false);

        if ($days < 0) {
            return strtr((string) Arr::get($config, 'DUE_OVERDUE'), ['{days}' => abs($days)]);
        }

        if ($days < 1) {
            return (string) Arr::get($config, 'DUE_TODAY');
        }

        return strtr((string) Arr::get($config, 'DUE_FUTURE'), ['{days}' => $days]);
    }

    /**
     * 這個人能不能查 —— 不能的話回要講的那句話
     *
     * @param array $from
     * @param array $config
     * @return string 空字串 ＝ 可以
     */
    private function permissionError(array $from, array $config)
    {
        $telegramId = Arr::get($from, 'id');

        // 短路：連 Telegram id 都沒有就不必查資料庫
        if (blank($telegramId)) {
            return (string) Arr::get($config, 'NOT_BOUND_TEXT');
        }

        $user = $this->userRepository->findByTelegramUserId((int) $telegramId);

        if (blank($user)) {
            return (string) Arr::get($config, 'NOT_BOUND_TEXT');
        }

        if (!$user->hasPermission(self::PERMISSION)) {
            Log::info('指令查虛擬機被權限擋下', ['user_id' => $user->id]);

            return (string) Arr::get($config, 'NO_PERMISSION_TEXT');
        }

        return '';
    }

    /**
     * 可以查的站台（一次指令內只撈一次）
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function stations()
    {
        if (blank($this->stations)) {
            $this->stations = $this->stationRepository->allForDropdown();
        }

        return $this->stations;
    }

    /**
     * @return string
     */
    private function stationNames()
    {
        $names = [];

        foreach ($this->stations() as $station) {
            $names[] = (string) $station->name;
        }

        return filled($names) ? implode('、', $names) : '（目前沒有站台）';
    }

    /**
     * `2026-10` → `10 月`
     *
     * @param string $month
     * @return string
     */
    private function monthText($month)
    {
        return ((int) substr((string) $month, 5, 2)) . ' 月';
    }

    /**
     * 金額顯示格式
     *
     * 兩位小數、不加千分位 —— 跟繳款通知與餘點告警同一個樣子，
     * 同一筆錢在不同地方長得不一樣才是問題。
     *
     * @param float $value
     * @return string
     */
    private function money($value)
    {
        return number_format((float) $value, 2, '.', '');
    }

    /**
     * @param string   $text
     * @param int|null $threadId
     * @return void
     */
    private function reply($text, $threadId)
    {
        $this->supportGroup->sendToThread($text, $threadId);
    }
}
