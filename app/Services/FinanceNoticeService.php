<?php

namespace App\Services;

use App\Models\CreditTopup;
use App\Models\VmBilling;
use App\Presenters\NumberPresenter;
use App\Repositories\UserRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 有人送件／有人審核「要花錢的那些」就私訊通知
 *
 * 需求方 2026-10-27：「如果有人上傳虛擬機的繳款證明，或是補點申請，
 * 要做私人訊息通知」，審核結果也要通知。
 *
 * ⚠ **跟 `ApprovalNoticeService` 是兩套，不要合併**：
 * 那一支管的是人事（請假、補打卡、代班），這一支管的是錢（繳款、補點）——
 * 審的人多半不是同一批，所以**收件人名單也是分開的另一份**
 * （需求方 2026-10-27 選的）。做成同一支的話，想收人事申請但不管財務的人
 * 就沒辦法只關掉其中一邊。
 *
 * ⚠ **這支只負責組內容與決定發給誰**，送出走 `StaffDmService`
 * （跟 `ApprovalNoticeService`／`ShiftNoticeService` 同一個分工）。
 *
 * ⚠ **發送失敗不能讓送件失敗**：`push()` 整段 try/catch，呼叫端也都擺在
 * 寫入成功之後。通知沒送到頂多主管晚點才看到；**補點申請被例外中斷的話，
 * 同仁會以為沒送出而重複送** —— 重複的補點要是被誤審，那是真的會動到
 * 客戶的點數。
 */
class FinanceNoticeService
{
    private $appSettingService;
    private $staffDm;
    private $userRepository;

    public function __construct(
        AppSettingService $appSettingService,
        StaffDmService $staffDm,
        UserRepository $userRepository
    ) {
        // 收件人存在 app_setting（通知設定頁維護），換人不用動程式
        $this->appSettingService = $appSettingService;
        $this->staffDm = $staffDm;
        // 送件人／審核人的暱稱要另外查
        $this->userRepository = $userRepository;
    }

    /**
     * 有人上傳虛擬機的繳款證明
     *
     * ⚠ `vm_billing` **沒有「誰上傳的」這個欄位**（需求方 2026-10-27 決定
     * 不加）—— 所以上傳者是從 Controller 的 `Auth::id()` 傳進來的，
     * 只寫進通知，帳單資料本身不會記。日後若要在帳單上查得到是誰，
     * 那是另一個題目（要加欄位）。
     *
     * @param VmBilling $billing
     * @param int|null  $uploaderId
     * @return void
     */
    public function proofUploaded(VmBilling $billing, $uploaderId)
    {
        $this->push('PROOF_UPLOADED', array_merge(
            $this->billingFields($billing),
            ['{name}' => $this->nameOf($uploaderId)]
        ));
    }

    /**
     * 繳款證明審核通過
     *
     * ⚠ 繳款證明**沒有「拒絕」這個動作** —— 介面上只有「審核通過」與
     * 「直接標記已收款」。所以這裡不像補點那樣分兩種結果。
     *
     * @param VmBilling $billing
     * @param int|null  $reviewerId
     * @return void
     */
    public function proofApproved(VmBilling $billing, $reviewerId)
    {
        $this->push('PROOF_APPROVED', array_merge(
            $this->billingFields($billing),
            ['{reviewer}' => $this->nameOf($reviewerId)]
        ));
    }

    /**
     * 有人申請補點／扣點
     *
     * @param CreditTopup $topup
     * @return void
     */
    public function topupApplied(CreditTopup $topup)
    {
        $this->push('TOPUP_APPLIED', array_merge(
            $this->topupFields($topup),
            ['{name}' => $this->nameOf($topup->requested_by)]
        ));
    }

    /**
     * 補點申請審核完了
     *
     * ⚠ 審核通過有**兩種結局**：主系統 API 加扣成功（`STATUS_COMPLETED`）
     * 與 API 失敗（`STATUS_FAILED`）。後者是「審了但點數沒進去」，
     * 主管一定要知道 —— 混成一句「已通過」會讓人以為事情結束了。
     *
     * @param CreditTopup $topup    已經更新過狀態的那筆
     * @param int|null    $reviewerId
     * @return void
     */
    public function topupReviewed(CreditTopup $topup, $reviewerId)
    {
        $templates = [
            CreditTopup::STATUS_COMPLETED => 'TOPUP_APPROVED',
            CreditTopup::STATUS_REJECTED  => 'TOPUP_REJECTED',
            CreditTopup::STATUS_FAILED    => 'TOPUP_FAILED',
        ];

        $templateKey = Arr::get($templates, (int) $topup->status);

        // 還在待審核就不該走到這裡；真的走到了也不要發一則看不懂的通知
        if (blank($templateKey)) {
            Log::warning('補點審核通知：狀態不是結案狀態，不發送', [
                'topup_id' => $topup->id,
                'status'   => $topup->status,
            ]);

            return;
        }

        $this->push($templateKey, array_merge(
            $this->topupFields($topup),
            [
                '{name}'     => $this->nameOf($topup->requested_by),
                '{reviewer}' => $this->nameOf($reviewerId),
                '{error}'    => $this->errorLine($topup->api_response),
            ]
        ));
    }

    /**
     * 測試發送（通知設定頁那顆按鈕）
     *
     * ⚠ 這種通知是**事件觸發**的，沒辦法像報表那樣「補發昨天那一份」——
     * 所以測試發的是一則說明用的訊息，確認的是「這份名單收不收得到」。
     *
     * @return array sent／reason／failed／names
     */
    public function test()
    {
        $userIds = $this->recipients();

        if (blank($userIds)) {
            return ['sent' => 0, 'reason' => 'no_manager', 'failed' => [], 'names' => []];
        }

        return $this->staffDm->sendToUserIds(
            $userIds,
            (string) config('constants.FINANCE_NOTICE.TEST_TEXT')
        );
    }

    /**
     * 組內容並送出
     *
     * ⚠ **整段包 try/catch**：理由見類別註解。
     *
     * @param string                $templateKey
     * @param array<string, string> $replace
     * @return void
     */
    private function push($templateKey, array $replace)
    {
        try {
            $userIds = $this->recipients();

            // 短路：沒設定收件人就不必查暱稱也不必組訊息
            if (blank($userIds)) {
                return;
            }

            $text = strtr((string) config("constants.FINANCE_NOTICE.{$templateKey}"), $replace);

            $result = $this->staffDm->sendToUserIds($userIds, $text);

            Log::info('財務申請通知已送出', [
                'template' => $templateKey,
                'sent'     => Arr::get($result, 'sent'),
                'failed'   => Arr::get($result, 'failed'),
            ]);
        } catch (\Exception $e) {
            Log::error('財務申請通知發送失敗', [
                'template' => $templateKey,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * 收件人（通知設定頁維護的那份名單）
     *
     * @return array<int>
     */
    private function recipients()
    {
        return $this->appSettingService->getIntList(AppSettingService::KEY_FINANCE_NOTICE_MANAGER);
    }

    /**
     * 虛擬機帳單兩則共用的欄位
     *
     * @param VmBilling $billing
     * @return array<string, string>
     */
    private function billingFields(VmBilling $billing)
    {
        $server = $billing->vmServer;
        $station = filled($server) ? $server->station : null;

        return [
            // 帳單可能是「還沒綁站台的機器」，那一行就不印
            '{vm}'     => filled($server) ? (string) $server->hostname : '',
            '{station}' => filled($station) ? $this->stationLine($station->name) : '',
            '{month}'  => (string) $billing->billing_month,
            '{amount}' => $this->amount($billing->amount),
        ];
    }

    /**
     * 補點四則共用的欄位
     *
     * @param CreditTopup $topup
     * @return array<string, string>
     */
    private function topupFields(CreditTopup $topup)
    {
        $station = $topup->station;

        return [
            '{action}'  => (string) config('constants.FINANCE_NOTICE.ACTION.' . (int) $topup->action_type),
            '{station}' => filled($station) ? (string) $station->name : '',
            '{credit}'  => $this->amount($topup->credit_amount),
            '{kind}'    => $this->kindLine($topup),
            '{usdt}'    => $this->usdtLine($topup),
            '{note}'    => $this->noteLine($topup->note),
        ];
    }

    /**
     * 「哪一種點數」那一行
     *
     * ⚠ `other` 這一類**不會呼叫主系統 API**（需求方 2026-10-14 決定），
     * 只是記一筆帳。不特別標出來的話，主管會以為按下去客戶點數就會動。
     *
     * @param CreditTopup $topup
     * @return string
     */
    private function kindLine(CreditTopup $topup)
    {
        $label = config('constants.FINANCE_NOTICE.CREDIT_TYPE.' . (string) $topup->credit_type);

        if (blank($label)) {
            return '';
        }

        return strtr((string) config('constants.FINANCE_NOTICE.KIND_LINE'), ['{kind}' => $label]);
    }

    /**
     * USDT 與匯率那一行（直接輸入點數時整行不印）
     *
     * ⚠ `input_type = TYPE_CREDIT` 時 `usdt_amount` 與 `exchange_rate`
     * 都是 0 —— 照印會變成「USDT 0 ／匯率 0」，看起來像壞掉的資料。
     *
     * @param CreditTopup $topup
     * @return string
     */
    private function usdtLine(CreditTopup $topup)
    {
        if ((int) $topup->input_type === CreditTopup::TYPE_CREDIT) {
            return '';
        }

        return strtr((string) config('constants.FINANCE_NOTICE.USDT_LINE'), [
            '{usdt}' => $this->amount($topup->usdt_amount),
            // 匯率不補千分位，小數位也多留兩位 —— 它本來就是 30 上下的小數
            '{rate}' => NumberPresenter::trimZeros($topup->exchange_rate, 4),
        ]);
    }

    /**
     * 站台那一行（沒綁站台就整行不印）
     *
     * @param string|null $name
     * @return string
     */
    private function stationLine($name)
    {
        if (blank($name)) {
            return '';
        }

        return strtr((string) config('constants.FINANCE_NOTICE.STATION_LINE'), ['{station}' => $name]);
    }

    /**
     * 備註那一行（沒填就整行不印）
     *
     * @param string|null $note
     * @return string
     */
    private function noteLine($note)
    {
        if (blank($note)) {
            return '';
        }

        return strtr((string) config('constants.FINANCE_NOTICE.NOTE_LINE'), ['{note}' => $note]);
    }

    /**
     * API 失敗時的原因那一行
     *
     * ⚠ 截短到 100 字：主系統回的可能是一整包 JSON，整串貼進私訊會洗版。
     * 要查完整內容在補點紀錄上看得到。
     *
     * @param string|null $response
     * @return string
     */
    private function errorLine($response)
    {
        if (blank($response)) {
            return '';
        }

        return strtr((string) config('constants.FINANCE_NOTICE.ERROR_LINE'), [
            '{error}' => mb_substr((string) $response, 0, 100),
        ]);
    }

    /**
     * 帶千分位的金額／點數
     *
     * ⚠ **刻意跟 `NumberPresenter::trimZeros()` 不一樣** —— 那支不加千分位，
     * 因為它多半用在**發給客戶**的文案。這裡是發給主管的內部通知，
     * 而主管要看的正是「有沒有多打一個零」：`1000000` 跟 `10000000`
     * 掃過去幾乎一樣，`1,000,000` 跟 `10,000,000` 一眼就分得出來。
     *
     * 小數位交給 `trimZeros()` 決定（尾數的 0 砍掉），這裡只負責補逗號。
     *
     * @param float|string|null $value
     * @param int               $maxDecimals
     * @return string
     */
    private function amount($value, $maxDecimals = 2)
    {
        $trimmed = NumberPresenter::trimZeros($value, $maxDecimals);
        $dot = strrchr($trimmed, '.');

        return number_format((float) $trimmed, $dot === false ? 0 : strlen($dot) - 1);
    }

    /**
     * 某個人的暱稱
     *
     * @param int|null $userId
     * @return string
     */
    private function nameOf($userId)
    {
        // 短路：沒有 id 就不必查 DB
        if (blank($userId)) {
            return '';
        }

        $user = $this->userRepository->getNamesByIds([(int) $userId])->first();

        return filled($user) ? (string) $user->nickname : '';
    }
}
