<?php

namespace App\Console\Commands;

use App\Services\AutoReplyService;
use Illuminate\Console\Command;

/**
 * 試跑自動回覆比對
 *
 * 在終端看模型挑了哪一則、信心多少、候選是什麼、實際會送出去的完整訊息長什麼樣。
 * 調 prompt 與話術不必真的跑去 Telegram 試。
 *
 * ⚠️ 會真的呼叫 Claude，消耗訂閱額度（或備援 API 費用）。
 *
 * @example php artisan auto-reply:test "虛擬機連不上怎麼辦"
 * @example php artisan auto-reply:test "第一個" --pending=12,34
 */
class AutoReplyTestCommand extends Command
{
    protected $signature = 'auto-reply:test
        {question : 客人的問題}
        {--pending= : 模擬反問後的第二輪，傳上一輪的候選題目 id（逗號分隔）}';

    protected $description = '試跑自動回覆比對，顯示決策與會送出的訊息';

    /**
     * @param AutoReplyService $autoReplyService
     * @return int
     */
    public function handle(AutoReplyService $autoReplyService)
    {
        $question = $this->argument('question');
        $pending = $this->parsePending();

        $this->line("客人：{$question}");

        if (filled($pending)) {
            $this->line('（模擬反問後的第二輪，候選：' . implode(', ', $pending) . '）');
        }

        $this->line('');

        $startedAt = microtime(true);
        $preview = $autoReplyService->preview($question, $pending);
        $duration = round((microtime(true) - $startedAt) * 1000);

        $this->showResult($preview, $duration);

        return 0;
    }

    /**
     * 顯示比對結果
     *
     * @param array $preview
     * @param float $duration 毫秒
     * @return void
     */
    private function showResult(array $preview, $duration)
    {
        $actions = config('constants.AUTO_REPLY.DECISION');
        $labels = [
            $actions['ANSWER']  => '命中題庫，送答案',
            $actions['CLARIFY'] => '沒把握，反問客人',
            $actions['WAIT']    => '題庫裡沒有，說稍等並轉內部群組',
        ];

        $action = $preview['action'];
        $this->warn('【決策】' . (isset($labels[$action]) ? $labels[$action] : $action));

        $result = $preview['result'];

        if (!filled($result)) {
            $this->error('比對器沒有回傳結果（逾時、額度用盡或格式錯誤，詳見 log）');
        } else {
            $this->table(['項目', '值'], [
                ['item_id', filled($result['item_id']) ? $result['item_id'] : 'null'],
                ['confidence', $result['confidence']],
                ['candidate_ids', implode(', ', $result['candidate_ids']) ?: '-'],
                ['來源', $this->sourceLabel($result)],
            ]);
        }

        $this->line("耗時：{$duration} ms");
        $this->line('');
        $this->warn('【實際會送出的訊息】');
        $this->line(filled($preview['content']) ? $preview['content'] : '（話術模板未設定，會送不出去）');
    }

    /**
     * @param array $result
     * @return string
     */
    private function sourceLabel(array $result)
    {
        $source = isset($result['source']) ? $result['source'] : null;

        return $source === config('constants.AUTO_REPLY.SOURCE.FALLBACK') ? '備援 API（計費）' : '訂閱';
    }

    /**
     * @return array
     */
    private function parsePending()
    {
        $option = $this->option('pending');

        if (!filled($option)) {
            return [];
        }

        return array_values(array_filter(array_map('intval', explode(',', $option))));
    }
}
