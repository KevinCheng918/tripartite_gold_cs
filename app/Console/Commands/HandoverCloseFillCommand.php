<?php

namespace App\Console\Commands;

use App\Models\ShiftHandoverGap;
use App\Repositories\ShiftHandoverGapRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 手動收掉「說要補但補不上去」的交接漏項
 *
 * ⚠⚠ **這是逃生門，不是常態流程。**
 *
 * 2026-11-24 線上狀況：有幾筆 gap 是在「還沒開始存 message_id」那幾版
 * 之前建立的 —— 沒有任何 id 可以反查，所以**引用任何訊息都認不得**，
 * 而催補又沒有停點 ⇒ 每 30 分鐘 tag 三個人，永遠不會停。
 *
 * 那種情況下當事人**做什麼都沒用**，只能從這裡收掉。
 *
 * ⚠ 預設 dry-run：不加 `--force` 只會列出要收哪幾筆。
 */
class HandoverCloseFillCommand extends Command
{
    protected $signature = 'handover:close-fill
        {--gap= : 只收這一筆（gap id）；不給就收「一個 message_id 都沒有」的那些}
        {--note= : 收尾說明，會寫進 filled_note 讓之後查得出是人工收的}
        {--force : 真的執行；不加就只是列出來看}';

    protected $description = '手動收掉補不上去的交接漏項（逃生門，預設只列出不執行）';

    /**
     * @param ShiftHandoverGapRepository $repository
     * @return int
     */
    public function handle(ShiftHandoverGapRepository $repository)
    {
        $gapId = (int) $this->option('gap');
        $force = (bool) $this->option('force');

        $query = ShiftHandoverGap::query()
            ->select(['id', 'shift_handover_id', 'seq', 'title', 'ask_message_id', 'remind_message_id', 'remind_count'])
            ->where('status', config('constants.HANDOVER_REVIEW.STATUS.FILLED'))
            ->whereNull('filled_at');

        if ($gapId > 0) {
            $query->where('id', $gapId);
        } else {
            /*
             * 不指定 id 時，只收「真的補不上去」的那些 —— 兩個 id 都沒有。
             *
             * ⚠ **不要連「補得上去但還沒補」的也一起收掉**：
             * 那些人只是還沒動手，收掉等於替他決定不補了。
             */
            $query->whereNull('ask_message_id')->whereNull('remind_message_id');
        }

        $gaps = $query->orderBy('shift_handover_id')->orderBy('seq')->get();

        if (blank($gaps)) {
            $this->info('沒有符合條件的項目。');

            return 0;
        }

        $note = (string) $this->option('note');
        $note = filled($note) ? $note : '（人工收尾：這一筆沒有可引用的訊息，補不上去）';

        foreach ($gaps as $gap) {
            $this->line("  gap #{$gap->id}（交接 #{$gap->shift_handover_id} 第 {$gap->seq} 題）"
                . ' 已催 ' . (int) $gap->remind_count . ' 次');

            if (!$force) {
                continue;
            }

            /*
             * ⚠ 寫 `filled_note` 而不是改 `status` —— 「他說要補」這件事
             * 仍然是事實，只是補的動作由人工收尾。之後回頭查得出是誰收的、
             * 為什麼收。
             */
            $repository->appendFilledNote($gap, $note);

            Log::warning('交接漏項由人工收尾（補不上去）', [
                'gap_id'       => $gap->id,
                'handover_id'  => $gap->shift_handover_id,
                'seq'          => $gap->seq,
                'remind_count' => $gap->remind_count,
            ]);
        }

        if (!$force) {
            $this->warn('');
            $this->warn('以上是 dry-run。確定要收的話加 --force。');

            return 0;
        }

        $this->info('');
        $this->info('已收掉 ' . $gaps->count() . ' 筆，這幾筆不會再被催。');

        return 0;
    }
}
