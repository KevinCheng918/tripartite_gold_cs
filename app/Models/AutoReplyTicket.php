<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 自動回覆求助單 Model
 *
 * 題庫裡找不到答案時開一張單，轉到內部支援群組請自己人回答，
 * 再用按鈕決定要回覆客人／加入題庫。
 *
 * ask_message_id 是整條迴路的對應鍵 —— 內部群組同時有好幾張單在跑時，
 * 只有 Telegram 的 reply_to_message.message_id 能把答案精準對回原本那張單。
 *
 * @property int         $id
 * @property int         $telegram_group_id
 * @property int|null    $message_id          客人那則訊息（TTL 清理後為 null）
 * @property string      $question            客人問題原文（快照）
 * @property int|null    $ask_message_id      求助訊息在內部群組的 telegram message_id
 * @property string|null $answer              自己人的回答原文
 * @property string|null $answered_by         回答者的 Telegram 顯示名稱
 * @property string|null $answered_at
 * @property int         $remind_count        見 constants.AUTO_REPLY.REMIND
 * @property string|null $last_reminded_at
 * @property int         $status              見 constants.AUTO_REPLY.TICKET_STATUS
 * @property int|null    $quick_reply_item_id 加入題庫後的題目 id
 */
class AutoReplyTicket extends Model
{
    protected $table = 'auto_reply_ticket';
    protected $guarded = ['id'];

    protected $casts = [
        'telegram_group_id'   => 'integer',
        'message_id'          => 'integer',
        'ask_message_id'      => 'integer',
        'answered_at'         => 'datetime',
        'remind_count'        => 'integer',
        'last_reminded_at'    => 'datetime',
        'status'              => 'integer',
        'quick_reply_item_id' => 'integer',
    ];

    /**
     * 客人所在群組
     *
     * @return BelongsTo
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(TelegramGroup::class, 'telegram_group_id')
            ->select(['id', 'chat_id', 'title']);
    }

    /**
     * 加入題庫後產生的題目
     *
     * 刻意不設外鍵，所以這裡查不到（題目被刪）是正常情況，不是資料損毀。
     *
     * @return BelongsTo
     */
    public function quickReplyItem(): BelongsTo
    {
        return $this->belongsTo(QuickReplyItem::class, 'quick_reply_item_id')
            ->select(['id', 'category_id', 'label', 'answer']);
    }

    /**
     * 是否還在等自己人回答
     *
     * @return bool
     */
    public function isPending()
    {
        return $this->status == config('constants.AUTO_REPLY.TICKET_STATUS.PENDING');
    }

    /**
     * 是否已處理完畢（已回客人／已入題庫／已忽略）
     *
     * @return bool
     */
    public function isClosed()
    {
        $closed = [
            config('constants.AUTO_REPLY.TICKET_STATUS.REPLIED'),
            config('constants.AUTO_REPLY.TICKET_STATUS.SAVED'),
            config('constants.AUTO_REPLY.TICKET_STATUS.IGNORED'),
        ];

        return in_array($this->status, $closed, false);
    }
}
