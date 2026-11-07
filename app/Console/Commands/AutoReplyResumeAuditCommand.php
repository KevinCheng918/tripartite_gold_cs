<?php

namespace App\Console\Commands;

use App\Services\AutoReplyResumeService;
use App\Services\SupportGroupService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * 上線盤點：哪些對話看起來是「忘記勾回來」的
 *
 * 自動恢復是靠關閉紀錄運作的，代價是**上線前就已經忘記勾回來的對話沒有紀錄**
 * —— 那一刻系統分不出它是「忘了關的」還是「本來就不開的」。這支把可疑的
 * 列出來給人看。
 *
 * 判斷依據：`auto_reply_at`（最後一次自動回覆時間）只會在 `isAutoReplyOn()`
 * 閘門之後寫入，所以有值代表「曾經真的開著回過客人」。有值又現在關著，
 * 就很可能是忘記勾回來的。
 *
 * ⚠ **這支一行都不寫入**，只印清單。會把 `auto_reply` 由 0 改成 1 的地方
 * 只有 `auto-reply:resume`，而它只看關閉紀錄 —— 盤點若順手建紀錄，
 * 就等於擅自把「列清單讓人決定」換成「自動全部開回來」，
 * 而那個方案是需求方否決掉的（開回來的訊息發給客人就收不回來）。
 *
 * ⚠ **不掛排程**，是手動跑的一次性工具。留著不刪：之後若有人懷疑
 * 「這個對話是不是又被忘記了」，同一支指令就能回答。
 */
class AutoReplyResumeAuditCommand extends Command
{
    protected $signature = 'auto-reply:resume-audit {--notify : 同時發一則到內部支援群組}';

    protected $description = '盤點「現在關著、但曾經自動回覆過」的對話（只讀不寫）';

    /**
     * @param AutoReplyResumeService $resumeService
     * @param SupportGroupService    $supportGroup
     * @return int
     */
    public function handle(AutoReplyResumeService $resumeService, SupportGroupService $supportGroup)
    {
        $candidates = $resumeService->auditCandidates();

        if ($candidates->isEmpty()) {
            $this->info('沒有「關著但曾經自動回覆過」的對話 —— 不需要補勾任何一個。');

            return 0;
        }

        $this->warn("以下 {$candidates->count()} 個對話目前關著自動回覆，但曾經開著回過客人：");
        $this->table(
            ['對話', '最後一次自動回覆', '最後一則訊息'],
            $candidates->map(function ($group) {
                return [
                    $group->title,
                    filled($group->auto_reply_at) ? (string) $group->auto_reply_at : '-',
                    filled($group->last_message_at) ? (string) $group->last_message_at : '-',
                ];
            })->all()
        );
        $this->line('');
        $this->line('⚠ 這份清單裡可能混著「刻意永久關掉」的客戶 —— 請逐一確認再到聊天視窗勾回來。');
        $this->line('⚠ 從來沒開過自動回覆的對話不在清單裡（它們不會被自動恢復機制碰到）。');

        if (!$this->option('notify')) {
            return 0;
        }

        if (!$supportGroup->isConfigured()) {
            $this->error('尚未設定內部支援群組，--notify 無法送出');

            return 0;
        }

        $supportGroup->send($this->buildText($candidates), null, null, AutoReplyResumeService::NOTICE_TYPE);
        $this->info('已發到內部支援群組');

        return 0;
    }

    /**
     * 組發到支援群組的那一則
     *
     * ⚠ 不要自己跳脫 HTML —— `TelegramBotService::sendMessage()` 已經
     * `escapeHtml()` 過，再跳脫一次會變成字面的 `&lt;b&gt;`。
     *
     * @param \Illuminate\Support\Collection $candidates
     * @return string
     */
    private function buildText($candidates)
    {
        $notice = (array) config('constants.AUTO_REPLY_RESUME.NOTICE');
        $max = (int) Arr::get($notice, 'MAX_LINES');

        $text = "🔍 <b>自動回覆盤點</b>\n"
            . "以下 <b>{$candidates->count()}</b> 個對話目前關著自動回覆，但曾經開著回過客人 ——"
            . "可能是處理完忘記勾回來：\n";

        foreach ($candidates->take($max) as $group) {
            $lastAt = filled($group->auto_reply_at) ? $group->auto_reply_at->format('Y-m-d H:i') : '-';
            $text .= "\n• {$group->title}\n　最後一次自動回覆：{$lastAt}\n";
        }

        if ($candidates->count() > $max) {
            $remain = $candidates->count() - $max;
            $text .= "\n…另外還有 {$remain} 個，請用指令查看完整清單\n";
        }

        return $text . "\n⚠ 其中可能混著刻意永久關掉的客戶，請逐一確認再勾回來。";
    }
}
