<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Telegram 群組 Model
 *
 * 每個 Telegram 群組對應一個獨立的客服對話。
 *
 * @property int         $id
 * @property int         $chat_id          Telegram 群組 chat ID
 * @property string      $title            群組名稱
 * @property int         $status           1=啟用, 0=封存
 * @property int|null    $assigned_user_id 值班客服 ID
 * @property string|null $last_message_at  最後訊息時間
 */
class TelegramGroup extends Model
{
    protected $table = 'telegram_group';
    protected $guarded = ['id'];

    protected $casts = [
        'chat_id'         => 'integer',
        'status'          => 'integer',
        'last_message_at' => 'datetime',
    ];

    /**
     * 值班客服
     *
     * @return BelongsTo
     */
    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_user_id')->select(['id', 'account', 'nickname']);
    }

    /**
     * 群組訊息
     *
     * @return HasMany
     */
    public function messages()
    {
        return $this->hasMany(TelegramMessage::class, 'telegram_group_id');
    }
}
