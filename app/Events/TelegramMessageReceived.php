<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

/**
 * Telegram 新訊息事件（Broadcasting）
 *
 * 收到或發送訊息時觸發，即時推送到前端。
 */
class TelegramMessageReceived implements ShouldBroadcast
{
    use SerializesModels;

    /** @var int 群組 ID */
    public $groupId;

    /** @var array 訊息資料 */
    public $message;

    /**
     * @param int   $groupId
     * @param array $message
     */
    public function __construct($groupId, $message)
    {
        $this->groupId = $groupId;
        $this->message = $message;
    }

    /**
     * @return Channel
     */
    public function broadcastOn()
    {
        return new Channel('telegram-chat');
    }

    /**
     * @return string
     */
    public function broadcastAs()
    {
        return 'telegram.message';
    }
}
