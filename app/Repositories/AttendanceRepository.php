<?php

namespace App\Repositories;

use App\Models\AttendanceRecord;
use Illuminate\Database\Eloquent\Collection;

/**
 * 打卡紀錄 Repository
 *
 * 負責 attendance_records 表的所有 DB 操作。
 */
class AttendanceRepository
{
    /** @var array 列表查詢欄位 */
    private const LIST_COLUMNS = [
        'id', 'user_id', 'assignment_id', 'date',
        'clock_in', 'clock_out',
        'clock_in_ip', 'clock_out_ip',
        'clock_in_device', 'clock_out_device',
        'late_minutes', 'early_leave_minutes', 'overtime_minutes',
        'status', 'created_at',
    ];

    /**
     * 依員工和日期查詢打卡紀錄
     *
     * @param int    $userId
     * @param string $date Y-m-d
     * @return AttendanceRecord|null
     */
    public function findByUserAndDate($userId, $date)
    {
        return AttendanceRecord::query()
            ->select(self::LIST_COLUMNS)
            ->where('user_id', $userId)
            ->where('date', $date)
            ->first();
    }

    /**
     * 新增打卡紀錄
     *
     * @param array $attributes
     * @return AttendanceRecord
     */
    public function create($attributes)
    {
        return AttendanceRecord::query()->create($attributes);
    }

    /**
     * 更新打卡紀錄
     *
     * @param AttendanceRecord $record
     * @param array            $attributes
     * @return AttendanceRecord
     */
    public function update(AttendanceRecord $record, $attributes)
    {
        $record->update($attributes);

        return $record;
    }

    /**
     * 查詢指定員工某月的打卡紀錄
     *
     * @param int    $userId
     * @param string $yearMonth Y-m（如 2026-07）
     * @return Collection
     */
    public function getByUserAndMonth($userId, $yearMonth)
    {
        return AttendanceRecord::query()
            ->select(self::LIST_COLUMNS)
            ->with(['assignment.shift'])
            ->where('user_id', $userId)
            ->where('date', 'like', "{$yearMonth}%")
            ->orderBy('date', 'desc')
            ->get();
    }

    /**
     * 查詢所有員工某月的打卡紀錄（管理者用月報表）
     *
     * @param string $yearMonth Y-m
     * @return Collection
     */
    public function getAllByMonth($yearMonth)
    {
        return AttendanceRecord::query()
            ->select(self::LIST_COLUMNS)
            ->with(['user', 'assignment.shift'])
            ->where('date', 'like', "{$yearMonth}%")
            ->orderBy('user_id')
            ->orderBy('date')
            ->get();
    }
}
