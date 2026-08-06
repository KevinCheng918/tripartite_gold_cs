<?php

namespace App\Services;

use App\Repositories\ShiftAssignmentRepository;
use App\Repositories\ShiftRepository;
use App\Repositories\UserRepository;

/**
 * Dashboard Service
 *
 * 彙整 Dashboard 所需的統計資料。
 */
class DashboardService
{
    private $userRepository;
    private $assignmentRepository;
    private $shiftRepository;

    public function __construct(
        UserRepository $userRepository,
        ShiftAssignmentRepository $assignmentRepository,
        ShiftRepository $shiftRepository
    ) {
        $this->userRepository = $userRepository;
        $this->assignmentRepository = $assignmentRepository;
        $this->shiftRepository = $shiftRepository;
    }

    /**
     * 取得 Admin Dashboard 資料
     *
     * @return array
     */
    public function getAdminData()
    {
        $today = now()->format('Y-m-d');
        $weekStart = now()->startOfWeek()->format('Y-m-d');
        $weekEnd = now()->endOfWeek()->format('Y-m-d');

        // 帳號統計
        $csUsers = $this->userRepository->getCsUsers();
        $allCsUsers = $this->userRepository->getAllCsUsers();

        $totalCs = $allCsUsers->count();
        $normalCs = $allCsUsers->where('status', config('constants.USER.STATUS.NORMAL'))->count();
        $lockCs = $allCsUsers->where('status', config('constants.USER.STATUS.LOCK'))->count();
        $deactivateCs = $allCsUsers->where('status', config('constants.USER.STATUS.DEACTIVATE'))->count();

        // 今日排班 — 固定三欄：早班、午班、晚班（依 shifts 表的 sort 排序）
        $todayAssignments = $this->assignmentRepository->getByDateRange($today, $today);
        $allShifts = $this->shiftRepository->allActive();

        $todayByShift = collect();
        foreach ($allShifts as $shift) {
            $users = $todayAssignments
                ->where('shift_id', $shift->id)
                ->map(function ($a) {
                    return $a->user ? $a->user->nickname : '-';
                })
                ->unique()
                ->values()
                ->all();

            $todayByShift->put($shift->display_name, [
                'shift' => $shift,
                'users' => $users,
            ]);
        }

        // 本週排班 — 依員工班次數排名（最多班的人排最前面）
        $weekAssignments = $this->assignmentRepository->getByDateRange($weekStart, $weekEnd);
        $weekTotal = $weekAssignments->count();

        $weekUserRanking = $weekAssignments->groupBy(function ($a) {
            return $a->user ? $a->user->nickname : '-';
        })->map(function ($group) {
            return $group->count();
        })->sortDesc();

        return compact(
            'totalCs', 'normalCs', 'lockCs', 'deactivateCs',
            'todayByShift', 'weekTotal', 'weekUserRanking'
        );
    }

    /**
     * 取得客服 Dashboard 資料（只看自己）
     *
     * @param int $userId
     * @return array
     */
    public function getCsData($userId)
    {
        $today = now()->format('Y-m-d');
        $weekStart = now()->startOfWeek()->format('Y-m-d');
        $weekEnd = now()->endOfWeek()->format('Y-m-d');

        $todayAssignments = $this->assignmentRepository->getByUserAndDateRange($userId, $today, $today);
        $weekAssignments = $this->assignmentRepository->getByUserAndDateRange($userId, $weekStart, $weekEnd);
        $weekTotal = $weekAssignments->count();

        return compact('todayAssignments', 'weekAssignments', 'weekTotal');
    }
}
