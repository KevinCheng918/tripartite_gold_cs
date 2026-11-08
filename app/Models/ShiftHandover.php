<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 交接紀錄 Model
 *
 * 一個交接窗口一列。窗口 ＝ 上一班與下一班回訊時間重疊的那一段
 * （需求方刻意設計的一小時）。
 *
 * 詳見 .claude/knowledge/features/shift-handover-topic.md
 *
 * @property int         $id
 * @property string      $date              上一班回訊開始日（查排班用）
 * @property int         $from_shift_id     要打交接事項的那一班
 * @property int         $to_shift_id       要接手的那一班
 * @property Carbon      $window_start      重疊開始＝下一班回訊開始
 * @property Carbon      $window_end        重疊結束＝上一班回訊結束
 * @property Carbon|null $handed_at
 * @property int|null    $handed_by_user_id
 * @property string|null $handed_by_name
 * @property int|null    $handed_message_id 交接那則的 Telegram message_id
 * @property int         $remind_count
 * @property Carbon|null $last_reminded_at
 * @property Carbon|null $notified_next_at
 * @property int         $status            見 constants.SHIFT_HANDOVER_TOPIC.STATUS
 */
class ShiftHandover extends Model
{
    protected $table = 'shift_handover';
    protected $guarded = ['id'];

    protected $casts = [
        'date'              => 'date',
        'from_shift_id'     => 'integer',
        'to_shift_id'       => 'integer',
        'window_start'      => 'datetime',
        'window_end'        => 'datetime',
        'handed_at'         => 'datetime',
        'handed_by_user_id' => 'integer',
        'handed_message_id' => 'integer',
        'remind_count'      => 'integer',
        'last_reminded_at'  => 'datetime',
        'notified_next_at'  => 'datetime',
        'status'            => 'integer',
    ];

    /**
     * 要打交接事項的那一班
     *
     * @return BelongsTo
     */
    public function fromShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'from_shift_id')
            ->select(['id', 'name', 'display_name', 'reply_start_time', 'reply_end_time']);
    }

    /**
     * 要接手的那一班
     *
     * @return BelongsTo
     */
    public function toShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'to_shift_id')
            ->select(['id', 'name', 'display_name', 'reply_start_time', 'reply_end_time']);
    }

}
