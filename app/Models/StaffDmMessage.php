<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 同仁私訊索引 Model
 *
 * 機器人發出的每一則同仁私訊記一筆 message_id，唯一用途是「將來刪得掉」。
 * **不存內容**（理由見 migration）。
 *
 * 詳見 .claude/knowledge/features/telegram-dm-wipe.md
 *
 * @property int         $id
 * @property int|null    $user_id
 * @property int         $telegram_chat_id
 * @property int         $telegram_message_id
 * @property string|null $notice_key
 * @property int|null    $result  見 constants.STAFF_DM_WIPE.RESULT；null＝還沒處理
 * @property Carbon|null $wiped_at
 * @property Carbon      $created_at 私訊送出時間
 */
class StaffDmMessage extends Model
{
    protected $table = 'staff_dm_message';

    // 只有 created_at（送出時間），沒有 updated_at —— 比照 login_log
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'telegram_chat_id',
        'telegram_message_id',
        'notice_key',
    ];

    protected $casts = [
        'user_id'             => 'integer',
        'telegram_chat_id'    => 'integer',
        'telegram_message_id' => 'integer',
        'result'              => 'integer',
        'wiped_at'            => 'datetime',
        'created_at'          => 'datetime',
    ];

    /**
     * 收件同仁（帳號刪掉後為 null）
     *
     * @return BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
