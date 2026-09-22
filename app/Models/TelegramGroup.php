<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Telegram 群組 Model
 *
 * 每個 Telegram 群組對應一個獨立的客服對話。
 *
 * @property int         $id
 * @property int         $chat_id             Telegram 群組 chat ID
 * @property string      $title               群組名稱
 * @property int         $status              1=啟用, 0=封存
 * @property int|null    $assigned_user_id    值班客服 ID
 * @property string|null $last_message_at     最後訊息時間
 * @property bool        $auto_reply          自動回覆開關（預設關）
 * @property string|null $auto_reply_at       最後一次自動回覆時間（模板版本、稍等冷卻共用）
 * @property int|null    $auto_reply_item_id  最後命中的題庫 id，null=上次沒有送題庫內容
 * @property string|null $auto_reply_pending  已停用。反問編號選項移除後不再寫入，欄位留著沒有清掉
 */
class TelegramGroup extends Model
{
    protected $table = 'telegram_group';
    protected $guarded = ['id'];

    protected $casts = [
        'chat_id'            => 'integer',
        'status'             => 'integer',
        'last_message_at'    => 'datetime',
        'auto_reply'         => 'boolean',
        'auto_reply_at'      => 'datetime',
        'auto_reply_item_id' => 'integer',
    ];

    /**
     * 此對話是否開啟自動回覆
     *
     * @return bool
     */
    public function isAutoReplyOn()
    {
        return (bool) $this->auto_reply;
    }

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

    /**
     * 對應的站台（反查）
     *
     * @return HasOne
     */
    public function station()
    {
        return $this->hasOne(Station::class, 'telegram_group_id')->select(['id', 'system_id', 'name', 'telegram_group_id']);
    }
}
