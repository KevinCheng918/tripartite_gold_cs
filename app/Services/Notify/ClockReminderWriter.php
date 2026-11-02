<?php

namespace App\Services\Notify;

use App\Services\AutoReply\ClaudeCodeMatcher;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 上班前那四則提醒的說法
 *
 * 需求方 2026-11-01：「這 4 次的說話跟語氣和態度每天都要不一樣，不然會產生
 * 惰性，話語與語氣這個是自己人看的，所以讓 AI 自由發揮就好。」
 *
 * ⚠ **一天只生一次，而且全體共用**（不是逐人生）。
 *
 * 這支掛在**每分鐘**跑的排程上，而同一個班別可能十幾個人同時到提醒時間 ——
 * 逐人逐則生的話，一個 tick 要打幾十次 CLI、每次幾秒，一分鐘根本跑不完
 * （`EncouragementWriter` 的 docblock 就寫著逐人生成的代價）。
 * 需求方要的是「每天不一樣」，不是「每個人不一樣」。
 *
 * ⚠ **一定要有公版退路**（同 `EncouragementWriter`）：模型沒設定、額度用完、
 * CLI 逾時都會發生，那時候提醒還是要發出去。
 * 「語氣沒變化」比「同仁沒收到提醒」好得多。
 *
 * ⚠ **模型只寫那一句話，事實另外接**（上班時間與剩幾分鐘由
 * `ClockReminderService` 用固定句型補上）。把時間交給模型寫，
 * 它某天多寫一個數字就會變成錯誤資訊 —— 而這是會讓人遲到的那種錯。
 */
class ClockReminderWriter
{
    /** @var string 快取 key 的前綴（每天一組） */
    const CACHE_PREFIX = 'clock_reminder:lines:';

    private $matcher;

    public function __construct(ClaudeCodeMatcher $matcher)
    {
        $this->matcher = $matcher;
    }

    /**
     * 今天這四則的說法
     *
     * @param string $date Y-m-d
     * @return array<int, string> 以階段序號（0 起算）為 key
     */
    public function linesFor($date)
    {
        $config = (array) config('constants.CLOCK_REMINDER.TONE');
        $fallbacks = $this->fallbacks($config);

        /*
         * ⚠ 快取失敗也要能發訊息，所以 `Cache::remember()` 包在 try 裡 ——
         * cache driver 壞掉時整支排程不該跟著停。
         */
        try {
            $lines = Cache::remember(
                self::CACHE_PREFIX . $date,
                // 存到當天結束就好：明天要換一組新的
                now()->endOfDay(),
                function () use ($config, $fallbacks) {
                    return $this->generate($config, count($fallbacks));
                }
            );
        } catch (\Exception $e) {
            Log::warning('打卡提醒文案快取失敗，改用公版', ['error' => $e->getMessage()]);

            return $fallbacks;
        }

        return blank($lines) ? $fallbacks : $lines;
    }

    /**
     * 叫模型寫一組
     *
     * ⚠ **一次要齊四則**。分四次呼叫的話，模型看不到前三則，寫出來的四句
     * 語氣會互相重複 —— 而需求正是「四次的態度不一樣」（越接近上班越急）。
     *
     * ⚠ 回傳 null（不是公版）讓呼叫端決定 —— 這樣「生成失敗」不會被
     * `Cache::remember()` 當成有效結果存一整天。
     *
     * @param array $config
     * @param int   $expected 要幾則
     * @return array<int, string>|null
     */
    private function generate(array $config, $expected)
    {
        try {
            $result = $this->matcher->generateStructured(
                (string) Arr::get($config, 'PROMPT'),
                strtr((string) Arr::get($config, 'INPUT'), [
                    '{date}'  => now()->toDateString(),
                    '{count}' => (string) $expected,
                ]),
                $this->schema($expected)
            );
        } catch (\Exception $e) {
            Log::warning('打卡提醒文案生成失敗，改用公版', ['error' => $e->getMessage()]);

            return null;
        }

        return $this->normalize($result, $expected, $config);
    }

    /**
     * 把模型回的東西檢查過再用
     *
     * ⚠ **數量不對就整組退公版**，不要補齊。補一半的話，那一天會出現
     * 三句活潑一句罐頭 —— 看起來像系統壞了。
     *
     * ⚠ **長度要擋**。這是私訊裡的一句話，模型偶爾會寫成一整段；
     * 太長就整組不要（理由同 `EncouragementWriter::generate()`）。
     *
     * @param mixed $result
     * @param int   $expected
     * @param array $config
     * @return array<int, string>|null
     */
    private function normalize($result, $expected, array $config)
    {
        $lines = (array) Arr::get((array) $result, 'lines', []);

        if (count($lines) !== $expected) {
            Log::warning('打卡提醒文案數量不對，改用公版', ['got' => count($lines)]);

            return null;
        }

        $max = (int) Arr::get($config, 'MAX_LENGTH');
        $clean = [];

        foreach ($lines as $index => $line) {
            $text = trim((string) $line);

            if (blank($text) || mb_strlen($text) > $max) {
                Log::warning('打卡提醒文案不可用，改用公版', ['index' => $index, 'length' => mb_strlen($text)]);

                return null;
            }

            $clean[] = $text;
        }

        return $clean;
    }

    /**
     * 公版（模型不可用時）
     *
     * ⚠ 這幾句**刻意寫得比較平**：它們每天都一樣，寫得太活潑反而會讓人
     * 很快發現「今天又是罐頭」。
     *
     * @param array $config
     * @return array<int, string>
     */
    private function fallbacks(array $config)
    {
        return array_values((array) Arr::get($config, 'FALLBACKS', []));
    }

    /**
     * @param int $expected
     * @return array
     */
    private function schema($expected)
    {
        return [
            'type'       => 'object',
            'properties' => [
                'lines' => [
                    'type'        => 'array',
                    'minItems'    => $expected,
                    'maxItems'    => $expected,
                    'items'       => ['type' => 'string'],
                    'description' => "{$expected} 句提醒，由早到晚（第一句最從容、最後一句最急）。每句都是純文字，不要放 JSON、不要寫時間或分鐘數",
                ],
            ],
            'required' => ['lines'],
        ];
    }
}
