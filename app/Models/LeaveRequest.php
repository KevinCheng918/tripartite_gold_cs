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

    protected $casts = [
        'start_date'  => 'date',
        'end_date'    => 'date',
        'is_full_day' => 'integer',
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
