<?php

namespace App\Console\Commands;

use App\Events\TelegramAlertTriggered;
use App\Repositories\TelegramRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Telegram 未回覆告警
 *
 * 每分鐘檢查是否有超時未回覆的訊息，
 * 週一至週五閾值 5 分鐘，週六日 30 分鐘。
 */
class TelegramAlertCommand extends Command
{
    protected $signature = 'telegram:alert';
    protected $description = '檢查未回覆的 Telegram 訊息並發送告警';

    /**
     * @return int
     */
    public function handle()
    {
        $isWeekend = in_array(now()->dayOfWeek, [0, 6]); // 0=日, 6=六
        $minutes = $isWeekend
            ? config('constants.TELEGRAM.ALERT.WEEKEND_MINUTES', 30)
            : config('constants.TELEGRAM.ALERT.WEEKDAY_MINUTES', 5);

        $unreplied = app(TelegramRepository::class)->getUnrepliedMessages($minutes);

        if ($unreplied->isEmpty()) {
            return 0;
        }

        // 依群組分組，每個群組只發一次告警
        $grouped = $unreplied->groupBy('telegram_group_id');

        foreach ($grouped as $groupId => $messages) {
            $group = $messages->first()->group;
            if (!$group) {
                continue;
            }

            // 計算最久未回覆的分鐘數
            $oldestMinutes = (int) now()->diffInMinutes($messages->min('created_at'));

            try {
                event(new TelegramAlertTriggered($groupId, $group->title, $oldestMinutes));
            } catch (\Exception $e) {
                Log::warning('Telegram 告警 Broadcasting 失敗', ['error' => $e->getMessage()]);
            }

            $this->warn("告警：{$group->title}（{$oldestMinutes} 分鐘未回覆）");
        }

        Log::info('Telegram 未回覆告警檢查完成', ['groups' => $grouped->count()]);

        return 0;
    }
}
