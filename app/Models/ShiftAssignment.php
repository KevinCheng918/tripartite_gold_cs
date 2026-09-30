<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 排班紀錄 Model
 *
 * 每筆紀錄代表一位員工在某日被指派（或自行報班）的班別。
 * 同一位員工同一天只能有一筆排班（unique: user_id + date）。
 *
 * @property int    $id
 * @property int    $user_id  員工 ID
 * @property int    $shift_id 班別 ID
 * @property string $date     排班日期（Y-m-d）
 */
class ShiftAssignment extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'date' => 'date',
    ];

    /**
     * 所屬員工
     *
     * @return BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class)->select(['id', 'account', 'nickname', 'status']);
    }

    /**
     * 所屬班別
     *
     * @return BelongsTo
     */
    public function shift()
    {
        /*
         * ⚠ reply_start_time / reply_end_time 一定要帶。
         *
         * 「現在該由誰回訊」看的是**回訊時間**，不是上下班時間 ——
         * 上班時間普遍是 12 小時、彼此大量重疊（早班 08–20、午班 10–22、
         * 晚班 12–00），15:00 用上班時間算會同時撈到三個人。
         *
         * 少了這兩欄，`isTimeInShiftRange()` 的 fallback 會悄悄改用上下班時間，
         * 不會報錯、只會 tag 錯人。
         */
        return $this->belongsTo(Shift::class)->select([
            'id', 'name', 'display_name',
            'start_time', 'end_time',
            'reply_start_time', 'reply_end_time',
        ]);
    }
}
