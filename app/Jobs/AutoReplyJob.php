<?php

namespace App\Jobs;

use App\Services\AutoReplyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 自動回覆工作
 *
 * Claude Code CLI 要跑數秒到數十秒，webhook 不能同步等 ——
 * 等下去 Telegram 會逾時重送，客人就會收到重複回覆。
 *
 * ⚠️ **不重試**（tries = 1）：這個工作會送訊息給客戶，
 * 重試等於再等一次 CLI，而且有很高機率重複回覆同一句話。
 * 失敗就讓它失敗，客人會收到「稍等」再由人工接手。
 */
class AutoReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int 不重試，理由見類別註解 */
    public $tries = 1;

    /** @var int 秒。要比 CLI 的 timeout 寬一些，讓 CLI 有機會自己逾時並被記錄 */
    public $timeout = 120;

    /** @var int 對話群組 id */
    protected $groupId;

    /** @var string 客人的話 */
    protected $text;

    /** @var int|null 客人那則訊息的後台 id（開求助單用） */
    protected $messageId;

    /**
     * @param int      $groupId
     * @param string   $text
     * @param int|null $messageId
     */
    public function __construct($groupId, $text, $messageId = null)
    {
        $this->groupId = $groupId;
        $this->text = $text;
        $this->messageId = $messageId;
    }

    /**
     * @param AutoReplyService $autoReplyService
     * @return void
     */
    public function handle(AutoReplyService $autoReplyService)
    {
        $autoReplyService->handle($this->groupId, $this->text, $this->messageId);
    }

    /**
     * 工作失敗（含逾時）時的處理
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed($exception)
    {
        Log::error('自動回覆工作失敗', [
            'group_id' => $this->groupId,
            'error'    => $exception->getMessage(),
        ]);
    }
}
