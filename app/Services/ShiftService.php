<?php

namespace App\Services;

use App\Criteria\ShiftAssignment\AssignmentDateRangeCriteria;
use App\Criteria\ShiftAssignment\AssignmentShiftCriteria;
use App\Criteria\ShiftAssignment\AssignmentUserCriteria;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftSwap;
use App\Repositories\ShiftAssignmentRepository;
use App\Repositories\ShiftRepository;
use App\Repositories\UserRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 排班 Service
 *
 * 處理班別管理、報班、換班等核心商業邏輯。
 * DB 操作一律透過 Repository，不直接 Model::where(...)。
 * DB Transaction 在此層處理，Controller 不碰 DB。
 */
class ShiftService
{
    private $shiftRepository;
    private $assignmentRepository;
    private $userRepository;

    public function __construct(
        ShiftRepository $shiftRepository,
        ShiftAssignmentRepository $assignmentRepository,
        UserRepository $userRepository
    ) {
        $this->shiftRepository = $shiftRepository;
        $this->assignmentRepository = $assignmentRepository;
        $this->userRepository = $userRepository;
    }

    // ---------------------------------------------------------------
    //  班別管理（Admin）
    // ---------------------------------------------------------------

    /**
     * 取得所有班別（含停用）
     *
     * @return Collection
     */
    public function listShifts()
    {
        return $this->shiftRepository->all();
    }

    /**
     * 取得所有啟用中的班別
     *
     * @return Collection
     */
    public function listActiveShifts()
    {
        return $this->shiftRepository->allActive();
    }

    /**
     * 更新班別時段（僅 Admin）
     *
     * @param Shift $shift
     * @param array $params
     * @return Shift
     */
    public function updateShift(Shift $shift, $params)
    {
        $attributes = array_filter([
            'display_name' => $params['display_name'] ?? null,
            'start_time'   => $params['start_time'] ?? null,
            'end_time'     => $params['end_time'] ?? null,
            'is_active'    => array_key_exists('is_active', $params) ? $params['is_active'] : null,
            'sort'         => array_key_exists('sort', $params) ? $params['sort'] : null,
        ], function ($value) {
            return filled($value);
        });

        return DB::transaction(function () use ($shift, $attributes) {
            return $this->shiftRepository->update($shift, $attributes);
        });
    }

    // ---------------------------------------------------------------
    //  排班紀錄
    // ---------------------------------------------------------------

    /**
     * 查詢排班紀錄（分頁，支援日期範圍、員工、班別篩選）
     *
     * @param array $params 可含 date_from, date_to, user_id, shift_id, per_page
     * @return LengthAwarePaginator
     */
    public function listAssignments($params)
    {
        $criteria = [];

        if (filled($params['date_from'] ?? null) && filled($params['date_to'] ?? null)) {
            $criteria[] = new AssignmentDateRangeCriteria($params['date_from'], $params['date_to']);
        }

        if (filled($params['user_id'] ?? null)) {
            $criteria[] = new AssignmentUserCriteria((int) $params['user_id']);
        }

        if (filled($params['shift_id'] ?? null)) {
            $criteria[] = new AssignmentShiftCriteria((int) $params['shift_id']);
        }

        return $this->assignmentRepository->paginate($criteria, (int) ($params['per_page'] ?? 20));
    }

    /**
     * 報班（員工自行選擇班別）
     *
     * @param array $params 含 user_id, shift_id, date
     * @return ShiftAssignment
     * @throws ValidationException 鎖定帳號或當天已有排班
     */
    /**
     * @param array $params        含 user_id, shift_id, date
     * @param bool  $byAdmin       是否由管理者指派（略過鎖定檢查）
     */
    public function assign($params, $byAdmin = false)
    {
        // 非管理者指派時，鎖定帳號不可自行報班
        $user = $this->userRepository->find((int) $params['user_id']);
        if (!$byAdmin && $user && $user->isLockStatus()) {
            throw ValidationException::withMessages([
                'user_id' => [trans('shift.locked_cannot_assign')],
            ]);
        }

        // 檢查該員工當天是否已有同一班別（同天可排多個不同班別）
        if ($this->assignmentRepository->existsByUserDateAndShift((int) $params['user_id'], $params['date'], (int) $params['shift_id'])) {
            throw ValidationException::withMessages([
                'shift_id' => [trans('shift.already_assigned_same_shift')],
            ]);
        }

        return DB::transaction(function () use ($params) {
            return $this->assignmentRepository->create([
                'user_id'  => $params['user_id'],
                'shift_id' => $params['shift_id'],
                'date'     => $params['date'],
            ]);
        });
    }

    /**
     * 刪除排班紀錄
     *
     * @param int $assignmentId
     * @return bool
     * @throws ValidationException 排班紀錄不存在
     */
    public function deleteAssignment($assignmentId)
    {
        $assignment = $this->assignmentRepository->find($assignmentId);

        if (!$assignment) {
            throw ValidationException::withMessages([
                'assignment' => [trans('shift.msg.assignment_not_found')],
            ]);
        }

        return $this->assignmentRepository->delete($assignment);
    }

    // ---------------------------------------------------------------
    //  換班
    // ---------------------------------------------------------------

    /**
     * 發起換班請求
     *
     * @param array $params      含 requester_assignment_id, target_assignment_id
     * @param int   $requesterId 發起者 user_id
     * @return ShiftSwap
     * @throws ValidationException 鎖定帳號、非本人排班、對方排班不存在等
     */
    public function requestSwap($params, $requesterId)
    {
        // 透過 Repository 查詢使用者狀態，鎖定帳號不可自行換班
        $requester = $this->userRepository->find($requesterId);
        if ($requester && $requester->isLockStatus()) {
            throw ValidationException::withMessages([
                'swap' => [trans('shift.locked_cannot_swap')],
            ]);
        }

        $requesterAssignment = $this->assignmentRepository->find((int) $params['requester_assignment_id']);
        $targetAssignment = $this->assignmentRepository->find((int) $params['target_assignment_id']);

        // 驗證發起方的排班屬於自己
        if (!$requesterAssignment || (int) $requesterAssignment->user_id !== $requesterId) {
            throw ValidationException::withMessages([
                'requester_assignment_id' => [trans('shift.swap_not_own_assignment')],
            ]);
        }

        // 驗證對方的排班存在
        if (!$targetAssignment) {
            throw ValidationException::withMessages([
                'target_assignment_id' => [trans('shift.swap_target_not_found')],
            ]);
        }

        // 不能跟自己換班
        if ((int) $targetAssignment->user_id === $requesterId) {
            throw ValidationException::withMessages([
                'target_assignment_id' => [trans('shift.swap_cannot_self')],
            ]);
        }

        // 檢查時間限制：任一方排班開始前 3 小時內不可換班
        $now = now();
        foreach ([$requesterAssignment, $targetAssignment] as $assignment) {
            $shift = $assignment->shift;
            if ($shift) {
                $shiftStart = \Carbon\Carbon::parse("{$assignment->date->format('Y-m-d')} {$shift->start_time}");
                $hoursUntilStart = $now->diffInMinutes($shiftStart, false) / 60;
                if ($hoursUntilStart < 3) {
                    throw ValidationException::withMessages([
                        'swap' => [trans('shift.swap_too_late')],
                    ]);
                }
            }
        }

        // 檢查發起方在對方班別那天是否已有其他排班（時間衝突）
        $targetDate = $targetAssignment->date->format('Y-m-d');
        if ($this->assignmentRepository->hasOtherAssignmentOnDate($requesterId, $targetDate, $requesterAssignment->id)) {
            throw ValidationException::withMessages([
                'swap' => [trans('shift.swap_conflict')],
            ]);
        }

        // 檢查是否已有相同的待確認換班紀錄
        if ($this->assignmentRepository->hasPendingSwap($requesterAssignment->id, $targetAssignment->id)) {
            throw ValidationException::withMessages([
                'swap' => [trans('shift.swap_duplicate_pending')],
            ]);
        }

        return DB::transaction(function () use ($requesterId, $requesterAssignment, $targetAssignment) {
            return $this->assignmentRepository->createSwap([
                'requester_id'            => $requesterId,
                'target_id'               => $targetAssignment->user_id,
                'requester_assignment_id' => $requesterAssignment->id,
                'target_assignment_id'    => $targetAssignment->id,
                'status'                  => ShiftSwap::STATUS_PENDING,
            ]);
        });
    }

    /**
     * 回應換班請求（同意或拒絕）
     *
     * 同意時會在 DB Transaction 中互換雙方的 shift_id。
     *
     * @param ShiftSwap $swap
     * @param int       $status   ShiftSwap::STATUS_APPROVED 或 STATUS_REJECTED
     * @param int       $targetId 回應者 user_id（必須是被換班方）
     * @return ShiftSwap
     * @throws ValidationException 非被換班方、狀態不正確等
     */
    public function respondSwap(ShiftSwap $swap, $status, $targetId)
    {
        // 只有被換班方可以回應
        if ((int) $swap->target_id !== $targetId) {
            throw ValidationException::withMessages([
                'swap' => [trans('shift.swap_not_target')],
            ]);
        }

        // 只有待確認狀態可以回應
        if ((int) $swap->status !== ShiftSwap::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'swap' => [trans('shift.swap_already_responded')],
            ]);
        }

        if ($status === ShiftSwap::STATUS_APPROVED) {
            return $this->executeSwap($swap);
        }

        return $this->assignmentRepository->updateSwapStatus($swap, ShiftSwap::STATUS_REJECTED);
    }

    /**
     * 執行換班：在 Transaction 中互換雙方 user_id 並更新狀態
     *
     * @param ShiftSwap $swap
     * @return ShiftSwap
     */
    private function executeSwap(ShiftSwap $swap)
    {
        return DB::transaction(function () use ($swap) {
            $requesterAssignment = $this->assignmentRepository->find((int) $swap->requester_assignment_id);
            $targetAssignment = $this->assignmentRepository->find((int) $swap->target_assignment_id);

            // 互換 user_id（你上我的班，我上你的班）
            $tempUserId = $requesterAssignment->user_id;

            $this->assignmentRepository->update($requesterAssignment, [
                'user_id' => $targetAssignment->user_id,
            ]);

            $this->assignmentRepository->update($targetAssignment, [
                'user_id' => $tempUserId,
            ]);

            return $this->assignmentRepository->updateSwapStatus($swap, ShiftSwap::STATUS_APPROVED);
        });
    }

    /**
     * 查詢與指定員工相關的換班請求
     *
     * @param int $userId
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    /**
     * 查詢換班請求
     *
     * Admin 查看全部，客服只查自己相關的。
     *
     * @param int  $userId
     * @param bool $isAdmin
     * @param int  $perPage
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function listSwaps($userId, $isAdmin, $perPage = 20)
    {
        if ($isAdmin) {
            return $this->assignmentRepository->paginateAllSwaps($perPage);
        }

        return $this->assignmentRepository->paginateSwapsByUser($userId, $perPage);
    }
}
