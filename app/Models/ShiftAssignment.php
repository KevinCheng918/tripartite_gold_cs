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
        return $this->belongsTo(Shift::class)->select(['id', 'name', 'display_name', 'start_time', 'end_time']);
    }
}
