<?php

namespace App\Console\Commands;

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
    public function handle(ShiftHandoverService $handoverService)
    {
        if (!$handoverService->isEnabled()) {
            $this->warn('交接提醒未啟用（通知設定頁可關閉／開啟）');

            return 0;
        }

        if (blank($handoverService->topicIds())) {
            $this->error('還沒在「通知設定 → 話題分流」勾交接話題，功能不會動作');

            return 0;
        }

        $count = $handoverService->run();

        $this->info("已送出 {$count} 則交接訊息");

        return 0;
    }
}
