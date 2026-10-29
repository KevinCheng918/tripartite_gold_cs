<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 打卡提醒的發送狀態
 *
 * @property int         $id
 * @property int         $user_id
 * @property string      $date         出勤日期（跨日班是開始上班那天）
 * @property string      $type         見 TYPE_*
 * @property int         $sent_count   發過幾次
 * @property \Illuminate\Support\Carbon|null $last_sent_at
 */
class AttendanceReminder extends Model
{
    protected $table = 'attendance_reminder';

    protected $guarded = ['id'];

    /**
     * 上班前提醒
     *
     * ⚠ **只發一次**。上班前 15 分鐘提醒過還沒打，再催也沒用 ——
     * 他要嘛在路上、要嘛請假忘了請，兩種都不是多發幾則能解決的。
     *
     * @var string
     */
    public const TYPE_CLOCK_IN = 'clock_in';

    /**
     * 下班後提醒
     *
     * ⚠ **會重複發**：下班後 30 分鐘開始，每 30 分鐘一次，直到他打了
     * 下班卡或超過上限（見 `ClockReminderService`）。
     *
     * 跟上班提醒不同的理由：沒打下班卡是「他人還在、只是忘了按」，
     * 再提醒一次就會去按；沒打上班卡多半是人根本還沒到。
     *
     * @var string
     */
    public const TYPE_CLOCK_OUT = 'clock_out';

    protected $casts = [
        'user_id'      => 'integer',
        'sent_count'   => 'integer',
        'last_sent_at' => 'datetime',
    ];
}
