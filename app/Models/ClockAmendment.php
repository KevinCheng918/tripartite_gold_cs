<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 補打卡申請 Model
 *
 * @property int         $id
 * @property int         $user_id       申請人
 * @property string      $date          補打卡日期
 * @property int         $type          1=補上班卡, 2=補下班卡
 * @property string      $clock_time    申請的打卡時間
 * @property string|null $reason        申請原因
 * @property int         $status        0=待審核, 1=通過, 2=拒絕
 * @property int|null    $reviewed_by   審核人
 * @property string|null $reviewed_at   審核時間
 */
class ClockAmendment extends Model
{
    protected $table = 'clock_amendment';

    protected $guarded = ['id'];

    /** @var int 補上班卡 */
    public const TYPE_CLOCK_IN = 1;

    /** @var int 補下班卡 */
    public const TYPE_CLOCK_OUT = 2;

    /** @var int 待審核 */
    public const STATUS_PENDING = 0;

    /** @var int 通過 */
    public const STATUS_APPROVED = 1;

    /** @var int 拒絕 */
    public const STATUS_REJECTED = 2;

    protected $casts = [
        'type'        => 'integer',
        'status'      => 'integer',
        'date'        => 'date',
        'reviewed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->select(['id', 'account', 'nickname']);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->select(['id', 'account', 'nickname']);
    }
}
