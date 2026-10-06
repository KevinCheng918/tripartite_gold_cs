<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 求助單提醒紀錄 Model
 *
 * 每送出一次提醒、每 tag 到一個人就是一列。存在的理由是「按人統計」——
 * 當班人員會隨時段換人，`auto_reply_ticket.remind_count` 答不出
 * 「某個人被催了幾次」。
 *
 * @property int      $id
 * @property int      $auto_reply_ticket_id
 * @property int      $seq     這張單的第幾次提醒（1 起算）
 * @property int|null $user_id 被 tag 的人（tag 不到人時為 null）
 * @property int      $stage   見 constants.AUTO_REPLY.REMIND.STAGE
 */
class AutoReplyTicketRemind extends Model
{
    protected $table = 'auto_reply_ticket_remind';
    protected $guarded = ['id'];

    protected $casts = [
        'auto_reply_ticket_id' => 'integer',
        'seq'                  => 'integer',
        'user_id'              => 'integer',
        'stage'                => 'integer',
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
     * 被 tag 的人
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->select(['id', 'nickname']);
    }
}
