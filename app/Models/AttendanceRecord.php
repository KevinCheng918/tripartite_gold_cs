<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 打卡紀錄 Model
 *
 * 每筆紀錄代表一位員工某日的上下班打卡，
 * 含 IP、裝置、遲到/早退/加班分鐘數。
 *
 * @property int         $id
 * @property int         $user_id             員工 ID
 * @property int|null    $assignment_id       對應的排班紀錄 ID
 * @property string      $date                打卡日期
 * @property string|null $clock_in            上班打卡時間
 * @property string|null $clock_out           下班打卡時間
 * @property string|null $clock_in_ip         上班打卡 IP
 * @property string|null $clock_out_ip        下班打卡 IP
 * @property string|null $clock_in_device     上班打卡裝置
 * @property string|null $clock_out_device    下班打卡裝置
 * @property int         $late_minutes        遲到分鐘數
 * @property int         $early_leave_minutes 早退分鐘數
 * @property int         $overtime_minutes    加班分鐘數
 * @property int         $status              狀態（0=未完成, 1=正常, 2=遲到, 3=早退, 4=遲到+早退, 5=曠工）
 */
class AttendanceRecord extends Model
{
    /** @var int 未完成（只打了上班卡） */
    public const STATUS_INCOMPLETE = 0;

    /** @var int 正常 */
    public const STATUS_NORMAL = 1;

    /** @var int 遲到 */
    public const STATUS_LATE = 2;

    /** @var int 早退 */
    public const STATUS_EARLY_LEAVE = 3;

    /** @var int 遲到 + 早退 */
    public const STATUS_LATE_AND_EARLY = 4;

    /** @var int 曠工 */
    public const STATUS_ABSENT = 5;

    protected $guarded = ['id'];

    protected $casts = [
        'date'                => 'date',
        'clock_in'            => 'datetime',
        'clock_out'           => 'datetime',
        'late_minutes'        => 'integer',
        'early_leave_minutes' => 'integer',
        'overtime_minutes'    => 'integer',
        'status'              => 'integer',
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
     * 對應的排班紀錄
     *
     * @return BelongsTo
     */
    public function assignment()
    {
        return $this->belongsTo(ShiftAssignment::class, 'assignment_id');
    }
}
