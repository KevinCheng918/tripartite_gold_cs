<?php

namespace App\Console\Commands;

use App\Services\CustomerFollowupService;
use Illuminate\Console\Command;

/**
 * 問當班人員要不要追蹤
 *
 * 有人在對話裡說「稍等」就會記成一筆待追蹤事項；這支每分鐘檢查
 * 「哪些對話已經安靜夠久」，把那幾筆發到內部支援群組並附兩顆按鈕。
 *
 * 沒人按按鈕時會每隔一段時間再問一次（第 3 次起連主管老闆一起 tag），
 * 到次數上限就發一則收尾然後停 —— 升級規則跟求助單提醒共用
 * （`EscalatingReminder`）。
 *
 * 間隔、上限、等待詞都在「通訊管理 → 通知設定 → 內部支援群組」調整；
 * 總開關預設是關的。
 */
class FollowupAskCommand extends Command
{
    protected $signature = 'followup:ask';

    protected $description = '問當班人員要不要追蹤那些說了「稍等」卻還沒回來的事項';

    /**
     * @param CustomerFollowupService $followupService
     * @return int
     */
    public function handle(CustomerFollowupService $followupService)
    {
        if (!$followupService->isEnabled()) {
            $this->warn('待追蹤事項功能未啟用（通知設定頁可開啟）');

            return 0;
        }

        $count = $followupService->askOnDuty();

        $this->info("已送出 {$count} 則詢問");

        return 0;
    }
}
