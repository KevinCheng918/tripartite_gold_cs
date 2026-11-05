<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 請假申請 Model
 *
 * @property int         $id
 * @property int         $user_id
 * @property string      $start_date
 * @property string      $end_date
 * @property string      $type          見 TYPE_*
 * @property int         $minutes       這張單總共幾分鐘（整天假已換算）
 * @property int         $is_full_day   1=整天, 0=時段
 * @property string|null $start_time
 * @property string|null $end_time
 * @property string|null $reason
 * @property int         $status        0=待審核, 1=通過, 2=拒絕
 * @property int|null    $reviewed_by
 * @property string|null $reviewed_at
 * @property string|null $review_note
 */
class LeaveRequest extends Model
{
    protected $table = 'leave_request';

    protected $guarded = ['id'];

    /** @var int 待審核 */
    public const STATUS_PENDING = 0;
    /** @var int 通過 */
    public const STATUS_APPROVED = 1;
    /** @var int 拒絕 */
    public const STATUS_REJECTED = 2;

    /** @var string 特休（會扣額度）*/
    public const TYPE_ANNUAL = 'annual';
    /** @var string 病假 */
    public const TYPE_SICK = 'sick';
    /** @var string 事假 */
    public const TYPE_PERSONAL = 'personal';

    /**
     * 改版前就存在的假單
     *
     * ⚠ **不是給人選的**：申請表單上不會出現它。它只是用來標出「這張單是
     * 2026-11-05 加假別之前就有的」—— 填特休會讓歷史假單去扣現在的額度，
     * 填事假則會被誤讀成「他當時真的請了事假」。
     *
     * @var string
     */
    public const TYPE_UNCLASSIFIED = 'unclassified';

    protected $casts = [
        'start_date'  => 'date',
        'end_date'    => 'date',
        'is_full_day' => 'integer',
        'minutes'     => 'integer',
        'status'      => 'integer',
        'reviewed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->select(['id', 'nickname']);
    }

    /**
     * @return BelongsTo
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->select(['id', 'nickname']);
    }
}
