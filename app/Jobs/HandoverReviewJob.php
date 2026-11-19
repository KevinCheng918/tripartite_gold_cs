<?php

namespace App\Jobs;

use App\Services\ShiftHandoverReviewService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 盤點這一次交接漏掉了什麼
 *
 * **為什麼要丟背景**：比對要叫 Claude Code CLI，幾秒到十幾秒 ——
 * 而觸發點在 webhook（同仁在交接話題發言的那一刻），同步等下去
 * Telegram 會逾時重送，整段交接流程會跑第二次。
 *
 * ⚠ **不重試**（`$tries = 1`）：重試等於話題裡多一組選單，而且兩組的
 * 編號會各自對到一批資料。真的失敗就讓它失敗 —— 交接本身已經完成了，
 * 漏項盤點是加值，不該為了它把話題洗版。
 *
 * （`ShiftHandoverReviewService::review()` 自己還有一道
 * 「已經盤過就不盤」的防線，擋的是佇列層級的重送。）
 */
class HandoverReviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int 不重試，理由見類別註解 */
    public $tries = 1;

    /** @var int 秒。比對要等模型，比一般 Job 久 */
    public $timeout = 180;

    /** @var int 交接紀錄 id */
    protected $handoverId;

    /** @var string 他打的交接原文 */
    protected $handoverText;

    /**
     * @param int    $handoverId
     * @param string $handoverText
     */
    public function __construct($handoverId, $handoverText)
    {
        $this->handoverId = (int) $handoverId;
        $this->handoverText = (string) $handoverText;
    }

    /**
     * @param ShiftHandoverReviewService $reviewService
     * @return void
     */
    public function handle(ShiftHandoverReviewService $reviewService)
    {
        $reviewService->review($this->handoverId, $this->handoverText);
    }

    /**
     * 工作整個失敗（含逾時）時的處理
     *
     * ⚠ **不通知任何人。** 交接本身已經完成（這支只是加值），
     * 跳出來講「盤點失敗」只會讓剛交接完的人以為自己哪裡沒做好。
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed($exception)
    {
        Log::error('交接漏項盤點失敗', [
            'handover_id' => $this->handoverId,
            'error'       => $exception->getMessage(),
        ]);
    }
}
