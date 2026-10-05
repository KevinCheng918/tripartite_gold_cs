<?php

namespace App\Jobs;

use App\Repositories\TelegramRepository;
use App\Services\AutoReplyProgressService;
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
     * @param AutoReplyService         $autoReplyService
     * @param AutoReplyProgressService $progressService
     * @return void
     */
    public function handle(AutoReplyService $autoReplyService, AutoReplyProgressService $progressService)
    {
        /*
         * 客人連著傳好幾句講同一件事時，只有最後一則該回覆 ——
         * 每則都回會把同一個問題答好幾次。
         *
         * 這是第一道（開始前）：客人在排隊等待期間又說話了，這則就不必跑了。
         * **連 AI 都不呼叫**，省一次 CLI。
         *
         * 答案的完整性不受影響：最後那則回覆時，脈絡本來就會帶上前面幾則
         * （`auto_reply.context`，前 6 則／15 分鐘），所以不需要把文字合併。
         */
        if ($this->supersededBy('開始前')) {
            return;
        }

        $progressService->start($this->groupId);

        try {
            $autoReplyService->handle($this->groupId, $this->text, $this->messageId);
        } finally {
            // 不管成功、失敗、丟例外都要收掉 —— 少了 finally，
            // Claude 一失敗畫面就會卡在「AI 回覆中」直到 TTL 過期
            $progressService->finish($this->groupId);
        }
    }

    /**
     * 客人在這則之後又說話了嗎
     *
     * ⚠ **兩個呼叫點分工不同**：
     *
     * - 開始前：省掉整次 AI 呼叫
     * - 真正要發送之前（`AutoReplyService`）：CLI 要跑數秒到數十秒，
     *   那段時間客人很可能又補了一句 —— 沒有第二道就還是會回兩次
     *
     * @param string $stage 只用在 log，方便分辨是哪一道擋下的
     * @return bool
     */
    private function supersededBy($stage)
    {
        $superseded = app(TelegramRepository::class)
            ->hasNewerInbound($this->groupId, $this->messageId);

        if ($superseded) {
            Log::info('客人已有更新的訊息，略過這則的自動回覆', [
                'group_id'   => $this->groupId,
                'message_id' => $this->messageId,
                'stage'      => $stage,
            ]);
        }

        return $superseded;
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

        // 逾時是直接被 kill，上面的 finally 不保證跑得到，所以這裡要再收一次。
        // 重複呼叫是安全的
        app(AutoReplyProgressService::class)->finish($this->groupId);
    }
}
