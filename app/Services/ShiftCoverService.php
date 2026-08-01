<?php

namespace App\Services;

use App\Models\ShiftCover;
use App\Repositories\ShiftAssignmentRepository;
use App\Repositories\ShiftCoverRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 代班 Service
 *
 * 處理代班申請、代班人回應、管理者審核等核心商業邏輯。
 * 流程：原班人發起 → 代班人同意 → 管理者審核。
 */
class ShiftCoverService
{
    private $coverRepository;
    private $assignmentRepository;

    public function __construct(
        ShiftCoverRepository $coverRepository,
        ShiftAssignmentRepository $assignmentRepository
    ) {
        $this->coverRepository = $coverRepository;
        $this->assignmentRepository = $assignmentRepository;
    }

    /**
     * 發起代班申請
     *
     * @param array $params      含 assignment_id, cover_user_id, cover_start, cover_end, reason
     * @param int   $requesterId 原班人 user_id
     * @return ShiftCover
     * @throws ValidationException
     */
    public function request($params, $requesterId)
    {
        // 驗證排班紀錄存在且屬於發起者
        $assignment = $this->assignmentRepository->find((int) $params['assignment_id']);

        if (!$assignment || (int) $assignment->user_id !== $requesterId) {
            throw ValidationException::withMessages([
                'assignment_id' => [trans('cover.msg.not_own_assignment')],
            ]);
        }

        // 不能找自己代班
        if ((int) $params['cover_user_id'] === $requesterId) {
            throw ValidationException::withMessages([
                'cover_user_id' => [trans('cover.msg.cannot_cover_self')],
            ]);
        }

        return DB::transaction(function () use ($params, $requesterId) {
            return $this->coverRepository->create([
                'assignment_id'    => $params['assignment_id'],
                'requester_id'     => $requesterId,
                'cover_user_id'    => $params['cover_user_id'],
                'cover_start'      => $params['cover_start'],
                'cover_end'        => $params['cover_end'],
                'reason'           => $params['reason'] ?? null,
                'cover_user_status' => ShiftCover::STATUS_PENDING,
                'admin_status'      => ShiftCover::STATUS_PENDING,
            ]);
        });
    }

    /**
     * 代班人回應（同意或拒絕）
     *
     * @param ShiftCover $cover
     * @param int        $status  ShiftCover::STATUS_APPROVED 或 STATUS_REJECTED
     * @param int        $userId  回應者 user_id（必須是代班人）
     * @return ShiftCover
     * @throws ValidationException
     */
    public function respondByCoverUser(ShiftCover $cover, $status, $userId)
    {
        if ((int) $cover->cover_user_id !== $userId) {
            throw ValidationException::withMessages([
                'cover' => [trans('cover.msg.not_cover_user')],
            ]);
        }

        if ((int) $cover->cover_user_status !== ShiftCover::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'cover' => [trans('cover.msg.already_responded')],
            ]);
        }

        return DB::transaction(function () use ($cover, $status) {
            return $this->coverRepository->update($cover, [
                'cover_user_status'       => $status,
                'cover_user_responded_at' => now(),
            ]);
        });
    }

    /**
     * 管理者審核（核准或駁回）
     *
     * @param ShiftCover $cover
     * @param int        $status  ShiftCover::STATUS_APPROVED 或 STATUS_REJECTED
     * @param int        $adminId 管理者 user_id
     * @return ShiftCover
     * @throws ValidationException
     */
    public function respondByAdmin(ShiftCover $cover, $status, $adminId)
    {
        // 代班人必須已同意
        if ((int) $cover->cover_user_status !== ShiftCover::STATUS_APPROVED) {
            throw ValidationException::withMessages([
                'cover' => [trans('cover.msg.cover_user_not_approved')],
            ]);
        }

        if ((int) $cover->admin_status !== ShiftCover::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'cover' => [trans('cover.msg.already_reviewed')],
            ]);
        }

        return DB::transaction(function () use ($cover, $status, $adminId) {
            return $this->coverRepository->update($cover, [
                'admin_status'       => $status,
                'admin_id'           => $adminId,
                'admin_responded_at' => now(),
            ]);
        });
    }

    /**
     * 查詢與指定員工相關的代班紀錄
     *
     * @param int $userId
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function listByUser($userId, $perPage = 20)
    {
        return $this->coverRepository->paginateByUser($userId, $perPage);
    }

    /**
     * 查詢所有待管理者審核的代班紀錄
     *
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function listPendingAdmin($perPage = 20)
    {
        return $this->coverRepository->paginatePendingAdmin($perPage);
    }

    /**
     * 查詢指定日期範圍內已核准的代班紀錄（課表用）
     *
     * @param string $dateFrom
     * @param string $dateTo
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function listApprovedByDateRange($dateFrom, $dateTo)
    {
        return $this->coverRepository->getApprovedByDateRange($dateFrom, $dateTo);
    }

    /**
     * 查詢所有代班紀錄（管理者用）
     *
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function listAll($perPage = 20)
    {
        return $this->coverRepository->paginateAll($perPage);
    }
}
