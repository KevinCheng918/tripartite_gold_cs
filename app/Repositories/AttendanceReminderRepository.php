<?php

namespace App\Repositories;

use App\Models\AttendanceReminder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * 打卡提醒的發送狀態
 */
class AttendanceReminderRepository
{
    /**
     * 判斷要用的欄位
     *
     * @var array<string>
     */
    private const STATE_COLUMNS = ['id', 'user_id', 'date', 'type', 'sent_count', 'last_sent_at'];

    /**
     * 一整批人在這幾天的提醒狀態
     *
     * ⚠ **一次撈完**：打卡提醒每分鐘跑一次，逐人查是一天幾萬次查詢。
     *
     * ⚠ 日期要帶**兩天**（今天與昨天）—— 跨日班的下班提醒發生在隔天清晨，
     * 但 `date` 存的是開始上班那天。只查今天的話，夜班的狀態一律查不到，
     * 於是每分鐘都會被當成「還沒發過」而重複發。
     *
     * @param array<int>    $userIds
     * @param array<string> $dates Y-m-d
     * @return array<string, AttendanceReminder> key 是 "{userId}|{date}|{type}"
     */
    public function getStates($userIds, $dates)
    {
        $userIds = array_values(array_filter((array) $userIds));
        $dates = array_values(array_filter((array) $dates));

        if (blank($userIds) || blank($dates)) {
            return [];
        }

        $states = [];

        $rows = AttendanceReminder::query()
            ->select(self::STATE_COLUMNS)
            ->whereIn('user_id', $userIds)
            ->whereIn('date', $dates)
            ->get();

        foreach ($rows as $row) {
            $states[self::key($row->user_id, $row->date, $row->type)] = $row;
        }

        return $states;
    }

    /**
     * 記一次發送（沒有就建，有就累加）
     *
     * ⚠ 用唯一索引當最後一道防線：排程理論上不會重疊
     * （`withoutOverlapping`），真的撞到時 `QueryException` 接住，
     * **不要讓一個人的重複寫入把整輪提醒中斷**。
     *
     * @param int    $userId
     * @param string $date Y-m-d
     * @param string $type
     * @return void
     */
    public function markSent($userId, $date, $type)
    {
        try {
            $state = AttendanceReminder::query()
                ->where('user_id', $userId)
                ->where('date', $date)
                ->where('type', $type)
                ->first();

            if (filled($state)) {
                $state->update([
                    'sent_count'   => (int) $state->sent_count + 1,
                    'last_sent_at' => now(),
                ]);

                return;
            }

            AttendanceReminder::query()->create([
                'user_id'      => $userId,
                'date'         => $date,
                'type'         => $type,
                'sent_count'   => 1,
                'last_sent_at' => now(),
            ]);
        } catch (QueryException $e) {
            Log::warning('打卡提醒狀態寫入失敗', [
                'user_id' => $userId,
                'date'    => $date,
                'type'    => $type,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * 狀態表的 key
     *
     * @param int    $userId
     * @param string $date
     * @param string $type
     * @return string
     */
    public static function key($userId, $date, $type)
    {
        // date 可能是 Carbon 也可能是字串，統一成 Y-m-d 才對得起來
        $date = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : (string) $date;

        return "{$userId}|{$date}|{$type}";
    }
}
