<?php

namespace App\Console\Commands;

use App\Services\LoginNoticeService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * 把登入紀錄私訊給帳號本人
 *
 * 需求方 2026-11-05：登入後要通知本人（時間、IP、作業系統、瀏覽器、裝置）。
 *
 * 登入當下只寫 `login_log`（本來就會寫），這支每分鐘撈未通知的發出去 ——
 * 跟 `task:notify-changes` 同一個模式。
 *
 * ⚠ **不要改成在 `LoginController` 裡直接發**：`TelegramBotService` 的
 * HTTP client 是 `['timeout' => 10]`，而本專案 `QUEUE_CONNECTION=sync`
 * （`dispatch()` 當場跑完、不進背景）—— Telegram 慢的時候使用者按下登入
 * 會卡最多 10 秒才進得去後台。
 *
 * 判斷規則與文案都在 `LoginNoticeService` 與 `constants.LOGIN_NOTICE`。
 */
class NotifyLoginCommand extends Command
{
    protected $signature = 'login:notify {--dry-run : 只印出會發什麼，不真的送、也不標記}';

    protected $description = '把登入紀錄私訊通知給帳號本人';

    /**
     * @param LoginNoticeService $noticeService
     * @return int
     */
    public function handle(LoginNoticeService $noticeService)
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $noticeService->run($dryRun);

        if ($dryRun) {
            foreach (Arr::get($result, 'messages') as $message) {
                $this->line(str_repeat('-', 40));
                $this->line($message);
            }
        }

        $this->info(sprintf(
            '%s已發送 %d 則、跳過 %d 筆、等累積 %d 筆、清掉過期 %d 筆',
            $dryRun ? '[dry-run] ' : '',
            (int) Arr::get($result, 'sent'),
            (int) Arr::get($result, 'skipped'),
            (int) Arr::get($result, 'pending'),
            (int) Arr::get($result, 'stale')
        ));

        $unreachable = (int) Arr::get($result, 'unreachable');

        if ($unreachable > 0) {
            $this->warn("其中 {$unreachable} 位同仁收不到（未綁定或沒私訊過機器人）");
        }

        return 0;
    }
}
