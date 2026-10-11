<?php

namespace App\Services\AutoReply;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 跟客人確認進度那一句
 *
 * 比照 `WaitWriter`：模型現寫，過不了就退公版。
 *
 * ⚠ **語氣不能催。** 客人沒有義務準時提供資料 —— 這句話的目的是把球遞回去，
 * 不是討債。prompt 裡明確禁止「請盡快」「我們已經等很久」這類說法。
 *
 * ⚠ **第二次之後要換說法**（帶 `round` 給模型），但語氣不能跟著變急 ——
 * 追問三次還是同一句話，客人會知道那是機器發的；而越問越急會把客人推走。
 *
 * ⚠ **一定要有公版退路**：模型沒設定、額度用完、CLI 逾時都會發生，
 * 那時候句子每次一樣也比完全不問好。
 */
class FollowupAskWriter
{
    private $matcher;

    public function __construct(ClaudeCodeMatcher $matcher)
    {
        $this->matcher = $matcher;
    }

    /**
     * 寫一句問進度的話
     *
     * @param string $waitText 客人當初說的那句話
     * @param int    $round    這是第幾次問（1 起算）
     * @return string
     */
    public function write($waitText, $round = 1)
    {
        $config = (array) config('constants.FOLLOWUP.ASK_CUSTOMER');
        $text = $this->generate($config, $waitText, $round);

        return filled($text) ? $text : (string) Arr::get($config, 'FALLBACK');
    }

    /**
     * 叫模型寫，不合用就回 null
     *
     * @param array  $config
     * @param string $waitText
     * @param int    $round
     * @return string|null
     */
    private function generate(array $config, $waitText, $round)
    {
        if (blank($waitText)) {
            return null;
        }

        try {
            $text = $this->matcher->generateText(
                (string) Arr::get($config, 'PROMPT'),
                strtr((string) Arr::get($config, 'INPUT'), [
                    '{text}'  => $waitText,
                    '{round}' => (int) $round,
                ])
            );
        } catch (\Exception $e) {
            // 排程與按鈕都不能因為模型掛掉就什麼都不發
            Log::warning('追問文案生成失敗，改用公版', ['error' => $e->getMessage()]);

            return null;
        }

        $text = trim((string) $text);

        if (blank($text)) {
            return null;
        }

        /*
         * ⚠ **寫太長就退公版**。這是一則問候，模型偶爾會寫成一整段 ——
         * 長出去會讓客人覺得我們在解釋一堆事情，而他只需要被提醒一句。
         */
        $max = (int) Arr::get($config, 'MAX_LENGTH');

        if ($max > 0 && mb_strlen($text) > $max) {
            Log::info('追問文案過長，改用公版', ['length' => mb_strlen($text)]);

            return null;
        }

        return $text;
    }
}
