<?php

namespace App\Jobs;

use App\Repositories\AutoReplyTicketRepository;
use App\Services\AutoReplySupportService;
use App\Services\SupportGroupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 把求助單的答案轉給客人
 *
 * **為什麼要丟背景**：webhook 不該等 Telegram 送訊的往返。同仁按
 * 「回覆並加入題庫」之後要能**立刻**看到類別選單，不是等答案送完才出現
 * （2026-10-06 需求方回報「這個按鈕跑很慢」）。
 *
 * ⚠ **不重試**（`$tries = 1`）：重試等於再發一次給客人。寧可漏掉一則由
 * 同仁手動補，也不要客人收到兩則一樣的答案。
 */
class ReplyTicketJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int 不重試，理由見類別註解 */
    public $tries = 1;

    /** @var int 秒。只是發一則 Telegram 訊息，不需要太久 */
    public $timeout = 60;

    /** @var int 求助單 id */
    protected $ticketId;

    /**
     * @param int $ticketId
     */
    public function __construct($ticketId)
    {
        $this->ticketId = (int) $ticketId;
    }

    /**
     * @param AutoReplySupportService   $supportService
     * @param AutoReplyTicketRepository $ticketRepository
     * @return void
     */
    public function handle(AutoReplySupportService $supportService, AutoReplyTicketRepository $ticketRepository)
    {
        $ticket = $ticketRepository->find($this->ticketId);

        if (blank($ticket)) {
            Log::warning('求助單不見了，答案沒有轉給客人', ['ticket_id' => $this->ticketId]);

            return;
        }

        if ($supportService->sendTicketAnswer($ticket)) {
            return;
        }

        /*
         * 失敗要讓同仁知道 —— 這時按鈕那邊早就顯示成功了（它不等結果），
         * 不講的話沒有人會發現客人根本沒收到。
         */
        $this->notifyFailure($ticket->id);
    }

    /**
     * 工作整個失敗（含逾時）時的處理
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed($exception)
    {
        Log::error('轉答案給客人的工作失敗', [
            'ticket_id' => $this->ticketId,
            'error'     => $exception->getMessage(),
        ]);

        $this->notifyFailure($this->ticketId);
    }

    /**
     * 到內部群組說一聲「這張單沒送出去」
     *
     * @param int $ticketId
     * @return void
     */
    private function notifyFailure($ticketId)
    {
        try {
            app(SupportGroupService::class)->send(
                strtr((string) config('constants.AUTO_REPLY.REPLY_FAILED_TEXT'), ['{id}' => $ticketId])
            );
        } catch (\Exception $e) {
            // 連通知都發不出去就只剩 log 了
            Log::error('轉答案失敗的通知也沒發出去', [
                'ticket_id' => $ticketId,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
