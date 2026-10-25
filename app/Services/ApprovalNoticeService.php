<?php

namespace App\Services;

use App\Models\ClockAmendment;
use App\Models\LeaveRequest;
use App\Models\ShiftCover;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 有人申請／有人審核時，私訊通知主管
 *
 * 需求方 2026-10-26：「有人申請請假、申請補打卡、申請代班的話，要通知，
 * 用私人通知就可以了」、「有人審核時，無論通過或拒絕，也都要發送」。
 *
 * ⚠ **換班不在內**（需求方決定）：換班沒有主管審核那一關 —— 被換的同仁答應
 * 就直接生效，主管收到也不能做什麼。判準是「主管要不要處理」，
 * 不是「有沒有人提出申請」。
 *
 * ⚠ **這支只負責組內容與決定發給誰**，送出走 `StaffDmService`
 * （跟 `ShiftNoticeService` 同一個分工）。
 *
 * ⚠ **發送失敗不能讓申請本身失敗**：每個入口都包 try/catch。
 * 通知沒送到頂多是主管晚點才看到，但申請被例外中斷的話，
 * 同仁會以為自己沒送出而重複送 —— 這個代價大得多。
 */
class ApprovalNoticeService
{
    /** @var string 請假 */
    const FLOW_LEAVE = 'leave';

    /** @var string 補打卡 */
    const FLOW_AMENDMENT = 'amendment';

    /** @var string 代班人回應 */
    const FLOW_COVER_USER = 'cover_user';

    /** @var string 代班的主管審核 */
    const FLOW_COVER_ADMIN = 'cover_admin';

    private $appSettingService;
    private $staffDm;
    private $userRepository;

    public function __construct(
        AppSettingService $appSettingService,
        StaffDmService $staffDm,
        \App\Repositories\UserRepository $userRepository
    ) {
        // 收件人存在 app_setting（通知設定頁維護），換人不用動程式
        $this->appSettingService = $appSettingService;
        $this->staffDm = $staffDm;
        // 申請人／審核人的暱稱要另外查（三個 Model 的關聯欄位不一樣）
        $this->userRepository = $userRepository;
    }

    /**
     * 有人申請請假
     *
     * @param LeaveRequest $leave
     * @return void
     */
    public function leaveApplied(LeaveRequest $leave)
    {
        $this->push('LEAVE_APPLIED', [
            '{name}'   => $this->nameOf($leave->user_id),
            '{period}' => $this->leavePeriod($leave),
            '{reason}' => $this->reasonLine($leave->reason),
        ]);
    }

    /**
     * 請假審核完
     *
     * @param LeaveRequest $leave
     * @param int          $status
     * @return void
     */
    public function leaveReviewed(LeaveRequest $leave, $status)
    {
        $this->push('LEAVE_REVIEWED', [
            '{name}'     => $this->nameOf($leave->user_id),
            '{reviewer}' => $this->nameOf($leave->reviewed_by),
            '{result}'   => $this->resultText(self::FLOW_LEAVE, $status, LeaveRequest::STATUS_APPROVED),
            '{period}'   => $this->leavePeriod($leave),
            '{note}'     => $this->noteLine($leave->review_note),
        ]);
    }

    /**
     * 有人申請補打卡
     *
     * @param ClockAmendment $amendment
     * @return void
     */
    public function amendmentApplied(ClockAmendment $amendment)
    {
        $this->push('AMENDMENT_APPLIED', $this->amendmentFields($amendment) + [
            '{reason}' => $this->reasonLine($amendment->reason),
        ]);
    }

    /**
     * 補打卡審核完
     *
     * @param ClockAmendment $amendment
     * @param int            $status
     * @return void
     */
    public function amendmentReviewed(ClockAmendment $amendment, $status)
    {
        $this->push('AMENDMENT_REVIEWED', $this->amendmentFields($amendment) + [
            '{reviewer}' => $this->nameOf($amendment->reviewed_by),
            '{result}'   => $this->resultText(self::FLOW_AMENDMENT, $status, ClockAmendment::STATUS_APPROVED),
        ]);
    }

    /**
     * 有人申請代班
     *
     * @param ShiftCover $cover
     * @return void
     */
    public function coverApplied(ShiftCover $cover)
    {
        $this->push('COVER_APPLIED', $this->coverFields($cover) + [
            '{reason}' => $this->reasonLine($cover->reason),
        ]);
    }

    /**
     * 代班人回應了
     *
     * ⚠ 這一則就是「輪到您審了」的信號 —— 代班人沒同意之前主管按不了
     * （`ShiftCoverService::respondByAdmin()` 會擋），少了它主管收到申請通知
     * 之後就是一片寂靜。拒絕也要發，不然主管會一直等一筆已經死掉的申請。
     *
     * @param ShiftCover $cover
     * @param int        $status
     * @return void
     */
    public function coverResponded(ShiftCover $cover, $status)
    {
        $approved = (int) $status === ShiftCover::STATUS_APPROVED;
        $config = (array) config('constants.APPROVAL_NOTICE');

        $this->push('COVER_RESPONDED', $this->coverFields($cover) + [
            '{result}' => $this->resultText(self::FLOW_COVER_USER, $status, ShiftCover::STATUS_APPROVED),
            '{next}'   => (string) Arr::get($config, $approved ? 'COVER_NEXT_APPROVED' : 'COVER_NEXT_REJECTED'),
        ]);
    }

    /**
     * 代班的主管審核完
     *
     * @param ShiftCover $cover
     * @param int        $status
     * @return void
     */
    public function coverReviewed(ShiftCover $cover, $status)
    {
        $this->push('COVER_REVIEWED', $this->coverFields($cover) + [
            '{reviewer}' => $this->nameOf($cover->admin_id),
            '{result}'   => $this->resultText(self::FLOW_COVER_ADMIN, $status, ShiftCover::STATUS_APPROVED),
        ]);
    }

    /**
     * 測試發送（通知設定頁那顆按鈕）
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
            (string) config('constants.APPROVAL_NOTICE.TEST_TEXT')
        );
    }

    /**
     * 組內容並送出
     *
     * ⚠ **整段包 try/catch**：通知失敗不能讓申請或審核本身失敗。
     * 沒送到頂多是主管晚點才看到；申請被例外中斷的話，同仁會以為沒送出
     * 而重複送 —— 那個代價大得多。
     *
     * @param string               $templateKey
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

            $text = strtr((string) config("constants.APPROVAL_NOTICE.{$templateKey}"), $replace);

            $result = $this->staffDm->sendToUserIds($userIds, $text);

            Log::info('申請審核通知已送出', [
                'template' => $templateKey,
                'sent'     => Arr::get($result, 'sent'),
                'failed'   => Arr::get($result, 'failed'),
            ]);
        } catch (\Exception $e) {
            Log::error('申請審核通知發送失敗', [
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
        return $this->appSettingService->getIntList(AppSettingService::KEY_APPROVAL_NOTICE_MANAGER);
    }

    /**
     * 補打卡三則共用的欄位
     *
     * @param ClockAmendment $amendment
     * @return array<string, string>
     */
    private function amendmentFields(ClockAmendment $amendment)
    {
        return [
            '{name}' => $this->nameOf($amendment->user_id),
            '{date}' => $this->dateText($amendment->date),
            '{type}' => (string) config("constants.APPROVAL_NOTICE.AMENDMENT_TYPE.{$amendment->type}"),
            '{time}' => (string) $amendment->clock_time,
        ];
    }

    /**
     * 代班三則共用的欄位
     *
     * @param ShiftCover $cover
     * @return array<string, string>
     */
    private function coverFields(ShiftCover $cover)
    {
        return [
            '{name}'   => $this->nameOf($cover->requester_id),
            '{cover}'  => $this->nameOf($cover->cover_user_id),
            '{period}' => $this->coverPeriod($cover),
        ];
    }

    /**
     * 請假期間
     *
     * ⚠ 整天與時段要分開講：只寫日期的話，請半天的人會被當成整天不在。
     *
     * @param LeaveRequest $leave
     * @return string
     */
    private function leavePeriod(LeaveRequest $leave)
    {
        $start = $this->dateText($leave->start_date);
        $end = $this->dateText($leave->end_date);

        $days = ($start === $end) ? $start : "{$start} ~ {$end}";

        if ((int) $leave->is_full_day === 1) {
            return "{$days}（整天）";
        }

        return "{$days} {$leave->start_time} ~ {$leave->end_time}";
    }

    /**
     * 代班時間
     *
     * @param ShiftCover $cover
     * @return string
     */
    private function coverPeriod(ShiftCover $cover)
    {
        return trim("{$cover->cover_start} ~ {$cover->cover_end}");
    }

    /**
     * 日期字串
     *
     * ⚠ 這幾個欄位有的 cast 成 date、有的是純字串，所以統一走 `Carbon`
     * 再格式化 —— 直接 `(string)` 的話 cast 過的會印出一整串時間。
     *
     * @param mixed $value
     * @return string
     */
    private function dateText($value)
    {
        if (blank($value)) {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return mb_substr((string) $value, 0, 10);
    }

    /**
     * 原因那一行（沒填就整行不印）
     *
     * @param string|null $reason
     * @return string
     */
    private function reasonLine($reason)
    {
        if (blank($reason)) {
            return '';
        }

        return strtr((string) config('constants.APPROVAL_NOTICE.REASON_LINE'), ['{reason}' => $reason]);
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

        return strtr((string) config('constants.APPROVAL_NOTICE.NOTE_LINE'), ['{note}' => $note]);
    }

    /**
     * 「通過／拒絕」要用哪個字眼
     *
     * ⚠ 各流程講法不同：請假是「已通過」、代班人是「同意」、
     * 主管審代班是「已核准」。混用會讓人以為是不同的狀態。
     *
     * @param string $flow
     * @param int    $status
     * @param int    $approvedValue
     * @return string
     */
    private function resultText($flow, $status, $approvedValue)
    {
        $key = ((int) $status === (int) $approvedValue) ? 'approved' : 'rejected';

        return (string) config("constants.APPROVAL_NOTICE.RESULT.{$flow}.{$key}");
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
