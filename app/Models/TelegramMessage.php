<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Telegram 訊息 Model
 *
 * 儲存收到和發送的 Telegram 訊息。
 *
 * @property int         $id
 * @property int         $telegram_group_id  所屬群組 ID
 * @property int         $direction          1=收（inbound）, 2=發（outbound）
 * @property int|null    $telegram_message_id Telegram 原始 message_id
 * @property string      $sender_name        發送者名稱
 * @property int|null    $sender_user_id     後台發送者 ID（僅 outbound）
 * @property string      $content            訊息內容
 * @property bool        $replied            是否已回覆（僅 inbound 有意義）
 */
class TelegramMessage extends Model
{
    protected $table = 'telegram_message';
    protected $guarded = ['id'];

    protected $casts = [
        'direction' => 'integer',
        'replied'   => 'boolean',
    ];

    /**
     * 所屬群組
     *
     * @return BelongsTo
     */
    public function group()
    {
        return $this->belongsTo(TelegramGroup::class, 'telegram_group_id');
    }

    /**
     * 後台發送者（僅 outbound）
     *
     * @return BelongsTo
     */
    public function senderUser()
    {
        return $this->belongsTo(User::class, 'sender_user_id')->select(['id', 'account', 'nickname']);
    }
}
