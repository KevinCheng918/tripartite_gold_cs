<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 撤銷請假的申請
 *
 * ⚠ 跟 `LeaveRequest` 是**兩筆紀錄**，不是同一筆的兩種狀態 ——
 * 需求方 2026-11-24 要「保留請假、撤假紀錄」，兩邊都要查得到。
 *
 * @property int         $id
 * @property int         $leave_request_id
 * @property int         $requested_by
 * @property string|null $reason
 * @property int         $status
 * @property int|null    $reviewed_by
 * @property \Illuminate\Support\Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property bool        $restore_quota
 *
 * 詳見 .claude/knowledge/features/leave-cancel.md
 */
class LeaveCancelRequest extends Model
{
    /** 還沒審 */
    public const STATUS_PENDING = 0;

    /** 撤假通過 —— 原假單會被改成已撤銷 */
    public const STATUS_APPROVED = 1;

    /** 撤假被拒 —— 原假單維持已通過 */
    public const STATUS_REJECTED = 2;

    protected $table = 'leave_cancel_request';

    protected $guarded = ['id'];

    protected $casts = [
        'leave_request_id' => 'integer',
        'requested_by'     => 'integer',
        'status'           => 'integer',
        'reviewed_by'      => 'integer',
        'reviewed_at'      => 'datetime',
        'restore_quota'    => 'boolean',
    ];

    /**
     * 被申請撤銷的那一張假單
     *
     * @return BelongsTo
     */
    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }

    /**
     * 申請撤銷的人
     *
     * @return BelongsTo
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by')
            ->select(['id', 'nickname']);
    }

    /**
     * 審核的人
     *
     * @return BelongsTo
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')
            ->select(['id', 'nickname']);
    }

    /**
     * 還沒被審
     *
     * @return bool
     */
    public function isPending()
    {
        return $this->status === self::STATUS_PENDING;
    }
}
