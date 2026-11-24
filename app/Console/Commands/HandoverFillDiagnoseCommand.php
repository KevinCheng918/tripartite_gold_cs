<?php

namespace App\Console\Commands;

use App\Models\ShiftHandoverGap;
use App\Repositories\ShiftHandoverRepository;
use App\Services\ShiftHandoverReviewService;
use App\Services\SupportGroupService;
use Illuminate\Console\Command;

/**
 * 診斷「交接漏項補不上去、一直被催」
 *
 * ⚠⚠ 2026-11-24 線上狀況：同仁引用催補訊息回內容，系統沒反應，
 * 催到第 23 次；補了 `remind_message_id` 之後**仍然在跳**，
 * 而且「有沒有引用都一樣」。
 *
 * 這一支把**每一道關卡的實際狀態**印出來，不要再用猜的：
 *
 * 1. 交接話題有沒有設定（沒設定的話整支功能靜默不動作）
 * 2. 還在被催的那幾筆，各自有沒有存到可供引用反查的 message_id
 * 3. 認不認得出來 —— 沒有任何一個 id 的那幾筆**永遠補不上去**
 *
 * ⚠ 唯讀，不會改任何資料。
 */
class HandoverFillDiagnoseCommand extends Command
{
    protected $signature = 'handover:diagnose-fill';

    protected $description = '診斷交接漏項為什麼補不上去、一直被催（唯讀）';

    /**
     * @param SupportGroupService     $supportGroup
     * @param ShiftHandoverRepository $handoverRepository
     * @return int
     */
    public function handle(SupportGroupService $supportGroup, ShiftHandoverRepository $handoverRepository)
    {
        $this->line('── ① 總開關與話題設定 ──────────────');

        $enabled = (bool) config('constants.HANDOVER_REVIEW.ENABLED');
        $this->line('  漏項盤點總開關：' . ($enabled ? '開' : '⚠ 關（整支功能不動作）'));

        $topicIds = $supportGroup->topicIdsFor(ShiftHandoverReviewService::NOTICE_TYPE);

        if (blank($topicIds)) {
            $this->error('  ⚠⚠ 沒有設定交接話題 —— 補充功能一定不會動作');
            $this->line('     （通知設定 → 話題分流 → 勾「交接」）');
        } else {
            $this->line('  交接話題 thread_id：' . implode('、', $topicIds));
            $this->warn('  ⚠ 如果交接是發在 Telegram 的「General」主區，');
            $this->warn('    那裡的訊息**沒有 message_thread_id**，這一道會擋掉所有補充。');
        }

        $this->line('');
        $this->line('── ② 還在被催的項目 ────────────────');

        $pending = ShiftHandoverGap::query()
            ->select([
                'id', 'shift_handover_id', 'seq', 'status', 'title',
                'ask_message_id', 'remind_message_id', 'remind_count', 'filled_at',
            ])
            ->where('status', config('constants.HANDOVER_REVIEW.STATUS.FILLED'))
            ->whereNull('filled_at')
            ->orderBy('shift_handover_id')
            ->orderBy('seq')
            ->get();

        if (blank($pending)) {
            $this->info('  沒有任何「說要補但還沒補」的項目 —— 現在不會有人被催。');

            return 0;
        }

        foreach ($pending as $gap) {
            $handover = $handoverRepository->find((int) $gap->shift_handover_id);

            $ids = [
                '題目'  => (int) $gap->ask_message_id,
                '催補'  => (int) $gap->remind_message_id,
                '開場'  => filled($handover) ? (int) $handover->review_opening_message_id : 0,
                '總結'  => filled($handover) ? (int) $handover->review_summary_message_id : 0,
            ];

            $usable = array_filter($ids, function ($id) {
                return $id > 0;
            });

            $this->line('');
            $this->line("  gap #{$gap->id}（交接 #{$gap->shift_handover_id} 第 {$gap->seq} 題）"
                . ' 已催 ' . (int) $gap->remind_count . ' 次');
            $this->line('    標題：' . (filled($gap->title) ? $gap->title : '（主管／老闆交代事項）'));

            foreach ($ids as $label => $id) {
                $this->line("    {$label} message_id：" . ($id > 0 ? $id : '— 沒有'));
            }

            if (blank($usable)) {
                /*
                 * ⚠⚠ **這就是補不上去的原因。**
                 *
                 * 這幾筆是在「還沒開始存 message_id」那幾版之前建立的 ——
                 * 沒有任何一個 id 可以反查，所以**引用任何訊息都認不得**，
                 * 而催補又沒有停點 ⇒ 永遠在跳。
                 */
                $this->error('    ⚠⚠ 一個 id 都沒有 —— 這一筆「引用什麼都認不得」，會被永遠催下去');
                continue;
            }

            $this->info('    可以引用：' . implode('、', array_keys($usable)) . ' 這幾則');
        }

        $this->line('');
        $this->line('── ③ 怎麼收尾 ──────────────────────');
        $this->line('  「一個 id 都沒有」的那幾筆，引用任何訊息都認不得 ——');
        $this->line('  要嘛等下一次催補把 remind_message_id 補起來（需要程式已更新），');
        $this->line('  要嘛用 handover:close-fill 直接收掉（見該指令說明）。');

        return 0;
    }
}
