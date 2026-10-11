<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 待追蹤事項的提醒紀錄 Model
 *
 * 每問當班人員一次、每 tag 到一個人就是一列。存在的理由跟
 * `AutoReplyTicketRemind` 一樣：當班人員會隨時段換人，
 * `conversation_followup.remind_count` 答不出「某個人被問了幾次」。
 *
 * @property int      $id
 * @property int      $conversation_followup_id
 * @property int      $seq     這筆事項的第幾次提醒（1 起算）
 * @property int|null $user_id 被 tag 的人（tag 不到人時為 null）
 * @property int      $stage   見 constants.AUTO_REPLY.REMIND.STAGE
 */
class ConversationFollowupRemind extends Model
{
    protected $table = 'conversation_followup_remind';
    protected $guarded = ['id'];

    protected $casts = [
        'conversation_followup_id' => 'integer',
        'seq'                      => 'integer',
        'user_id'                  => 'integer',
        'stage'                    => 'integer',
    ];

    /**
     * 哪一筆待追蹤事項
     *
     * @return BelongsTo
     */
    public function followup(): BelongsTo
    {
        return $this->belongsTo(ConversationFollowup::class, 'conversation_followup_id')
            ->select(['id', 'telegram_group_id', 'source', 'wait_text', 'status', 'wait_count', 'remind_count', 'created_at']);
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
