<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 交接漏項 Model
 *
 * 同仁打完交接之後，系統盤出「那一班發生過、但交接裡沒提到」的事，
 * 一項一列，用選單問他要不要補上。
 *
 * ⚠ `title` / `detail` 是**快照**：按鈕可能半小時後才被按，那時求助單
 * 早就被處理掉了，重查會查出跟選單上不一樣的話。
 *
 * 詳見 .claude/knowledge/features/handover-gap-review.md
 *
 * @property int         $id
 * @property int         $shift_handover_id
 * @property int         $source_type 見 constants.HANDOVER_REVIEW.SOURCE
 * @property int|null    $source_id   來源主鍵；固定項（主管交代）為 null
 * @property string      $title
 * @property string|null $detail
 * @property bool        $covered     交接內容有沒有提到
 * @property int         $status      見 constants.HANDOVER_REVIEW.STATUS
 * @property int         $seq         選單編號（callback_data 只帶這個）
 */
class ShiftHandoverGap extends Model
{
    protected $table = 'shift_handover_gap';
    protected $guarded = ['id'];

    protected $casts = [
        'shift_handover_id' => 'integer',
        'source_type'       => 'integer',
        'source_id'         => 'integer',
        'covered'           => 'boolean',
        'status'            => 'integer',
        'seq'               => 'integer',
    ];

    /**
     * 這一項屬於哪一次交接
     *
     * @return BelongsTo
     */
    public function handover(): BelongsTo
    {
        return $this->belongsTo(ShiftHandover::class, 'shift_handover_id')
            ->select(['id', 'from_shift_id', 'handed_by_user_id', 'handed_by_name', 'handed_message_id']);
    }

    /**
     * 是不是「主管／老闆交代」那一項
     *
     * ⚠ 它的「補上」行為跟其他項不一樣 —— 系統貼不出事實（口頭交代根本
     * 看不到），按下去是 tag 他請他自己打。
     *
     * @return bool
     */
    public function isManagerAsk()
    {
        return $this->source_type === (int) config('constants.HANDOVER_REVIEW.SOURCE.MANAGER');
    }

    /**
     * 還沒被決定（沒按過補上、也沒按過不用）
     *
     * @return bool
     */
    public function isPending()
    {
        return $this->status === (int) config('constants.HANDOVER_REVIEW.STATUS.PENDING');
    }
}
