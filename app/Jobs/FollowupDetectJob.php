<?php

namespace App\Jobs;

use App\Services\CustomerFollowupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 等待詞偵測工作
 *
 * 丟佇列的理由跟 `AutoReplyJob` 一樣：命中詞表之後要叫模型確認，
 * 那是數秒到數十秒的 CLI —— webhook 同步等下去 Telegram 會逾時重送。
 *
 * ⚠ **詞表沒命中就不會叫模型**，所以絕大多數訊息在這支裡面是毫秒級的。
 * 丟佇列是為了那少數命中的。
 *
 * ⚠️ **不重試**（tries = 1）：這支可能會開一筆待追蹤事項，重試等於
 * 同一句話記兩筆（去重只擋「同對話同來源還活著」的，兩次都在同一瞬間時
 * 擋不住）。漏記一筆比重複記一筆好收拾。
 */
class FollowupDetectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int 不重試，理由見類別註解 */
    public $tries = 1;

    /** @var int 秒。要比 CLI 的 timeout 寬一些，讓 CLI 有機會自己逾時並被記錄 */
    public $timeout = 120;

    /** @var int 對話群組 id */
    protected $groupId;

    /** @var int|null 那則訊息的後台 id */
    protected $messageId;

    /** @var string 訊息內容 */
    protected $text;

    /**
     * 判斷來源要用的那幾個欄位
     *
     * direction / is_auto / from / sender_name / sender_user_id / group_id。
     * **只放純量與陣列** —— Job 會被序列化，塞 Model 進來會連帶把整個
     * 物件寫進佇列。
     *
     * @var array
     */
    protected $context;

    /**
     * @param int      $groupId
     * @param int|null $messageId
     * @param string   $text
     * @param array    $context
     */
    public function __construct($groupId, $messageId, $text, array $context)
    {
        $this->groupId = $groupId;
        $this->messageId = $messageId;
        $this->text = $text;
        $this->context = $context;
    }

    /**
     * @param CustomerFollowupService $followupService
     * @return void
     */
    public function handle(CustomerFollowupService $followupService)
    {
        $followupService->handleMessage(
            $this->groupId,
            $this->messageId,
            $this->text,
            $this->context
        );
    }

    /**
     * 工作失敗（含逾時）時的處理
     *
     * ⚠ 失敗**只記 log、不補救**：待追蹤事項是輔助功能，漏記一筆的代價是
     * 那件事回到「靠人記得」，而不是客人收到奇怪的訊息。
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed($exception)
    {
        Log::error('等待詞偵測工作失敗', [
            'group_id'   => $this->groupId,
            'message_id' => $this->messageId,
            'error'      => $exception->getMessage(),
        ]);
    }
}
