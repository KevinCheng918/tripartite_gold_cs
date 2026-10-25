<?php

namespace App\Console\Commands;

use App\Models\TelegramGroup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 把兩筆重複的 Telegram 對話合併成一筆
 *
 * 群組升級成 supergroup 時 Telegram 會換一個 chat_id。收訊端在
 * 2026-10-26 之前沒處理，於是新 id 的第一則訊息進來時又建了一筆 ——
 * 對話列表就出現同一個群組兩個視窗。
 *
 * 收訊端已經修好（`TelegramChatService::migrateChatIdIfNeeded()`），
 * 這支是**清既有資料**用的。
 *
 * ```
 * php artisan telegram:merge-group -- -123456789 -1001234567890
 * php artisan telegram:merge-group -- -123456789 -1001234567890 --force
 * ```
 *
 * ⚠ **chat_id 是負數，中間那個 `--` 不能省**。少了它 Symfony Console 會
 * 把 `-1234...` 當成選項，直接噴 `The "-1" option does not exist`。
 *
 * ⚠ **預設先看不動手**：沒加 `--force` 一律只印出會動到什麼。
 * 這支要改 9 張表，而且舊對話的訊息會接到新對話底下 —— 看清楚再做。
 */
class MergeTelegramGroupCommand extends Command
{
    protected $signature = 'telegram:merge-group
        {from? : 舊的 chat_id（要被併掉的那個）；不給就列出有哪些重複的}
        {to? : 新的 chat_id（保留的那個）}
        {--force : 真的執行；不加這個只印出會動到什麼}';

    protected $description = '把升級 supergroup 後重複出現的兩筆 Telegram 對話合併（chat_id 是負數，用法：telegram:merge-group -- -舊 -新）';

    /**
     * 要搬的表與欄位
     *
     * ⚠ **有唯一索引的要特別處理**：直接 UPDATE 會撞鍵。
     * 那幾張表的語意都是「這個人在這個對話的狀態」，舊對話那一筆直接丟掉
     * 即可（新對話已經有一筆更新的）。
     *
     * @var array<string, array{column: string, unique_with: string|null}>
     */
    private const TABLES = [
        'telegram_message'           => ['column' => 'telegram_group_id', 'unique_with' => null],
        'auto_reply_ticket'          => ['column' => 'telegram_group_id', 'unique_with' => null],
        'conversation_followup'      => ['column' => 'telegram_group_id', 'unique_with' => null],
        'llm_usage_log'              => ['column' => 'telegram_group_id', 'unique_with' => null],
        'quick_reply_phrasing'       => ['column' => 'telegram_group_id', 'unique_with' => null],
        'station'                    => ['column' => 'telegram_group_id', 'unique_with' => null],
        'telegram_group_read'        => ['column' => 'telegram_group_id', 'unique_with' => 'user_id'],
        'telegram_group_member'      => ['column' => 'telegram_group_id', 'unique_with' => 'telegram_user_id'],
        'telegram_group_staff_allow' => ['column' => 'telegram_group_id', 'unique_with' => 'user_id'],
    ];

    /**
     * @return int
     */
    public function handle()
    {
        // 不給 chat_id 就先幫他找出哪些對話重複了 —— 他需要這兩個 id 才下得了手
        if (blank($this->argument('from')) || blank($this->argument('to'))) {
            return $this->listDuplicates();
        }

        $from = $this->findGroup($this->argument('from'), '舊');
        $to = $this->findGroup($this->argument('to'), '新');

        if (blank($from) || blank($to)) {
            return 1;
        }

        if ((int) $from->id === (int) $to->id) {
            $this->error('兩個 chat_id 指到同一筆，不用合併');

            return 1;
        }

        $this->line("舊：#{$from->id} {$from->title}（chat_id {$from->chat_id}）");
        $this->line("新：#{$to->id} {$to->title}（chat_id {$to->chat_id}）");
        $this->newLine();

        $plan = $this->buildPlan($from, $to);

        foreach ($plan as $table => $counts) {
            $this->line(sprintf(
                '%-28s 搬 %d 筆，丟掉 %d 筆（新對話已經有）',
                $table,
                $counts['move'],
                $counts['drop']
            ));
        }

        $this->newLine();

        if (!$this->option('force')) {
            $this->warn('這是試算，什麼都沒改。確認無誤後加 --force 真的執行。');

            return 0;
        }

        $this->merge($from, $to, $plan);

        $this->info("合併完成，舊對話 #{$from->id} 已刪除。");

        return 0;
    }

    /**
     * 列出標題一樣的對話（多半就是升級前後那兩筆）
     *
     * ⚠ 不是用 `chat_id` 比對 —— 升級後的 id 跟舊的沒有可靠的對應關係
     * （常見是 `-100` 加上舊 id 的數字，但不保證）。用**標題**找最準，
     * 因為升級不會改群組名稱。
     *
     * @return int
     */
    private function listDuplicates()
    {
        $titles = TelegramGroup::query()
            ->selectRaw('title, COUNT(*) as total')
            ->groupBy('title')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('title');

        if (blank($titles)) {
            $this->info('沒有標題重複的對話。');

            return 0;
        }

        foreach ($titles as $title) {
            $this->newLine();
            $this->line("<comment>{$title}</comment>");

            $groups = TelegramGroup::query()
                ->select(['id', 'chat_id', 'title', 'last_message_at'])
                ->where('title', $title)
                ->orderBy('last_message_at')
                ->get();

            foreach ($groups as $group) {
                $this->line(sprintf(
                    '  #%-5d chat_id %-20s 最後訊息 %s',
                    $group->id,
                    $group->chat_id,
                    filled($group->last_message_at) ? $group->last_message_at->toDateTimeString() : '（無）'
                ));
            }

            $old = $groups->first();
            $new = $groups->last();

            // ⚠ 要把 `--` 寫出來：chat_id 是負數，少了它會被當成選項
            $this->line("  → php artisan telegram:merge-group -- {$old->chat_id} {$new->chat_id}");
        }

        $this->newLine();
        $this->warn('最後訊息較晚的那筆是升級後的新對話，合併時放在後面（保留它）。');

        return 0;
    }

    /**
     * 找出那一筆對話
     *
     * @param string $chatId
     * @param string $label
     * @return TelegramGroup|null
     */
    private function findGroup($chatId, $label)
    {
        $group = TelegramGroup::query()->where('chat_id', $chatId)->first();

        if (blank($group)) {
            $this->error("找不到{$label}的 chat_id：{$chatId}");
        }

        return $group;
    }

    /**
     * 算出每張表要搬幾筆、丟幾筆
     *
     * @param TelegramGroup $from
     * @param TelegramGroup $to
     * @return array<string, array{move: int, drop: int}>
     */
    private function buildPlan(TelegramGroup $from, TelegramGroup $to)
    {
        $plan = [];

        foreach (self::TABLES as $table => $spec) {
            $column = $spec['column'];
            $uniqueWith = $spec['unique_with'];

            $total = DB::table($table)->where($column, $from->id)->count();

            // 沒有唯一索引的整批搬
            if (blank($uniqueWith)) {
                $plan[$table] = ['move' => $total, 'drop' => 0];

                continue;
            }

            /*
             * 有唯一索引：新對話已經有同一個 (對話, 人) 的那幾筆要丟掉，
             * 直接 UPDATE 會撞鍵。
             */
            $drop = DB::table($table)
                ->where($column, $from->id)
                ->whereIn($uniqueWith, function ($query) use ($table, $column, $uniqueWith, $to) {
                    $query->select($uniqueWith)->from($table)->where($column, $to->id);
                })
                ->count();

            $plan[$table] = ['move' => $total - $drop, 'drop' => $drop];
        }

        return $plan;
    }

    /**
     * 真的搬
     *
     * ⚠ **整段包在 transaction 裡**：搬到一半失敗的話，訊息會散在兩個對話底下
     * —— 那比現在的「兩個視窗」更難收拾。
     *
     * @param TelegramGroup $from
     * @param TelegramGroup $to
     * @param array         $plan
     * @return void
     */
    private function merge(TelegramGroup $from, TelegramGroup $to, array $plan)
    {
        DB::transaction(function () use ($from, $to) {
            foreach (self::TABLES as $table => $spec) {
                $column = $spec['column'];
                $uniqueWith = $spec['unique_with'];

                if (filled($uniqueWith)) {
                    // 先丟掉會撞鍵的，剩下的才搬得動
                    DB::table($table)
                        ->where($column, $from->id)
                        ->whereIn($uniqueWith, function ($query) use ($table, $column, $uniqueWith, $to) {
                            $query->select($uniqueWith)->from($table)->where($column, $to->id);
                        })
                        ->delete();
                }

                DB::table($table)->where($column, $from->id)->update([$column => $to->id]);
            }

            /*
             * ⚠ 最後訊息時間取兩者較晚的 —— 舊對話可能有比新對話更晚的訊息
             * （升級前後交錯），直接沿用新對話的會讓它在列表往下掉。
             */
            if (filled($from->last_message_at)
                && (blank($to->last_message_at) || $from->last_message_at->greaterThan($to->last_message_at))) {
                $to->last_message_at = $from->last_message_at;
                $to->save();
            }

            $from->delete();
        });
    }
}
