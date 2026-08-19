<?php

namespace App\Services;

use App\Models\LeaveRequest;
use App\Repositories\LeaveRequestRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 請假 Service
 */
class LeaveRequestService
{
    private $leaveRepository;

    public function __construct(LeaveRequestRepository $leaveRepository)
    {
        $this->leaveRepository = $leaveRepository;
    }

    /**
     * 查詢全部（審核用）
     *
     * @param array $criteria
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAll($criteria = [])
    {
        return $this->leaveRepository->all($criteria);
    }

    /**
     * 查詢個人請假
     *
     * @param int $userId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getMyList($userId)
    {
        return $this->leaveRepository->getByUser($userId);
    }

    /**
     * 申請請假
     *
     * @param array $params
     * @param int   $userId
     * @return LeaveRequest
     * @throws ValidationException
     */
    public function apply($params, $userId)
    {
        $endDate = filled($params['end_date'] ?? null) ? $params['end_date'] : $params['start_date'];

        // 檢查是否有重疊的請假
        $hasOverlap = $this->leaveRepository->hasOverlapping(
            $userId,
            $params['start_date'],
            $endDate
        );

        if ($hasOverlap) {
            throw ValidationException::withMessages([
                'date' => [trans('leave.msg.overlap')],
            ]);
        }

        return $this->leaveRepository->create([
            'user_id'    => $userId,
            'start_date' => $params['start_date'],
            'end_date'   => $endDate,
            'is_full_day' => (int) $params['is_full_day'],
            'start_time' => $params['start_time'] ?? null,
            'end_time'   => $params['end_time'] ?? null,
            'reason'     => $params['reason'] ?? null,
        ]);
    }

    /**
     * 審核請假（通過/拒絕）
     *
     * @param LeaveRequest $leaveRequest
     * @param int          $status
     * @param int          $reviewerId
     * @param string|null  $note
     * @return LeaveRequest
     * @throws ValidationException
     */
    public function respond(LeaveRequest $leaveRequest, $status, $reviewerId, $note = null)
    {
        if ((int) $leaveRequest->status !== LeaveRequest::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'leave' => [trans('leave.msg.already_reviewed')],
            ]);
        }

        return DB::transaction(function () use ($leaveRequest, $status, $reviewerId, $note) {
            return $this->leaveRepository->update($leaveRequest, [
                'status'      => (int) $status,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);
        });
    }

    /**
     * 取得指定日期已通過的請假
     *
     * @param int    $userId
     * @param string $date
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getApprovedOnDate($userId, $date)
    {
        return $this->leaveRepository->getApprovedOnDate($userId, $date);
    }

    /**
     * 檢查指定日期是否被請假阻擋（供排班用）
     *
     * @param int         $userId
     * @param string      $date
     * @param string|null $shiftStart HH:mm
     * @param string|null $shiftEnd   HH:mm
     * @return bool
     */
    public function isBlockedByLeave($userId, $date, $shiftStart = null, $shiftEnd = null)
    {
        $leaves = $this->leaveRepository->getApprovedOnDate($userId, $date);

        if ($leaves->isEmpty()) {
            return false;
        }

        foreach ($leaves as $leave) {
            if ((int) $leave->is_full_day === 1) {
                return true;
            }

            // 時段假：檢查與班別時段是否重疊
            if (filled($shiftStart) && filled($shiftEnd) && filled($leave->start_time) && filled($leave->end_time)) {
                $leaveStart = strtotime($leave->start_time);
                $leaveEnd = strtotime($leave->end_time);
                $sStart = strtotime($shiftStart);
                $sEnd = strtotime($shiftEnd);

                if (max($leaveStart, $sStart) < min($leaveEnd, $sEnd)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 指定日期是否有整天假（供打卡用）
     *
     * @param int    $userId
     * @param string $date
     * @return bool
     */
    public function hasFullDayLeave($userId, $date)
    {
        return $this->leaveRepository->hasApprovedFullDayOnDate($userId, $date);
    }
}
