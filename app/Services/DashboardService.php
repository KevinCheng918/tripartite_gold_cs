<?php

namespace App\Services;

use App\Repositories\ShiftAssignmentRepository;
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

    public function __construct(
        UserRepository $userRepository,
        ShiftAssignmentRepository $assignmentRepository
    ) {
        $this->userRepository = $userRepository;
        $this->assignmentRepository = $assignmentRepository;
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

        // 今日排班 — 依班別分組（早班是誰、午班是誰、晚班是誰）
        $todayAssignments = $this->assignmentRepository->getByDateRange($today, $today);

        $todayByShift = $todayAssignments->groupBy(function ($a) {
            return $a->shift ? $a->shift->display_name : '-';
        })->map(function ($group) {
            return [
                'shift'    => $group->first()->shift,
                'users'    => $group->map(function ($a) {
                    return $a->user ? $a->user->nickname : '-';
                })->values()->all(),
            ];
        });

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
