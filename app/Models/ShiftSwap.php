<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 換班紀錄 Model
 *
 * 員工 A（requester）向員工 B（target）發起換班請求，
 * 雙方各有一筆 ShiftAssignment，換班成功後互換 shift_id。
 *
 * @property int $id
 * @property int $requester_id            發起換班的員工 ID
 * @property int $target_id               被換班的員工 ID
 * @property int $requester_assignment_id  發起方的排班紀錄 ID
 * @property int $target_assignment_id     對方的排班紀錄 ID
 * @property int $status                  狀態（0=待確認, 1=已同意, 2=已拒絕）
 */
class ShiftSwap extends Model
{
    use HasFactory;

    /** @var int 待確認 */
    public const STATUS_PENDING = 0;

    /** @var int 已同意 */
    public const STATUS_APPROVED = 1;

    /** @var int 已拒絕 */
    public const STATUS_REJECTED = 2;

    protected $guarded = ['id'];

    protected $casts = [
        'status' => 'integer',
    ];

    /**
     * 發起換班的員工
     *
     * @return BelongsTo
     */
    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_id')->select(['id', 'account', 'nickname']);
    }

    /**
     * 被換班的員工
     *
     * @return BelongsTo
     */
    public function target()
    {
        return $this->belongsTo(User::class, 'target_id')->select(['id', 'account', 'nickname']);
    }

    /**
     * 發起方的排班紀錄
     *
     * @return BelongsTo
     */
    public function requesterAssignment()
    {
        return $this->belongsTo(ShiftAssignment::class, 'requester_assignment_id');
    }

    /**
     * 對方的排班紀錄
     *
     * @return BelongsTo
     */
    public function targetAssignment()
    {
        return $this->belongsTo(ShiftAssignment::class, 'target_assignment_id');
    }
}
