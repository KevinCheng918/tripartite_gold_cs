<?php

namespace App\Console\Commands;

use App\Services\StaffDmWipeService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * 自動清除機器人與同仁的私訊
 *
 * 清掉超過「保留時數」的那些 —— 讓內部資訊不要一直留在同仁的 Telegram 裡。
 *
 * ⚠⚠ **總開關預設關**（通知設定 → 私訊清除）。服務自己會短路，
 * 所以掛排程不等於開始刪東西。
 *
 * ⚠⚠ **清掉私訊等於清掉通知本身**。逾時升級那則私訊是唯一告訴人「哪張單
 * 逾時了」的東西（電話只會響、沒有內容）—— 保留時數設太短會讓還沒讀到的
 * 人永遠看不到。預設 12 小時就是為了這件事。
 *
 * ⚠ **48 小時以前的永遠清不掉**（Telegram 的硬限制）。所以這支要跑得夠勤：
 * 保留 12 小時的設定下，訊息只有 36 小時的可清窗口。
 *
 * 詳見 .claude/knowledge/features/telegram-dm-wipe.md
 */
class WipeStaffDmCommand extends Command
{
    protected $signature = 'staff-dm:wipe';

    protected $description = '清除超過保留時數的同仁私訊（通知設定頁可開啟，預設關）';

    /**
     * @param StaffDmWipeService $wipeService
     * @return int
     */
    public function handle(StaffDmWipeService $wipeService)
    {
        $result = $wipeService->autoWipe();

        if (Arr::get($result, 'skipped')) {
            $this->warn('自動清除未啟用（通知設定 → 私訊清除 可開啟）');

            return 0;
        }

        $cleared = (int) Arr::get($result, 'cleared');
        $expired = (int) Arr::get($result, 'expired');
        $failed = (int) Arr::get($result, 'failed');

        $this->info("已清除 {$cleared} 則");

        // ⚠ 這兩個數字要印出來：失敗會自己重試，但過期的是永久清不掉了
        if ($failed > 0) {
            $this->warn("{$failed} 則送出失敗（維持未處理，下一輪再試）");
        }

        if ($expired > 0) {
            $this->warn("{$expired} 則已超過 48 小時，Telegram 永久不允許刪除");
        }

        return 0;
    }
}
