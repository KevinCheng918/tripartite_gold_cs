<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/**
 * Telegram 客服正在輸入事件
 */
class TelegramTyping implements ShouldBroadcast
{
    /** @var int */
    public $groupId;

    /** @var string */
    public $nickname;

    /**
     * @param int    $groupId
     * @param string $nickname
     */
    public function __construct($groupId, $nickname)
    {
        $this->groupId = $groupId;
        $this->nickname = $nickname;
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
        return 'telegram.typing';
    }
}
