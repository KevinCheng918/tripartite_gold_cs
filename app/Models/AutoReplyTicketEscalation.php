<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 求助單升級紀錄 Model
 *
 * 催到升級門檻還沒人處理時，每一次升級動作（撥號、私訊）一列。
 *
 * ⚠ `result` 的「成功」只代表**動作送出去了**，不代表人被叫醒或事情被處理 ——
 * 電話接起來會斷線、私訊讀不到已讀。真正的判準一律是求助單自己的 status。
 *
 * 詳見 .claude/knowledge/features/ticket-overdue-escalation.md
 *
 * @property int         $id
 * @property int         $auto_reply_ticket_id
 * @property int         $stage     見 constants.TICKET_ESCALATION.STAGE
 * @property int         $seq       觸發時那張單已經催到第幾次
 * @property int         $attempt   同一階段的第幾次（撥號 1-3，私訊固定 1）
 * @property int|null    $user_id
 * @property string|null $user_name
 * @property int         $result    見 constants.TICKET_ESCALATION.RESULT
 * @property string|null $detail
 * @property Carbon      $created_at
 */
class AutoReplyTicketEscalation extends Model
{
    protected $table = 'auto_reply_ticket_escalation';
    protected $guarded = ['id'];

    protected $casts = [
        'auto_reply_ticket_id' => 'integer',
        'stage'                => 'integer',
        'seq'                  => 'integer',
        'attempt'              => 'integer',
        'user_id'              => 'integer',
        'result'               => 'integer',
    ];

    /**
     * 哪一張求助單
     *
     * @return BelongsTo
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(AutoReplyTicket::class, 'auto_reply_ticket_id')
            ->select(['id', 'telegram_group_id', 'question', 'status', 'remind_count', 'created_at']);
    }

    /**
     * 通知／撥打的對象
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')
            ->select(['id', 'nickname', 'telegram_username']);
    }
}
