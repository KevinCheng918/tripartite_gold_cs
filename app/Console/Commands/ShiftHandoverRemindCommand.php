<?php

namespace App\Console\Commands;

use App\Services\ShiftHandoverReviewService;
use App\Services\ShiftHandoverService;
use Illuminate\Console\Command;

/**
 * 交接提醒
 *
 * 每班都要在交接話題打交接事項。這支每分鐘檢查交接窗口（兩班回訊時間
 * 重疊的那一小時），在下一班回訊開始前 5 分鐘開始提醒，一則訊息 tag 兩邊：
 * 上一班「請打交接事項」、下一班「你要上線了」。
 *
 * ⚠ **沒交接會一直催到完成**（需求方 2026-11-08：「就是催到他完成交接事項」，
 * 因為「他沒有就會打沒有了」）。沒有次數上限、沒有窗口結束的停點 ——
 * 控制「會不會吵」的唯一旋鈕是設定頁的間隔（預設 30 分鐘）。
 *
 * ⚠ **上一班今天沒人排班就不催**，改發一則給下一班請他去看「早班待接手
 * 清單」與上一份交接（引用那則）。這是大夜班現在沒人的情況。
 *
 * 總開關**預設開**（需求方指定），但沒設定交接話題時服務自己會短路。
 */
class ShiftHandoverRemindCommand extends Command
{
    protected $signature = 'shift:handover-remind';

    protected $description = '交接窗口到了就在交接話題 tag 上下兩班，沒交接就一直催';

    /**
     * @param ShiftHandoverService $handoverService
     * @return int
     */
    public function handle(ShiftHandoverService $handoverService, ShiftHandoverReviewService $reviewService)
    {
        /*
         * ⚠⚠ **兩件事要各自判斷、不要一起短路。**
         *
         * 「交接提醒」與「漏項補充催促」有**各自的總開關**
         * （`SHIFT_HANDOVER_TOPIC` vs `HANDOVER_REVIEW.ENABLED`），
         * 共用一個 `return` 會讓關掉其中一個把另一個也關掉 ——
         * 而那不會報錯，只會安靜地不動作。
         */
        if ($handoverService->isEnabled() && filled($handoverService->topicIds())) {
            $count = $handoverService->run();
            $this->info("已送出 {$count} 則交接訊息");
        } else {
            $this->warn('交接提醒未啟用，或還沒在「通知設定 → 話題分流」勾交接話題');
        }

        /*
         * 說了「要補上」卻還沒補的，催一輪（需求方 2026-11-23 選「催到補完」）。
         *
         * ⚠ 這一支自己會短路（總開關、間隔設 0、沒有到期的項目都直接回 0），
         * 所以每分鐘呼叫的成本是一次設定讀取。
         */
        $filled = $reviewService->remindUnfilled();

        if ($filled > 0) {
            $this->info("已催 {$filled} 筆交接補充");
        }

        return 0;
    }
}
