<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/**
 * 自動回覆進行中／結束事件
 *
 * 與「正在輸入」（TelegramTyping）不同：那個是節流式的，收到就顯示、幾秒後自動隱藏；
 * 這個有明確的開始與結束，中間可能數十秒完全沒有事件，前端不能自己把提示收掉。
 */
class AutoReplyProgress implements ShouldBroadcast
{
    /** @var int 對話群組 id */
    public $groupId;

    /** @var bool true=開始回覆，false=結束 */
    public $running;

    /**
     * @param int  $groupId
     * @param bool $running
     */
    public function __construct($groupId, $running)
    {
        $this->groupId = (int) $groupId;
        $this->running = (bool) $running;
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
        return 'auto-reply.progress';
    }
}
