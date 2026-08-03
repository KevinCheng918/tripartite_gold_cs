<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

/**
 * Telegram 未回覆告警事件（Broadcasting）
 *
 * 訊息超時未回覆時觸發，前端顯示告警。
 */
class TelegramAlertTriggered implements ShouldBroadcast
{
    use SerializesModels;

    /** @var int 群組 ID */
    public $groupId;

    /** @var string 群組名稱 */
    public $groupTitle;

    /** @var int 最久未回覆的分鐘數 */
    public $minutes;

    /**
     * @param int    $groupId
     * @param string $groupTitle
     * @param int    $minutes
     */
    public function __construct($groupId, $groupTitle, $minutes)
    {
        $this->groupId = $groupId;
        $this->groupTitle = $groupTitle;
        $this->minutes = $minutes;
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
        return 'telegram.alert';
    }
}
