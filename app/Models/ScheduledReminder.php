<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * 預約提醒
 *
 * 在內部群組 tag 機器人說「什麼時候提醒我什麼」，時間到了原文發回同一個話題。
 *
 * @property int         $id
 * @property int         $chat_id
 * @property int|null    $message_thread_id      null = 群組主區（General）
 * @property string      $content                提醒內容（原文，含 @帳號）
 * @property \Carbon\Carbon $remind_at
 * @property int         $status                 見 constants.REMINDER.STATUS
 * @property string|null $sent_at
 * @property string|null $created_by             預約的人（Telegram 顯示名）
 * @property int|null    $created_by_telegram_id
 */
class ScheduledReminder extends Model
{
    use HasFactory;

    protected $table = 'scheduled_reminder';

    protected $guarded = ['id'];

    protected $casts = [
        'chat_id'           => 'integer',
        'message_thread_id' => 'integer',
        'remind_at'         => 'datetime',
        'status'            => 'integer',
        'sent_at'           => 'datetime',
    ];

    /**
     * 還沒發也沒被取消
     *
     * @return bool
     */
    public function isPending()
    {
        return $this->status === (int) config('constants.REMINDER.STATUS.PENDING');
    }
}
