<?php

namespace App\Repositories;

use App\Criteria\CriteriaInterface;
use App\Models\ShiftAssignment;
use App\Models\ShiftSwap;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * 排班紀錄 Repository
 *
 * 負責 shift_assignments 與 shift_swaps 表的所有 DB 操作。
 */
class ShiftAssignmentRepository
{
    /** @var array 列表查詢欄位 */
    private const LIST_COLUMNS = ['id', 'user_id', 'shift_id', 'date', 'created_at'];

    /**
     * 依條件分頁查詢排班紀錄
     *
     * @param array $criteria
     * @param int   $perPage
     * @return LengthAwarePaginator
     */
    public function paginate($criteria = [], $perPage = 20)
    {
        $query = ShiftAssignment::query()
            ->select(self::LIST_COLUMNS)
            ->with(['user', 'shift']);

        foreach ($criteria as $criterion) {
            $query = $criterion->apply($query);
        }

        return $query->orderByDesc('date')->orderByDesc('id')->paginate($perPage);
    }

    /**
     * 依條件查詢排班紀錄（不分頁）
     *
     * @param array $criteria
     * @return Collection
     */
    public function getByCriteria($criteria = [])
    {
        $query = ShiftAssignment::query()
            ->select(self::LIST_COLUMNS)
            ->with(['user', 'shift']);

        foreach ($criteria as $criterion) {
            $query = $criterion->apply($query);
        }

        return $query->orderBy('date')->get();
    }

    /**
     * 依 ID 查詢排班紀錄
     *
     * @param int $id
     * @return ShiftAssignment|null
     */
    public function find($id)
    {
        return ShiftAssignment::query()
            ->select(self::LIST_COLUMNS)
            ->with(['user', 'shift'])
            ->find($id);
    }

    /**
     * 檢查員工在該日是否已有排班
     *
     * @param int      $userId
     * @param string   $date      日期（Y-m-d）
     * @param int|null $excludeId 排除的排班 ID（用於換班檢查）
     * @return bool
     */
    public function existsByUserAndDate($userId, $date, $excludeId = null)
    {
        $query = ShiftAssignment::query()
            ->where('user_id', $userId)
            ->where('date', $date);

        if (filled($excludeId)) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /**
     * 檢查員工在該日是否已有同一班別
     *
     * @param int    $userId
     * @param string $date
     * @param int    $shiftId
     * @return bool
     */
    public function existsByUserDateAndShift($userId, $date, $shiftId)
    {
        return ShiftAssignment::query()
            ->where('user_id', $userId)
            ->where('date', $date)
            ->where('shift_id', $shiftId)
            ->exists();
    }

    /**
     * 新增排班紀錄
     *
     * @param array $attributes
     * @return ShiftAssignment
     */
    public function create($attributes)
    {
        return ShiftAssignment::query()->create($attributes);
    }

    /**
     * 更新排班紀錄
     *
     * @param ShiftAssignment $assignment
     * @param array           $attributes
     * @return ShiftAssignment
     */
    public function update(ShiftAssignment $assignment, $attributes)
    {
        $assignment->update($attributes);

        return $assignment;
    }

    /**
     * 依日期範圍查詢排班紀錄
     *
     * @param string $dateFrom
     * @param string $dateTo
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getByDateRange($dateFrom, $dateTo)
    {
        return ShiftAssignment::query()
            ->select(self::LIST_COLUMNS)
            ->with(['user', 'shift'])
            ->whereBetween('date', [$dateFrom, $dateTo])
            ->orderBy('date')
            ->get();
    }

    /**
     * 這段期間有排班的人（只回 user_id，去重）
     *
     * 打卡報表用來補人：有排班卻整段期間沒打卡、也沒請假的人，
     * 原本整列都不會出現（見 `AttendanceReportService::collect()`）。
     *
     * ⚠ **不要改用 `getByDateRange()` 再自己 pluck**：那一支會 eager load
     * `user` 與 `shift`、撈回整批 model —— 一個月的排班是幾百筆，
     * 而這裡只需要一串 id。`distinct` 讓資料庫去重，回來的是純量陣列。
     *
     * ⚠ **不濾離職／停用**：查歷史區間時，當時在職、現在已離職的人
     * 本來就該出現在那一期的報表裡。要不要排除是呼叫端的事。
     *
     * @param string $dateFrom Y-m-d
     * @param string $dateTo   Y-m-d（含當天）
     * @return array<int>
     */
    public function getUserIdsByDateRange($dateFrom, $dateTo)
    {
        $ids = ShiftAssignment::query()
            ->distinct()
            ->whereBetween('date', [$dateFrom, $dateTo])
            ->pluck('user_id')
            ->all();

        return array_map('intval', $ids);
    }

    /**
     * 一段日期內「哪一天的哪一班有哪些人」（交接提醒整輪只查一次）
     *
     * ⚠ **存在的理由就是消 N+1。** 交接提醒一輪要問好幾次「某天某班有誰」
     * （每個窗口的上一班與下一班各一次，加上建窗口時的判斷）——
     * 逐窗口各查一次就是那個次數的查詢。
     * 比照 `CustomerFollowupService::askOnDuty()` 的「整輪只查一次」。
     *
     * ⚠ 只回 id：名稱與 Telegram 帳號由呼叫端用
     * `UserRepository::getMentionableByIds()` 一次撈完（那支會濾掉工程、
     * 停用、沒填 Telegram 帳號的）。
     *
     * @param string $dateFrom Y-m-d
     * @param string $dateTo   Y-m-d（含當天）
     * @return array<string, array<int, array<int>>> [Y-m-d][shift_id] => [user_id, ...]
     */
    public function getShiftUserMapByDateRange($dateFrom, $dateTo)
    {
        $rows = ShiftAssignment::query()
            ->select(['date', 'shift_id', 'user_id'])
            ->whereBetween('date', [$dateFrom, $dateTo])
            ->get();

        $map = [];

        foreach ($rows as $row) {
            // `date` 有 cast 成 date，所以一律格式化成 Y-m-d 當 key
            $date = $row->date instanceof \DateTimeInterface
                ? $row->date->format('Y-m-d')
                : (string) $row->date;

            $map[$date][(int) $row->shift_id][] = (int) $row->user_id;
        }

        return $map;
    }

    /**
     * 某人在這幾天排到的班別 id（交接偵測用）
     *
     * 有人在交接話題發言時，要知道他排的是哪一班才算得出那句話屬於
     * 哪一個交接窗口。
     *
     * ⚠ **要吃一個日期區間而不是單日**：大夜班的交接窗口在隔天早上，
     * 那時他的排班紀錄還掛在前一天。呼叫端會傳「昨天到今天」。
     *
     * @param int    $userId
     * @param string $dateFrom Y-m-d
     * @param string $dateTo   Y-m-d（含當天）
     * @return array<int>
     */
    public function getShiftIdsByUserAndDateRange($userId, $dateFrom, $dateTo)
    {
        $ids = ShiftAssignment::query()
            ->distinct()
            ->where('user_id', $userId)
            ->whereBetween('date', [$dateFrom, $dateTo])
            ->pluck('shift_id')
            ->all();

        return array_map('intval', $ids);
    }

    /**
     * 依員工和日期範圍查詢排班紀錄
     *
     * @param int    $userId
     * @param string $dateFrom
     * @param string $dateTo
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getByUserAndDateRange($userId, $dateFrom, $dateTo)
    {
        return ShiftAssignment::query()
            ->select(self::LIST_COLUMNS)
            ->with(['shift'])
            ->where('user_id', $userId)
            ->whereBetween('date', [$dateFrom, $dateTo])
            ->orderBy('date')
            ->get();
    }

    /**
     * 一整批人在某一天的排班
     *
     * ⚠ 打卡提醒每分鐘跑一次，逐人查排班是一天幾萬次查詢 ——
     * 這支一次把所有人當天的排班撈回來（見
     * `AttendanceService::resolveWorkTimes()`）。
     *
     * ⚠ `with('shift')` 不能省：上下班時間在班別上，沒有 eager load
     * 就變成「省了一次查詢、換來每人一次」。
     *
     * @param array<int> $userIds
     * @param string     $date Y-m-d
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getByUserIdsAndDate($userIds, $date)
    {
        $userIds = array_values(array_filter((array) $userIds));

        // 短路：沒有人就不必下查詢（whereIn 空陣列在不同版本行為不一）
        if (blank($userIds)) {
            return ShiftAssignment::query()->whereRaw('1 = 0')->get();
        }

        return ShiftAssignment::query()
            ->select(self::LIST_COLUMNS)
            ->with(['shift'])
            ->whereIn('user_id', $userIds)
            ->where('date', $date)
            ->get();
    }

    /**
     * 刪除排班紀錄
     *
     * @param ShiftAssignment $assignment
     * @return bool
     */
    public function delete(ShiftAssignment $assignment)
    {
        return (bool) $assignment->delete();
    }

    /**
     * 新增換班請求
     *
     * @param array $attributes
     * @return ShiftSwap
     */
    /**
     * 檢查是否已有相同的待確認換班紀錄
     *
     * @param int $requesterAssignmentId
     * @param int $targetAssignmentId
     * @return bool
     */
    /**
     * 檢查某人在某日是否已有其他排班（排除指定 assignment）
     *
     * @param int    $userId
     * @param string $date
     * @param int    $excludeAssignmentId
     * @return bool
     */
    public function hasOtherAssignmentOnDate($userId, $date, $excludeAssignmentId)
    {
        return ShiftAssignment::query()
            ->where('user_id', $userId)
            ->where('date', $date)
            ->where('id', '!=', $excludeAssignmentId)
            ->exists();
    }

    /**
     * 這兩筆排班之間有沒有還沒處理完的換班申請
     *
     * @param int $requesterAssignmentId 提出換班的那一筆排班
     * @param int $targetAssignmentId    想換到的那一筆排班
     * @return bool
     */
    public function hasPendingSwap($requesterAssignmentId, $targetAssignmentId)
    {
        return ShiftSwap::query()
            ->where('requester_assignment_id', $requesterAssignmentId)
            ->where('target_assignment_id', $targetAssignmentId)
            ->where('status', ShiftSwap::STATUS_PENDING)
            ->exists();
    }

    /**
     * 建立換班申請
     *
     * @param array $attributes
     * @return \App\Models\ShiftSwap
     */
    public function createSwap($attributes)
    {
        return ShiftSwap::query()->create($attributes);
    }

    /**
     * 依 ID 查詢換班請求（含關聯）
     *
     * @param int $id
     * @return ShiftSwap|null
     */
    public function findSwap($id)
    {
        return ShiftSwap::query()
            ->with(['requester', 'target', 'requesterAssignment.shift', 'targetAssignment.shift'])
            ->find($id);
    }

    /**
     * 查詢所有換班請求（管理者用）
     *
     * @param int $perPage
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginateAllSwaps($perPage = 20)
    {
        return ShiftSwap::query()
            ->with(['requester', 'target', 'requesterAssignment.shift', 'targetAssignment.shift'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * 查詢與指定員工相關的換班請求（發起或被請求）
     *
     * @param int $userId
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function paginateSwapsByUser($userId, $perPage = 20)
    {
        return ShiftSwap::query()
            ->with(['requester', 'target', 'requesterAssignment.shift', 'targetAssignment.shift'])
            ->where(function ($q) use ($userId) {
                $q->where('requester_id', $userId)
                    ->orWhere('target_id', $userId);
            })
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * 更新換班請求狀態
     *
     * @param ShiftSwap $swap
     * @param int       $status
     * @return ShiftSwap
     */
    public function updateSwapStatus(ShiftSwap $swap, $status)
    {
        $swap->update(['status' => $status]);

        return $swap;
    }
}
