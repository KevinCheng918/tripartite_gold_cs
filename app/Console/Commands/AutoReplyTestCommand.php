<?php

namespace App\Console\Commands;

use App\Services\AutoReplyService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * 試跑自動回覆比對
 *
 * 在終端看模型怎麼判斷這句話、挑了哪一則、承接句寫了什麼、
 * 以及實際會送出去的完整訊息長什麼樣。調 prompt 與話術不必真的跑去 Telegram 試。
 *
 * ⚠️ 會真的呼叫 Claude，消耗訂閱額度（或備援 API 費用）。
 *
 * @example php artisan auto-reply:test "虛擬機連不上怎麼辦"
 * @example php artisan auto-reply:test "我想再加一台機器"
 * @example php artisan auto-reply:test "好，謝謝"
 * @example php artisan auto-reply:test "所以改好了嗎？" --tag=龍
 * @example php artisan auto-reply:test "這個帳號不能登入" --tag=@sl0168888
 * @example php artisan auto-reply:test "麻煩看一下" --our-tag=@cs_mei
 */
class AutoReplyTestCommand extends Command
{
    /*
     * ⚠ `--tag`／`--our-tag` 是用來測「這句是講給誰聽的」（`not_for_us`）。
     * 線上這份名單是 webhook 從 Telegram 的 `entities` 算出來的，
     * 終端沒有那個東西，所以要讓人手動指定 —— 不然這條路徑只能真的
     * 跑去客戶群組裡 tag 人才測得到。
     */
    protected $signature = 'auto-reply:test
        {question : 客人說的話}
        {--tag=* : 這則 tag 到的「對方的人」（可重複）}
        {--our-tag=* : 這則 tag 到的「我們的人」（可重複）}';

    protected $description = '試跑自動回覆比對，顯示決策與會送出的訊息';

    /**
     * @param AutoReplyService $autoReplyService
     * @return int
     */
    public function handle(AutoReplyService $autoReplyService)
    {
        $question = $this->argument('question');
        $mentions = $this->buildMentions();

        $this->line("客人：{$question}");

        if (filled($mentions)) {
            $this->line('　（這則 tag 了：' . implode('、', array_column($mentions, 'label')) . '）');
        }

        $this->line('');

        $startedAt = microtime(true);
        $preview = $autoReplyService->preview($question, $mentions);
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
            $actions['ANSWER'] => '命中題庫，承接句 + 答案原文',
            $actions['REPLY']  => '只回一句承接話，不帶題庫內容',
            $actions['WAIT']   => '沒把握或是需求，說稍等並轉內部群組',
            $actions['SILENT'] => '不需要回應，完全不回',
        ];

        $action = $preview['action'];
        $this->warn('【決策】' . Arr::get($labels, $action, $action));

        $result = $preview['result'];

        if (blank($result)) {
            $this->error('比對器沒有回傳結果（逾時、額度用盡或格式錯誤，詳見 log）');
        } else {
            $itemId = Arr::get($result, 'item_id');
            $opening = Arr::get($result, 'opening');

            $this->table(['項目', '值'], [
                ['intent', $this->intentLabel($result)],
                ['item_id', filled($itemId) ? $itemId : 'null'],
                ['confidence', Arr::get($result, 'confidence')],
                ['opening（模型原文）', filled($opening) ? $opening : '（沒給）'],
                ['來源', $this->sourceLabel($result)],
            ]);
        }

        $this->line("耗時：{$duration} ms");
        $this->line('');

        $this->warn('【實際會送給客人的訊息】');

        if ($action === $actions['SILENT']) {
            $this->line('（不回覆）');
        } else {
            $this->line(filled($preview['content']) ? $preview['content'] : '（話術模板未設定，會送不出去）');
        }

        // 轉人工時同仁會在支援群組多看到這段
        if (filled($preview['hint'])) {
            $this->line('');
            $this->warn('【支援群組會多看到的 AI 判斷】');
            $this->line($preview['hint']);
        }
    }

    /**
     * 把 `--tag`／`--our-tag` 組成跟線上一樣的 tag 名單
     *
     * @return array<int, array{label: string, ours: bool}>
     */
    private function buildMentions()
    {
        $mentions = [];

        foreach ((array) $this->option('tag') as $label) {
            $mentions[] = ['label' => (string) $label, 'ours' => false];
        }

        foreach ((array) $this->option('our-tag') as $label) {
            $mentions[] = ['label' => (string) $label, 'ours' => true];
        }

        return $mentions;
    }

    /**
     * @param array $result
     * @return string
     */
    private function intentLabel(array $result)
    {
        $intents = config('constants.AUTO_REPLY.INTENT');
        $labels = [
            $intents['QUESTION']   => 'question（在問事情）',
            $intents['REQUEST']    => 'request（提需求）',
            $intents['CHAT']       => 'chat（寒暄）',
            $intents['UNCLEAR']    => 'unclear（判斷不出來，轉人工）',
            $intents['NOT_FOR_US'] => 'not_for_us（他在跟自己人講話，完全不回）',
        ];

        $intent = Arr::get($result, 'intent');

        return Arr::get($labels, $intent, (string) $intent);
    }

    /**
     * @param array $result
     * @return string
     */
    private function sourceLabel(array $result)
    {
        $source = Arr::get($result, 'source');

        return $source === config('constants.AUTO_REPLY.SOURCE.FALLBACK') ? '備援 API（計費）' : '訂閱';
    }
}
