<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 代班紀錄 Model
 *
 * 原班人發起代班申請，指定代班人和時段，
 * 代班人同意後交由管理者審核，三方確認後生效。
 *
 * @property int         $id
 * @property int         $assignment_id           原排班紀錄 ID
 * @property int         $requester_id            原班人（發起者）
 * @property int         $cover_user_id           代班人
 * @property string      $cover_start             代班開始時間
 * @property string      $cover_end               代班結束時間
 * @property string|null $reason                  代班原因
 * @property int         $cover_user_status       代班人回應（0=待確認, 1=同意, 2=拒絕）
 * @property int         $admin_status            管理者審核（0=待審核, 1=核准, 2=駁回）
 * @property int|null    $admin_id                審核的管理者 ID
 * @property string|null $cover_user_responded_at 代班人回應時間
 * @property string|null $admin_responded_at      管理者審核時間
 */
class ShiftCover extends Model
{
    /** @var int 待確認 / 待審核 */
    public const STATUS_PENDING = 0;

    /** @var int 同意 / 核准 */
    public const STATUS_APPROVED = 1;

    /** @var int 拒絕 / 駁回 */
    public const STATUS_REJECTED = 2;

    protected $guarded = ['id'];

    protected $casts = [
        'cover_user_status'       => 'integer',
        'admin_status'            => 'integer',
        'cover_user_responded_at' => 'datetime',
        'admin_responded_at'      => 'datetime',
    ];

    /**
     * 原排班紀錄
     *
     * @return BelongsTo
     */
    public function assignment()
    {
        return $this->belongsTo(ShiftAssignment::class, 'assignment_id');
    }

    /**
     * 原班人（發起者）
     *
     * @return BelongsTo
     */
    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_id')->select(['id', 'account', 'nickname']);
    }

    /**
     * 代班人
     *
     * @return BelongsTo
     */
    public function coverUser()
    {
        return $this->belongsTo(User::class, 'cover_user_id')->select(['id', 'account', 'nickname']);
    }

    /**
     * 審核的管理者
     *
     * @return BelongsTo
     */
    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id')->select(['id', 'account', 'nickname']);
    }

    /**
     * 是否代班人已同意且等待管理者審核
     *
     * @return bool
     */
    public function isPendingAdmin()
    {
        return $this->cover_user_status === self::STATUS_APPROVED
            && $this->admin_status === self::STATUS_PENDING;
    }

    /**
     * 是否完全核准（三方確認）
     *
     * @return bool
     */
    public function isFullyApproved()
    {
        return $this->cover_user_status === self::STATUS_APPROVED
            && $this->admin_status === self::STATUS_APPROVED;
    }
}
